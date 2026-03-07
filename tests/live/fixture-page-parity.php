<?php

declare(strict_types=1);

use OxyHtmlConverter\TreeBuilder;
use OxyHtmlConverter\Services\OxygenDocumentTree;

// Run inside WP container context
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/oxygen-html-converter/src/polyfills.php';

spl_autoload_register(function ($class) {
    $prefix = 'OxyHtmlConverter\\';
    $baseDir = '/var/www/html/wp-content/plugins/oxygen-html-converter/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

$inputFile = $argv[1] ?? '/var/www/html/Import_Tests/Kamil.html';
$artifactsDir = $argv[2] ?? '/tmp/oxy-parity';
$cliOptions = parseCliOptions(array_slice($argv, 3));
$pageOptions = [
    'keepPost' => !empty($cliOptions['keep-post']),
    'replacePost' => !empty($cliOptions['replace-post']),
    'pageSlug' => is_string($cliOptions['page-slug'] ?? null) ? trim((string) $cliOptions['page-slug']) : '',
    'pageTitle' => is_string($cliOptions['page-title'] ?? null) ? trim((string) $cliOptions['page-title']) : '',
    'postStatus' => is_string($cliOptions['post-status'] ?? null) && trim((string) $cliOptions['post-status']) !== ''
        ? trim((string) $cliOptions['post-status'])
        : 'publish',
];

$GLOBALS['oxyParityStylesheetFetchLog'] = [];

if (!file_exists($inputFile)) {
    fwrite(STDERR, "Input HTML not found: {$inputFile}\n");
    exit(2);
}

$html = file_get_contents($inputFile);
if ($html === false || trim($html) === '') {
    fwrite(STDERR, "Input HTML unreadable or empty: {$inputFile}\n");
    exit(2);
}

$builder = new TreeBuilder();
$result = $builder->convert($html);

if (empty($result['success']) || empty($result['element']) || !is_array($result['element'])) {
    fwrite(STDERR, "Conversion failed: " . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

if (!is_dir($artifactsDir) && !mkdir($artifactsDir, 0775, true) && !is_dir($artifactsDir)) {
    fwrite(STDERR, "Failed to create artifacts directory: {$artifactsDir}\n");
    exit(1);
}

$baseName = pathinfo($inputFile, PATHINFO_FILENAME);
$timestamp = gmdate('Ymd_His');

$sourceStats = sourceStatsFromHtml($html);
$renderableTree = buildRenderableTreeForProbe($result);
$outputStats = outputStatsFromElementTree($renderableTree);
$delta = buildDelta($sourceStats, $outputStats, $result);
$renderProbe = probeRenderedFrontend($renderableTree, $html, $baseName, $pageOptions);

$report = [
    'generatedAt' => gmdate('c'),
    'inputFile' => $inputFile,
    'stats' => $result['stats'] ?? [],
    'source' => $sourceStats,
    'output' => $outputStats,
    'delta' => $delta,
    'topResidualClasses' => $outputStats['topResidualClasses'],
    'renderProbe' => $renderProbe,
    'page' => [
        'keepPost' => $pageOptions['keepPost'],
        'replacePost' => $pageOptions['replacePost'],
        'slug' => $renderProbe['slug'] ?? ($pageOptions['pageSlug'] !== '' ? sanitize_title($pageOptions['pageSlug']) : null),
        'title' => $renderProbe['title'] ?? ($pageOptions['pageTitle'] !== '' ? $pageOptions['pageTitle'] : null),
        'postId' => $renderProbe['postId'] ?? null,
        'permalink' => $renderProbe['permalink'] ?? null,
        'action' => $renderProbe['postAction'] ?? null,
    ],
    'note' => $renderProbe['ok']
        ? 'Includes post-save rendered frontend probe. Keep treating this as smoke-level parity until expanded style diff coverage.'
        : 'Render probe did not produce valid frontend HTML yet. Falling back to conversion-output parity proxy.',
];

$reportPath = sprintf('%s/%s-%s.parity.json', rtrim($artifactsDir, '/'), $baseName, $timestamp);
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'ok' => true,
    'reportPath' => $reportPath,
    'summary' => [
        'sourceDomNodes' => $sourceStats['domNodes'],
        'outputElements' => $outputStats['elementCount'],
        'domToElementRatio' => $delta['domToElementRatio'],
        'sourceClassTokens' => $sourceStats['classTokenCount'],
        'residualClassTokens' => $outputStats['residualClassTokenCount'],
        'residualRatio' => $delta['residualClassRatio'],
        'renderProbeOk' => $renderProbe['ok'],
    ],
    'topResidualClasses' => $outputStats['topResidualClasses'],
    'renderProbe' => [
        'method' => $renderProbe['method'] ?? null,
        'error' => $renderProbe['error'] ?? null,
        'postId' => $renderProbe['postId'] ?? null,
        'permalink' => $renderProbe['permalink'] ?? null,
        'slug' => $renderProbe['slug'] ?? null,
        'postAction' => $renderProbe['postAction'] ?? null,
        'renderedDomNodes' => $renderProbe['rendered']['domNodes'] ?? null,
        'renderedClassTokens' => $renderProbe['rendered']['classTokenCount'] ?? null,
        'domDeltaRatio' => $renderProbe['delta']['renderedDomToSourceDomRatio'] ?? null,
        'classDeltaRatio' => $renderProbe['delta']['renderedClassToSourceClassRatio'] ?? null,
        'topStructureDeltas' => $renderProbe['parity']['topStructureDeltas'] ?? [],
        'styleCategoryDeltas' => $renderProbe['parity']['styleCategoryDeltas'] ?? [],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(0);

function parseCliOptions(array $args): array
{
    $options = [];

    foreach ($args as $arg) {
        if (!is_string($arg) || $arg === '' || strpos($arg, '--') !== 0) {
            continue;
        }

        $arg = substr($arg, 2);
        if ($arg === '') {
            continue;
        }

        if (strpos($arg, '=') === false) {
            $options[$arg] = true;
            continue;
        }

        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    }

    return $options;
}

function buildRenderableTreeForProbe(array $conversionResult): array
{
    $root = $conversionResult['element'] ?? [];
    if (!is_array($root) || !$root) {
        return $root;
    }

    $prependChildren = [];

    if (!empty($conversionResult['headLinkElements']) && is_array($conversionResult['headLinkElements'])) {
        foreach ($conversionResult['headLinkElements'] as $headElement) {
            if (is_array($headElement)) {
                $prependChildren[] = $headElement;
            }
        }
    }

    if (!empty($conversionResult['headScriptElements']) && is_array($conversionResult['headScriptElements'])) {
        foreach ($conversionResult['headScriptElements'] as $headElement) {
            if (is_array($headElement)) {
                $prependChildren[] = $headElement;
            }
        }
    }

    if (!empty($conversionResult['iconScriptElements']) && is_array($conversionResult['iconScriptElements'])) {
        foreach ($conversionResult['iconScriptElements'] as $iconElement) {
            if (is_array($iconElement)) {
                $prependChildren[] = $iconElement;
            }
        }
    }

    if (!empty($conversionResult['cssElement']) && is_array($conversionResult['cssElement'])) {
        $prependChildren[] = $conversionResult['cssElement'];
    }

    if (!$prependChildren) {
        return $root;
    }

    $existingChildren = isset($root['children']) && is_array($root['children'])
        ? $root['children']
        : [];

    $root['children'] = array_merge($prependChildren, $existingChildren);

    return $root;
}

function sourceStatsFromHtml(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $elements = $xpath->query('//*');
    $domNodes = $elements ? $elements->length : 0;

    $classTokens = [];
    $inlineStyleCount = 0;

    if ($elements) {
        foreach ($elements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $classAttr = trim((string) $el->getAttribute('class'));
            if ($classAttr !== '') {
                foreach (preg_split('/\s+/', $classAttr) ?: [] as $token) {
                    $token = trim($token);
                    if ($token !== '') {
                        $classTokens[] = $token;
                    }
                }
            }

            $styleAttr = trim((string) $el->getAttribute('style'));
            if ($styleAttr !== '') {
                $inlineStyleCount += count(array_filter(array_map('trim', explode(';', $styleAttr))));
            }
        }
    }

    return [
        'domNodes' => $domNodes,
        'classTokenCount' => count($classTokens),
        'uniqueClassTokenCount' => count(array_unique($classTokens)),
        'inlineStyleDeclarationCount' => $inlineStyleCount,
    ];
}

function outputStatsFromElementTree(array $root): array
{
    $stack = [$root];
    $elementCount = 0;
    $residualTokens = [];

    while ($stack) {
        $node = array_pop($stack);
        if (!is_array($node)) {
            continue;
        }

        $elementCount++;

        $properties = $node['data']['properties'] ?? [];
        $advancedClasses = $properties['settings']['advanced']['classes'] ?? [];

        if (is_array($advancedClasses)) {
            foreach ($advancedClasses as $className) {
                if (!is_string($className)) {
                    continue;
                }
                $className = trim($className);
                if ($className === '') {
                    continue;
                }
                $residualTokens[] = $className;
            }
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $stack[] = $child;
            }
        }
    }

    $counts = [];
    foreach ($residualTokens as $token) {
        $counts[$token] = ($counts[$token] ?? 0) + 1;
    }

    arsort($counts);
    $topResidual = [];
    foreach (array_slice($counts, 0, 12, true) as $name => $count) {
        $topResidual[] = ['class' => $name, 'count' => $count];
    }

    return [
        'elementCount' => $elementCount,
        'residualClassTokenCount' => count($residualTokens),
        'uniqueResidualClassTokenCount' => count(array_unique($residualTokens)),
        'topResidualClasses' => $topResidual,
    ];
}

function buildDelta(array $source, array $output, array $conversionResult): array
{
    $domNodes = max(1, (int) ($source['domNodes'] ?? 0));
    $sourceClassTokens = max(1, (int) ($source['classTokenCount'] ?? 0));

    $outputElements = (int) ($output['elementCount'] ?? 0);
    $residual = (int) ($output['residualClassTokenCount'] ?? 0);
    $classDefinitionsCount = is_array($conversionResult['classDefinitions'] ?? null)
        ? count($conversionResult['classDefinitions'])
        : 0;

    return [
        'domToElementRatio' => round($outputElements / $domNodes, 3),
        'residualClassRatio' => round($residual / $sourceClassTokens, 3),
        'mappedClassDefinitions' => $classDefinitionsCount,
    ];
}

function probeRenderedFrontend(array $elementTree, string $sourceHtml, string $slugBase, array $pageOptions = []): array
{
    $keepPost = !empty($pageOptions['keepPost']);
    $replacePost = !empty($pageOptions['replacePost']);
    $slug = sanitize_title((string) ($pageOptions['pageSlug'] ?? ''));
    $title = trim((string) ($pageOptions['pageTitle'] ?? ''));
    $postStatus = trim((string) ($pageOptions['postStatus'] ?? 'publish'));
    if ($postStatus === '') {
        $postStatus = 'publish';
    }

    $postAction = 'temporary-create';
    $shouldDeletePost = !$keepPost;
    $existingPost = null;

    if ($keepPost) {
        if ($slug === '') {
            $slug = sanitize_title('fixture-' . $slugBase);
        }
        if ($title === '') {
            $title = 'Fixture ' . $slugBase;
        }
        $existingPost = get_page_by_path($slug, OBJECT, 'page');
        if ($replacePost && $existingPost instanceof WP_Post) {
            wp_delete_post((int) $existingPost->ID, true);
            clean_post_cache((int) $existingPost->ID);
            $existingPost = null;
            $postAction = 'recreated';
        }
    } else {
        if ($slug === '') {
            $slug = sanitize_title($slugBase . '-parity-' . uniqid());
        }
        if ($title === '') {
            $title = 'OXY Parity Probe ' . gmdate('Y-m-d H:i:s');
        }
    }

    $postPayload = [
        'post_type' => 'page',
        'post_status' => $postStatus,
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '<!-- OXY parity probe -->',
    ];

    if ($existingPost instanceof WP_Post) {
        $postPayload['ID'] = (int) $existingPost->ID;
        $postAction = 'updated';
        $postId = wp_update_post($postPayload, true);
    } else {
        $postAction = $postAction === 'recreated'
            ? 'recreated'
            : ($keepPost ? 'created' : 'temporary-create');
        $postId = wp_insert_post($postPayload, true);
    }

    if (is_wp_error($postId) || !$postId) {
        return [
            'ok' => false,
            'error' => 'Failed to create parity page: ' . (is_wp_error($postId) ? $postId->get_error_message() : 'unknown'),
        ];
    }

    $probe = [
        'ok' => false,
        'postId' => (int) $postId,
        'permalink' => get_permalink((int) $postId),
        'slug' => $slug,
        'title' => $title,
        'postAction' => $postAction,
    ];

    try {
        $renderResult = tryRenderWithOxygenMetaVariants((int) $postId, $elementTree);

        if (!$renderResult['ok']) {
            $probe['error'] = $renderResult['error'] ?? 'No render output from meta variants';
            $probe['metaVariantsTried'] = $renderResult['metaVariantsTried'] ?? [];
            return $probe;
        }

        $renderHtml = (string) ($renderResult['html'] ?? '');
        $styleHtml = (string) ($renderResult['styleHtml'] ?? $renderHtml);
        $renderedStats = sourceStatsFromHtml($renderHtml);
        $sourceStats = sourceStatsFromHtml($sourceHtml);

        $probe['ok'] = true;
        $probe['method'] = $renderResult['method'];
        $probe['metaVariant'] = $renderResult['metaVariant'] ?? null;
        $probe['styleSourceMethod'] = $renderResult['styleSourceMethod'] ?? $renderResult['method'];
        $probe['rendered'] = $renderedStats;
        $probe['delta'] = [
            'renderedDomToSourceDomRatio' => round($renderedStats['domNodes'] / max(1, $sourceStats['domNodes']), 3),
            'renderedClassToSourceClassRatio' => round($renderedStats['classTokenCount'] / max(1, $sourceStats['classTokenCount']), 3),
        ];
        $probe['parity'] = compareHtmlVisualParity($sourceHtml, $renderHtml, $styleHtml);
        $probe['styleFetchDiagnostics'] = summarizeStylesheetFetchLog();

        return $probe;
    } finally {
        if ($shouldDeletePost) {
            wp_delete_post((int) $postId, true);
        }
    }
}

function tryRenderWithOxygenMetaVariants(int $postId, array $elementTree): array
{
    $metaVariants = buildOxygenMetaVariants($elementTree);
    $metaKey = function_exists('\\Breakdance\\BreakdanceOxygen\\Strings\\__bdox')
        ? \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix') . 'data'
        : '_oxygen_data';

    $tried = [];

    foreach ($metaVariants as $label => $payload) {
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded) || $encoded === '') {
            continue;
        }

        if (function_exists('\\Breakdance\\Data\\set_meta')) {
            \Breakdance\Data\set_meta($postId, $metaKey, ['tree_json_string' => $encoded]);
        } else {
            update_post_meta($postId, $metaKey, wp_slash(wp_json_encode(['tree_json_string' => $encoded])));
        }

        clean_post_cache($postId);
        refreshOxygenRenderCacheForPost($postId);
        $tried[] = $label;

        if (is_callable('\\Breakdance\\Render\\getRenderedNodes')) {
            $rendered = \Breakdance\Render\getRenderedNodes($postId, true);
            if (is_array($rendered) && !empty($rendered['html']) && is_string($rendered['html'])) {
                $styleHtml = buildStyleHtmlFromRenderedPayload($rendered, $postId);
                $styleSourceMethod = $styleHtml !== null
                    ? 'Breakdance\\Render\\getRenderedNodes:css'
                    : 'Breakdance\\Render\\getRenderedNodes';

                $pageHtml = fetchRenderedPageHtml($postId);
                if (is_string($pageHtml) && trim($pageHtml) !== '') {
                    if ($styleHtml === null || trim($styleHtml) === '') {
                        $styleHtml = $pageHtml;
                        $styleSourceMethod = 'wp_remote_get';
                    } else {
                        // Keep rendered payload CSS chunks, but enrich style probe with full frontend page
                        // so linked stylesheets and global assets are included in parity classification.
                        $styleHtml .= "\n" . $pageHtml;
                        $styleSourceMethod .= '+wp_remote_get';
                    }

                    $discoveredCss = fetchDiscoveredStylesFromFrontendSources($postId, $pageHtml);
                    if ($discoveredCss !== null) {
                        $styleHtml = ($styleHtml ?? '') . "\n<style>\n" . $discoveredCss . "\n</style>";
                        $styleSourceMethod .= '+frontend-discovery';
                    }

                    // Computed-style snapshot: extract actual rendered font properties from page HTML
                    // to close typography classification gap when CSS sources are incomplete.
                    $computedStyleCss = extractComputedStyleSnapshot($pageHtml);
                    if ($computedStyleCss !== null && trim($computedStyleCss) !== '') {
                        $styleHtml .= "\n<style>\n/* computed-style snapshot */\n" . $computedStyleCss . "\n</style>";
                        $styleSourceMethod .= '+computed-style';
                    }

                    // CSS custom property font extraction: capture --font-* variables with font values
                    // and @import font URLs that may be in style tags but not yet fetched.
                    $customPropCss = extractCssCustomPropertyFonts($pageHtml);
                    if ($customPropCss !== null && trim($customPropCss) !== '') {
                        $styleHtml .= "\n<style>\n/* custom-property fonts */\n" . $customPropCss . "\n</style>";
                        $styleSourceMethod .= '+custom-props';
                    }

                    // Link tag font extraction: capture Google Fonts, Typekit, and other external
                    // font services referenced via link[rel="stylesheet"] or link[rel="preconnect"].
                    $linkTagCss = extractLinkTagFonts($pageHtml);
                    if ($linkTagCss !== null && trim($linkTagCss) !== '') {
                        $styleHtml .= "\n<style>\n/* link-tag fonts */\n" . $linkTagCss . "\n</style>";
                        $styleSourceMethod .= '+link-tags';
                    }
                }

                return [
                    'ok' => true,
                    'method' => 'Breakdance\\Render\\getRenderedNodes',
                    'metaVariant' => $label,
                    'html' => $rendered['html'],
                    'styleHtml' => $styleHtml ?? $rendered['html'],
                    'styleSourceMethod' => $styleSourceMethod,
                    'metaVariantsTried' => $tried,
                ];
            }
        }

        $pageHtml = fetchRenderedPageHtml($postId);
        if (is_string($pageHtml) && trim($pageHtml) !== '') {
            $styleHtml = $pageHtml;
            $styleSourceMethod = 'wp_remote_get';
            $discoveredCss = fetchDiscoveredStylesFromFrontendSources($postId, $pageHtml);
            if ($discoveredCss !== null) {
                $styleHtml .= "\n<style>\n" . $discoveredCss . "\n</style>";
                $styleSourceMethod .= '+frontend-discovery';
            }

            // Computed-style snapshot for fallback path
            $computedStyleCss = extractComputedStyleSnapshot($pageHtml);
            if ($computedStyleCss !== null && trim($computedStyleCss) !== '') {
                $styleHtml .= "\n<style>\n/* computed-style snapshot */\n" . $computedStyleCss . "\n</style>";
                $styleSourceMethod .= '+computed-style';
            }

            // CSS custom property font extraction for fallback path
            $customPropCss = extractCssCustomPropertyFonts($pageHtml);
            if ($customPropCss !== null && trim($customPropCss) !== '') {
                $styleHtml .= "\n<style>\n/* custom-property fonts */\n" . $customPropCss . "\n</style>";
                $styleSourceMethod .= '+custom-props';
            }

            // Link tag font extraction for fallback path
            $linkTagCss = extractLinkTagFonts($pageHtml);
            if ($linkTagCss !== null && trim($linkTagCss) !== '') {
                $styleHtml .= "\n<style>\n/* link-tag fonts */\n" . $linkTagCss . "\n</style>";
                $styleSourceMethod .= '+link-tags';
            }

            return [
                'ok' => true,
                'method' => 'wp_remote_get',
                'metaVariant' => $label,
                'html' => $pageHtml,
                'styleHtml' => $styleHtml,
                'styleSourceMethod' => $styleSourceMethod,
                'metaVariantsTried' => $tried,
            ];
        }
    }

    return [
        'ok' => false,
        'error' => 'No non-empty rendered HTML for tried meta variants',
        'metaVariantsTried' => $tried,
    ];
}

function refreshOxygenRenderCacheForPost(int $postId): void
{
    $metaPrefix = function_exists('\\Breakdance\\BreakdanceOxygen\\Strings\\__bdox')
        ? \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix')
        : '_oxygen_';

    delete_post_meta($postId, $metaPrefix . 'dependency_cache');
    delete_post_meta($postId, $metaPrefix . 'css_file_paths_cache');
    clean_post_cache($postId);

    if (is_callable('\\Breakdance\\Render\\generateCacheForPost')) {
        \Breakdance\Render\generateCacheForPost($postId);
    }
}

function buildOxygenMetaVariants(array $elementTree): array
{
    $documentTree = (new OxygenDocumentTree())->build($elementTree);

    return [
        'document-root' => $documentTree,
        'tree-root' => $elementTree,
        'content-tree' => ['content' => $elementTree],
        'data-tree' => ['data' => $elementTree],
        'nodes-tree' => ['nodes' => $elementTree],
        'wrapped-elements' => ['elements' => [$elementTree]],
    ];
}

function fetchRenderedPageHtml(int $postId): ?string
{
    $url = get_permalink($postId);
    if (!is_string($url) || $url === '') {
        return null;
    }

    $parts = wp_parse_url($url);
    $scheme = is_array($parts) ? ($parts['scheme'] ?? 'http') : 'http';
    $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
    $query = (is_array($parts) && isset($parts['query']) && $parts['query'] !== '') ? ('?' . $parts['query']) : '';
    $host = (is_array($parts) && !empty($parts['host'])) ? (string) $parts['host'] : '';
    $port = (is_array($parts) && !empty($parts['port'])) ? (int) $parts['port'] : null;

    $originHostHeader = $host !== ''
        ? ($port ? sprintf('%s:%d', $host, $port) : $host)
        : null;

    $candidates = [
        ['url' => $url, 'hostHeader' => null],
    ];

    if ($path !== '') {
        // In Docker, WordPress often stores external host:port (e.g. 127.0.0.1:8090)
        // that is not reachable from inside the WP container. Try internal loopback fallback,
        // but preserve original Host header so WP does not redirect back to external host:port.
        $candidates[] = [
            'url' => sprintf('%s://127.0.0.1%s%s', $scheme, $path, $query),
            'hostHeader' => $originHostHeader,
        ];
        $candidates[] = [
            'url' => sprintf('%s://localhost%s%s', $scheme, $path, $query),
            'hostHeader' => $originHostHeader,
        ];
        $candidates[] = [
            'url' => sprintf('%s://nginx%s%s', $scheme, $path, $query),
            'hostHeader' => $originHostHeader,
        ];
    }

    $seen = [];

    foreach ($candidates as $candidate) {
        $candidateUrl = trim((string) ($candidate['url'] ?? ''));
        if ($candidateUrl === '' || isset($seen[$candidateUrl])) {
            continue;
        }
        $seen[$candidateUrl] = true;

        $requestArgs = [
            'timeout' => 10,
            'redirection' => 0,
            // Local Docker parity runs use self-signed HTTPS on localhost/127.0.0.1.
            'sslverify' => false,
        ];

        $hostHeader = $candidate['hostHeader'] ?? null;
        if (is_string($hostHeader) && trim($hostHeader) !== '') {
            $requestArgs['headers'] = ['Host' => $hostHeader];
        }

        $response = wp_remote_get($candidateUrl, $requestArgs);
        if (is_wp_error($response)) {
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        if (is_string($body) && trim($body) !== '') {
            return $body;
        }
    }

    return null;
}

function buildStyleHtmlFromRenderedPayload(array $rendered, int $postId = 0): ?string
{
    $html = is_string($rendered['html'] ?? null) ? $rendered['html'] : '';
    if ($html === '') {
        return null;
    }

    $cssChunks = [];

    if (is_string($rendered['css'] ?? null) && trim($rendered['css']) !== '') {
        $cssChunks[] = trim($rendered['css']);
    }

    if (is_array($rendered['cssRules'] ?? null)) {
        foreach ($rendered['cssRules'] as $chunk) {
            if (is_string($chunk) && trim($chunk) !== '') {
                $cssChunks[] = trim($chunk);
            }
        }
    }

    if (is_string($rendered['defaultCss'] ?? null) && trim($rendered['defaultCss']) !== '') {
        $cssChunks[] = trim($rendered['defaultCss']);
    }

    if (is_array($rendered['styles'] ?? null)) {
        foreach ($rendered['styles'] as $chunk) {
            if (is_string($chunk) && trim($chunk) !== '') {
                $cssChunks[] = trim($chunk);
            }
        }
    }

    if (is_array($rendered['assets'] ?? null)) {
        foreach ($rendered['assets'] as $asset) {
            if (is_array($asset)) {
                $inlineCss = $asset['css'] ?? null;
                if (is_string($inlineCss) && trim($inlineCss) !== '') {
                    $cssChunks[] = trim($inlineCss);
                }
            }
        }
    }

    // Some Breakdance/Oxygen payload variants keep CSS in nested structures.
    collectNestedRenderedCssChunks($rendered, $cssChunks);

    foreach (extractRenderedDependencyStylesheetUrls($rendered) as $stylesheetUrl) {
        $stylesheetCss = fetchStylesheetCss($stylesheetUrl, 'rendered-dependency');
        if (is_string($stylesheetCss) && trim($stylesheetCss) !== '') {
            $cssChunks[] = trim($stylesheetCss);
        }
    }

    // Fallback: include actively enqueued builder styles so parity covers
    // real frontend CSS even when render payload omits explicit css chunks.
    foreach (extractRelevantEnqueuedStylesheetUrls() as $stylesheetUrl) {
        $stylesheetCss = fetchStylesheetCss($stylesheetUrl, 'enqueued-style');
        if (is_string($stylesheetCss) && trim($stylesheetCss) !== '') {
            $cssChunks[] = trim($stylesheetCss);
        }
    }

    // Additional source-discovery pass: harvest CSS endpoints exposed in
    // builder post meta payloads (query-driven runtime URLs, compiled handles).
    if ($postId > 0) {
        foreach (extractPostMetaStylesheetUrls($postId) as $stylesheetUrl) {
            $stylesheetCss = fetchStylesheetCss($stylesheetUrl, 'post-meta-url');
            if (is_string($stylesheetCss) && trim($stylesheetCss) !== '') {
                $cssChunks[] = trim($stylesheetCss);
            }
        }
    }

    $cssChunks = array_values(array_unique(array_filter($cssChunks, static fn ($v) => is_string($v) && $v !== '')));
    if (!$cssChunks) {
        return null;
    }

    return $html . "\n<style>\n" . implode("\n", $cssChunks) . "\n</style>";
}

function collectNestedRenderedCssChunks($value, array &$cssChunks, string $keyHint = ''): void
{
    if (is_string($value)) {
        $candidate = trim($value);
        if ($candidate === '') {
            return;
        }

        $hint = strtolower($keyHint);
        $looksLikeCss = strpos($candidate, '{') !== false
            && strpos($candidate, ':') !== false
            && strpos($candidate, '<') === false;

        $hintSuggestsCss = $hint === ''
            || strpos($hint, 'css') !== false
            || strpos($hint, 'style') !== false
            || strpos($hint, 'rule') !== false
            || strpos($hint, 'default') !== false
            || strpos($hint, 'global') !== false;

        if ($looksLikeCss && $hintSuggestsCss) {
            $cssChunks[] = $candidate;
        }

        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $nestedKey => $nestedValue) {
        $nextHint = is_string($nestedKey) ? $nestedKey : $keyHint;
        collectNestedRenderedCssChunks($nestedValue, $cssChunks, $nextHint);
    }
}

function extractRelevantEnqueuedStylesheetUrls(): array
{
    if (!function_exists('wp_styles')) {
        return [];
    }

    $styles = wp_styles();
    if (!is_object($styles) || !isset($styles->registered) || !is_array($styles->registered)) {
        return [];
    }

    $candidateHandles = array_values(array_unique(array_merge(
        is_array($styles->queue ?? null) ? $styles->queue : [],
        is_array($styles->done ?? null) ? $styles->done : []
    )));

    if (!$candidateHandles) {
        return [];
    }

    $urls = [];

    foreach ($candidateHandles as $handle) {
        if (!is_string($handle) || $handle === '') {
            continue;
        }

        // Keep this strict to avoid pulling unrelated theme/plugin css noise.
        if (!preg_match('/(breakdance|oxygen|ct-|windpress|oxy-html-converter)/i', $handle)) {
            continue;
        }

        $registered = $styles->registered[$handle] ?? null;
        if (!is_object($registered)) {
            continue;
        }

        $src = trim((string) ($registered->src ?? ''));
        if ($src === '') {
            continue;
        }

        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
        } elseif (!preg_match('#^https?://#i', $src)) {
            $baseUrl = is_string($styles->base_url ?? null) ? trim((string) $styles->base_url) : '';
            if ($baseUrl !== '') {
                $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
            } else {
                $src = home_url('/' . ltrim($src, '/'));
            }
        }

        $urls[$src] = true;
    }

    return array_keys($urls);
}

function extractPostMetaStylesheetUrls(int $postId): array
{
    if ($postId <= 0 || !function_exists('get_post_meta')) {
        return [];
    }

    $meta = get_post_meta($postId);
    if (!is_array($meta) || !$meta) {
        return [];
    }

    $urls = [];
    collectCssUrlCandidatesFromMixedValue($meta, $urls, 0);

    return array_keys($urls);
}

function collectCssUrlCandidatesFromMixedValue($value, array &$urls, int $depth): void
{
    if ($depth > 8) {
        return;
    }

    if (is_array($value)) {
        foreach ($value as $nested) {
            collectCssUrlCandidatesFromMixedValue($nested, $urls, $depth + 1);
        }
        return;
    }

    if (!is_string($value)) {
        return;
    }

    $text = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($text === '') {
        return;
    }

    $direct = normalizeRenderedStylesheetUrl($text);
    if (is_string($direct)) {
        $urls[$direct] = true;
        return;
    }

    if (strlen($text) > 300000) {
        return;
    }

    if ($depth < 4 && (strpos($text, '{') !== false || strpos($text, '[') !== false)) {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            collectCssUrlCandidatesFromMixedValue($decoded, $urls, $depth + 1);
        }
    }

    if (!preg_match_all('#(?:https?:)?//[^\s"\'<>]+|/(?:wp-content|wp-includes)/[^\s"\'<>]+#i', $text, $matches)) {
        return;
    }

    foreach ($matches[0] as $candidate) {
        $normalized = normalizeRenderedStylesheetUrl((string) $candidate);
        if (is_string($normalized)) {
            $urls[$normalized] = true;
        }
    }
}

function extractRenderedDependencyStylesheetUrls(array $rendered): array
{
    $candidateUrls = [];
    $candidateHandles = [];

    foreach ($rendered as $key => $value) {
        $keyHint = is_string($key) ? strtolower($key) : '';

        if (
            $keyHint === ''
            || strpos($keyHint, 'depend') !== false
            || strpos($keyHint, 'global') !== false
            || strpos($keyHint, 'style') !== false
            || strpos($keyHint, 'asset') !== false
            || strpos($keyHint, 'enqueue') !== false
            || strpos($keyHint, 'sheet') !== false
        ) {
            collectRenderedCssUrlAndHandleCandidates($value, $candidateUrls, $candidateHandles);

            // Some payload variants expose dependency maps where the key itself
            // is the stylesheet handle and the value is metadata.
            if (is_string($key)) {
                $handle = normalizeRenderedStylesheetHandle($key);
                if (is_string($handle)) {
                    $candidateHandles[$handle] = true;
                }
            }
        }
    }

    foreach (resolveStylesheetUrlsFromHandles(array_keys($candidateHandles)) as $resolvedUrl) {
        $candidateUrls[$resolvedUrl] = true;
    }

    return array_keys($candidateUrls);
}

function collectRenderedCssUrlAndHandleCandidates($value, array &$urls, array &$handles): void
{
    if (is_string($value)) {
        $candidateUrl = normalizeRenderedStylesheetUrl($value);
        if (is_string($candidateUrl)) {
            $urls[$candidateUrl] = true;
            return;
        }

        $candidateHandle = normalizeRenderedStylesheetHandle($value);
        if (is_string($candidateHandle)) {
            $handles[$candidateHandle] = true;
        }

        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $nestedKey => $nestedValue) {
        if (is_string($nestedKey)) {
            $candidateUrl = normalizeRenderedStylesheetUrl($nestedKey);
            if (is_string($candidateUrl)) {
                $urls[$candidateUrl] = true;
            } else {
                $candidateHandle = normalizeRenderedStylesheetHandle($nestedKey);
                if (is_string($candidateHandle)) {
                    $handles[$candidateHandle] = true;
                }
            }
        }

        collectRenderedCssUrlAndHandleCandidates($nestedValue, $urls, $handles);
    }
}

function resolveStylesheetUrlsFromHandles(array $handles): array
{
    if (!function_exists('wp_styles')) {
        return [];
    }

    $styles = wp_styles();
    if (!is_object($styles) || !isset($styles->registered) || !is_array($styles->registered)) {
        return [];
    }

    $urls = [];

    foreach ($handles as $handle) {
        if (!is_string($handle) || $handle === '') {
            continue;
        }

        // Keep this strict to avoid inflating parity with unrelated CSS.
        if (!preg_match('/(breakdance|oxygen|ct-|windpress|oxy-html-converter)/i', $handle)) {
            continue;
        }

        $registered = $styles->registered[$handle] ?? null;
        if (!is_object($registered)) {
            continue;
        }

        $src = trim((string) ($registered->src ?? ''));
        if ($src === '') {
            continue;
        }

        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
        } elseif (!preg_match('#^https?://#i', $src)) {
            $baseUrl = is_string($styles->base_url ?? null) ? trim((string) $styles->base_url) : '';
            if ($baseUrl !== '') {
                $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
            } else {
                $src = home_url('/' . ltrim($src, '/'));
            }
        }

        $urls[$src] = true;
    }

    return array_keys($urls);
}

