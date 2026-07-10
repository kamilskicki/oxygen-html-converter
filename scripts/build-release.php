<?php

declare(strict_types=1);

require __DIR__ . '/release_common.php';

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive extension is required to build release ZIPs.');
    }

    $root = release_repo_root();
    $allowlistPath = $root . '/scripts/release-allowlist.json';
    $allowlist = read_release_allowlist($allowlistPath);
    $slug = $allowlist['pluginSlug'];
    $version = release_plugin_version();
    $outputDir = $root . '/artifacts/release';
    $stagingRoot = $outputDir . '/staging-' . date('Ymd-His');
    $packageDir = $stagingRoot . '/' . $slug;
    $zipPath = sprintf('%s/%s-%s.zip', $outputDir, $slug, $version);

    ensure_directory($packageDir);

    try {
        copy_allowlisted_runtime($root, $packageDir, $allowlist);

        $relativeFiles = collect_package_files($packageDir);
        verify_package_contents($relativeFiles, $allowlist);
        write_release_zip($zipPath, $packageDir, $slug, $relativeFiles);

        $sha256 = hash_file('sha256', $zipPath);
        if (!is_string($sha256)) {
            throw new RuntimeException('Failed to calculate ZIP SHA256: ' . $zipPath);
        }

        echo json_encode([
            'ok' => true,
            'version' => $version,
            'zipPath' => release_normalize_path($zipPath),
            'sha256' => $sha256,
            'entryCount' => count($relativeFiles),
            'stagingDir' => release_normalize_path($stagingRoot),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        remove_directory($stagingRoot);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return array{pluginSlug:string, files:list<string>, directories:list<string>}
 */
function read_release_allowlist(string $path): array
{
    $json = file_get_contents($path);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Release allowlist is not valid JSON: ' . $path);
    }

    $slug = trim((string) ($data['pluginSlug'] ?? ''));
    $files = normalize_allowlist_entries($data['files'] ?? null);
    $directories = normalize_allowlist_entries($data['directories'] ?? null);

    if ($slug === '' || $files === [] || $directories === []) {
        throw new RuntimeException('Release allowlist must define pluginSlug, files, and directories.');
    }

    return [
        'pluginSlug' => $slug,
        'files' => $files,
        'directories' => $directories,
    ];
}

/**
 * @return list<string>
 */
function normalize_allowlist_entries(mixed $entries): array
{
    if (!is_array($entries)) {
        return [];
    }

    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_scalar($entry)) {
            continue;
        }

        $path = trim(release_normalize_path((string) $entry), '/');
        if ($path !== '' && !str_contains($path, '..')) {
            $normalized[$path] = $path;
        }
    }

    $values = array_values($normalized);
    sort($values);

    return $values;
}

/**
 * @param array{pluginSlug:string, files:list<string>, directories:list<string>} $allowlist
 */
function copy_allowlisted_runtime(string $root, string $packageDir, array $allowlist): void
{
    foreach ($allowlist['files'] as $file) {
        $source = $root . '/' . $file;
        if (!is_file($source)) {
            throw new RuntimeException('Allowlisted release file is missing: ' . $file);
        }

        copy_file($source, $packageDir . '/' . $file);
    }

    foreach ($allowlist['directories'] as $directory) {
        $source = $root . '/' . $directory;
        if (!is_dir($source)) {
            throw new RuntimeException('Allowlisted release directory is missing: ' . $directory);
        }

        copy_directory($source, $packageDir . '/' . $directory);
    }
}

function ensure_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function copy_file(string $source, string $destination): void
{
    ensure_directory(dirname($destination));
    if (!copy($source, $destination)) {
        throw new RuntimeException('Failed to copy file: ' . $source);
    }
}

function copy_directory(string $source, string $destination): void
{
    ensure_directory($destination);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!($item instanceof SplFileInfo)) {
            continue;
        }

        $relativePath = release_normalize_path(substr($item->getPathname(), strlen($source) + 1));
        $target = $destination . '/' . $relativePath;

        if ($item->isDir()) {
            ensure_directory($target);
            continue;
        }

        if ($item->isFile()) {
            copy_file($item->getPathname(), $target);
        }
    }
}

/**
 * @return list<string>
 */
function collect_package_files(string $packageDir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $item) {
        if (!($item instanceof SplFileInfo) || !$item->isFile()) {
            continue;
        }

        $files[] = release_normalize_path(substr($item->getPathname(), strlen($packageDir) + 1));
    }

    sort($files);
    return $files;
}

/**
 * @param list<string> $relativeFiles
 * @param array{pluginSlug:string, files:list<string>, directories:list<string>} $allowlist
 */
function verify_package_contents(array $relativeFiles, array $allowlist): void
{
    $allowedFiles = array_flip($allowlist['files']);
    $allowedDirectories = $allowlist['directories'];
    $forbiddenPatterns = [
        '#(^|/)node_modules(/|$)#',
        '#(^|/)tests(/|$)#',
        '#(^|/)scripts(/|$)#',
        '#(^|/)docs(/|$)#',
        '#(^|/)scaffolds(/|$)#',
        '#(^|/)skill(-docs)?(/|$)#',
        '#(^|/)\.git(/|$)#',
        '#(^|/)\.github(/|$)#',
        '#(^|/)\.tmp(?:-|/|$)#',
        '#(^|/)phpunit\.xml$#',
        '#(^|/)phpstan\.neon\.dist$#',
        '#(^|/)phpcs\.xml\.dist$#',
        '#(^|/)package(-lock)?\.json$#',
    ];

    foreach ($allowlist['files'] as $file) {
        if (!in_array($file, $relativeFiles, true)) {
            throw new RuntimeException('Release package is missing allowlisted file: ' . $file);
        }
    }

    foreach ($relativeFiles as $file) {
        foreach ($forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                throw new RuntimeException('Release package contains forbidden file: ' . $file);
            }
        }

        if (isset($allowedFiles[$file])) {
            continue;
        }

        $isAllowed = false;
        foreach ($allowedDirectories as $directory) {
            if (str_starts_with($file, $directory . '/')) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new RuntimeException('Release package contains file outside allowlist: ' . $file);
        }
    }
}

/**
 * @param list<string> $relativeFiles
 */
function write_release_zip(string $zipPath, string $packageDir, string $slug, array $relativeFiles): void
{
    ensure_directory(dirname($zipPath));
    if (is_file($zipPath) && !unlink($zipPath)) {
        throw new RuntimeException('Failed to replace existing ZIP: ' . $zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Failed to create ZIP: ' . $zipPath);
    }

    foreach ($relativeFiles as $relativeFile) {
        $absolutePath = $packageDir . '/' . $relativeFile;
        $entryName = $slug . '/' . $relativeFile;
        if (!$zip->addFile($absolutePath, $entryName)) {
            throw new RuntimeException('Failed to add file to ZIP: ' . $relativeFile);
        }

        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($entryName, 946684800);
        }
    }

    $zip->close();
}

function remove_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!($item instanceof SplFileInfo)) {
            continue;
        }

        if ($item->isDir()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Failed to remove staging directory: ' . $item->getPathname());
            }
            continue;
        }

        if ($item->isFile() && !unlink($item->getPathname())) {
            throw new RuntimeException('Failed to remove staging file: ' . $item->getPathname());
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Failed to remove staging directory: ' . $path);
    }
}
