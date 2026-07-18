<?php

declare(strict_types=1);

use OxyHtmlConverter\TreeBuilder;

require_once __DIR__ . '/../tests/bootstrap.php';

try {
    $fixtureDir = resolve_measure_fixture_dir();
    if ($fixtureDir === null) {
        throw new RuntimeException('Local fixture directory was not found.');
    }

    $fixtures = discover_measure_fixtures($fixtureDir);
    if ($fixtures === []) {
        throw new RuntimeException('No HTML fixtures were found in: ' . $fixtureDir);
    }

    $rows = [];
    $totals = [
        'fixtures' => count($fixtures),
        'fallbackCssBytes' => 0,
        'pageCssBytes' => 0,
        'globalCssBytes' => 0,
        'pageScopedCssBytes' => 0,
    ];

    foreach ($fixtures as $fixture) {
        $html = file_get_contents($fixture);
        if (!is_string($html)) {
            throw new RuntimeException('Failed to read fixture: ' . $fixture);
        }

        $builder = new TreeBuilder();
        $result = $builder->convert($html);
        if (empty($result['success'])) {
            throw new RuntimeException('Conversion failed for fixture: ' . $fixture);
        }

        $css = fallback_css_channels($result);
        $row = [
            'fixture' => normalize_measure_path(substr($fixture, strlen($fixtureDir) + 1)),
            'fallbackCssBytes' => bytes_for_unique_css($css),
            'pageCssBytes' => strlen($css['pageCss']),
            'globalCssBytes' => strlen($css['globalCss']),
            'pageScopedCssBytes' => strlen($css['pageScopedCss']),
            'fallbackRoutes' => count_fallback_css_routes($result),
            'routeOwners' => implode(', ', fallback_css_route_owners($result)),
        ];

        foreach (['fallbackCssBytes', 'pageCssBytes', 'globalCssBytes', 'pageScopedCssBytes'] as $key) {
            $totals[$key] += $row[$key];
        }

        $rows[] = $row;
    }

    $markdown = render_fallback_css_report($fixtureDir, $rows, $totals);
    $outputPath = parse_output_path($argv);
    if ($outputPath !== null) {
        $outputAbsolute = make_absolute_output_path($outputPath);
        $outputDirectory = dirname($outputAbsolute);
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Failed to create output directory: ' . $outputDirectory);
        }

        if (file_put_contents($outputAbsolute, $markdown) === false) {
            throw new RuntimeException('Failed to write fallback CSS report: ' . $outputAbsolute);
        }
    }

    echo $markdown;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

function resolve_measure_fixture_dir(): ?string
{
    $candidates = [];
    $envDir = getenv('OXY_HTML_CONVERTER_LOCAL_FIXTURE_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        $candidates[] = $envDir;
    }

    $candidates[] = __DIR__ . '/../fixtures/html';

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if (is_string($resolved) && is_dir($resolved)) {
            return $resolved;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function discover_measure_fixtures(string $fixtureDir): array
{
    $indexed = discover_indexed_measure_fixtures($fixtureDir);
    if ($indexed !== []) {
        return $indexed;
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item instanceof SplFileInfo && $item->isFile() && strtolower($item->getExtension()) === 'html') {
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return $files;
}

/**
 * @return list<string>
 */
function discover_indexed_measure_fixtures(string $fixtureDir): array
{
    $manifestPath = $fixtureDir . DIRECTORY_SEPARATOR . 'fixture-index.json';
    $json = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest) || !is_array($manifest['stableHtmlFixtures'] ?? null)) {
        return [];
    }

    $files = [];
    foreach ($manifest['stableHtmlFixtures'] as $entry) {
        if (!is_array($entry) || !is_scalar($entry['fixture'] ?? null)) {
            continue;
        }

        $relative = trim(str_replace('\\', '/', (string) $entry['fixture']), '/');
        $absolute = $fixtureDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            $files[] = $absolute;
        }
    }

    sort($files);
    return $files;
}

/**
 * @param array<string, mixed> $result
 * @return array{pageCss:string, globalCss:string, pageScopedCss:string}
 */