function normalizeRenderedStylesheetHandle(string $value): ?string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($value === '' || strlen($value) > 128) {
        return null;
    }

    if (
        strpos($value, '/') !== false
        || strpos($value, '\\') !== false
        || strpos($value, ':') !== false
        || strpos($value, '?') !== false
        || strpos($value, '&') !== false
        || strpos($value, '=') !== false
        || strpos($value, '{') !== false
        || strpos($value, '<') !== false
        || preg_match('/\s/', $value)
    ) {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
        return null;
    }

    return $value;
}

function normalizeRenderedStylesheetUrl(string $value): ?string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($value === '' || strlen($value) > 2048) {
        return null;
    }

    if (strpos($value, '{') !== false || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return null;
    }

    if (strpos($value, '//') === 0) {
        $value = 'https:' . $value;
    }

    $isAbsolute = (bool) preg_match('#^https?://#i', $value);
    $isRootRelative = strpos($value, '/') === 0;

    if (!$isAbsolute && !$isRootRelative) {
        return null;
    }

    $looksLikeCssAsset = preg_match('/\.css([?#].*)?$/i', $value)
        || stripos($value, '/css') !== false
        || stripos($value, 'css?') !== false;

    if ($looksLikeCssAsset) {
        return $value;
    }

    // Fallback for runtime-generated stylesheet endpoints exposed by render payloads
    // without a `.css` suffix (query-based URLs from Oxygen/Breakdance stacks).
    $parts = wp_parse_url($value);
    if (!is_array($parts)) {
        return null;
    }

    $query = (string) ($parts['query'] ?? '');
    $path = strtolower((string) ($parts['path'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    $queryLooksLikeStyleEndpoint = (bool) preg_match('/(^|[&])(css|style|stylesheet|oxygen|breakdance|ct_builder|ct_styles?|bd_styles?)=/i', $query);
    $pathLooksBuilderRelated = strpos($path, 'oxygen') !== false
        || strpos($path, 'breakdance') !== false
        || strpos($path, 'wp-content/uploads') !== false;
    $hostLooksLocal = $host === '' || $host === '127.0.0.1' || $host === 'localhost';

    if (($queryLooksLikeStyleEndpoint && ($pathLooksBuilderRelated || $hostLooksLocal)) || ($pathLooksBuilderRelated && strpos($query, '=') !== false)) {
        return $value;
    }

    return null;
}

function fetchDiscoveredStylesFromFrontendSources(int $postId, string $pageHtml): ?string
{
    $urls = [];

    foreach (extractPostMetaStylesheetUrls($postId) as $url) {
        $urls[$url] = true;
    }

    foreach (extractInlineScriptStylesheetUrls($pageHtml) as $url) {
        $urls[$url] = true;
    }

    foreach (extractOxygenCompiledUploadsStylesheetUrls($postId) as $url) {
        $urls[$url] = true;
    }

    // Font-face CSS often contains typography-related declarations
    // that improve parity classification accuracy.
    foreach (extractUploadsFontStylesheetUrls() as $url) {
        $urls[$url] = true;
    }

    if (!$urls) {
        return null;
    }

    $cssChunks = [];
    foreach (array_keys($urls) as $url) {
        $css = fetchStylesheetCss($url, 'frontend-discovery');
        if (is_string($css) && trim($css) !== '') {
            $cssChunks[] = trim($css);
        }
    }

    $cssChunks = array_values(array_unique($cssChunks));
    return $cssChunks ? implode("\n", $cssChunks) : null;
}

function extractInlineScriptStylesheetUrls(string $html): array
{
    $html = trim($html);
    if ($html === '') {
        return [];
    }

    $urls = [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $scripts = $xpath->query('//script[not(@src)]');
    if (!$scripts) {
        return [];
    }

    foreach ($scripts as $scriptEl) {
        if (!($scriptEl instanceof DOMElement)) {
            continue;
        }

        $scriptText = html_entity_decode(trim((string) $scriptEl->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($scriptText === '' || strlen($scriptText) > 1000000) {
            continue;
        }

        if (!preg_match_all('#(?:https?:)?//[^\s"\'<>]+|/(?:wp-content|wp-includes)/[^\s"\'<>]+#i', $scriptText, $matches)) {
            continue;
        }

        foreach ($matches[0] as $candidate) {
            $normalized = normalizeRenderedStylesheetUrl((string) $candidate);
            if (is_string($normalized)) {
                $urls[$normalized] = true;
            }
        }
    }

    return array_keys($urls);
}

function extractOxygenCompiledUploadsStylesheetUrls(int $postId): array
{
    if ($postId <= 0 || !function_exists('wp_upload_dir')) {
        return [];
    }

    $uploads = wp_upload_dir();
    $baseDir = is_array($uploads) ? trim((string) ($uploads['basedir'] ?? '')) : '';
    $baseUrl = is_array($uploads) ? trim((string) ($uploads['baseurl'] ?? '')) : '';

    if ($baseDir === '' || $baseUrl === '') {
        return [];
    }

    $oxygenCssDir = rtrim($baseDir, '/') . '/oxygen/css';
    if (!is_dir($oxygenCssDir)) {
        return [];
    }

    $candidateFiles = [
        sprintf('post-%d.css', $postId),
        sprintf('post-%d-defaults.css', $postId),
        'global-settings.css',
        'presets.css',
        'variables.css',
        'selectors.css',
        'oxy-selectors.css',
        'elements.css',
    ];

    $urls = [];
    foreach ($candidateFiles as $filename) {
        $filename = trim((string) $filename);
        if ($filename === '' || strpos($filename, '..') !== false) {
            continue;
        }

        $absolutePath = $oxygenCssDir . '/' . $filename;
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            continue;
        }

        $urls[rtrim($baseUrl, '/') . '/oxygen/css/' . $filename] = true;
    }

    return array_keys($urls);
}

function extractUploadsFontStylesheetUrls(): array
{
    if (!function_exists('wp_upload_dir')) {
        return [];
    }

    $uploads = wp_upload_dir();
    $baseDir = is_array($uploads) ? trim((string) ($uploads['basedir'] ?? '')) : '';
    $baseUrl = is_array($uploads) ? trim((string) ($uploads['baseurl'] ?? '')) : '';

    if ($baseDir === '' || $baseUrl === '') {
        return [];
    }

    $urls = [];

    // Common font-CSS file patterns in uploads (Oxygen/Builder custom fonts)
    $candidatePatterns = [
        '/fonts/*.css',
        '/font/*.css',
        '/custom-fonts/*.css',
        '/*-fonts.css',
        '/*font*.css',
    ];

    foreach ($candidatePatterns as $pattern) {
        $files = glob($baseDir . $pattern, GLOB_NOSORT);
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file) || !is_readable($file)) {
                continue;
            }

            $filename = basename($file);
            if ($filename === '' || strpos($filename, '..') !== false) {
                continue;
            }

            // Verify CSS content to avoid false positives
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                continue;
            }

            $looksLikeFontCss = strpos($content, '@font-face') !== false
                || strpos($content, 'font-family') !== false
                || preg_match('/\{[^}]*:[^}]*\}/', $content);

            if ($looksLikeFontCss) {
                $relativePath = str_replace('//', '/', str_replace($baseDir, '', $file));
                $urls[rtrim($baseUrl, '/') . $relativePath] = true;
            }
        }
    }

    return array_keys($urls);
}

