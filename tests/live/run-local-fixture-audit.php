<?php

declare(strict_types=1);

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use OxyHtmlConverter\TreeBuilder;

require_once __DIR__ . '/../bootstrap.php';

$fixtureDir = resolve_fixture_dir();
if ($fixtureDir === null) {
    echo json_encode([
        'status' => 'skipped',
        'reason' => 'Local fixture directory was not found.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$files = discover_fixture_files($fixtureDir);
if ($files === []) {
    fwrite(STDERR, "No local HTML fixtures found in {$fixtureDir}\n");
    exit(1);
}

$requestedMode = parse_requested_class_mode($argv);
$modes = $requestedMode === null ? ['native', 'windpress'] : [$requestedMode];
$previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;

$mapper = new TailwindPropertyMapper();
$tailwindDetector = new TailwindDetector();
$failures = [];
$summaries = [];

foreach ($modes as $mode) {
    $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $mode;
    $modeSummaries = [];

    foreach ($files as $file) {
        $html = file_get_contents($file);
        if (!is_string($html)) {
            $failures[] = ['mode' => $mode, 'file' => normalize_path($file), 'message' => 'Fixture could not be read.'];
            continue;
        }

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        if (empty($result['success'])) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => $result['error'] ?? 'Conversion failed.',
            ];
            continue;
        }

        $types = count_element_types($result);
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $fixtureFailures = array_merge(
            assert_property_roots($result['element'], $file, $mode),
            assert_code_element_budget($types, $file, $mode),
            assert_selector_payload_matches_mode($selectorPayload, $file, $mode, $tailwindDetector)
        );

        if ($mode === 'native') {
            $fixtureFailures = array_merge(
                $fixtureFailures,
                assert_no_mapped_tailwind_residuals($result['element'], $mapper, $file, $mode),
                assert_selector_refs_for_residual_classes($result['element'], $file, $mode)
            );
        } else {
            $fixtureFailures = array_merge(
                $fixtureFailures,
                assert_windpress_selector_refs_only_for_native_classes($result['element'], $file, $tailwindDetector, $mode)
            );
        }

        foreach ($fixtureFailures as $failure) {
            $failures[] = $failure;
        }

        $modeSummaries[] = [
            'file' => normalize_path($file),
            'elements' => (int) ($result['stats']['elements'] ?? 0),
            'htmlCode' => $types[ElementTypes::HTML_CODE] ?? 0,
            'cssCode' => $types[ElementTypes::CSS_CODE] ?? 0,
            'selectors' => is_array($selectorPayload['selectors'] ?? null) ? count($selectorPayload['selectors']) : 0,
            'hasFallbackCss' => trim((string) ($result['extractedCss'] ?? '')) !== '',
        ];
    }

    $summaries[$mode] = $modeSummaries;
}

if ($previousClassMode === null) {
    unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
} else {
    $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $previousClassMode;
}

$report = [
    'status' => $failures === [] ? 'passed' : 'failed',
    'fixtureDir' => normalize_path($fixtureDir),
    'fixtureCount' => count($files),
    'modes' => $modes,
    'fixtures' => $summaries,
    'failures' => $failures,
];

echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);

function resolve_fixture_dir(): ?string
{
    $candidates = [];
    $envDir = getenv('OXY_HTML_CONVERTER_LOCAL_FIXTURE_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        $candidates[] = $envDir;
    }

    $candidates[] = __DIR__ . '/../../../../fixtures/html';
    $candidates[] = __DIR__ . '/../../../fixtures/html';

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if (is_string($resolved) && is_dir($resolved)) {
            return $resolved;
        }
    }

    return null;
}

/**
 * @return array<int, string>
 */
function discover_fixture_files(string $fixtureDir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir));

    foreach ($iterator as $item) {
        if (!($item instanceof SplFileInfo) || !$item->isFile()) {
            continue;
        }

        if (strtolower($item->getExtension()) !== 'html') {
            continue;
        }

        $files[] = $item->getPathname();
    }

    sort($files);
    return $files;
}

/**
 * @param array<string, mixed> $result
 * @return array<string, int>
 */
function count_element_types(array $result): array
{
    $types = [];
    count_element_type_in_node($result['element'], $types);

    foreach (['cssElement', 'headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
        if (empty($result[$key])) {
            continue;
        }

        $items = isset($result[$key]['data']) ? [$result[$key]] : $result[$key];
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                count_element_type_in_node($item, $types);
            }
        }
    }

    return $types;
}

/**
 * @param array<string, mixed> $node
 * @param array<string, int> $types
 */
function count_element_type_in_node(array $node, array &$types): void
{
    $type = $node['data']['type'] ?? null;
    if (is_string($type)) {
        $types[$type] = ($types[$type] ?? 0) + 1;
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            count_element_type_in_node($child, $types);
        }
    }
}

/**
 * @param array<string, mixed> $node
 * @return array<int, array<string, string>>
 */