function fallback_css_channels(array $result): array
{
    $routing = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];

    return [
        'pageCss' => (string) ($routing['pageCss'] ?? ($result['extractedCss'] ?? '')),
        'globalCss' => (string) ($routing['globalCss'] ?? ($result['globalCss'] ?? '')),
        'pageScopedCss' => (string) ($routing['pageScopedCss'] ?? ($result['pageScopedCss'] ?? '')),
    ];
}

/**
 * @param array{pageCss:string, globalCss:string, pageScopedCss:string} $css
 */
function bytes_for_unique_css(array $css): int
{
    $unique = [];
    foreach ($css as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $unique[$chunk] = strlen($chunk);
        }
    }

    return array_sum($unique);
}

/**
 * @param array<string, mixed> $result
 */
function count_fallback_css_routes(array $result): int
{
    return count(fallback_css_routes($result));
}

/**
 * @param array<string, mixed> $result
 * @return list<string>
 */
function fallback_css_route_owners(array $result): array
{
    $owners = [];
    foreach (fallback_css_routes($result) as $route) {
        $owner = trim((string) ($route['owner'] ?? ''));
        $destination = trim((string) ($route['destination'] ?? ''));
        $owners[] = $owner !== '' && $destination !== '' ? "{$owner}:{$destination}" : 'unknown';
    }

    $owners = array_values(array_unique($owners));
    sort($owners);

    return $owners;
}

/**
 * @param array<string, mixed> $result
 * @return list<array<string, mixed>>
 */
function fallback_css_routes(array $result): array
{
    $routing = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
    $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
    $fallbackRoutes = [];

    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        $type = (string) ($route['type'] ?? '');
        $destination = (string) ($route['destination'] ?? '');
        if (str_contains($type, 'fallback') || in_array($destination, ['page_css', 'page_scoped_css'], true)) {
            $fallbackRoutes[] = $route;
        }
    }

    return $fallbackRoutes;
}

/**
 * @param list<array{fixture:string, fallbackCssBytes:int, pageCssBytes:int, globalCssBytes:int, pageScopedCssBytes:int, fallbackRoutes:int, routeOwners:string}> $rows
 * @param array{fixtures:int, fallbackCssBytes:int, pageCssBytes:int, globalCssBytes:int, pageScopedCssBytes:int} $totals
 */
function render_fallback_css_report(string $fixtureDir, array $rows, array $totals): string
{
    $lines = [
        '# Fallback CSS Baseline',
        '',
        '- Date: 2026-07-07',
        '- Command: `php scripts/measure-fallback-css.php --output=artifacts/fallback-css-baseline.md`',
        '- Fixture directory: `' . normalize_measure_path($fixtureDir) . '`',
        '- Verdict: fallback CSS is reported per conversion and routed to page-owned/page-scoped channels where emitted; live route isolation still requires the artifact/live smoke gate.',
        '',
        '## Totals',
        '',
        '| Fixtures | Fallback CSS bytes | Page CSS bytes | Global CSS bytes | Page-scoped CSS bytes |',
        '| ---: | ---: | ---: | ---: | ---: |',
        sprintf(
            '| %d | %d | %d | %d | %d |',
            $totals['fixtures'],
            $totals['fallbackCssBytes'],
            $totals['pageCssBytes'],
            $totals['globalCssBytes'],
            $totals['pageScopedCssBytes']
        ),
        '',
        '## Fixtures',
        '',
        '| Fixture | Fallback CSS bytes | Page CSS bytes | Global CSS bytes | Page-scoped CSS bytes | Fallback routes | Route owners |',
        '| --- | ---: | ---: | ---: | ---: | ---: | --- |',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| `%s` | %d | %d | %d | %d | %d | %s |',
            str_replace('|', '\\|', $row['fixture']),
            $row['fallbackCssBytes'],
            $row['pageCssBytes'],
            $row['globalCssBytes'],
            $row['pageScopedCssBytes'],
            $row['fallbackRoutes'],
            $row['routeOwners'] !== '' ? '`' . str_replace('|', '\\|', $row['routeOwners']) . '`' : ''
        );
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function parse_output_path(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--output=')) {
            $output = trim(substr($arg, strlen('--output=')));
            return $output !== '' ? $output : null;
        }
    }

    return null;
}

function make_absolute_output_path(string $path): string
{
    if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }

    return dirname(__DIR__) . '/' . str_replace('\\', '/', $path);
}

function normalize_measure_path(string $path): string
{
    return str_replace('\\', '/', $path);
}