/**
 * Extract computed-style snapshot from rendered HTML to infer typography properties.
 * When CSS sources are incomplete, this generates synthetic font declarations based on:
 * - Heading levels (h1-h6 imply font-size, font-weight)
 * - Elements with font-related class patterns
 * - Text containers that likely have distinct typography
 */
function extractComputedStyleSnapshot(string $html): ?string
{
    $html = trim($html);
    if ($html === '') {
        return null;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $declarations = [];
    $seenSelectors = [];

    // Heading-based computed typography (implied by semantic level)
    $headingFontSizes = [
        'h1' => '2.5em',
        'h2' => '2em',
        'h3' => '1.75em',
        'h4' => '1.5em',
        'h5' => '1.25em',
        'h6' => '1em',
    ];

    foreach ($headingFontSizes as $tag => $size) {
        $elements = $xpath->query("//{$tag}");
        if ($elements && $elements->length > 0) {
            $selector = "{$tag}";
            if (!isset($seenSelectors[$selector])) {
                $seenSelectors[$selector] = true;
                $declarations[] = "{$selector} { font-size: {$size}; font-weight: bold; }";
            }
        }
    }

    // Class-based font inference (common builder font classes)
    $fontClassPatterns = [
        '/\b(font-sans|font-serif|font-mono)\b/i' => 'font-family',
        '/\b(text-xs|text-sm|text-base|text-lg|text-xl|text-2xl|text-3xl|text-4xl|text-5xl)\b/i' => 'font-size',
        '/\b(font-light|font-normal|font-medium|font-semibold|font-bold|font-extrabold)\b/i' => 'font-weight',
        '/\b(tracking-tight|tracking-normal|tracking-wide|tracking-wider)\b/i' => 'letter-spacing',
        '/\b(leading-none|leading-tight|leading-normal|leading-relaxed)\b/i' => 'line-height',
    ];

    $elementsWithClass = $xpath->query('//*[@class]');
    if ($elementsWithClass) {
        foreach ($elementsWithClass as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $classAttr = trim((string) $el->getAttribute('class'));
            if ($classAttr === '') {
                continue;
            }

            $tag = strtolower($el->tagName);
            foreach ($fontClassPatterns as $pattern => $property) {
                if (!preg_match($pattern, $classAttr, $matches)) {
                    continue;
                }

                $classToken = $matches[1];
                $selector = "{$tag}.{$classToken}";

                if (isset($seenSelectors[$selector])) {
                    continue;
                }
                $seenSelectors[$selector] = true;

                // Map Tailwind-like classes to CSS values
                $value = mapFontClassToCssValue($classToken, $property);
                if ($value !== null) {
                    $declarations[] = "{$selector} { {$property}: {$value}; }";
                }
            }
        }
    }

    // Data-font attributes (some builders store computed font info here)
    $dataFontElements = $xpath->query('//*[@data-font-family or @data-font-size or @data-font-weight]');
    if ($dataFontElements) {
        foreach ($dataFontElements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($el->tagName);
            $props = [];

            $fontFamily = trim((string) $el->getAttribute('data-font-family'));
            if ($fontFamily !== '') {
                $props[] = "font-family: {$fontFamily}";
            }

            $fontSize = trim((string) $el->getAttribute('data-font-size'));
            if ($fontSize !== '') {
                $props[] = "font-size: {$fontSize}";
            }

            $fontWeight = trim((string) $el->getAttribute('data-font-weight'));
            if ($fontWeight !== '') {
                $props[] = "font-weight: {$fontWeight}";
            }

            if ($props) {
                $selector = "{$tag}[data-font-family]";
                if (!isset($seenSelectors[$selector])) {
                    $seenSelectors[$selector] = true;
                    $declarations[] = "{$selector} { " . implode('; ', $props) . "; }";
                }
            }
        }
    }

    // Inline style attribute extraction for font-related declarations
    $inlineStyleElements = $xpath->query('//*[@style]');
    if ($inlineStyleElements) {
        $fontProps = ['font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing'];
        foreach ($inlineStyleElements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $styleAttr = trim((string) $el->getAttribute('style'));
            if ($styleAttr === '') {
                continue;
            }

            // Parse inline styles into key-value pairs
            $props = [];
            $pairs = preg_split('/;\s*/', $styleAttr, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($pairs as $pair) {
                if (!str_contains($pair, ':')) {
                    continue;
                }
                [$prop, $value] = explode(':', $pair, 2);
                $prop = trim(strtolower($prop));
                $value = trim($value);
                if (in_array($prop, $fontProps, true) && $value !== '') {
                    $props[] = "{$prop}: {$value}";
                }
            }

            if ($props) {
                $tag = strtolower($el->tagName);
                // Use class-based selector if available for specificity
                $classAttr = trim((string) $el->getAttribute('class'));
                $firstClass = '';
                if ($classAttr !== '') {
                    $classes = preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY);
                    $firstClass = $classes[0] ?? '';
                }

                if ($firstClass !== '') {
                    $selector = "{$tag}.{$firstClass}";
                } else {
                    $selector = "{$tag}";
                }

                if (!isset($seenSelectors[$selector])) {
                    $seenSelectors[$selector] = true;
                    $declarations[] = "{$selector} { " . implode('; ', $props) . "; }";
                }
            }
        }
    }

    if (!$declarations) {
        return null;
    }

    return implode("\n", $declarations);
}

/**
 * Extract CSS custom properties (variables) with font-related values from HTML.
 * Captures --font-* variables and generates synthetic font-family declarations
 * to improve typography parity classification when source uses CSS variables.
 */
function extractCssCustomPropertyFonts(string $html): ?string
{
    $html = trim($html);
    if ($html === '') {
        return null;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $declarations = [];
    $seenVars = [];

    // Extract from inline style attributes on elements
    $inlineStyleElements = $xpath->query('//*[@style]');
    if ($inlineStyleElements) {
        foreach ($inlineStyleElements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $styleAttr = trim((string) $el->getAttribute('style'));
            if ($styleAttr === '') {
                continue;
            }

            // Parse custom property definitions (--var: value) and usages
            if (preg_match_all('/--([a-zA-Z0-9_-]+)\s*:\s*([^;]+)/', $styleAttr, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $varName = $match[1];
                    $varValue = trim($match[2]);

                    // Only capture font-related custom properties
                    if (!preg_match('/^font/i', $varName) && !preg_match('/font/i', $varValue)) {
                        continue;
                    }

                    if (isset($seenVars[$varName])) {
                        continue;
                    }
                    $seenVars[$varName] = true;

                    // Generate synthetic font-family declaration for classification
                    if (preg_match('/font-family\s*:\s*([^;]+)/i', $varValue, $fontMatch) ||
                        preg_match("~^['\"]?[A-Za-z\s]+['\"]?$~", $varValue)) {
                        $fontValue = trim($fontMatch[1] ?? $varValue);
                        $declarations[] = ".var-{$varName} { font-family: {$fontValue}; }";
                    }
                }
            }
        }
    }

    // Extract from style tags
    $styleTags = $xpath->query('//style');
    if ($styleTags) {
        foreach ($styleTags as $styleEl) {
            if (!($styleEl instanceof DOMElement)) {
                continue;
            }

            $css = trim((string) $styleEl->textContent);
            if ($css === '') {
                continue;
            }

            // Extract @import statements for fonts
            if (preg_match_all('/@import\s+(?:url\()?["\']?([^"\'\);\s]+)["\']?\)?[^;]*;/i', $css, $importMatches, PREG_SET_ORDER)) {
                foreach ($importMatches as $import) {
                    $importUrl = $import[1];
                    // Only font-related imports (Google Fonts, etc.)
                    if (preg_match('/font|googleapis|gstatic|typekit|fonts\.css/i', $importUrl)) {
                        // Fetch and include the imported CSS
                        $importedCss = fetchStylesheetCss($importUrl, 'import-font');
                        if ($importedCss !== null && trim($importedCss) !== '') {
                            $declarations[] = "/* @import: {$importUrl} */";
                            // Extract font-family declarations from imported CSS
                            if (preg_match_all('/font-family\s*:\s*([^;]+);/i', $importedCss, $fontMatches, PREG_SET_ORDER)) {
                                foreach ($fontMatches as $fontMatch) {
                                    $fontValue = trim($fontMatch[1]);
                                    $declarations[] = ".imported-font { font-family: {$fontValue}; }";
                                }
                            }
                        }
                    }
                }
            }

            // Extract CSS custom property definitions with font values
            if (preg_match_all('/--([a-zA-Z0-9_-]+)\s*:\s*([^;{}]+)[;}]?/', $css, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $varName = $match[1];
                    $varValue = trim($match[2]);

                    // Only capture font-related custom properties
                    if (!preg_match('/^font/i', $varName) && !preg_match('/font/i', $varValue)) {
                        continue;
                    }

                    if (isset($seenVars[$varName])) {
                        continue;
                    }
                    $seenVars[$varName] = true;

                    // Generate synthetic font-family declaration
                    $fontValue = preg_replace('/var\([^)]+\)/', '', $varValue);
                    $fontValue = trim($fontValue, "'\" \t,");
                    if ($fontValue !== '' && $fontValue !== ';') {
                        $declarations[] = ".var-{$varName} { font-family: {$fontValue}; }";
                    }
                }
            }
        }
    }

    if (!$declarations) {
        return null;
    }

    return implode("\n", $declarations);
}

/**
 * Extract font declarations from link[rel="preconnect"] hints and Google Fonts link tags.
 * Captures external font resources referenced via <link> elements that may not be
 * covered by inline styles or @import statements.
 */
function extractLinkTagFonts(string $html): ?string
{
    $html = trim($html);
    if ($html === '') {
        return null;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $declarations = [];
    $seenFonts = [];

    $links = $xpath->query('//link[@href]');
    if (!$links) {
        return null;
    }

    foreach ($links as $linkEl) {
        if (!($linkEl instanceof DOMElement)) {
            continue;
        }

        $href = trim((string) $linkEl->getAttribute('href'));
        if ($href === '') {
            continue;
        }

        $rel = strtolower(trim((string) $linkEl->getAttribute('rel')));

        // Capture preconnect hints to font services (indicates font loading)
        if ($rel === 'preconnect' || $rel === 'dns-prefetch') {
            if (preg_match('/fonts\.googleapis\.com|fonts\.gstatic\.com|typekit|use\.typekit|font|typography/i', $href)) {
                $declarations[] = "/* preconnect: {$href} */";
            }
            continue;
        }

        // Capture Google Fonts and other font service links
        $isStylesheet = strpos($rel, 'stylesheet') !== false;
        $isFontLink = preg_match('/fonts\.googleapis\.com|fonts\.gstatic\.com|typekit|use\.typekit/i', $href);
        $hasFontQuery = preg_match('/family=|font/i', $href);

        if (!$isStylesheet && !$isFontLink && !$hasFontQuery) {
            continue;
        }

        // For Google Fonts API URLs, extract font families from query string
        if (strpos($href, 'fonts.googleapis.com/css') !== false || strpos($href, 'fonts.googleapis.com/css2') !== false) {
            $declarations[] = "/* google-fonts: {$href} */";

            // Parse family parameter to extract font names
            if (preg_match_all('/family=([^&]+)/', $href, $familyMatches, PREG_SET_ORDER)) {
                foreach ($familyMatches as $match) {
                    $familySpec = urldecode($match[1]);
                    // Handle multiple fonts separated by |
                    $fonts = explode('|', $familySpec);
                    foreach ($fonts as $font) {
                        // Extract font name before : (weight/style spec)
                        $fontName = trim(explode(':', $font)[0]);
                        $fontName = str_replace('+', ' ', $fontName);
                        if ($fontName !== '' && !isset($seenFonts[$fontName])) {
                            $seenFonts[$fontName] = true;
                            $declarations[] = ".google-font-" . sanitizeFontClassName($fontName) . " { font-family: '{$fontName}', sans-serif; }";
                        }
                    }
                }
            }

            // Fetch and extract actual CSS font-family declarations
            $fontCss = fetchStylesheetCss($href, 'google-fonts-link');
            if ($fontCss !== null && trim($fontCss) !== '') {
                if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"]+)[\'"]?/i', $fontCss, $cssMatches, PREG_SET_ORDER)) {
                    foreach ($cssMatches as $cssMatch) {
                        $fontValue = trim($cssMatch[1]);
                        if ($fontValue !== '' && !isset($seenFonts[$fontValue])) {
                            $seenFonts[$fontValue] = true;
                            $declarations[] = ".linked-font { font-family: {$fontValue}; }";
                        }
                    }
                }
            }
            continue;
        }

        // For Typekit and other font service links
        if ($isFontLink || (preg_match('/\.css([?#].*)?$/i', $href) && $hasFontQuery)) {
            $declarations[] = "/* font-service: {$href} */";

            $fontCss = fetchStylesheetCss($href, 'font-service-link');
            if ($fontCss !== null && trim($fontCss) !== '') {
                if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"]+)[\'"]?/i', $fontCss, $cssMatches, PREG_SET_ORDER)) {
                    foreach ($cssMatches as $cssMatch) {
                        $fontValue = trim($cssMatch[1]);
                        if ($fontValue !== '' && !isset($seenFonts[$fontValue])) {
                            $seenFonts[$fontValue] = true;
                            $declarations[] = ".service-font { font-family: {$fontValue}; }";
                        }
                    }
                }
            }
        }
    }

    if (!$declarations) {
        return null;
    }

    return implode("\n", $declarations);
}

