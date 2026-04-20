<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/polyfills.php';

$inputFile = $argv[1] ?? '';

if (!is_string($inputFile) || trim($inputFile) === '' || !is_file($inputFile)) {
    fwrite(STDERR, "usage: php tests/live/benchmark-fixture.php <fixture-file>\n");
    exit(1);
}

$builder = new \OxyHtmlConverter\TreeBuilder();
$html = file_get_contents($inputFile);

if (!is_string($html)) {
    fwrite(STDERR, "failed to read fixture\n");
    exit(1);
}

$start = microtime(true);
$result = $builder->convert($html);
$durationMs = round((microtime(true) - $start) * 1000, 1);

$payload = [
    'fixture' => basename($inputFile),
    'convertTimeMs' => $durationMs,
    'success' => (bool) ($result['success'] ?? false),
    'elementCount' => (int) ($result['stats']['elements'] ?? 0),
    'warningCount' => is_array($result['stats']['warnings'] ?? null) ? count($result['stats']['warnings']) : 0,
];

fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
