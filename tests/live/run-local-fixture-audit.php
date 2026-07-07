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

$fixtureIndex = load_fixture_index($fixtureDir);
$files = $fixtureIndex['stableHtmlPaths'];
if ($files === []) {
    fwrite(STDERR, "No local HTML fixtures found in {$fixtureDir}\n");
    exit(1);
}

$nativeNoCodeContract = load_native_no_code_contract($fixtureDir);
$requestedMode = parse_requested_class_mode($argv);
$modes = $requestedMode === null ? ['native'] : [$requestedMode];
$previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;

if ($requestedMode === 'windpress') {
    add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
        $flags['windpress_integration'] = true;
        $flags['windpress_class_mode'] = true;
        return $flags;
    });
}

$mapper = new TailwindPropertyMapper();
$tailwindDetector = new TailwindDetector();
$failures = array_merge($nativeNoCodeContract['failures'], $fixtureIndex['failures']);
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

        $visibleTypes = count_visible_element_types($result);
        $surfaceTypes = count_conversion_surface_element_types($result);
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $fixtureFailures = array_merge(
            assert_property_roots($result['element'], $file, $mode),
            assert_code_element_budget($visibleTypes, $file, $mode),
            assert_native_no_code_contract(
                $nativeNoCodeContract['fixturesByPath'][normalize_path($file)] ?? null,
                $result,
                $visibleTypes,
                $file,
                $mode
            ),
            assert_fixture_index_contract(
                $fixtureIndex['fixturesByPath'][normalize_path($file)] ?? null,
                $result,
                $visibleTypes,
                $file,
                $mode
            ),
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
            'htmlCode' => $visibleTypes[ElementTypes::HTML_CODE] ?? 0,
            'cssCode' => $visibleTypes[ElementTypes::CSS_CODE] ?? 0,
            'javascriptCode' => $visibleTypes[ElementTypes::JAVASCRIPT_CODE] ?? 0,
            'surfaceHtmlCode' => $surfaceTypes[ElementTypes::HTML_CODE] ?? 0,
            'surfaceCssCode' => $surfaceTypes[ElementTypes::CSS_CODE] ?? 0,
            'surfaceJavaScriptCode' => $surfaceTypes[ElementTypes::JAVASCRIPT_CODE] ?? 0,
            'selectors' => is_array($selectorPayload['selectors'] ?? null) ? count($selectorPayload['selectors']) : 0,
            'fallbackCount' => count_fallback_routes($result),
            'hasFallbackCss' => has_owned_fallback_css($result),
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
    'fixtureIndex' => [
        'manifestPath' => $fixtureIndex['manifestPath'],
        'stableHtmlCount' => count($fixtureIndex['fixturesByPath']),
        'supportingFixtureCount' => $fixtureIndex['supportingFixtureCount'],
        'requiredGapCount' => $fixtureIndex['requiredGapCount'],
    ],
    'nativeNoCodeManifest' => $nativeNoCodeContract['manifestPath'],
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
function count_visible_element_types(array $result): array
{
    $types = [];
    count_element_type_in_node($result['element'], $types);

    foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
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
 * @param array<string, mixed> $result
 * @return array<string, int>
 */
function count_conversion_surface_element_types(array $result): array
{
    $types = count_visible_element_types($result);

    if (!empty($result['cssElement']) && is_array($result['cssElement'])) {
        count_element_type_in_node($result['cssElement'], $types);
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
 * @return array{
 *     manifestPath:?string,
 *     stableHtmlPaths:list<string>,
 *     fixturesByPath:array<string, array<string, mixed>>,
 *     supportingFixtureCount:int,
 *     requiredGapCount:int,
 *     failures:list<array<string, string>>
 * }
 */
function load_fixture_index(string $fixtureDir): array
{
    $manifestPath = $fixtureDir . DIRECTORY_SEPARATOR . 'fixture-index.json';
    $fallbackFiles = discover_fixture_files($fixtureDir);

    if (!is_file($manifestPath)) {
        return [
            'manifestPath' => null,
            'stableHtmlPaths' => $fallbackFiles,
            'fixturesByPath' => [],
            'supportingFixtureCount' => 0,
            'requiredGapCount' => 0,
            'failures' => [[
                'mode' => 'native',
                'file' => normalize_path($fixtureDir),
                'message' => 'M8 fixture index is missing.',
            ]],
        ];
    }

    $json = file_get_contents($manifestPath);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest) || !is_array($manifest['stableHtmlFixtures'] ?? null)) {
        return [
            'manifestPath' => normalize_path($manifestPath),
            'stableHtmlPaths' => $fallbackFiles,
            'fixturesByPath' => [],
            'supportingFixtureCount' => 0,
            'requiredGapCount' => 0,
            'failures' => [[
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index is invalid.',
            ]],
        ];
    }

    $devRoot = dirname(dirname($fixtureDir));
    $fixturesByPath = [];
    $stableHtmlPaths = [];
    $indexedRelative = [];
    $coveredGapIds = [];
    $failures = [];

    foreach ($manifest['stableHtmlFixtures'] as $entry) {
        if (!is_array($entry)) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index has a non-object stableHtmlFixtures entry.',
            ];
            continue;
        }

        $relativeFile = normalize_manifest_fixture_file($entry['fixture'] ?? '');
        if ($relativeFile === '') {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index stable entry is missing fixture.',
            ];
            continue;
        }

        $entryFailures = validate_fixture_index_entry_shape($entry, $relativeFile, 'stableHtmlFixtures');
        foreach ($entryFailures as $failure) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => $failure,
            ];
        }

        foreach (($entry['gapIds'] ?? []) as $gapId) {
            $coveredGapIds[(string) $gapId] = true;
        }

        $absolutePath = $fixtureDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (!is_file($absolutePath)) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($absolutePath),
                'message' => 'M8 indexed stable HTML fixture file is missing.',
            ];
            continue;
        }

        $normalizedPath = normalize_path($absolutePath);
        $indexedRelative[$relativeFile] = true;
        $fixturesByPath[$normalizedPath] = $entry;
        $stableHtmlPaths[] = $absolutePath;
    }

    sort($stableHtmlPaths);

    foreach ($fallbackFiles as $file) {
        $relativeFile = str_replace('\\', '/', substr($file, strlen($fixtureDir) + 1));
        if (!isset($indexedRelative[$relativeFile])) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($file),
                'message' => 'HTML fixture is not listed in M8 fixture index.',
            ];
        }
    }

    $supportingFixtures = is_array($manifest['supportingFixtures'] ?? null) ? $manifest['supportingFixtures'] : [];
    foreach ($supportingFixtures as $entry) {
        if (!is_array($entry)) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index has a non-object supportingFixtures entry.',
            ];
            continue;
        }

        $relativeFile = normalize_manifest_fixture_file($entry['fixture'] ?? '');
        if ($relativeFile === '') {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index supporting entry is missing fixture.',
            ];
            continue;
        }

        $entryFailures = validate_fixture_index_entry_shape($entry, $relativeFile, 'supportingFixtures');
        foreach ($entryFailures as $failure) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => $failure,
            ];
        }

        foreach (($entry['gapIds'] ?? []) as $gapId) {
            $coveredGapIds[(string) $gapId] = true;
        }

        $absolutePath = $devRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (!is_file($absolutePath)) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($absolutePath),
                'message' => 'M8 indexed supporting fixture file is missing.',
            ];
        }
    }

    $requiredGapIds = is_array($manifest['requiredGapIds'] ?? null) ? $manifest['requiredGapIds'] : [];
    foreach ($requiredGapIds as $gapId) {
        if (!isset($coveredGapIds[(string) $gapId])) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'M8 fixture index coverage is missing: ' . (string) $gapId,
            ];
        }
    }

    return [
        'manifestPath' => normalize_path($manifestPath),
        'stableHtmlPaths' => $stableHtmlPaths,
        'fixturesByPath' => $fixturesByPath,
        'supportingFixtureCount' => count($supportingFixtures),
        'requiredGapCount' => count($requiredGapIds),
        'failures' => $failures,
    ];
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function validate_fixture_index_entry_shape(array $entry, string $fixture, string $section): array
{
    $failures = [];

    if (!is_array($entry['gapIds'] ?? null) || $entry['gapIds'] === []) {
        $failures[] = "M8 {$section} entry {$fixture} is missing gapIds.";
    }

    $expected = is_array($entry['expected'] ?? null) ? $entry['expected'] : [];
    $codeBlocks = is_array($expected['codeBlocks'] ?? null) ? $expected['codeBlocks'] : [];
    foreach (['total', 'html', 'css', 'javascript'] as $key) {
        if (!array_key_exists($key, $codeBlocks) || !is_numeric($codeBlocks[$key])) {
            $failures[] = "M8 {$section} entry {$fixture} is missing expected.codeBlocks.{$key}.";
        }
    }

    foreach (['fallbackCount', 'unsupportedCount'] as $key) {
        if (!array_key_exists($key, $expected) || !is_numeric($expected[$key])) {
            $failures[] = "M8 {$section} entry {$fixture} is missing expected.{$key}.";
        }
    }

    $liveSmoke = is_array($entry['liveSmoke'] ?? null) ? $entry['liveSmoke'] : [];
    if (!array_key_exists('required', $liveSmoke) || !is_bool($liveSmoke['required'])) {
        $failures[] = "M8 {$section} entry {$fixture} is missing liveSmoke.required.";
    }
    if (!is_string($liveSmoke['status'] ?? null) || trim((string) $liveSmoke['status']) === '') {
        $failures[] = "M8 {$section} entry {$fixture} is missing liveSmoke.status.";
    }

    return $failures;
}

