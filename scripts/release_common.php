<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI tooling must preserve raw paths and subprocess diagnostics.

function release_repo_root(): string
{
    return dirname(__DIR__);
}

function release_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function release_read_file(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Failed to read file: {$path}");
    }

    return $content;
}

function release_plugin_version(): string
{
    $bootstrap = release_read_file(release_repo_root() . '/oxygen-html-converter.php');

    if (!preg_match("/define\\('OXY_HTML_CONVERTER_VERSION',\\s*'([^']+)'\\);/", $bootstrap, $matches)) {
        throw new RuntimeException('Could not find OXY_HTML_CONVERTER_VERSION in plugin bootstrap.');
    }

    return trim($matches[1]);
}

function release_plugin_header_version(): string
{
    $bootstrap = release_read_file(release_repo_root() . '/oxygen-html-converter.php');

    if (!preg_match('/^\\s*\\*\\s+Version:\\s+(.+)$/m', $bootstrap, $matches)) {
        throw new RuntimeException('Could not find Version header in plugin bootstrap.');
    }

    return trim($matches[1]);
}

function release_version_doc_names(string $version): array
{
    return [
        'readmeMarker' => sprintf('`v%s`', $version),
        'releaseNotes' => sprintf('RELEASE_NOTES_%s.md', strtoupper(str_replace('-', '_', $version))),
        'dod' => sprintf('DOD-%s.md', strtoupper($version)),
    ];
}

function release_distignore_patterns(): array
{
    $path = release_repo_root() . '/.distignore';
    $lines = preg_split('/\\R/', release_read_file($path)) ?: [];
    $patterns = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $patterns[] = release_normalize_path(preg_replace('#^\./#', '', $line) ?? $line);
    }

    return $patterns;
}

function release_path_matches_pattern(string $relativePath, string $pattern): bool
{
    $relativePath = release_normalize_path(preg_replace('#^\./#', '', ltrim($relativePath)) ?? $relativePath);
    $pattern = release_normalize_path(preg_replace('#^\./#', '', ltrim($pattern)) ?? $pattern);

    if ($pattern === '') {
        return false;
    }

    if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
        $firstSegment = explode('/', $relativePath, 2)[0];

        return fnmatch($pattern, $relativePath)
            || fnmatch($pattern, basename($relativePath))
            || fnmatch($pattern, $firstSegment);
    }

    $pattern = rtrim($pattern, '/');

    return $relativePath === $pattern || str_starts_with($relativePath, $pattern . '/');
}