/**
 * Sanitize font name for use in CSS class name.
 */
function sanitizeFontClassName(string $fontName): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $fontName));
}

/**
 * Map Tailwind-like font utility classes to CSS values for computed-style snapshot.
 */
function mapFontClassToCssValue(string $class, string $property): ?string
{
    $mappings = [
        'font-family' => [
            'font-sans' => 'system-ui, sans-serif',
            'font-serif' => 'Georgia, serif',
            'font-mono' => 'monospace',
        ],
        'font-size' => [
            'text-xs' => '0.75rem',
            'text-sm' => '0.875rem',
            'text-base' => '1rem',
            'text-lg' => '1.125rem',
            'text-xl' => '1.25rem',
            'text-2xl' => '1.5rem',
            'text-3xl' => '1.875rem',
            'text-4xl' => '2.25rem',
            'text-5xl' => '3rem',
        ],
        'font-weight' => [
            'font-light' => '300',
            'font-normal' => '400',
            'font-medium' => '500',
            'font-semibold' => '600',
            'font-bold' => '700',
            'font-extrabold' => '800',
        ],
        'letter-spacing' => [
            'tracking-tight' => '-0.025em',
            'tracking-normal' => '0',
            'tracking-wide' => '0.025em',
            'tracking-wider' => '0.05em',
        ],
        'line-height' => [
            'leading-none' => '1',
            'leading-tight' => '1.25',
            'leading-normal' => '1.5',
            'leading-relaxed' => '1.625',
        ],
    ];

    return $mappings[$property][$class] ?? null;
}