/**
 * @return array{manifestPath:?string, fixturesByPath:array<string, array<string, mixed>>, failures:list<array<string, string>>}
 */
function load_native_no_code_contract(string $fixtureDir): array
{
    $manifestCandidates = [
        $fixtureDir . DIRECTORY_SEPARATOR . 'native-no-code' . DIRECTORY_SEPARATOR . 'manifest.json',
        $fixtureDir . DIRECTORY_SEPARATOR . 'manifest.json',
    ];
    $manifestPath = null;

    foreach ($manifestCandidates as $candidate) {
        if (is_file($candidate)) {
            $manifestPath = $candidate;
            break;
        }
    }

    if ($manifestPath === null) {
        return [
            'manifestPath' => null,
            'fixturesByPath' => [],
            'failures' => [[
                'mode' => 'native',
                'file' => normalize_path($fixtureDir),
                'message' => 'Native no-code fixture manifest is missing.',
            ]],
        ];
    }

    $json = file_get_contents($manifestPath);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest) || !is_array($manifest['fixtures'] ?? null)) {
        return [
            'manifestPath' => normalize_path($manifestPath),
            'fixturesByPath' => [],
            'failures' => [[
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'Native no-code fixture manifest is invalid.',
            ]],
        ];
    }

    $manifestDir = dirname($manifestPath);
    $fixturesByPath = [];
    $coverage = [];
    $failures = [];

    foreach ($manifest['fixtures'] as $fixture) {
        if (!is_array($fixture)) {
            continue;
        }

        $relativeFile = normalize_manifest_fixture_file($fixture['file'] ?? '');
        if ($relativeFile === '') {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'Native no-code fixture entry is missing file.',
            ];
            continue;
        }

        $absolutePath = $manifestDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (!is_file($absolutePath)) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($absolutePath),
                'message' => 'Native no-code fixture file is missing.',
            ];
            continue;
        }

        foreach (($fixture['coverage'] ?? []) as $coverageItem) {
            $coverage[(string) $coverageItem] = true;
        }

        $fixturesByPath[normalize_path($absolutePath)] = $fixture;
    }

    $requiredCoverage = is_array($manifest['requiredCoverage'] ?? null) ? $manifest['requiredCoverage'] : [];
    foreach ($requiredCoverage as $required) {
        if (!isset($coverage[(string) $required])) {
            $failures[] = [
                'mode' => 'native',
                'file' => normalize_path($manifestPath),
                'message' => 'Native no-code fixture coverage is missing: ' . (string) $required,
            ];
        }
    }

    return [
        'manifestPath' => normalize_path($manifestPath),
        'fixturesByPath' => $fixturesByPath,
        'failures' => $failures,
    ];
}