function assert_property_roots(array $node, string $file, string $mode): array
{
    $failures = [];
    $allowed = ['content' => true, 'design' => true, 'settings' => true, 'meta' => true];
    $properties = $node['data']['properties'] ?? [];

    if (is_array($properties)) {
        foreach (array_keys($properties) as $key) {
            if (!isset($allowed[(string) $key])) {
                $failures[] = [
                    'mode' => $mode,
                    'file' => normalize_path($file),
                    'message' => 'Unsupported top-level property root: ' . (string) $key,
                ];
            }
        }
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $failures = array_merge($failures, assert_property_roots($child, $file, $mode));
        }
    }

    return $failures;
}

/**
 * @param array<string, mixed> $node
 * @return array<int, array<string, string>>
 */
function assert_no_mapped_tailwind_residuals(array $node, TailwindPropertyMapper $mapper, string $file, string $mode): array
{
    $failures = [];
    $classes = $node['data']['properties']['settings']['advanced']['classes'] ?? [];

    if (is_array($classes)) {
        foreach ($classes as $className) {
            if (is_string($className) && $mapper->mapClass($className) !== []) {
                $failures[] = [
                    'mode' => $mode,
                    'file' => normalize_path($file),
                    'message' => 'Mapped Tailwind utility remained as residual class: ' . $className,
                ];
            }
        }
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $failures = array_merge($failures, assert_no_mapped_tailwind_residuals($child, $mapper, $file, $mode));
        }
    }

    return $failures;
}

/**
 * @param array<string, mixed> $node
 * @return array<int, array<string, string>>
 */
function assert_selector_refs_for_residual_classes(array $node, string $file, string $mode): array
{
    $failures = [];
    $classes = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
    $selectorRefs = $node['data']['properties']['meta']['classes'] ?? [];

    if (is_array($classes)) {
        $nativeClassCount = count(array_filter(
            $classes,
            static fn($className): bool => is_string($className)
                && preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $className) === 1
                && preg_match('/^ohc-native-\d+$/', $className) !== 1
        ));

        if ($nativeClassCount > 0 && (!is_array($selectorRefs) || count($selectorRefs) < $nativeClassCount)) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => 'Residual native classes were not mirrored into data.properties.meta.classes.',
            ];
        }
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $failures = array_merge($failures, assert_selector_refs_for_residual_classes($child, $file, $mode));
        }
    }

    return $failures;
}

/**
 * @param array<string, mixed> $selectorPayload
 * @return array<int, array<string, string>>
 */
function assert_selector_payload_matches_mode(
    array $selectorPayload,
    string $file,
    string $mode,
    TailwindDetector $tailwindDetector
): array {
    $failures = [];
    $selectors = $selectorPayload['selectors'] ?? [];
    if (!is_array($selectors)) {
        return $failures;
    }

    foreach ($selectors as $selector) {
        if (!is_array($selector)) {
            continue;
        }

        $name = is_string($selector['name'] ?? null) ? trim($selector['name']) : '';
        if ($name === '') {
            continue;
        }

        if ($mode === 'windpress' && $tailwindDetector->isTailwindClass($name)) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => 'WindPress selector payload imported a Tailwind utility selector: ' . $name,
            ];
        }
    }

    return $failures;
}

/**
 * @param array<string, mixed> $node
 * @return array<int, array<string, string>>
 */
function assert_windpress_selector_refs_only_for_native_classes(
    array $node,
    string $file,
    TailwindDetector $tailwindDetector,
    string $mode
): array {
    $failures = [];
    $classes = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
    $selectorRefs = $node['data']['properties']['meta']['classes'] ?? [];

    if (is_array($classes) && is_array($selectorRefs)) {
        $nativeResidualClassCount = count(array_filter(
            $classes,
            static function ($className) use ($tailwindDetector): bool {
                return is_string($className)
                    && preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $className) === 1
                    && preg_match('/^ohc-native-\d+$/', $className) !== 1
                    && !$tailwindDetector->isTailwindClass($className);
            }
        ));

        if (count($selectorRefs) > $nativeResidualClassCount) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => 'WindPress mirrored more selector refs than native residual classes.',
            ];
        }
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $failures = array_merge(
                $failures,
                assert_windpress_selector_refs_only_for_native_classes($child, $file, $tailwindDetector, $mode)
            );
        }
    }

    return $failures;
}

/**
 * @param array<string, int> $types
 * @return array<int, array<string, string>>
 */
function assert_code_element_budget(array $types, string $file, string $mode): array
{
    $normalized = str_replace('\\', '/', $file);
    if (stripos($normalized, '/Maximus/') === false) {
        return [];
    }

    $failures = [];
    $htmlCode = $types[ElementTypes::HTML_CODE] ?? 0;
    $cssCode = $types[ElementTypes::CSS_CODE] ?? 0;

    if ($htmlCode > 8) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => "Maximus HtmlCode budget exceeded: {$htmlCode} > 8.",
        ];
    }

    if ($cssCode > 2) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => "Maximus CssCode budget exceeded: {$cssCode} > 2.",
        ];
    }

    return $failures;
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function parse_requested_class_mode(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--class-mode=') !== 0) {
            continue;
        }

        $mode = trim(substr($arg, strlen('--class-mode=')));
        if (in_array($mode, ['native', 'windpress'], true)) {
            return $mode;
        }
    }

    return null;
}