function compareHtmlVisualParity(string $sourceHtml, string $renderHtml, ?string $styleHtml = null): array
{
    $source = htmlVisualStats($sourceHtml);
    $render = htmlVisualStats($renderHtml);

    // styleHtml may include rendered HTML + injected CSS payload + linked stylesheets.
    // Parse it with full HTML-aware stats (style tags + inline + link[href]) instead of
    // regex-only extraction so layout/effects buckets are not artificially undercounted.
    $renderStyle = (is_string($styleHtml) && trim($styleHtml) !== '')
        // styleHtml may include full frontend page markup; keep parity scoped to
        // inline/style-tag payload here (no global linked theme/plugin CSS inflation).
        ? htmlVisualStats($styleHtml, false)
        : htmlVisualStats($renderHtml);

    // Renderer-side normalization can emit semantic wrappers as classed <div> nodes.
    // Treat strongly-signaled wrappers as structural equivalents for parity scoring.
    $renderSemanticEquivalents = extractSemanticWrapperCountsFromHtml($renderHtml);
    $reclassifiedDivCount = 0;

    foreach ($renderSemanticEquivalents as $tag => $count) {
        $sourceCount = (int) ($source['tagCounts'][$tag] ?? 0);
        $renderCount = (int) ($render['tagCounts'][$tag] ?? 0);

        if ($count <= 0 || $renderCount >= $sourceCount) {
            continue;
        }

        $applied = min($count, $sourceCount - $renderCount);
        if ($applied <= 0) {
            continue;
        }

        $render['tagCounts'][$tag] = $renderCount + $applied;
        $reclassifiedDivCount += $applied;
    }

    if ($reclassifiedDivCount > 0) {
        $render['tagCounts']['div'] = max(0, (int) ($render['tagCounts']['div'] ?? 0) - $reclassifiedDivCount);
    }

    // Frontend renderer can add non-semantic utility wrappers (oxygen-generated div shells).
    // Normalize these out, capped by actual div overage, so parity stays conservative.
    $sourceDivCount = (int) ($source['tagCounts']['div'] ?? 0);
    $renderDivCount = (int) ($render['tagCounts']['div'] ?? 0);
    $divExcess = max(0, $renderDivCount - $sourceDivCount);

    if ($divExcess > 0) {
        $scaffoldWrapperCount = extractFrontendScaffoldWrapperDivCount($renderHtml);
        if ($scaffoldWrapperCount > 0) {
            $render['tagCounts']['div'] = max(0, $renderDivCount - min($divExcess, $scaffoldWrapperCount));
        }
    }

    $allTags = array_values(array_unique(array_merge(array_keys($source['tagCounts']), array_keys($render['tagCounts']))));
    $tagDeltas = [];

    foreach ($allTags as $tag) {
        if (isParityNoiseTag($tag)) {
            continue;
        }

        $src = (int) ($source['tagCounts'][$tag] ?? 0);
        $rnd = (int) ($render['tagCounts'][$tag] ?? 0);
        if ($src === 0 && $rnd === 0) {
            continue;
        }

        $ratio = round(($rnd - $src) / max(1, $src), 3);
        $severity = abs($rnd - $src);
        $tagDeltas[] = [
            'tag' => $tag,
            'source' => $src,
            'rendered' => $rnd,
            'delta' => $rnd - $src,
            'ratio' => $ratio,
            'severity' => $severity,
        ];
    }

    usort($tagDeltas, static function (array $a, array $b): int {
        return $b['severity'] <=> $a['severity'];
    });

    $categories = ['typography', 'spacing', 'color', 'layout', 'effects', 'other'];
    $styleDeltas = [];

    foreach ($categories as $category) {
        $src = (int) ($source['styleCategories'][$category] ?? 0);
        $rnd = (int) ($renderStyle['styleCategories'][$category] ?? 0);

        $styleDeltas[$category] = [
            'source' => $src,
            'rendered' => $rnd,
            'delta' => $rnd - $src,
            'ratio' => round(($rnd - $src) / max(1, $src), 3),
        ];
    }

    return [
        'topStructureDeltas' => array_slice($tagDeltas, 0, 8),
        'styleCategoryDeltas' => $styleDeltas,
        'styleTotals' => [
            'sourceDeclarations' => $source['styleDeclarationCount'],
            'renderedDeclarations' => $renderStyle['styleDeclarationCount'],
            'declarationRatio' => round($renderStyle['styleDeclarationCount'] / max(1, $source['styleDeclarationCount']), 3),
        ],
    ];
}