function normalize_manifest_fixture_file(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim(str_replace('\\', '/', (string) $value), '/');
}

/**
 * @param array<string, mixed>|null $fixture
 * @param array<string, mixed> $result
 * @param array<string, int> $visibleTypes
 * @return array<int, array<string, string>>
 */
function assert_native_no_code_contract(?array $fixture, array $result, array $visibleTypes, string $file, string $mode): array
{
    if ($fixture === null) {
        return [];
    }

    $modes = is_array($fixture['modes'] ?? null) ? array_map('strval', $fixture['modes']) : ['native'];
    if (!in_array($mode, $modes, true)) {
        return [];
    }

    $failures = [];
    $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
    $expectedCodeBlocks = is_array($expected['visibleCodeBlocks'] ?? null) ? $expected['visibleCodeBlocks'] : [];
    $actualCodeBlocks = code_block_counts($visibleTypes);

    foreach (['total', 'html', 'css', 'javascript'] as $key) {
        if (!array_key_exists($key, $expectedCodeBlocks)) {
            continue;
        }

        if ((int) $expectedCodeBlocks[$key] !== $actualCodeBlocks[$key]) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => "Native no-code visible {$key} code block count mismatch: "
                    . $actualCodeBlocks[$key] . ' !== ' . (int) $expectedCodeBlocks[$key] . '.',
            ];
        }
    }

    if (!empty($fixture['supported']) && $actualCodeBlocks['total'] !== 0) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Supported native no-code fixture emitted a visible code block.',
        ];
    }

    if ($actualCodeBlocks['total'] === 0) {
        foreach (['headScriptElements', 'iconScriptElements'] as $key) {
            if (!empty($result[$key])) {
                $failures[] = [
                    'mode' => $mode,
                    'file' => normalize_path($file),
                    'message' => "Native no-code fixture unexpectedly emitted {$key}.",
                ];
            }
        }
    }

    $unsupportedItems = is_array($result['stats']['unsupportedItems'] ?? null)
        ? $result['stats']['unsupportedItems']
        : [];
    $expectedUnsupportedCount = (int) ($expected['unsupportedCount'] ?? 0);
    if (count($unsupportedItems) !== $expectedUnsupportedCount) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Native no-code unsupported item count mismatch: '
                . count($unsupportedItems) . ' !== ' . $expectedUnsupportedCount . '.',
        ];
    }

    $expectedCategories = array_values(array_map('strval', is_array($expected['fallbackCategories'] ?? null)
        ? $expected['fallbackCategories']
        : []));
    $actualCategories = array_values(array_filter(array_map(
        static fn(array $item): string => (string) ($item['fallbackCategory'] ?? ''),
        $unsupportedItems
    )));
    sort($expectedCategories);
    sort($actualCategories);
    if ($actualCategories !== $expectedCategories) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Native no-code fallback categories mismatch: '
                . json_encode($actualCategories) . ' !== ' . json_encode($expectedCategories) . '.',
        ];
    }

    $expectedFallbackCss = (bool) ($expected['fallbackCss'] ?? false);
    $actualFallbackCss = has_owned_fallback_css($result);
    if ($actualFallbackCss !== $expectedFallbackCss) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Native no-code fallback CSS mismatch: '
                . ($actualFallbackCss ? 'true' : 'false') . ' !== ' . ($expectedFallbackCss ? 'true' : 'false') . '.',
        ];
    }

    if ($expectedFallbackCss) {
        $failures = array_merge($failures, assert_fallback_css_owner_route($result, $file, $mode));
    }

    $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
    $selectors = is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : [];
    $minSelectors = (int) ($expected['minSelectors'] ?? 0);
    if (count($selectors) < $minSelectors) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Native no-code selector count below expectation: '
                . count($selectors) . ' < ' . $minSelectors . '.',
        ];
    }

    return $failures;
}