function release_should_exclude(string $relativePath, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (release_path_matches_pattern($relativePath, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, string>
 */
function release_distribution_files(string $root, array $patterns): array
{
    $trackedFiles = release_run_command(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root);
    release_require_success($trackedFiles);

    $files = [];
    foreach (explode("\0", (string) $trackedFiles['stdout']) as $relativePath) {
        $relativePath = release_normalize_path(trim($relativePath));
        if ($relativePath === '' || release_should_exclude($relativePath, $patterns)) {
            continue;
        }

        $absolutePath = $root . '/' . $relativePath;
        if (is_file($absolutePath)) {
            $files[$relativePath] = $relativePath;
        }
    }

    $files = array_values($files);
    sort($files);

    return $files;
}

/**
 * @param array<string, mixed> $overrides
 * @param array<string, string|false>|null $environment
 * @return array{timeoutSeconds: float, outputLimitBytes: int, pollIntervalMicroseconds: int}
 */
function release_process_options(array $overrides = [], ?array $environment = null): array
{
    $environment ??= [
        'OXY_HTML_CONVERTER_COMMAND_TIMEOUT' => getenv('OXY_HTML_CONVERTER_COMMAND_TIMEOUT'),
        'OXY_HTML_CONVERTER_OUTPUT_CAP_BYTES' => getenv('OXY_HTML_CONVERTER_OUTPUT_CAP_BYTES'),
    ];

    $environmentTimeout = $environment['OXY_HTML_CONVERTER_COMMAND_TIMEOUT'] ?? false;
    $environmentOutputLimit = $environment['OXY_HTML_CONVERTER_OUTPUT_CAP_BYTES'] ?? false;
    $timeout = array_key_exists('timeoutSeconds', $overrides)
        ? $overrides['timeoutSeconds']
        : (is_string($environmentTimeout) && trim($environmentTimeout) !== '' ? $environmentTimeout : 900);
    $outputLimit = array_key_exists('outputLimitBytes', $overrides)
        ? $overrides['outputLimitBytes']
        : (is_string($environmentOutputLimit) && trim($environmentOutputLimit) !== ''
            ? $environmentOutputLimit
            : (4 * 1024 * 1024));
    $pollInterval = $overrides['pollIntervalMicroseconds'] ?? 10000;

    if (!is_numeric($timeout) || (float) $timeout <= 0) {
        throw new InvalidArgumentException('Process timeout must be greater than zero seconds.');
    }

    if (!is_numeric($outputLimit) || (int) $outputLimit < 1) {
        throw new InvalidArgumentException('Process output capture limit must be at least one byte.');
    }

    if (!is_numeric($pollInterval) || (int) $pollInterval < 1000) {
        throw new InvalidArgumentException('Process poll interval must be at least 1000 microseconds.');
    }

    return [
        'timeoutSeconds' => (float) $timeout,
        'outputLimitBytes' => (int) $outputLimit,
        'pollIntervalMicroseconds' => (int) $pollInterval,
    ];
}

/**
 * @return array{content: string, receivedBytes: int, truncated: bool}
 */
function release_new_output_capture(): array
{
    return [
        'content' => '',
        'receivedBytes' => 0,
        'truncated' => false,
    ];
}

/**
 * @param array{content: string, receivedBytes: int, truncated: bool} $capture
 */
function release_capture_output(array &$capture, string $chunk, int $limitBytes): void
{
    $chunkBytes = strlen($chunk);
    $capture['receivedBytes'] += $chunkBytes;
    $remaining = max(0, $limitBytes - strlen($capture['content']));

    if ($remaining > 0) {
        $capture['content'] .= substr($chunk, 0, $remaining);
    }

    if ($capture['receivedBytes'] > $limitBytes) {
        $capture['truncated'] = true;
    }
}

/**
 * Build a deterministic deepest-child-first cleanup plan without spawning anything.
 *
 * @return array<int, array<int, string>>
 */
function release_timeout_cleanup_commands(int $rootPid, bool $windows, string $processTable = ''): array
{
    if ($rootPid < 1) {
        return [];
    }

    if ($windows) {
        return [['taskkill', '/PID', (string) $rootPid, '/T', '/F']];
    }

    $children = [];
    foreach (preg_split('/\R/', trim($processTable)) ?: [] as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $matches) !== 1) {
            continue;
        }

        $pid = (int) $matches[1];
        $parentPid = (int) $matches[2];
        $children[$parentPid][] = $pid;
    }

    $ordered = [];
    $appendTree = static function (int $pid) use (&$appendTree, &$children, &$ordered): void {
        foreach ($children[$pid] ?? [] as $childPid) {
            $appendTree($childPid);
        }
        $ordered[] = $pid;
    };
    $appendTree($rootPid);

    return array_map(
        static fn (int $pid): array => ['kill', '-TERM', (string) $pid],
        $ordered
    );
}

/**
 * Execute a short best-effort cleanup command without recursing through release_run_command().
 */
function release_execute_cleanup_command(array $command): void
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, release_repo_root());
    if (!is_resource($process)) {
        return;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
}

function release_process_table(): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return '';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(['ps', '-eo', 'pid=,ppid='], $descriptors, $pipes, release_repo_root());
    if (!is_resource($process)) {
        return '';
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return is_string($output) ? $output : '';
}

/**
 * @param resource $process
 */
function release_cleanup_process_tree($process, int $pid): void
{
    $windows = DIRECTORY_SEPARATOR === '\\';
    $processTable = $windows ? '' : release_process_table();

    foreach (release_timeout_cleanup_commands($pid, $windows, $processTable) as $cleanupCommand) {
        release_execute_cleanup_command($cleanupCommand);
    }

    $status = proc_get_status($process);
    if (is_array($status) && ($status['running'] ?? false)) {
        @proc_terminate($process, 9);
    }
}

/**
 * @return array{content: string, receivedBytes: int, truncated: bool}
 */
function release_capture_file(string $path, int $limitBytes): array
{
    clearstatcache(true, $path);
    $receivedBytes = is_file($path) ? (int) filesize($path) : 0;
    $content = $receivedBytes > 0 ? file_get_contents($path, false, null, 0, $limitBytes) : '';

    return [
        'content' => is_string($content) ? $content : '',
        'receivedBytes' => $receivedBytes,
        'truncated' => $receivedBytes > $limitBytes,
    ];
}