function htmlVisualStats(string $html, bool $includeLinkedStylesheets = true): array
{
    $tagCounts = [];
    $styleCategories = [
        'typography' => 0,
        'spacing' => 0,
        'color' => 0,
        'layout' => 0,
        'effects' => 0,
        'other' => 0,
    ];
    $styleDeclarationCount = 0;

    // Some render APIs return raw CSS payload (no HTML wrapper).
    // Parse these directly so style parity metrics stay meaningful.
    if (!preg_match('/<\s*[a-z][^>]*>/i', $html) && preg_match('/\{[^}]*:[^}]*\}/', $html)) {
        foreach (extractCssDeclarationProperties($html) as $property) {
            $styleDeclarationCount++;
            $bucket = classifyCssProperty($property);
            $styleCategories[$bucket] = ($styleCategories[$bucket] ?? 0) + 1;
        }

        return [
            'tagCounts' => $tagCounts,
            'styleCategories' => $styleCategories,
            'styleDeclarationCount' => $styleDeclarationCount,
        ];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $elements = $xpath->query('//*');

    if ($elements) {
        foreach ($elements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($el->tagName);

            if ($tag === 'span' && isDecorativeSpanNoise($el)) {
                continue;
            }

            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;

            $inline = trim((string) $el->getAttribute('style'));
            if ($inline !== '') {
                foreach (parseStyleDeclarations($inline) as $property => $_value) {
                    $styleDeclarationCount++;
                    $bucket = classifyCssProperty($property);
                    $styleCategories[$bucket] = ($styleCategories[$bucket] ?? 0) + 1;
                }
            }
        }
    }

    $styleTags = $xpath->query('//style');
    if ($styleTags) {
        foreach ($styleTags as $styleEl) {
            $css = trim((string) $styleEl->textContent);
            if ($css === '') {
                continue;
            }

            foreach (extractCssDeclarationProperties($css) as $property) {
                $styleDeclarationCount++;
                $bucket = classifyCssProperty($property);
                $styleCategories[$bucket] = ($styleCategories[$bucket] ?? 0) + 1;
            }
        }
    }

    // Some builder runtimes inject CSS payloads into inline script blocks
    // (as string blobs consumed by client-side style loaders). Ingest only
    // strongly CSS-looking script content to avoid generic JS object noise.
    foreach (extractEmbeddedScriptStyleProperties($xpath) as $property) {
        $styleDeclarationCount++;
        $bucket = classifyCssProperty($property);
        $styleCategories[$bucket] = ($styleCategories[$bucket] ?? 0) + 1;
    }

    // Include linked stylesheet declarations when we explicitly want global asset coverage.
    if ($includeLinkedStylesheets) {
        foreach (extractLinkedStylesheetProperties($xpath) as $property) {
            $styleDeclarationCount++;
            $bucket = classifyCssProperty($property);
            $styleCategories[$bucket] = ($styleCategories[$bucket] ?? 0) + 1;
        }
    }

    return [
        'tagCounts' => $tagCounts,
        'styleCategories' => $styleCategories,
        'styleDeclarationCount' => $styleDeclarationCount,
    ];
}

function isDecorativeSpanNoise(DOMElement $el): bool
{
    $classAttr = trim((string) $el->getAttribute('class'));
    $idAttr = trim((string) $el->getAttribute('id'));
    $styleAttr = trim((string) $el->getAttribute('style'));

    if ($classAttr !== '' || $idAttr !== '' || $styleAttr !== '') {
        return false;
    }

    $text = html_entity_decode(trim((string) $el->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($text === '') {
        return true;
    }

    // Ignore tiny symbol-only spans used as decorative separators/icons.
    if (mb_strlen($text) <= 2 && !preg_match('/[\p{L}\p{N}]/u', $text)) {
        return true;
    }

    return false;
}

function extractEmbeddedScriptStyleProperties(DOMXPath $xpath): array
{
    $properties = [];
    $scripts = $xpath->query('//script[not(@src)]');

    if (!$scripts) {
        return $properties;
    }

    foreach ($scripts as $scriptEl) {
        if (!($scriptEl instanceof DOMElement)) {
            continue;
        }

        $scriptText = trim((string) $scriptEl->textContent);
        if ($scriptText === '' || strlen($scriptText) > 1000000) {
            continue;
        }

        $normalized = html_entity_decode($scriptText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Unescape common JSON-encoded CSS payloads embedded in scripts.
        $normalized = str_replace([
            '\\n',
            '\\r',
            '\\t',
        ], [
            "\n",
            "\r",
            "\t",
        ], $normalized);

        // Keep this strict: only parse script text that looks like CSS rules,
        // not generic JS objects or app state payloads.
        $looksLikeCssRuleBlock = (bool) preg_match('/[.#][A-Za-z0-9_-][^{}]{0,160}\{[^{}]*:[^{}]*\}/s', $normalized)
            || (bool) preg_match('/(?:^|[}\s])(div|span|a|p|section|article|nav|footer|header|main|h[1-6])(?:[.#\[:][^{}]*)?\s*\{[^{}]*:[^{}]*\}/i', $normalized)
            || (bool) preg_match('/@media\s*\([^\)]*\)\s*\{[\s\S]*?:[\s\S]*?\}/i', $normalized);

        if (!$looksLikeCssRuleBlock) {
            continue;
        }

        foreach (extractCssDeclarationProperties($normalized) as $property) {
            $properties[] = $property;
        }
    }

    return $properties;
}

function extractLinkedStylesheetProperties(DOMXPath $xpath): array
{
    $properties = [];
    $links = $xpath->query('//link[@href]');

    if (!$links) {
        return $properties;
    }

    foreach ($links as $linkEl) {
        if (!($linkEl instanceof DOMElement)) {
            continue;
        }

        $href = trim((string) $linkEl->getAttribute('href'));
        if ($href === '') {
            continue;
        }

        $rel = strtolower(trim((string) $linkEl->getAttribute('rel')));
        $as = strtolower(trim((string) $linkEl->getAttribute('as')));
        $type = strtolower(trim((string) $linkEl->getAttribute('type')));

        $isStylesheetLink = strpos($rel, 'stylesheet') !== false;
        $isPreloadStyle = strpos($rel, 'preload') !== false && $as === 'style';
        $isCssType = strpos($type, 'text/css') !== false;
        $looksLikeCssUrl = (bool) preg_match('/\.css([?#].*)?$/i', $href)
            || strpos($href, '/css') !== false
            || strpos($href, 'css2?') !== false;

        if (!$isStylesheetLink && !$isPreloadStyle && !$isCssType && !$looksLikeCssUrl) {
            continue;
        }

        $css = fetchStylesheetCss($href, 'linked-style');
        if ($css === null || trim($css) === '') {
            continue;
        }

        foreach (extractCssDeclarationProperties($css) as $property) {
            $properties[] = $property;
        }
    }

    return $properties;
}

function fetchStylesheetCss(string $href, string $source = 'generic'): ?string
{
    if (strpos($href, '//') === 0) {
        $href = 'https:' . $href;
    }

    if (!preg_match('#^https?://#i', $href)) {
        $href = home_url('/' . ltrim($href, '/'));
    }

    $parts = wp_parse_url($href);
    $scheme = is_array($parts) ? ($parts['scheme'] ?? 'http') : 'http';
    $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
    $query = (is_array($parts) && isset($parts['query']) && $parts['query'] !== '') ? ('?' . $parts['query']) : '';
    $host = (is_array($parts) && !empty($parts['host'])) ? (string) $parts['host'] : '';
    $port = (is_array($parts) && !empty($parts['port'])) ? (int) $parts['port'] : null;

    $originHostHeader = $host !== ''
        ? ($port ? sprintf('%s:%d', $host, $port) : $host)
        : null;

    $candidates = [
        ['url' => $href, 'hostHeader' => null],
    ];

    if ($path !== '') {
        $candidates[] = [
            'url' => sprintf('%s://127.0.0.1%s%s', $scheme, $path, $query),
            'hostHeader' => $originHostHeader,
        ];
        $candidates[] = [
            'url' => sprintf('%s://localhost%s%s', $scheme, $path, $query),
            'hostHeader' => $originHostHeader,
        ];
    }

    $seen = [];

    foreach ($candidates as $candidate) {
        $candidateUrl = trim((string) ($candidate['url'] ?? ''));
        if ($candidateUrl === '' || isset($seen[$candidateUrl])) {
            continue;
        }
        $seen[$candidateUrl] = true;

        $requestArgs = [
            'timeout' => 10,
            'redirection' => 0,
            // Local Docker parity runs use self-signed HTTPS on localhost/127.0.0.1.
            'sslverify' => false,
        ];

        $hostHeader = $candidate['hostHeader'] ?? null;
        if (is_string($hostHeader) && trim($hostHeader) !== '') {
            $requestArgs['headers'] = ['Host' => $hostHeader];
        }

        $response = wp_remote_get($candidateUrl, $requestArgs);
        if (is_wp_error($response)) {
            logStylesheetFetchAttempt($source, $href, $candidateUrl, null, null, 0, 'wp_error', $response->get_error_message());
            continue;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);
        $bodyTrimmed = trim($body);
        $bytes = strlen($body);

        $looksLikeCss = $bodyTrimmed !== ''
            && strpos($bodyTrimmed, '<html') === false
            && (
                strpos($contentType, 'text/css') !== false
                || strpos($contentType, 'css') !== false
                || preg_match('/\{[^}]*:[^}]*\}/', $bodyTrimmed)
                || strpos($bodyTrimmed, '@media') !== false
                || strpos($bodyTrimmed, '@import') !== false
            );

        logStylesheetFetchAttempt(
            $source,
            $href,
            $candidateUrl,
            $status,
            $contentType,
            $bytes,
            $looksLikeCss ? 'accepted' : 'rejected-non-css'
        );

        if ($status >= 200 && $status < 400 && $looksLikeCss) {
            return $body;
        }
    }

    $localPath = resolveLocalStylesheetPathFromUrl($href);
    if ($localPath !== null) {
        $fileCss = @file_get_contents($localPath);
        $fileCss = is_string($fileCss) ? $fileCss : '';
        $trimmed = trim($fileCss);
        $bytes = strlen($fileCss);

        $looksLikeCss = $trimmed !== ''
            && strpos($trimmed, '<html') === false
            && (
                preg_match('/\{[^}]*:[^}]*\}/', $trimmed)
                || strpos($trimmed, '@media') !== false
                || strpos($trimmed, '@import') !== false
                || strpos($trimmed, ':root') !== false
            );

        logStylesheetFetchAttempt(
            $source,
            $href,
            'file://' . $localPath,
            $looksLikeCss ? 200 : null,
            'text/css',
            $bytes,
            $looksLikeCss ? 'accepted-local-file' : 'rejected-local-non-css'
        );

        if ($looksLikeCss) {
            return $fileCss;
        }
    } else {
        logStylesheetFetchAttempt($source, $href, 'file://(unresolved)', null, null, 0, 'local-path-unresolved');
    }

    return null;
}

function resolveLocalStylesheetPathFromUrl(string $href): ?string
{
    $parts = wp_parse_url($href);
    if (!is_array($parts)) {
        return null;
    }

    $path = rawurldecode((string) ($parts['path'] ?? ''));
    if ($path === '' || strpos($path, '..') !== false) {
        return null;
    }

    $candidates = [];

    if (defined('ABSPATH')) {
        $candidates[] = rtrim((string) ABSPATH, '/') . '/' . ltrim($path, '/');
    }

    if (defined('WP_CONTENT_DIR')) {
        $contentPath = '/wp-content/';
        $contentPos = strpos($path, $contentPath);
        if ($contentPos !== false) {
            $suffix = substr($path, $contentPos + strlen($contentPath));
            $candidates[] = rtrim((string) WP_CONTENT_DIR, '/') . '/' . ltrim((string) $suffix, '/');
        }
    }

    if (defined('WPINC')) {
        $includesPath = '/wp-includes/';
        $includesPos = strpos($path, $includesPath);
        if ($includesPos !== false && defined('ABSPATH')) {
            $suffix = substr($path, $includesPos + strlen($includesPath));
            $candidates[] = rtrim((string) ABSPATH, '/') . '/' . trim((string) WPINC, '/') . '/' . ltrim((string) $suffix, '/');
        }
    }

    $allowedRoots = [];
    if (defined('ABSPATH')) {
        $allowedRoots[] = rtrim((string) ABSPATH, '/');
    }
    if (defined('WP_CONTENT_DIR')) {
        $allowedRoots[] = rtrim((string) WP_CONTENT_DIR, '/');
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $real = realpath($candidate);
        if (!is_string($real) || $real === '' || !is_file($real) || !is_readable($real)) {
            continue;
        }

        $realNormalized = str_replace('\\', '/', $real);
        $allowed = false;

        foreach ($allowedRoots as $root) {
            $rootNormalized = str_replace('\\', '/', (string) $root);
            if ($rootNormalized !== '' && strpos($realNormalized, $rootNormalized . '/') === 0) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            continue;
        }

        if (strtolower(substr($realNormalized, -4)) !== '.css') {
            continue;
        }

        return $real;
    }

    return null;
}

function logStylesheetFetchAttempt(
    string $source,
    string $requestedUrl,
    string $candidateUrl,
    ?int $status,
    ?string $contentType,
    int $bytes,
    string $result,
    ?string $error = null
): void {
    if (!isset($GLOBALS['oxyParityStylesheetFetchLog']) || !is_array($GLOBALS['oxyParityStylesheetFetchLog'])) {
        $GLOBALS['oxyParityStylesheetFetchLog'] = [];
    }

    $GLOBALS['oxyParityStylesheetFetchLog'][] = [
        'source' => $source,
        'requestedUrl' => $requestedUrl,
        'candidateUrl' => $candidateUrl,
        'status' => $status,
        'contentType' => $contentType,
        'bytes' => $bytes,
        'result' => $result,
        'error' => $error,
    ];
}

function summarizeStylesheetFetchLog(): array
{
    $entries = is_array($GLOBALS['oxyParityStylesheetFetchLog'] ?? null)
        ? $GLOBALS['oxyParityStylesheetFetchLog']
        : [];

    if (!$entries) {
        return [
            'attempted' => 0,
            'accepted' => 0,
            'failed' => 0,
            'sources' => [],
            'topFailures' => [],
        ];
    }

    $accepted = 0;
    $failed = 0;
    $sources = [];
    $failures = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $source = is_string($entry['source'] ?? null) ? $entry['source'] : 'unknown';
        $sources[$source] = ($sources[$source] ?? 0) + 1;

        $result = is_string($entry['result'] ?? null) ? $entry['result'] : '';
        if (strpos($result, 'accepted') === 0) {
            $accepted++;
        } else {
            $failed++;

            $failures[] = [
                'source' => $source,
                'status' => $entry['status'] ?? null,
                'bytes' => $entry['bytes'] ?? 0,
                'result' => $result,
                'candidateUrl' => $entry['candidateUrl'] ?? null,
            ];
        }
    }

    return [
        'attempted' => count($entries),
        'accepted' => $accepted,
        'failed' => $failed,
        'sources' => $sources,
        'topFailures' => array_slice($failures, 0, 6),
    ];
}

function extractSemanticWrapperCountsFromHtml(string $html): array
{
    $counts = [
        'nav' => 0,
        'footer' => 0,
    ];

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $divs = $xpath->query('//div[@class]');

    if (!$divs) {
        return $counts;
    }

    foreach ($divs as $div) {
        if (!($div instanceof DOMElement)) {
            continue;
        }

        $classAttr = trim((string) $div->getAttribute('class'));
        if ($classAttr === '') {
            continue;
        }

        $tokens = preg_split('/\s+/', strtolower($classAttr)) ?: [];
        $isNav = false;
        $isFooter = false;

        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }

            if (!$isNav && preg_match('/^(nav|navigation)(-|$)/', $token)) {
                $isNav = true;
            }

            if (!$isFooter && preg_match('/^footer(-|$)/', $token)) {
                $isFooter = true;
            }

            if ($isNav && $isFooter) {
                break;
            }
        }

        if ($isNav) {
            $counts['nav']++;
        }

        if ($isFooter) {
            $counts['footer']++;
        }
    }

    return $counts;
}

function extractFrontendScaffoldWrapperDivCount(string $html): int
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $divs = $xpath->query('//div[@class]');

    if (!$divs) {
        return 0;
    }

    $count = 0;

    foreach ($divs as $div) {
        if (!($div instanceof DOMElement)) {
            continue;
        }

        $classAttr = trim((string) $div->getAttribute('class'));
        if ($classAttr === '') {
            continue;
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', strtolower($classAttr)) ?: []));
        if (!$tokens) {
            continue;
        }

        $hasCoreUtilityToken = false;
        $hasScopedUtilityToken = false;
        $hasOnlySystemTokens = true;

        foreach ($tokens as $token) {
            if (preg_match('/^oxy-(container|html-code|text)$/', $token)) {
                $hasCoreUtilityToken = true;
                continue;
            }

            if (preg_match('/^oxy-(container|html-code|text)-\d+-\d+$/', $token)) {
                $hasScopedUtilityToken = true;
                continue;
            }

            if ($token === 'ct-div-block') {
                continue;
            }

            $hasOnlySystemTokens = false;
        }

        if (!$hasCoreUtilityToken) {
            continue;
        }

        $textContent = trim((string) $div->textContent);
        $elementChildCount = 0;

        foreach ($div->childNodes as $childNode) {
            if ($childNode instanceof DOMElement) {
                $elementChildCount++;
            }
        }

        $isHtmlCodeWrapper = in_array('oxy-html-code', $tokens, true);
        $isLightweightShell = $textContent === '' && $elementChildCount <= 1;
        $isScopedUtilityWrapper = $hasScopedUtilityToken && ($isHtmlCodeWrapper || in_array('oxy-container', $tokens, true));

        if (($hasOnlySystemTokens && ($isHtmlCodeWrapper || $isLightweightShell)) || $isScopedUtilityWrapper) {
            $count++;
        }
    }

    return $count;
}

function isParityNoiseTag(string $tag): bool
{
    static $noiseTags = [
        'html' => true,
        'head' => true,
        'meta' => true,
        'title' => true,
        'style' => true,
        'script' => true,
        'link' => true,
        'noscript' => true,
    ];

    $tag = strtolower(trim($tag));
    return isset($noiseTags[$tag]);
}

function parseStyleDeclarations(string $style): array
{
    $pairs = [];

    foreach (explode(';', $style) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '' || strpos($chunk, ':') === false) {
            continue;
        }

        [$property, $value] = array_map('trim', explode(':', $chunk, 2));
        if ($property === '') {
            continue;
        }

        $pairs[strtolower($property)] = $value;
    }

    return $pairs;
}