/**
 * @param array<string, mixed>|null $fixture
 * @param array<string, mixed> $result
 * @param array<string, int> $visibleTypes
 * @return array<int, array<string, string>>
 */
function assert_fixture_index_contract(?array $fixture, array $result, array $visibleTypes, string $file, string $mode): array
{
    if ($fixture === null) {
        return [[
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'Stable HTML fixture is not covered by M8 fixture index.',
        ]];
    }

    $failures = [];
    $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
    $expectedCodeBlocks = is_array($expected['codeBlocks'] ?? null) ? $expected['codeBlocks'] : [];
    $actualCodeBlocks = code_block_counts($visibleTypes);

    foreach (['total', 'html', 'css', 'javascript'] as $key) {
        if (!array_key_exists($key, $expectedCodeBlocks)) {
            continue;
        }

        if ((int) $expectedCodeBlocks[$key] !== $actualCodeBlocks[$key]) {
            $failures[] = [
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => "M8 fixture index {$key} code block count mismatch: "
                    . $actualCodeBlocks[$key] . ' !== ' . (int) $expectedCodeBlocks[$key] . '.',
            ];
        }
    }

    $expectedFallbackCount = (int) ($expected['fallbackCount'] ?? 0);
    $actualFallbackCount = count_fallback_routes($result);
    if ($actualFallbackCount !== $expectedFallbackCount) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'M8 fixture index fallback count mismatch: '
                . $actualFallbackCount . ' !== ' . $expectedFallbackCount . '.',
        ];
    }

    $unsupportedItems = is_array($result['stats']['unsupportedItems'] ?? null)
        ? $result['stats']['unsupportedItems']
        : [];
    $expectedUnsupportedCount = (int) ($expected['unsupportedCount'] ?? 0);
    if (count($unsupportedItems) !== $expectedUnsupportedCount) {
        $failures[] = [
            'mode' => $mode,
            'file' => normalize_path($file),
            'message' => 'M8 fixture index unsupported count mismatch: '
                . count($unsupportedItems) . ' !== ' . $expectedUnsupportedCount . '.',
        ];
    }

    return $failures;
}

