<?php

declare(strict_types=1);

require __DIR__ . '/release_common.php';

try {
    $root = release_repo_root();
    $version = release_plugin_version();
    $headerVersion = release_plugin_header_version();
    $docNames = release_version_doc_names($version);

    if ($version !== $headerVersion) {
        throw new RuntimeException(sprintf(
            'Plugin version mismatch: constant=%s header=%s',
            $version,
            $headerVersion
        ));
    }

    $readme = release_read_file($root . '/README.md');
    if (!str_contains($readme, $docNames['readmeMarker'])) {
        throw new RuntimeException('README does not mention current release line marker ' . $docNames['readmeMarker']);
    }

    $releaseNotesPath = $root . '/docs/' . $docNames['releaseNotes'];
    if (!is_file($releaseNotesPath)) {
        throw new RuntimeException('Missing release notes file: ' . basename($releaseNotesPath));
    }

    $dodPath = $root . '/docs/' . $docNames['dod'];
    if (!is_file($dodPath)) {
        throw new RuntimeException('Missing DOD file: ' . basename($dodPath));
    }

    if (!is_file($root . '/package-lock.json')) {
        throw new RuntimeException('package-lock.json is required for npm ci.');
    }

    $trackedFiles = release_run_command(['git', 'ls-files'], $root);
    release_require_success($trackedFiles);

    $forbiddenPatterns = [
        '.phpunit.cache',
        '.phpstan',
        'artifacts',
        '.worktrees',
        '*.zip',
    ];

    $trackedArtifactFiles = [];
    foreach (preg_split('/\\R/', $trackedFiles['stdout']) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        foreach ($forbiddenPatterns as $pattern) {
            if (release_path_matches_pattern($line, $pattern)) {
                $trackedArtifactFiles[] = $line;
                break;
            }
        }
    }

    if ($trackedArtifactFiles !== []) {
        throw new RuntimeException(
            'Tracked release/cache artifacts detected: ' . implode(', ', $trackedArtifactFiles)
        );
    }

    $requiredDistignoreEntries = [
        '.phpunit.cache',
        '.phpstan',
        '.worktrees',
        'artifacts',
        'vendor',
        'node_modules',
        'tests',
        'docs',
        'scripts',
        '*.zip',
        'package-lock.json',
        'phpcs.xml.dist',
        'phpstan.neon.dist',
    ];

    $patterns = release_distignore_patterns();
    foreach ($requiredDistignoreEntries as $entry) {
        if (!in_array($entry, $patterns, true)) {
            throw new RuntimeException('.distignore is missing required entry: ' . $entry);
        }
    }

    echo json_encode([
        'ok' => true,
        'version' => $version,
        'releaseNotes' => basename($releaseNotesPath),
        'dod' => basename($dodPath),
        'checkedDistignoreEntries' => $requiredDistignoreEntries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