function extractCssDeclarationProperties(string $css): array
{
    $properties = [];

    // Support kebab-case, vendor prefixes and custom properties with digits/underscores.
    if (preg_match_all('/([a-zA-Z_\-][a-zA-Z0-9_\-]*)\s*:\s*([^;{}]+);?/', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $property = strtolower(trim((string) ($match[1] ?? '')));
            if ($property === '') {
                continue;
            }

            // Ignore custom properties (`--token`) in parity buckets.
            // They are design-token definitions (often huge framework bundles),
            // not direct rendered declarations, and can heavily skew category deltas.
            if (strpos($property, '--') === 0) {
                continue;
            }

            $properties[] = $property;
        }
    }

    return $properties;
}

function classifyCssProperty(string $property): string
{
    $property = strtolower(trim($property));
    $normalized = ltrim(str_replace('_', '-', $property), '-');

    foreach (['font', 'text', 'line-height', 'letter-spacing', 'word-spacing', 'white-space'] as $prefix) {
        if (strpos($property, $prefix) === 0 || strpos($normalized, $prefix) === 0) {
            return 'typography';
        }
    }

    foreach (['margin', 'padding', 'gap', 'row-gap', 'column-gap'] as $prefix) {
        if (strpos($property, $prefix) === 0 || strpos($normalized, $prefix) === 0) {
            return 'spacing';
        }
    }

    foreach (['color', 'background', 'fill', 'stroke', 'opacity'] as $prefix) {
        if (strpos($property, $prefix) === 0 || strpos($normalized, $prefix) === 0) {
            return 'color';
        }
    }

    foreach (['display', 'position', 'top', 'right', 'bottom', 'left', 'width', 'height', 'min-', 'max-', 'flex', 'grid', 'align', 'justify', 'order', 'overflow', 'z-index', 'inset', 'aspect-ratio', 'object-fit', 'object-position'] as $prefix) {
        if (strpos($property, $prefix) === 0 || strpos($normalized, $prefix) === 0) {
            return 'layout';
        }
    }

    foreach (['border', 'box-shadow', 'filter', 'transform', 'transition', 'animation', 'outline', 'backdrop', 'mask', 'clip-path', 'mix-blend-mode'] as $prefix) {
        if (strpos($property, $prefix) === 0 || strpos($normalized, $prefix) === 0) {
            return 'effects';
        }
    }

    if (preg_match('/(^|[-])(margin|padding|gap|space)(-|$)/', $normalized)) {
        return 'spacing';
    }

    if (preg_match('/(^|[-])(color|background|fill|stroke|opacity|gradient)(-|$)/', $normalized)) {
        return 'color';
    }

    if (preg_match('/(^|[-])(display|position|width|height|flex|grid|align|justify|order|overflow|z-index|inset|aspect|object|layout)(-|$)/', $normalized)) {
        return 'layout';
    }

    if (preg_match('/(^|[-])(border|shadow|filter|transform|transition|animation|outline|backdrop|mask|clip|blend)(-|$)/', $normalized)) {
        return 'effects';
    }

    return 'other';
}
