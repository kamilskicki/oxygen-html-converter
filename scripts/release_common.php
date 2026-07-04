<?php

declare(strict_types=1);

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

function release_run_command(array $command, ?string $cwd = null): array
{
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

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($processCommand, $descriptors, $pipes, $cwd ?? release_repo_root());
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start process: ' . $displayCommand);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'command' => $displayCommand,
        'exitCode' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function release_require_success(array $result): void
{
    if (($result['exitCode'] ?? 1) === 0) {
        return;
    }

    $message = trim((string) (($result['stderr'] ?? '') !== '' ? $result['stderr'] : $result['stdout']));
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
