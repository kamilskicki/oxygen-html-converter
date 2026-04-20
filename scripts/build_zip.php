<?php

declare(strict_types=1);

require __DIR__ . '/release_common.php';

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive extension is required to build release ZIPs.');
    }

    $root = release_repo_root();
    $version = release_plugin_version();
    $slug = 'oxygen-html-converter';
    $outputDir = $root . '/artifacts/release';
    $zipPath = sprintf('%s/%s-%s.zip', $outputDir, $slug, $version);
    $patterns = release_distignore_patterns();

    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputDir);
    }

    if (is_file($zipPath) && !unlink($zipPath)) {
        throw new RuntimeException('Failed to replace existing ZIP: ' . $zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Failed to create ZIP: ' . $zipPath);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $fileCount = 0;
    foreach ($iterator as $item) {
        $absolutePath = release_normalize_path($item->getPathname());
        $relativePath = ltrim(substr($absolutePath, strlen(release_normalize_path($root))), '/');
        if ($relativePath === '') {
            continue;
        }

        if (release_should_exclude($relativePath, $patterns)) {
            continue;
        }

        if ($item->isDir()) {
            continue;
        }

        $zipEntry = $slug . '/' . $relativePath;
        if (!$zip->addFile($item->getPathname(), $zipEntry)) {
            throw new RuntimeException('Failed to add file to ZIP: ' . $relativePath);
        }

        $fileCount++;
    }

    $zip->close();

    if ($fileCount === 0) {
        throw new RuntimeException('Release ZIP was created without files.');
    }

    echo json_encode([
        'ok' => true,
        'version' => $version,
        'zipPath' => $zipPath,
        'fileCount' => $fileCount,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
