<?php

declare(strict_types=1);

require __DIR__ . '/release_common.php';

try {
    $root = release_repo_root();
    $runLiveGate = in_array('--with-live', $argv, true)
        || in_array(strtolower((string) getenv('OXY_HTML_CONVERTER_RELEASE_LIVE')), ['1', 'true', 'yes', 'on'], true);

    $checks = [
        ['php', 'scripts/check_release_hygiene.php'],
        ['npm', 'ci', '--ignore-scripts', '--dry-run'],
        ['composer', 'install', '--no-interaction', '--prefer-dist', '--dry-run'],
        ['npm', 'run', 'check'],
    ];

    if ($runLiveGate) {
        $checks[] = ['npm', 'run', 'test:live'];
    }

    $checks[] = ['php', 'scripts/build_zip.php'];

    foreach ($checks as $command) {
        $result = release_run_command($command, $root);
        release_require_success($result);
    }

    $zipPath = sprintf('%s/artifacts/release/oxygen-html-converter-%s.zip', $root, release_plugin_version());
    if (!is_file($zipPath)) {
        throw new RuntimeException('Expected release ZIP was not created: ' . $zipPath);
    }

    $entries = release_zip_entries($zipPath);
    $requiredEntry = 'oxygen-html-converter/oxygen-html-converter.php';
    if (!in_array($requiredEntry, $entries, true)) {
        throw new RuntimeException('Release ZIP is missing required bootstrap entry: ' . $requiredEntry);
    }

    $forbiddenEntries = [
        'oxygen-html-converter/.gitattributes',
        'oxygen-html-converter/release-audit.md',
        'oxygen-html-converter/tests/',
        'oxygen-html-converter/docs/',
        'oxygen-html-converter/scripts/',
        'oxygen-html-converter/node_modules/',
        'oxygen-html-converter/vendor/',
        'oxygen-html-converter/.phpunit.cache/',
        'oxygen-html-converter/.phpstan/',
        'oxygen-html-converter/.worktrees/',
        'oxygen-html-converter/.screens/',
    ];

    foreach ($entries as $entry) {
        foreach ($forbiddenEntries as $forbiddenPrefix) {
            if (str_starts_with($entry, $forbiddenPrefix)) {
                throw new RuntimeException('Release ZIP contains forbidden entry: ' . $entry);
            }
        }

        if (preg_match('#^oxygen-html-converter/\.tmp(?:-|/)#', $entry) === 1) {
            throw new RuntimeException('Release ZIP contains forbidden temp entry: ' . $entry);
        }
    }

    echo json_encode([
        'ok' => true,
        'zipPath' => $zipPath,
        'entryCount' => count($entries),
        'requiredEntry' => $requiredEntry,
        'liveGate' => $runLiveGate ? 'run' : 'skipped; pass --with-live or set OXY_HTML_CONVERTER_RELEASE_LIVE=1',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