/**
 * @param array<int, string> $command
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function release_run_command(array $command, ?string $cwd = null, array $options = []): array
{
    if ($command === []) {
        throw new InvalidArgumentException('Process command must not be empty.');
    }

    $settings = release_process_options($options);
    $displayCommand = implode(' ', array_map('escapeshellarg', $command));
    $processCommand = $command;

    if (DIRECTORY_SEPARATOR === '\\') {
        $windowsCommand = implode(' ', array_map(
            static function (string $argument): string {
                if ($argument === '') {
                    return '""';
                }

                if (!preg_match('/[\s"]/u', $argument)) {
                    return $argument;
                }

                return '"' . str_replace('"', '\"', $argument) . '"';
            },
            $command
        ));
        $processCommand = 'cmd.exe /d /s /c "' . $windowsCommand . '"';
    }

    $useCaptureFiles = DIRECTORY_SEPARATOR === '\\';
    $capturePaths = [];
    if ($useCaptureFiles) {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'oxy-release-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'oxy-release-stderr-');
        if (!is_string($stdoutPath) || !is_string($stderrPath)) {
            throw new RuntimeException('Failed to allocate process output capture files.');
        }
        $capturePaths = [$stdoutPath, $stderrPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];
    } else {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    $process = proc_open($processCommand, $descriptors, $pipes, $cwd ?? release_repo_root());
    if (!is_resource($process)) {
        foreach ($capturePaths as $capturePath) {
            @unlink($capturePath);
        }
        throw new RuntimeException('Failed to start process: ' . $displayCommand);
    }

    fclose($pipes[0]);
    if (!$useCaptureFiles) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    $stdout = release_new_output_capture();
    $stderr = release_new_output_capture();
    $startedAt = microtime(true);
    $timedOut = false;
    $observedExitCode = null;

    while (true) {
        if (!$useCaptureFiles) {
            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);
            if (is_string($stdoutChunk) && $stdoutChunk !== '') {
                release_capture_output($stdout, $stdoutChunk, $settings['outputLimitBytes']);
            }
            if (is_string($stderrChunk) && $stderrChunk !== '') {
                release_capture_output($stderr, $stderrChunk, $settings['outputLimitBytes']);
            }
        }

        $status = proc_get_status($process);
        if (!is_array($status) || !($status['running'] ?? false)) {
            if (is_array($status) && isset($status['exitcode']) && (int) $status['exitcode'] >= 0) {
                $observedExitCode = (int) $status['exitcode'];
            }
            break;
        }

        if ((microtime(true) - $startedAt) >= $settings['timeoutSeconds']) {
            $timedOut = true;
            release_cleanup_process_tree($process, (int) ($status['pid'] ?? 0));
            break;
        }

        usleep($settings['pollIntervalMicroseconds']);
    }

    if ($useCaptureFiles) {
        $stdout = release_capture_file($stdoutPath, $settings['outputLimitBytes']);
        $stderr = release_capture_file($stderrPath, $settings['outputLimitBytes']);
        foreach ($capturePaths as $capturePath) {
            @unlink($capturePath);
        }
    } else {
        foreach ([[1, &$stdout], [2, &$stderr]] as &$streamCapture) {
            $chunk = stream_get_contents($pipes[$streamCapture[0]]);
            if (is_string($chunk) && $chunk !== '') {
                release_capture_output($streamCapture[1], $chunk, $settings['outputLimitBytes']);
            }
        }
        unset($streamCapture);

        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    $closeExitCode = proc_close($process);
    $exitCode = $timedOut ? 124 : ($observedExitCode ?? $closeExitCode);

    return [
        'command' => $displayCommand,
        'exitCode' => $exitCode,
        'stdout' => $stdout['content'],
        'stderr' => $stderr['content'],
        'stdoutBytes' => $stdout['receivedBytes'],
        'stderrBytes' => $stderr['receivedBytes'],
        'stdoutTruncated' => $stdout['truncated'],
        'stderrTruncated' => $stderr['truncated'],
        'timedOut' => $timedOut,
        'timeoutSeconds' => $settings['timeoutSeconds'],
        'durationSeconds' => microtime(true) - $startedAt,
    ];
}

function release_require_success(array $result): void
{
    if (($result['exitCode'] ?? 1) === 0) {
        return;
    }

    $message = trim((string) (($result['stderr'] ?? '') !== '' ? $result['stderr'] : $result['stdout']));
    $usedStderr = ($result['stderr'] ?? '') !== '';
    $wasTruncated = $usedStderr
        ? (bool) ($result['stderrTruncated'] ?? false)
        : (bool) ($result['stdoutTruncated'] ?? false);
    if ($wasTruncated) {
        $message .= sprintf(
            "\n[output truncated at %d captured bytes]",
            strlen((string) ($usedStderr ? $result['stderr'] : $result['stdout']))
        );
    }

    if (($result['timedOut'] ?? false) === true) {
        throw new RuntimeException(sprintf(
            'Command timed out after %.3f seconds (%s): %s',
            (float) ($result['timeoutSeconds'] ?? 0),
            (string) ($result['command'] ?? 'unknown'),
            $message !== '' ? $message : 'no output captured'
        ));
    }

    throw new RuntimeException(sprintf(
        "Command failed (%s): %s",
        (string) ($result['command'] ?? 'unknown'),
        $message
    ));
}

function release_zip_entries(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Failed to open ZIP: ' . $zipPath);
    }

    $entries = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entries[] = $zip->getNameIndex($index);
    }
    $zip->close();

    return array_values(array_filter($entries, 'is_string'));
}