/**
 * @param array<string, int> $types
 * @return array{total:int, html:int, css:int, javascript:int}
 */
function code_block_counts(array $types): array
{
    $html = $types[ElementTypes::HTML_CODE] ?? 0;
    $css = $types[ElementTypes::CSS_CODE] ?? 0;
    $javascript = $types[ElementTypes::JAVASCRIPT_CODE] ?? 0;

    return [
        'total' => $html + $css + $javascript,
        'html' => $html,
        'css' => $css,
        'javascript' => $javascript,
    ];
}

/**
 * @param array<string, mixed> $result
 */
function count_fallback_routes(array $result): int
{
    $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
    $routes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];
    $count = 0;

    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        if (str_contains((string) ($route['type'] ?? ''), 'fallback')) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array<string, mixed> $result
 */
function has_owned_fallback_css(array $result): bool
{
    if (trim((string) ($result['extractedCss'] ?? '')) !== '') {
        return true;
    }

    foreach (['globalCss', 'pageScopedCss'] as $key) {
        if (trim((string) ($result[$key] ?? '')) !== '') {
            return true;
        }
    }

    $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
    foreach (['pageCss', 'globalCss', 'pageScopedCss'] as $key) {
        if (trim((string) ($styleRouting[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $result
 * @return array<int, array<string, string>>
 */
function assert_fallback_css_owner_route(array $result, string $file, string $mode): array
{
    $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
    $routes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];

    foreach ($routes as $route) {
        if (!is_array($route) || ($route['type'] ?? '') !== 'page_fallback') {
            continue;
        }

        $expected = [
            'destination' => 'page_css',
            'owner' => 'page',
            'rollbackStore' => 'page_styles',
        ];

        foreach ($expected as $key => $value) {
            if (($route[$key] ?? null) !== $value) {
                return [[
                    'mode' => $mode,
                    'file' => normalize_path($file),
                    'message' => "Fallback CSS route {$key} mismatch.",
                ]];
            }
        }

        if (($route['pluginDependency'] ?? null) !== null) {
            return [[
                'mode' => $mode,
                'file' => normalize_path($file),
                'message' => 'Fallback CSS route unexpectedly has a plugin dependency.',
            ]];
        }

        return [];
    }

    return [[
        'mode' => $mode,
        'file' => normalize_path($file),
        'message' => 'Expected page_fallback CSS route was not found.',
    ]];
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
