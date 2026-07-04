<?php

declare(strict_types=1);

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\GlobalStyleRepository;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\OxygenGlobalSettingsRepository;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\OxygenSelectorRepository;
use OxyHtmlConverter\Services\OxygenVariableRepository;
use OxyHtmlConverter\Services\PageStyleRepository;
use OxyHtmlConverter\Validation\OutputValidator;
use OxyHtmlConverter\TreeBuilder;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'oxyconvo6.localhost';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once '/var/www/html/wp-load.php';

$pluginDir = '/var/www/html/wp-content/plugins/oxygen-html-converter';
if (file_exists($pluginDir . '/src/polyfills.php')) {
    require_once $pluginDir . '/src/polyfills.php';
}

spl_autoload_register(static function (string $class) use ($pluginDir): void {
    $prefix = 'OxyHtmlConverter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $pluginDir . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

if (!defined('OHC_MAXIMUS_BUILD_LIB_ONLY')) {
    $options = parseCliOptions(array_slice($argv, 1));
    $fixtureRoot = is_string($options['fixtures'] ?? null) && trim((string) $options['fixtures']) !== ''
        ? rtrim((string) $options['fixtures'], '/')
        : '/tmp/ohc-maximus-fixtures';
    $remoteReportPath = is_string($options['report'] ?? null) && trim((string) $options['report']) !== ''
        ? (string) $options['report']
        : '/tmp/ohc-maximus-site-build-report.json';
    $shouldReset = !empty($options['reset']);

    try {
    if (!is_dir($fixtureRoot)) {
        throw new RuntimeException('Fixture root not found: ' . $fixtureRoot);
    }

    update_option('oxy_html_converter_class_mode', 'native');
    update_option('oxy_html_converter_element_mapping_mode', 'oxygen');

    if ($shouldReset) {
        resetLocalMaximusSite();
    }

    seedMaximusBrandSystem();

    $fixtures = maximusFixtureDefinitions($fixtureRoot);
    $links = buildInitialLinkMap($fixtures);
    $pageImporter = new OxygenPageImporter();
    $pageResults = [];

    foreach ($fixtures as $key => $fixture) {
        $sourceHtml = readFixtureHtml($fixture['path']);
        $pageHtml = rewriteHtmlLinks($sourceHtml, $links);

        if (empty($fixture['keepsOwnShell'])) {
            $pageHtml = stripDocumentShell($pageHtml);
        }

        $payload = buildConversionPayload($pageHtml, $fixture['title'], $fixture['slug'], 'page');
        $payload['replaceExisting'] = true;
        $payload['postStatus'] = 'publish';
        $payload['sourceHash'] = sha1($sourceHtml);
        $payload = routeCanvasCssOutOfTree($payload, 'page');
        $payload = enrichWithMaximusBrandPayload($payload);

        $result = $pageImporter->import($payload);
        if (empty($result['success'])) {
            throw new RuntimeException('Page import failed for ' . $fixture['slug'] . ': ' . (string) ($result['message'] ?? 'unknown'));
        }

        $pageResults[$key] = [
            'postId' => (int) $result['postId'],
            'title' => (string) $result['title'],
            'slug' => (string) $result['slug'],
            'permalink' => (string) $result['permalink'],
            'selectorPersistence' => $result['selectorPersistence'] ?? [],
            'pageStylePersistence' => $result['pageStylePersistence'] ?? [],
            'variablePersistence' => $result['variablePersistence'] ?? [],
            'oxygenGlobalSettingsPersistence' => $result['oxygenGlobalSettingsPersistence'] ?? [],
            'nativeCoverage' => $payload['importPlan']['nativeCoverage'] ?? [],
        ];

        if (is_string($result['permalink'] ?? null) && $result['permalink'] !== '') {
            $links[$key] = (string) $result['permalink'];
        }
    }

    $homeHtml = readFixtureHtml($fixtures['home']['path']);
    $headerFragment = rewriteHtmlLinks(extractFirstLayoutFragment($homeHtml), $links);
    $footerFragment = rewriteHtmlLinks(extractLastTagFragment($homeHtml, 'footer'), $links);
    $homeShell = makeDocumentFromFragment($homeHtml, $headerFragment);
    $footerShell = makeDocumentFromFragment($homeHtml, $footerFragment);
    $diagnosisPostId = (int) ($pageResults['diagnosis']['postId'] ?? 0);

    $headerResult = importOxygenDocumentPost(
        'oxygen_header',
        'Maximus Site Header',
        'maximus-site-header',
        $homeShell,
        buildEverywhereExceptPostSettings($diagnosisPostId, 30),
        'page'
    );
    $footerResult = importOxygenDocumentPost(
        'oxygen_footer',
        'Maximus Site Footer',
        'maximus-site-footer',
        $footerShell,
        buildEverywhereExceptPostSettings($diagnosisPostId, 20),
        'page'
    );
    $templateResult = createTemplateContentAreaTemplate();

    $componentResults = [];
    $componentResults[] = importOxygenDocumentPost(
        'oxygen_block',
        'Maximus Header Component',
        'maximus-header-component',
        $homeShell,
        null,
        'page'
    );
    $componentResults[] = importOxygenDocumentPost(
        'oxygen_block',
        'Maximus Footer Component',
        'maximus-footer-component',
        $footerShell,
        null,
        'page'
    );

    $ctaFragment = extractAnchorByText($homeHtml, 'Umow diagnoze startowa');
    if ($ctaFragment !== '') {
        $componentResults[] = importOxygenDocumentPost(
            'oxygen_block',
            'Maximus Primary CTA',
            'maximus-primary-cta',
            makeDocumentFromFragment($homeHtml, rewriteHtmlLinks($ctaFragment, $links)),
            null,
            'page'
        );
    }

    $cardFragment = extractFirstElementByClassTokens($homeHtml, ['bg-ivory-base', 'p-10', 'border']);
    if ($cardFragment !== '') {
        $componentResults[] = importOxygenDocumentPost(
            'oxygen_block',
            'Maximus Program Card',
            'maximus-program-card',
            makeDocumentFromFragment($homeHtml, rewriteHtmlLinks($cardFragment, $links)),
            null,
            'page'
        );
    }

    $sectionComponentResults = importMaximusSectionComponents($fixtures, $links);
    $componentResults = array_merge($componentResults, $sectionComponentResults);
    $componentizationResults = componentizeImportedPages($pageResults, $sectionComponentResults);

    configureWordPressSite($pageResults, $links);

    $counts = collectSiteCounts();
    $report = [
        'ok' => true,
        'generatedAt' => gmdate('c'),
        'fixtureRoot' => $fixtureRoot,
        'reset' => $shouldReset,
        'site' => [
            'homeUrl' => home_url('/'),
            'frontPageId' => (int) get_option('page_on_front'),
            'frontPageUrl' => isset($pageResults['home']['postId']) ? get_permalink((int) $pageResults['home']['postId']) : '',
        ],
        'pages' => $pageResults,
        'layout' => [
            'header' => $headerResult,
            'footer' => $footerResult,
            'template' => $templateResult,
        ],
        'components' => $componentResults,
        'componentization' => $componentizationResults,
        'counts' => $counts,
        'globals' => collectGlobalStateSummary(),
        'designSystem' => collectMaximusDesignSystemSummary($componentResults),
        'acceptance' => buildAcceptanceSummary($pageResults, $headerResult, $footerResult, $templateResult, $componentResults, $componentizationResults),
    ];

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode report JSON.');
    }

    file_put_contents($remoteReportPath, $encoded);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo $encoded . PHP_EOL;
    exit(0);
    } catch (Throwable $throwable) {
        $error = [
            'ok' => false,
            'generatedAt' => gmdate('c'),
            'error' => $throwable->getMessage(),
            'trace' => array_slice(explode("\n", $throwable->getTraceAsString()), 0, 12),
        ];
        $encoded = json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            @file_put_contents($remoteReportPath, $encoded);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        fwrite(STDERR, (is_string($encoded) ? $encoded : $throwable->getMessage()) . PHP_EOL);
        exit(1);
    }
}

/**
 * @param list<string> $args
 * @return array<string, mixed>
 */
function parseCliOptions(array $args): array
{
    $options = [];

    foreach ($args as $index => $arg) {
        if ($index === 0 && is_string($arg) && $arg !== '' && str_starts_with($arg, '/') && !str_starts_with($arg, '--')) {
            $options['fixtures'] = $arg;
            continue;
        }

        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (!str_contains($arg, '=')) {
            $options[$arg] = true;
            continue;
        }

        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    }

    return $options;
}

function resetLocalMaximusSite(): void
{
    $postTypes = ['page', 'post', 'oxygen_header', 'oxygen_footer', 'oxygen_template', 'oxygen_block'];
    foreach ($postTypes as $postType) {
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($posts as $postId) {
            wp_delete_post((int) $postId, true);
        }
    }

    foreach (wp_get_nav_menus() as $menu) {
        if (isset($menu->term_id)) {
            wp_delete_nav_menu((int) $menu->term_id);
        }
    }

    update_option(GlobalStyleRepository::OPTION_NAME, wp_json_encode(['version' => 1, 'updatedAt' => gmdate('c'), 'styles' => []]));
    update_option('show_on_front', 'posts');
    update_option('page_on_front', 0);

    if (function_exists('\Breakdance\Data\set_global_option')) {
        \Breakdance\Data\set_global_option('oxy_selectors_json_string', []);
        \Breakdance\Data\set_global_option('oxy_selectors_collections_json_string', []);
        \Breakdance\Data\set_global_option('breakdance_classes_json_string', '[]');
        \Breakdance\Data\set_global_option('variables_json_string', []);
        \Breakdance\Data\set_global_option('variables_collections_json_string', []);
        \Breakdance\Data\set_global_option('global_settings_json_string', wp_json_encode(['settings' => []]));
        \Breakdance\Data\set_global_option('global_css_cache', []);
        \Breakdance\Data\set_global_option('dependency_cache', []);
        \Breakdance\Data\set_global_option('is_theme_disabled', 'yes');
    }
}

function seedMaximusBrandSystem(): void
{
    $payload = enrichWithMaximusBrandPayload([
        'designDocument' => ['tokens' => []],
        'oxygenGlobalSettings' => ['settings' => []],
    ]);

    (new OxygenVariableRepository())->saveFromPayload($payload);
    (new OxygenGlobalSettingsRepository())->saveFromPayload($payload);
    (new OxygenSelectorRepository())->savePayload([
        'selectors' => maximusDesignSystemSelectors(),
        'collections' => [maximusDesignSystemCollectionName()],
    ]);
    pruneMaximusGeneratedGlobalStyles([
        '/* Maximus base site-kit global asset. */',
        '/* Extracted from <style> tag */ .material-symbols-outlined',
        '.maximus-page { font-family: var(--ohc-font-body-main)',
        '.maximus-section { width: 100% !important; display: flex',
        '.ohc-maximus-site-header-native-',
        '.ohc-maximus-site-footer-native-',
    ]);
    (new GlobalStyleRepository())->saveFromPayload([
        'globalCss' => maximusGlobalAssetCss(),
        'styleRouting' => [],
    ]);
}

/**
 * @param list<string> $markers
 * @return array{removed:int,remaining:int}
 */
function pruneMaximusGeneratedGlobalStyles(array $markers): array
{
    $markers = array_values(array_filter(array_map('strval', $markers), static fn (string $marker): bool => trim($marker) !== ''));
    if ($markers === []) {
        return ['removed' => 0, 'remaining' => 0];
    }

    $raw = get_option(GlobalStyleRepository::OPTION_NAME, []);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    $library = is_array($raw) ? $raw : [];
    $styles = is_array($library['styles'] ?? null) ? $library['styles'] : [];
    $kept = [];
    $removed = 0;

    foreach ($styles as $style) {
        $css = is_array($style) && is_string($style['css'] ?? null) ? (string) $style['css'] : '';
        $normalizedCss = preg_replace('/\s+/', ' ', $css) ?? $css;
        $matchesMarker = false;
        foreach ($markers as $marker) {
            $normalizedMarker = preg_replace('/\s+/', ' ', $marker) ?? $marker;
            if ($css !== '' && (str_contains($css, $marker) || str_contains($normalizedCss, $normalizedMarker))) {
                $matchesMarker = true;
                break;
            }
        }

        if ($matchesMarker) {
            $removed++;
            continue;
        }

        $kept[] = $style;
    }

    if ($removed > 0) {
        $library['version'] = 1;
        $library['updatedAt'] = gmdate('c');
        $library['styles'] = $kept;
        update_option(GlobalStyleRepository::OPTION_NAME, wp_json_encode($library));
    }

    return ['removed' => $removed, 'remaining' => count($kept)];
}

/**
 * @return array<string, array{path:string,title:string,slug:string,keepsOwnShell?:bool}>
 */
function maximusFixtureDefinitions(string $fixtureRoot): array
{
    $definitions = [
        'home' => [
            'dir' => 'maximus_transformacja_domu',
            'title' => 'Maximus - Transformacja domu',
            'slug' => 'maximus-transformacja-domu',
        ],
        'for_whom' => [
            'dir' => 'dla_kogo_wybierz_swoj_cie_k',
            'title' => 'Dla kogo - Wybierz swoja sciezke',
            'slug' => 'dla-kogo-wybierz-swoja-sciezke',
        ],
        'offer' => [
            'dir' => 'oferta_i_cie_ki_rozwoju',
            'title' => 'Oferta i sciezki rozwoju',
            'slug' => 'oferta-i-sciezki-rozwoju',
        ],
        'starters' => [
            'dir' => 'startery_i_programy_wej_ciowe',
            'title' => 'Startery i programy wejsciowe',
            'slug' => 'startery-i-programy-wejsciowe',
        ],
        'membership' => [
            'dir' => 'cz_onkostwo_i_us_ugi_premium',
            'title' => 'Czlonkostwo i uslugi premium',
            'slug' => 'czlonkostwo-i-uslugi-premium',
        ],
        'locations' => [
            'dir' => 'lokalizacje_klubu',
            'title' => 'Lokalizacje klubu',
            'slug' => 'lokalizacje-klubu',
        ],
        'diagnosis' => [
            'dir' => 'diagnoza_i_dob_r_programu',
            'title' => 'Diagnoza i dobor programu',
            'slug' => 'diagnoza-i-dobor-programu',
            'keepsOwnShell' => true,
        ],
    ];

    $fixtures = [];
    foreach ($definitions as $key => $definition) {
        $path = $fixtureRoot . '/' . $definition['dir'] . '/code.html';
        if (!file_exists($path)) {
            throw new RuntimeException('Missing Maximus fixture: ' . $path);
        }

        $fixtures[$key] = [
            'path' => $path,
            'title' => $definition['title'],
            'slug' => $definition['slug'],
        ];
        if (!empty($definition['keepsOwnShell'])) {
            $fixtures[$key]['keepsOwnShell'] = true;
        }
    }

    return $fixtures;
}

/**
 * @param array<string, array{slug:string}> $fixtures
 * @return array<string, string>
 */
function buildInitialLinkMap(array $fixtures): array
{
    $links = [];
    foreach ($fixtures as $key => $fixture) {
        $links[$key] = home_url('/' . $fixture['slug'] . '/');
    }

    return $links;
}

function readFixtureHtml(string $path): string
{
    $html = file_get_contents($path);
    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('Unable to read fixture HTML: ' . $path);
    }

    return stabilizeMaximusFixtureMedia($html);
}

function stabilizeMaximusFixtureMedia(string $html): string
{
    if (!str_contains($html, 'lh3.googleusercontent.com/aida-public') && !str_contains($html, 'images.unsplash.com')) {
        return $html;
    }

    $dom = loadHtmlDocument($html);
    foreach ($dom->getElementsByTagName('img') as $image) {
        if (!$image instanceof DOMElement) {
            continue;
        }

        $src = trim($image->getAttribute('src'));
        if ($src === '' || !maximusShouldLocalizeMediaUrl($src)) {
            continue;
        }

        $image->setAttribute('data-ohc-original-src', $src);
        $image->setAttribute('data-ohc-media-source', 'localized');
        $image->setAttribute('src', maximusLocalMediaUrl($src));
    }

    return saveHtmlDocument($dom);
}

function maximusShouldLocalizeMediaUrl(string $url): bool
{
    return str_contains($url, 'lh3.googleusercontent.com/aida-public')
        || str_contains($url, 'images.unsplash.com');
}

function maximusLocalMediaUrl(string $sourceUrl): string
{
    $uploads = wp_upload_dir();
    $baseDir = is_string($uploads['basedir'] ?? null) ? (string) $uploads['basedir'] : '';
    $baseUrl = is_string($uploads['baseurl'] ?? null) ? (string) $uploads['baseurl'] : '';
    if ($baseDir === '' || $baseUrl === '') {
        return maximusBrandPlaceholderDataUri($sourceUrl);
    }

    $mediaDir = rtrim($baseDir, '/\\') . '/ohc-maximus-media';
    if (!is_dir($mediaDir) && !wp_mkdir_p($mediaDir)) {
        return maximusBrandPlaceholderDataUri($sourceUrl);
    }

    $hash = sha1($sourceUrl);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $existingExtension) {
        $existingPath = $mediaDir . '/' . $hash . '.' . $existingExtension;
        if (is_file($existingPath)) {
            return rtrim($baseUrl, '/') . '/ohc-maximus-media/' . basename($existingPath);
        }
    }

    $response = wp_remote_get($sourceUrl, [
        'timeout' => 20,
        'redirection' => 5,
        'user-agent' => 'Oxygen HTML Converter Maximus media localizer',
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
        return maximusBrandPlaceholderDataUri($sourceUrl);
    }

    $body = wp_remote_retrieve_body($response);
    if (!is_string($body) || strlen($body) < 1024) {
        return maximusBrandPlaceholderDataUri($sourceUrl);
    }

    $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    $extension = 'jpg';
    if (str_contains($contentType, 'png')) {
        $extension = 'png';
    } elseif (str_contains($contentType, 'webp')) {
        $extension = 'webp';
    } elseif (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
        $extension = 'jpg';
    }

    $targetPath = $mediaDir . '/' . $hash . '.' . $extension;
    if (@file_put_contents($targetPath, $body) === false) {
        return maximusBrandPlaceholderDataUri($sourceUrl);
    }

    return rtrim($baseUrl, '/') . '/ohc-maximus-media/' . basename($targetPath);
}

function maximusBrandPlaceholderDataUri(string $seed): string
{
    $hash = sha1($seed);
    $variant = hexdec($hash[0]) % 4;
    $accentOffset = 210 + ((hexdec(substr($hash, 1, 2)) % 180));
    $circleX = 720 + ((hexdec(substr($hash, 3, 2)) % 260));
    $circleY = 130 + ((hexdec(substr($hash, 5, 2)) % 260));

    $variants = [
        ['#17120F', '#4D0907', '#9A7440'],
        ['#201A17', '#731B19', '#BE8656'],
        ['#17120F', '#002A3A', '#9A7440'],
        ['#2A211D', '#540306', '#E8DED0'],
    ];
    [$base, $deep, $accent] = $variants[$variant];

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">'
        . '<defs>'
        . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="' . $base . '"/>'
        . '<stop offset="0.58" stop-color="' . $deep . '"/>'
        . '<stop offset="1" stop-color="#E8DED0"/>'
        . '</linearGradient>'
        . '<radialGradient id="r" cx="50%" cy="50%" r="60%">'
        . '<stop offset="0" stop-color="' . $accent . '" stop-opacity="0.32"/>'
        . '<stop offset="1" stop-color="' . $accent . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '</defs>'
        . '<rect width="1200" height="800" fill="url(#g)"/>'
        . '<circle cx="' . $circleX . '" cy="' . $circleY . '" r="' . $accentOffset . '" fill="url(#r)"/>'
        . '<path d="M-80 650 C220 500 350 760 650 600 S1010 430 1300 560" fill="none" stroke="#F3EDE4" stroke-width="3" opacity="0.22"/>'
        . '<path d="M-40 210 C180 110 380 230 570 160 S920 60 1240 190" fill="none" stroke="#BE8656" stroke-width="2" opacity="0.2"/>'
        . '<rect x="56" y="56" width="1088" height="688" fill="none" stroke="#F3EDE4" stroke-width="1.5" opacity="0.18"/>'
        . '<text x="72" y="704" font-family="Georgia, serif" font-size="64" fill="#F3EDE4" opacity="0.62" letter-spacing="13">MAXIMUS</text>'
        . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * @param array<string, string> $links
 */
function rewriteHtmlLinks(string $html, array $links): string
{
    $dom = loadHtmlDocument($html);
    $anchors = $dom->getElementsByTagName('a');

    foreach ($anchors as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }

        $target = linkForAnchorText(normalizeText($anchor->textContent), $links);
        if ($target !== '') {
            $anchor->setAttribute('href', $target);
        }
    }

    return saveHtmlDocument($dom);
}

/**
 * @param array<string, string> $links
 */
function linkForAnchorText(string $text, array $links): string
{
    $rules = [
        'umow konsultacje' => 'diagnosis',
        'umow diagnoze startowa' => 'diagnosis',
        'diagnoza' => 'diagnosis',
        'dolacz' => 'diagnosis',
        'aplikuj' => 'diagnosis',
        'dla kogo' => 'for_whom',
        'wybierz swoj program' => 'for_whom',
        'programy' => 'offer',
        'autyzmup' => 'offer',
        'system rodzinny' => 'for_whom',
        'startery' => 'starters',
        'membership' => 'membership',
        'czlonkostwo' => 'membership',
        'premium' => 'membership',
        'lokalizacje' => 'locations',
        'transformacja' => 'home',
        'dziedzictwo' => 'home',
        'maximus' => 'home',
    ];

    foreach ($rules as $needle => $key) {
        if (str_contains($text, $needle) && isset($links[$key])) {
            return $links[$key];
        }
    }

    return '';
}

function stripDocumentShell(string $html): string
{
    $dom = loadHtmlDocument($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body instanceof DOMElement) {
        return $html;
    }

    $remove = [];
    foreach (iterator_to_array($body->childNodes) as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        $class = strtolower($child->getAttribute('class'));

        if ($tag === 'header' || $tag === 'footer') {
            $remove[] = $child;
            continue;
        }

        if ($tag === 'nav' && isBeforeFirstMain($child)) {
            $remove[] = $child;
            continue;
        }

        if (str_contains($class, 'fixed') && str_contains($class, 'bottom-0') && isBeforeFirstMain($child)) {
            $remove[] = $child;
        }
    }

    foreach ($remove as $node) {
        $node->parentNode?->removeChild($node);
    }

    return saveHtmlDocument($dom);
}

function isBeforeFirstMain(DOMElement $node): bool
{
    $current = $node;
    while ($current->nextSibling !== null) {
        $current = $current->nextSibling;
        if ($current instanceof DOMElement && strtolower($current->tagName) === 'main') {
            return true;
        }
    }

    return false;
}

function extractFirstLayoutFragment(string $html): string
{
    $dom = loadHtmlDocument($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body instanceof DOMElement) {
        return '';
    }

    foreach ($body->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        if ($tag === 'header' || ($tag === 'nav' && isBeforeFirstMain($child))) {
            return (string) $dom->saveHTML($child);
        }
    }

    return '';
}

function extractLastTagFragment(string $html, string $tag): string
{
    $dom = loadHtmlDocument($html);
    $nodes = $dom->getElementsByTagName($tag);
    if ($nodes->length < 1) {
        return '';
    }

    $node = $nodes->item($nodes->length - 1);
    return $node instanceof DOMElement ? (string) $dom->saveHTML($node) : '';
}

function extractAnchorByText(string $html, string $needle): string
{
    $needle = normalizeText($needle);
    $dom = loadHtmlDocument($html);
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }

        if (str_contains(normalizeText($anchor->textContent), $needle)) {
            return (string) $dom->saveHTML($anchor);
        }
    }

    return '';
}

/**
 * @param list<string> $tokens
 */
function extractFirstElementByClassTokens(string $html, array $tokens): string
{
    $dom = loadHtmlDocument($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[@class]');
    if (!$nodes instanceof DOMNodeList) {
        return '';
    }

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        $classes = array_flip($classes);
        $matches = true;
        foreach ($tokens as $token) {
            if (!isset($classes[$token])) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return (string) $dom->saveHTML($node);
        }
    }

    return '';
}

function makeDocumentFromFragment(string $sourceHtml, string $fragment): string
{
    if (trim($fragment) === '') {
        throw new RuntimeException('Cannot create document from empty fragment.');
    }

    $dom = loadHtmlDocument($sourceHtml);
    $head = $dom->getElementsByTagName('head')->item(0);
    $headHtml = '';
    if ($head instanceof DOMElement) {
        foreach ($head->childNodes as $child) {
            $headHtml .= (string) $dom->saveHTML($child);
        }
    }

    return '<!doctype html><html><head>' . $headHtml . '</head><body>' . $fragment . '</body></html>';
}

function buildConversionPayload(string $html, string $title, string $slug, string $scope): array
{
    $conversionHtml = stripMaximusRuntimeHeadAssets($html);
    $conversionHtml = flattenMaximusFormsForNativeEditing($conversionHtml);
    $conversionHtml = applyMaximusDesignSystemClasses($conversionHtml, $slug);
    $result = (new TreeBuilder())->convert($conversionHtml);
    if (empty($result['success']) || !is_array($result['element'] ?? null)) {
        throw new RuntimeException('Conversion failed for ' . $slug . ': ' . (string) ($result['error'] ?? 'unknown'));
    }

    // Site kits should not expose document <head> assets as editable canvas
    // HtmlCode nodes. Fonts/icons are registered once as global CSS instead.
    $result['headLinkElements'] = [];
    $result['headScriptElements'] = [];
    $result['iconScriptElements'] = [];

    $response = (new ConvertPayloadBuilder(
        new OxygenDocumentTree(),
        new ConversionAuditBuilder(),
        new OutputValidator()
    ))->build($result, [
        'wrapInContainer' => true,
        'includeCssElement' => false,
        'strictNative' => false,
        'startingNodeId' => 1,
        'scope' => $scope,
    ], $conversionHtml);

    if (empty($response['success']) || !is_array($response['data'] ?? null)) {
        throw new RuntimeException('Payload build failed for ' . $slug . ': ' . implode('; ', (array) ($response['data']['errors'] ?? [])));
    }

    $payload = $response['data'];
    $payload['title'] = $title;
    $payload['slug'] = $slug;

    $payload = namespaceGeneratedNativeClasses($payload, $slug);

    return attachMaximusDesignSystemSelectors($payload);
}

function stripMaximusRuntimeHeadAssets(string $html): string
{
    $dom = loadHtmlDocument($html);
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head instanceof DOMElement) {
        return $html;
    }

    $remove = [];
    foreach (iterator_to_array($head->childNodes) as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        if ($tag === 'script') {
            $src = strtolower((string) $child->getAttribute('src'));
            $id = strtolower((string) $child->getAttribute('id'));
            $content = strtolower((string) $child->textContent);
            if (str_contains($src, 'cdn.tailwindcss.com') || $id === 'tailwind-config' || str_contains($content, 'tailwind.config')) {
                $remove[] = $child;
            }
            continue;
        }

        if ($tag === 'link') {
            $href = strtolower((string) $child->getAttribute('href'));
            if (str_contains($href, 'fonts.googleapis.com') || str_contains($href, 'fonts.gstatic.com')) {
                $remove[] = $child;
            }
            continue;
        }

        if ($tag === 'style') {
            $content = strtolower((string) $child->textContent);
            if (str_contains($content, 'material-symbols-outlined') && str_contains($content, 'font-variation-settings')) {
                $remove[] = $child;
            }
        }
    }

    foreach ($remove as $node) {
        $node->parentNode?->removeChild($node);
    }

    return saveHtmlDocument($dom);
}

function flattenMaximusFormsForNativeEditing(string $html): string
{
    $dom = loadHtmlDocument($html);
    foreach (['input', 'textarea', 'select'] as $tag) {
        $nodes = iterator_to_array($dom->getElementsByTagName($tag));
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    foreach (['label', 'form'] as $tag) {
        $nodes = iterator_to_array($dom->getElementsByTagName($tag));
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $replacement = $dom->createElement('div');
            copyEditableContainerAttributes($node, $replacement);
            while ($node->firstChild !== null) {
                $replacement->appendChild($node->firstChild);
            }

            $node->parentNode?->replaceChild($replacement, $node);
        }
    }

    return saveHtmlDocument($dom);
}

function applyMaximusDesignSystemClasses(string $html, string $slug): string
{
    $dom = loadHtmlDocument($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body instanceof DOMElement) {
        appendClass($body, ['maximus-page', 'maximus-page-' . sanitize_title($slug)]);
    }

    foreach ($dom->getElementsByTagName('*') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($node->tagName);
        $text = normalizeText($node->textContent);
        $classes = classTokenSet($node);
        $add = [];
        $parent = $node->parentNode;
        $isInsideHeader = $tag === 'header' || hasTagAncestor($node, 'header');
        $isInsideFooter = $tag === 'footer' || hasTagAncestor($node, 'footer');

        if ($tag === 'header') {
            $add[] = 'maximus-site-header';
            $add[] = 'maximus-shell';
        }

        if ($tag === 'div' && $parent instanceof DOMElement && strtolower($parent->tagName) === 'header') {
            $add[] = 'maximus-site-header-inner';
        }

        if ($isInsideHeader && $tag === 'nav') {
            $add[] = 'maximus-site-nav';
        }

        if ($isInsideFooter && $tag === 'nav') {
            $add[] = 'maximus-footer-nav';
        }

        if ($tag === 'nav') {
            $add[] = 'maximus-nav';
        }

        if ($tag === 'footer') {
            $add[] = 'maximus-site-footer';
            $add[] = 'maximus-shell';
        }

        if ($tag === 'div' && $parent instanceof DOMElement && strtolower($parent->tagName) === 'footer') {
            $add[] = 'maximus-site-footer-inner';
        }

        if (($isInsideHeader || $isInsideFooter) && $text === 'maximus') {
            $add[] = 'maximus-wordmark';
        }

        if ($isInsideHeader
            && $tag === 'div'
            && (!$parent instanceof DOMElement || strtolower($parent->tagName) !== 'header')
            && str_contains($text, 'diagnoza')
            && str_contains($text, 'umow konsultacje')
        ) {
            $add[] = 'maximus-site-actions';
        }

        if ($isInsideHeader && $text === 'menu') {
            $add[] = 'maximus-mobile-menu-icon';
        }

        if ($isInsideFooter && !hasElementChild($node) && str_contains($text, 'wszelkie prawa zastrzezone')) {
            $add[] = 'maximus-footer-legal';
        }

        if ($node->getAttribute('data-ohc-source-tag') === 'form') {
            $add[] = 'maximus-questionnaire';
        }

        if (isset($classes['relative'], $classes['justify-between'], $classes['items-center'], $classes['w-full'])
            && str_contains($text, 'diagnoza')
            && str_contains($text, 'rekomendacja')
        ) {
            $add[] = 'maximus-progress-track';
        }

        if (hasClassAncestor($node, 'maximus-progress-track') && isset($classes['absolute'], $classes['h-[1px]'])) {
            $add[] = 'maximus-progress-line';
        }

        if (hasClassAncestor($node, 'maximus-progress-track') && isset($classes['flex-col'], $classes['items-center'], $classes['gap-3'])) {
            $add[] = 'maximus-progress-step';
        }

        if (hasClassAncestor($node, 'maximus-progress-step') && isset($classes['rounded-full'])) {
            $add[] = 'maximus-progress-dot';
        }

        if ($tag === 'main') {
            $add[] = 'maximus-main';
        }

        if ($tag === 'section') {
            if (hasSourceTagAncestor($node, 'form')) {
                $add[] = 'maximus-question-block';
            } else {
                $add[] = 'maximus-section';
                markMaximusSectionInner($node);
                foreach (classifyMaximusSection($node) as $sectionClass) {
                    $add[] = $sectionClass;
                }
            }
        }

        if (hasAnyClassPrefix($classes, ['max-w-', 'max-w-screen']) && isset($classes['mx-auto'])) {
            $add[] = 'maximus-container';
        }

        if (isset($classes['grid'])) {
            $add[] = 'maximus-grid';
            if (isset($classes['grid-cols-4']) || isset($classes['md:grid-cols-4'])) {
                $add[] = 'maximus-grid-4';
            } elseif (isset($classes['grid-cols-3']) || isset($classes['md:grid-cols-3'])) {
                $add[] = 'maximus-grid-3';
            } else {
                $add[] = 'maximus-grid-2';
            }
        }

        if (isMaximusButtonElement($node, $classes)) {
            $add[] = 'maximus-button';
            $add[] = classifyMaximusButtonVariant($node, $classes);
        }

        if (isMaximusCardElement($node, $classes)) {
            $add[] = 'maximus-card';
            foreach (classifyMaximusCard($node, $classes) as $cardClass) {
                $add[] = $cardClass;
            }
        }

        if ($tag === 'h1') {
            $add[] = 'maximus-heading';
            $add[] = 'maximus-heading-xl';
        } elseif ($tag === 'h2') {
            $add[] = 'maximus-heading';
            $add[] = 'maximus-heading-lg';
        } elseif ($tag === 'h3') {
            $add[] = 'maximus-heading';
            $add[] = 'maximus-heading-md';
        }

        if ($tag === 'p') {
            $add[] = 'maximus-body-copy';
        }

        if ($tag === 'span' && isset($classes['material-symbols-outlined'])) {
            $add[] = 'maximus-icon';
        }

        if ($tag === 'span' && (str_contains($text, 'kultura fizyczna') || str_contains($text, 'filozofia') || str_contains($text, 'metodologia'))) {
            $add[] = 'maximus-eyebrow';
        }

        appendClass($node, $add);
    }

    return saveHtmlDocument($dom);
}

function hasClassAncestor(DOMElement $node, string $className): bool
{
    $current = $node->parentNode;

    while ($current instanceof DOMElement) {
        $classes = preg_split('/\s+/', trim($current->getAttribute('class'))) ?: [];
        if (in_array($className, $classes, true)) {
            return true;
        }

        $current = $current->parentNode;
    }

    return false;
}

function hasTagAncestor(DOMElement $node, string $tagName): bool
{
    $tagName = strtolower($tagName);
    $current = $node->parentNode;

    while ($current instanceof DOMElement) {
        if (strtolower($current->tagName) === $tagName) {
            return true;
        }

        $current = $current->parentNode;
    }

    return false;
}

function hasElementChild(DOMElement $node): bool
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            return true;
        }
    }

    return false;
}

function hasSourceTagAncestor(DOMElement $node, string $sourceTag): bool
{
    $sourceTag = strtolower($sourceTag);
    $current = $node->parentNode;

    while ($current instanceof DOMElement) {
        if (strtolower($current->getAttribute('data-ohc-source-tag')) === $sourceTag) {
            return true;
        }

        $current = $current->parentNode;
    }

    return false;
}

function markMaximusSectionInner(DOMElement $section): void
{
    $candidate = null;
    foreach ($section->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $classes = classTokenSet($child);
        if (isset($classes['maximus-section-inner'])) {
            return;
        }

        if ($candidate === null) {
            $candidate = $child;
        }

        if (isset($classes['mx-auto']) || hasAnyClassPrefix($classes, ['max-w-', 'max-w-screen'])) {
            $candidate = $child;
            break;
        }
    }

    if ($candidate instanceof DOMElement) {
        appendClass($candidate, ['maximus-section-inner']);
    }
}

/**
 * @return array<string, bool>
 */
function classTokenSet(DOMElement $node): array
{
    $tokens = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

    return array_fill_keys($tokens, true);
}

/**
 * @param array<string, bool> $classes
 * @param list<string> $prefixes
 */
function hasAnyClassPrefix(array $classes, array $prefixes): bool
{
    foreach (array_keys($classes) as $className) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function classifyMaximusSection(DOMElement $node): array
{
    $text = normalizeText($node->textContent);
    $classes = classTokenSet($node);
    $sectionClasses = [];

    if (str_contains($text, 'kultura fizyczna') || isset($classes['min-h-[870px]'])) {
        $sectionClasses[] = 'maximus-section-hero';
    } elseif (str_contains($text, 'gotowy') || str_contains($text, 'pierwszy krok')) {
        $sectionClasses[] = 'maximus-section-cta';
    } elseif (str_contains($text, 'map') || str_contains($text, 'krakow') || str_contains($text, 'lokalizacja')) {
        $sectionClasses[] = 'maximus-section-location';
    } elseif (str_contains($text, 'sciezki') || str_contains($text, 'program') || str_contains($text, 'czlonkostwo')) {
        $sectionClasses[] = 'maximus-section-cards';
    } else {
        $sectionClasses[] = 'maximus-section-editorial';
    }

    if (str_contains($text, 'diagnoza') || str_contains($text, 'profilaktyka')) {
        $sectionClasses[] = 'maximus-section-diagnosis';
    }

    return array_values(array_unique($sectionClasses));
}

/**
 * @param array<string, bool> $classes
 */
function isMaximusButtonElement(DOMElement $node, array $classes): bool
{
    $tag = strtolower($node->tagName);
    if (!in_array($tag, ['a', 'button'], true)) {
        return false;
    }

    if (isset($classes['maximus-nav-link'])) {
        return false;
    }

    $classString = implode(' ', array_keys($classes));
    return str_contains($classString, 'px-')
        || str_contains($classString, 'py-')
        || str_contains($classString, 'bg-')
        || str_contains($classString, 'border');
}

/**
 * @param array<string, bool> $classes
 */
function classifyMaximusButtonVariant(DOMElement $node, array $classes): string
{
    unset($node);
    $classNames = array_keys($classes);
    $hasSolidBackground = false;

    foreach ($classNames as $className) {
        if (str_contains($className, ':')) {
            continue;
        }

        if (in_array($className, ['bg-oxblood-primary', 'bg-oxblood-deep', 'bg-primary', 'bg-ink-black', 'bg-brass-accent'], true)) {
            $hasSolidBackground = true;
            break;
        }
    }

    if ($hasSolidBackground) {
        return 'maximus-button-primary';
    }

    foreach ($classNames as $className) {
        if (str_contains($className, ':')) {
            continue;
        }

        if ($className === 'border' || str_starts_with($className, 'border-')) {
            return 'maximus-button-outline';
        }
    }

    $classString = implode(' ', $classNames);
    if (str_contains($classString, 'border')) {
        return 'maximus-button-outline';
    }

    return 'maximus-button-secondary';
}

/**
 * @param array<string, bool> $classes
 */
function isMaximusCardElement(DOMElement $node, array $classes): bool
{
    $tag = strtolower($node->tagName);
    if (!in_array($tag, ['div', 'article', 'li'], true)) {
        return false;
    }

    $classString = implode(' ', array_keys($classes));
    return (str_contains($classString, 'border') && preg_match('/\bp(?:x|y)?-\d+|\bp-\d+/', $classString) === 1)
        || str_contains($classString, 'bg-paper-soft')
        || str_contains($classString, 'bg-ivory-base');
}

/**
 * @param array<string, bool> $classes
 * @return list<string>
 */
function classifyMaximusCard(DOMElement $node, array $classes): array
{
    $text = normalizeText($node->textContent);
    $cardClasses = [];

    if (str_contains($text, 'program') || str_contains($text, 'starter') || str_contains($text, 'performance')) {
        $cardClasses[] = 'maximus-card-program';
    }

    if (str_contains($text, 'rodzic') || str_contains($text, 'opiekun') || str_contains($text, 'dorosly')) {
        $cardClasses[] = 'maximus-card-path';
    }

    if (str_contains($text, 'nowa huta') || str_contains($text, 'gortat') || str_contains($text, 'krakow')) {
        $cardClasses[] = 'maximus-card-location';
    }

    if (isset($classes['bg-primary']) || isset($classes['bg-ink-black']) || isset($classes['bg-oxblood-deep']) || isset($classes['text-on-primary'])) {
        $cardClasses[] = 'maximus-card-selected';
    }

    return $cardClasses === [] ? ['maximus-card-basic'] : array_values(array_unique($cardClasses));
}

/**
 * @param list<string> $classes
 */
function appendClass(DOMElement $node, array $classes): void
{
    $existing = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    $existing = array_values(array_filter($existing, static fn (string $className): bool => $className !== ''));

    foreach ($classes as $className) {
        $className = trim($className);
        if ($className !== '') {
            $existing[] = $className;
        }
    }

    $existing = array_values(array_unique($existing));
    if ($existing !== []) {
        $node->setAttribute('class', implode(' ', $existing));
    }
}

function copyEditableContainerAttributes(DOMElement $source, DOMElement $target): void
{
    foreach (iterator_to_array($source->attributes) as $attribute) {
        if (!($attribute instanceof DOMAttr)) {
            continue;
        }

        $name = strtolower($attribute->name);
        if (
            in_array($name, ['id', 'class', 'style', 'title', 'role'], true)
            || str_starts_with($name, 'aria-')
            || str_starts_with($name, 'data-')
        ) {
            $target->setAttribute($attribute->name, $attribute->value);
        }
    }

    $target->setAttribute('data-ohc-source-tag', strtolower($source->tagName));
}

function namespaceGeneratedNativeClasses(array $payload, string $scope): array
{
    $scope = sanitize_title($scope);
    if ($scope === '') {
        $scope = substr(sha1((string) wp_json_encode($payload)), 0, 8);
    }

    $replacement = 'ohc-' . $scope . '-native-';

    return replaceNativeClassPrefix($payload, $replacement);
}

/**
 * @param mixed $value
 * @return mixed
 */
function replaceNativeClassPrefix($value, string $replacement)
{
    if (is_string($value)) {
        return str_replace('ohc-native-', $replacement, $value);
    }

    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = replaceNativeClassPrefix($item, $replacement);
    }

    return $value;
}

function attachMaximusDesignSystemSelectors(array $payload): array
{
    $selectorPayload = is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : [];
    $existingSelectors = is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : [];
    $byId = [];

    foreach ($existingSelectors as $selector) {
        if (!is_array($selector) || !is_string($selector['id'] ?? null)) {
            continue;
        }

        $byId[$selector['id']] = $selector;
    }

    foreach (maximusDesignSystemSelectors() as $selector) {
        $byId[$selector['id']] = $selector;
    }

    $collections = is_array($selectorPayload['collections'] ?? null) ? $selectorPayload['collections'] : [];
    $collections[] = maximusDesignSystemCollectionName();

    $payload['selectorPayload'] = array_merge($selectorPayload, [
        'selectors' => array_values($byId),
        'collections' => array_values(array_unique(array_filter(array_map('strval', $collections)))),
    ]);

    $payload['designSystem'] = [
        'selectorCollection' => maximusDesignSystemCollectionName(),
        'selectors' => count(maximusDesignSystemSelectors()),
    ];

    return $payload;
}

function maximusDesignSystemCollectionName(): string
{
    return 'Maximus Design System';
}

/**
 * @return list<array<string, mixed>>
 */
function maximusDesignSystemSelectors(): array
{
    $rules = [
        'maximus-page' => [
            'font-family' => 'var(--ohc-font-body-main)',
            'color' => 'var(--ohc-on-surface)',
            'background-color' => 'var(--ohc-surface)',
        ],
        'maximus-main' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'width' => '100%',
        ],
        'maximus-shell' => [
            'width' => '100%',
            'background-color' => 'var(--ohc-surface)',
        ],
        'maximus-component-instance' => [
            'display' => 'block',
            'width' => '100%',
        ],
        'maximus-section-component-instance' => [
            'width' => '100%',
        ],
        'maximus-site-header' => [
            'display' => 'flex',
            'justify-content' => 'space-between',
            'align-items' => 'center',
            'width' => '100%',
            'position' => 'sticky',
            'top' => '0',
            'z-index' => '50',
            'background-color' => 'var(--ohc-surface)',
            'border-bottom' => '1px solid var(--ohc-paper-soft)',
        ],
        'maximus-site-header-inner' => [
            'display' => 'flex',
            'justify-content' => 'space-between',
            'align-items' => 'center',
            'width' => '100%',
            'max-width' => '1536px',
            'padding' => '24px 32px',
            'margin-left' => 'auto',
            'margin-right' => 'auto',
        ],
        'maximus-nav' => [
            'display' => 'flex',
            'align-items' => 'center',
            'gap' => '32px',
            'width' => 'auto',
        ],
        'maximus-site-nav' => [
            'font-family' => 'var(--ohc-font-hero-serif)',
            'font-size' => '12px',
            'line-height' => '1',
            'font-weight' => '500',
            'text-transform' => 'uppercase',
        ],
        'maximus-site-actions' => [
            'display' => 'none',
            'align-items' => 'center',
            'gap' => '24px',
        ],
        'maximus-wordmark' => [
            'font-family' => 'var(--ohc-font-hero-serif)',
            'font-weight' => '900',
            'color' => 'var(--ohc-oxblood-primary)',
        ],
        'maximus-mobile-menu-icon' => [
            'display' => 'none',
            'color' => 'var(--ohc-oxblood-primary)',
        ],
        'maximus-site-footer' => [
            'width' => '100%',
            'border-top' => '1px solid var(--ohc-paper-soft)',
        ],
        'maximus-site-footer-inner' => [
            'display' => 'flex',
            'justify-content' => 'space-between',
            'align-items' => 'center',
            'gap' => '32px',
            'width' => '100%',
            'max-width' => '1536px',
            'padding' => '64px 48px',
            'margin-left' => 'auto',
            'margin-right' => 'auto',
        ],
        'maximus-footer-nav' => [
            'display' => 'flex',
            'flex-wrap' => 'wrap',
            'justify-content' => 'center',
            'gap' => '24px',
            'font-family' => 'var(--ohc-font-hero-serif)',
            'font-size' => '10px',
            'letter-spacing' => '0.2em',
            'text-transform' => 'uppercase',
        ],
        'maximus-footer-legal' => [
            'font-family' => 'var(--ohc-font-hero-serif)',
            'font-size' => '10px',
            'letter-spacing' => '0.2em',
            'text-transform' => 'uppercase',
            'text-align' => 'right',
            'color' => 'var(--ohc-oxblood-primary)',
        ],
        'maximus-section' => [
            'width' => '100%',
            'display' => 'flex',
            'flex-direction' => 'column',
            'justify-content' => 'center',
            'align-items' => 'center',
            'padding-top' => 'var(--ohc-space-section-gap)',
            'padding-bottom' => 'var(--ohc-space-section-gap)',
        ],
        'maximus-section-hero' => [
            'min-height' => '870px',
            'padding-top' => 'var(--ohc-space-margin-page)',
            'padding-bottom' => 'var(--ohc-space-section-gap)',
            'background-color' => 'var(--ohc-paper-bright)',
        ],
        'maximus-section-editorial' => [
            'background-color' => 'var(--ohc-ivory-base)',
        ],
        'maximus-section-cards' => [
            'background-color' => 'var(--ohc-paper-bright)',
        ],
        'maximus-section-cta' => [
            'text-align' => 'center',
            'background-color' => 'var(--ohc-ivory-base)',
        ],
        'maximus-section-location' => [
            'background-color' => 'var(--ohc-surface)',
        ],
        'maximus-section-diagnosis' => [
            'max-width' => '768px',
            'margin-left' => 'auto',
            'margin-right' => 'auto',
        ],
        'maximus-questionnaire' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => '80px',
        ],
        'maximus-question-block' => [
            'display' => 'block',
            'width' => '100%',
        ],
        'maximus-progress-track' => [
            'display' => 'flex',
            'position' => 'relative',
            'justify-content' => 'space-between',
            'align-items' => 'center',
            'width' => '100%',
        ],
        'maximus-progress-line' => [
            'position' => 'absolute',
            'left' => '0',
            'top' => '50%',
            'width' => '100%',
            'height' => '1px',
            'transform' => 'translateY(-50%)',
            'background-color' => 'rgba(137, 113, 111, 0.4)',
            'z-index' => '0',
        ],
        'maximus-progress-step' => [
            'display' => 'flex',
            'position' => 'relative',
            'z-index' => '1',
            'flex-direction' => 'column',
            'align-items' => 'center',
            'gap' => '12px',
            'padding-left' => '16px',
            'padding-right' => '16px',
            'background-color' => 'var(--ohc-ivory-base)',
        ],
        'maximus-progress-dot' => [
            'width' => '10px',
            'height' => '10px',
            'border-radius' => '9999px',
            'box-shadow' => '0 0 0 6px var(--ohc-ivory-base)',
        ],
        'maximus-container' => [
            'width' => '100%',
            'max-width' => '1536px',
            'padding-left' => 'var(--ohc-space-gutter-grid)',
            'padding-right' => 'var(--ohc-space-gutter-grid)',
        ],
        'maximus-section-inner' => [
            'width' => '100%',
            'max-width' => '1536px',
        ],
        'maximus-grid' => [
            'display' => 'grid',
            'gap' => 'var(--ohc-space-gutter-grid)',
            'width' => '100%',
        ],
        'maximus-grid-2' => [
            'grid-template-columns' => 'repeat(2, minmax(0, 1fr))',
        ],
        'maximus-grid-3' => [
            'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
        ],
        'maximus-grid-4' => [
            'grid-template-columns' => 'repeat(4, minmax(0, 1fr))',
        ],
        'maximus-heading' => [
            'font-family' => 'var(--ohc-font-hero-serif)',
            'color' => 'var(--ohc-ink-black)',
            'letter-spacing' => '0',
        ],
        'maximus-heading-xl' => [
            'font-size' => '64px',
            'line-height' => '1.1',
            'font-weight' => '700',
        ],
        'maximus-heading-lg' => [
            'font-size' => '40px',
            'line-height' => '1.2',
            'font-weight' => '700',
        ],
        'maximus-heading-md' => [
            'font-size' => '24px',
            'line-height' => '1.25',
            'font-weight' => '600',
        ],
        'maximus-body-copy' => [
            'font-family' => 'var(--ohc-font-body-main)',
            'font-size' => '16px',
            'line-height' => '1.6',
            'color' => 'var(--ohc-ink-soft)',
        ],
        'maximus-eyebrow' => [
            'font-family' => 'var(--ohc-font-body-main)',
            'font-size' => '12px',
            'line-height' => '1.4',
            'font-weight' => '600',
            'letter-spacing' => '0.18em',
            'text-transform' => 'uppercase',
            'color' => 'var(--ohc-primary-container)',
        ],
        'maximus-button' => [
            'display' => 'inline-flex',
            'align-items' => 'center',
            'justify-content' => 'center',
            'gap' => '12px',
            'padding-top' => '20px',
            'padding-right' => '40px',
            'padding-bottom' => '20px',
            'padding-left' => '40px',
            'font-family' => 'var(--ohc-font-body-main)',
            'font-size' => '12px',
            'line-height' => '1.4',
            'font-weight' => '700',
            'letter-spacing' => '0.12em',
            'text-transform' => 'uppercase',
            'text-decoration' => 'none',
        ],
        'maximus-button-primary' => [
            'background-color' => 'var(--ohc-oxblood-deep)',
            'color' => '#FFFFFF',
        ],
        'maximus-button-secondary' => [
            'background-color' => 'transparent',
            'color' => 'var(--ohc-oxblood-deep)',
        ],
        'maximus-button-outline' => [
            'background-color' => 'transparent',
            'color' => 'var(--ohc-oxblood-deep)',
            'border' => '1px solid var(--ohc-oxblood-deep)',
        ],
        'maximus-card' => [
            'background-color' => 'var(--ohc-paper-soft)',
            'border' => '1px solid var(--ohc-outline)',
            'padding-top' => 'var(--ohc-space-component-padding)',
            'padding-right' => 'var(--ohc-space-component-padding)',
            'padding-bottom' => 'var(--ohc-space-component-padding)',
            'padding-left' => 'var(--ohc-space-component-padding)',
        ],
        'maximus-card-basic' => [
            'background-color' => 'var(--ohc-paper-soft)',
        ],
        'maximus-card-program' => [
            'background-color' => 'var(--ohc-ivory-base)',
        ],
        'maximus-card-path' => [
            'background-color' => 'var(--ohc-paper-bright)',
        ],
        'maximus-card-location' => [
            'background-color' => 'var(--ohc-paper-bright)',
        ],
        'maximus-card-selected' => [
            'background-color' => 'var(--ohc-primary-container)',
            'color' => '#FFFFFF',
        ],
        'maximus-icon' => [
            'font-family' => '"Material Symbols Outlined"',
            'font-weight' => '300',
            'line-height' => '1',
        ],
    ];

    $selectors = [];
    foreach ($rules as $className => $declarations) {
        $selectors[] = maximusSelectorRecord($className, $declarations);
    }

    return $selectors;
}

/**
 * @param array<string, string> $declarations
 * @return array<string, mixed>
 */
function maximusSelectorRecord(string $className, array $declarations): array
{
    return [
        'id' => deterministicSelectorUuid($className),
        'name' => $className,
        'selector' => '.' . $className,
        'type' => 'class',
        'collection' => maximusDesignSystemCollectionName(),
        'locked' => false,
        'children' => [],
        'properties' => maximusSelectorProperties($declarations),
    ];
}

/**
 * @param array<string, string> $declarations
 * @return array<string, mixed>
 */
function maximusSelectorProperties(array $declarations): array
{
    $base = [];
    $customCss = [];

    foreach ($declarations as $property => $value) {
        $property = strtolower(trim($property));
        $value = trim($value);
        if ($property === '' || $value === '') {
            continue;
        }

        if (preg_match('/^(padding|margin)-(top|right|bottom|left)$/', $property, $matches) === 1) {
            setSelectorNestedValue($base, ['spacing', 'spacing', $matches[1], $matches[2]], selectorMeasurement($value));
            continue;
        }

        $path = match ($property) {
            'font-family' => ['typography', 'font_family'],
            'font-size' => ['typography', 'font_size'],
            'font-weight' => ['typography', 'font_weight'],
            'line-height' => ['typography', 'line_height'],
            'letter-spacing' => ['typography', 'letter_spacing'],
            'text-align' => ['typography', 'text_align'],
            'text-transform' => ['typography', 'text_transform'],
            'text-decoration' => ['typography', 'style', 'text_decoration'],
            'color' => ['typography', 'color'],
            'width' => ['size', 'width'],
            'max-width' => ['size', 'max_width'],
            'min-height' => ['size', 'min_height'],
            'display' => ['layout', 'display'],
            'flex-direction' => ['layout', 'flex_direction'],
            'justify-content' => ['layout', 'justify_content'],
            'align-items' => ['layout', 'align_items'],
            'gap' => ['layout', 'gap'],
            'background-color' => ['background', 'background_color'],
            default => null,
        };

        if ($path === null) {
            $customCss[$property] = $value;
            continue;
        }

        setSelectorNestedValue($base, $path, selectorValue($property, $value));
    }

    if ($customCss !== []) {
        $base['custom_css']['custom_css'] = selectorCustomCssBlock($customCss);
    }

    return ['breakpoint_base' => $base];
}

function selectorValue(string $property, string $value)
{
    if (in_array($property, ['font-size', 'line-height', 'letter-spacing', 'width', 'max-width', 'min-height', 'gap'], true)) {
        return selectorMeasurement($value);
    }

    if ($property === 'font-weight' && is_numeric($value)) {
        return (int) $value;
    }

    if (($property === 'color' || $property === 'background-color') && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
        return strtoupper($value) . 'FF';
    }

    return $value;
}

function selectorMeasurement(string $value)
{
    $value = trim($value);
    $keyword = strtolower($value);
    if ($keyword === 'auto' || $keyword === 'none') {
        return [
            'number' => null,
            'unit' => $keyword,
            'style' => $keyword,
        ];
    }

    if (preg_match('/^(-?\d*\.?\d+)\s*(px|rem|em|%|vw|vh|vmin|vmax|ch)?$/', $value, $matches) !== 1) {
        return $value;
    }

    $number = (float) $matches[1];
    if ($number == (int) $number) {
        $number = (int) $number;
    }

    $unit = $matches[2] ?? 'px';

    return [
        'number' => $number,
        'unit' => $unit,
        'style' => $number . $unit,
    ];
}

/**
 * @param array<string, string> $declarations
 */
function selectorCustomCssBlock(array $declarations): string
{
    $lines = [':selector {'];
    foreach ($declarations as $property => $value) {
        $lines[] = '  ' . $property . ': ' . $value . ';';
    }
    $lines[] = '}';

    return implode("\n", $lines);
}

function setSelectorNestedValue(array &$array, array $path, $value): void
{
    $current = &$array;
    foreach ($path as $key) {
        if (!isset($current[$key]) || !is_array($current[$key])) {
            $current[$key] = [];
        }
        $current = &$current[$key];
    }

    $current = $value;
}

function deterministicSelectorUuid(string $className): string
{
    $hash = sha1('oxy-html-converter-selector:' . $className);
    $variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

    return substr($hash, 0, 8)
        . '-' . substr($hash, 8, 4)
        . '-5' . substr($hash, 13, 3)
        . '-' . $variant . substr($hash, 17, 3)
        . '-' . substr($hash, 20, 12);
}

function routeCanvasCssOutOfTree(array $payload, string $scope): array
{
    $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
    $pageCss = is_string($routing['pageCss'] ?? null) ? trim((string) $routing['pageCss']) : '';
    $pageScopedCss = is_string($payload['pageScopedCss'] ?? null) ? trim((string) $payload['pageScopedCss']) : '';
    $globalCss = is_string($payload['globalCss'] ?? null) ? pruneRedundantMaximusGlobalCss((string) $payload['globalCss']) : '';

    if ($scope === 'global') {
        $payload['globalCss'] = joinCss([$globalCss, $pageCss, $pageScopedCss]);
        $payload['pageScopedCss'] = '';
    } else {
        $payload['globalCss'] = $globalCss;
        $payload['pageScopedCss'] = joinCss([$pageCss, $pageScopedCss]);
    }

    $payload['cssElement'] = null;

    return $payload;
}

function pruneRedundantMaximusGlobalCss(string $css): string
{
    $css = trim($css);
    if ($css === '') {
        return '';
    }

    $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    $withoutMaterialSymbolRules = preg_replace(
        '/\.material-symbols-outlined(?:\.[a-zA-Z0-9_-]+)?(?:\s*,\s*\.material-symbols-outlined(?:\.[a-zA-Z0-9_-]+)?)*\s*\{[^{}]*\}/s',
        '',
        $withoutComments
    ) ?? $withoutComments;

    if (trim($withoutMaterialSymbolRules) === '') {
        return '';
    }

    return $css;
}

/**
 * @param list<string> $parts
 */
function joinCss(array $parts): string
{
    $parts = array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts), static fn (string $part): bool => $part !== ''));
    return implode("\n\n", $parts);
}

function importOxygenDocumentPost(
    string $postType,
    string $title,
    string $slug,
    string $html,
    ?array $templateSettings,
    string $styleScope
): array {
    $payload = buildConversionPayload($html, $title, $slug, $postType);
    $payload = routeCanvasCssOutOfTree($payload, $styleScope);
    $payload = enrichWithMaximusBrandPayload($payload);
    if ($postType === 'oxygen_block') {
        $payload = enableMaximusComponentProperties($payload, $slug);
    }

    $postId = createOrUpdatePost($postType, $title, $slug);
    persistPayloadForPost($postId, $payload, $templateSettings);

    return summarizeImportedPost($postId, $postType, $title, $slug, $payload, $templateSettings);
}

/**
 * @param array<string, array{path:string,title:string,slug:string,keepsOwnShell?:bool}> $fixtures
 * @param array<string, string> $links
 * @return list<array<string, mixed>>
 */
function importMaximusSectionComponents(array $fixtures, array $links): array
{
    $results = [];

    foreach ($fixtures as $key => $fixture) {
        $sourceHtml = readFixtureHtml($fixture['path']);
        $pageHtml = rewriteHtmlLinks($sourceHtml, $links);
        if (empty($fixture['keepsOwnShell'])) {
            $pageHtml = stripDocumentShell($pageHtml);
        }

        foreach (extractMaximusReusableSectionFragments($pageHtml, $key) as $index => $section) {
            $number = $index + 1;
            $label = $section['label'] !== '' ? $section['label'] : ('Section ' . $number);
            $title = 'Maximus Section - ' . maximusFixtureComponentLabel($key) . ' - ' . $label;
            $slug = 'maximus-section-' . sanitize_title($key . '-' . $number . '-' . $label);
            $result = importOxygenDocumentPost(
                'oxygen_block',
                $title,
                $slug,
                makeDocumentFromFragment($sourceHtml, rewriteHtmlLinks($section['html'], $links)),
                null,
                'page'
            );
            $result['componentKind'] = 'section';
            $result['sourceFixture'] = $key;
            $result['sourceSectionIndex'] = $number;
            $results[] = $result;
        }
    }

    return $results;
}

function enableMaximusComponentProperties(array $payload, string $slug): array
{
    $activeTreeKey = is_array($payload['documentTree'] ?? null) ? 'documentTree' : 'element';
    if (!is_array($payload[$activeTreeKey] ?? null)) {
        $payload['componentProperties'] = ['targets' => [], 'properties' => []];
        return $payload;
    }

    $schema = attachEditableTextPropertiesToTree($payload[$activeTreeKey], $slug);
    $payload['componentProperties'] = $schema;

    return $payload;
}

/**
 * @param array<string, mixed> $tree
 * @return array{targets:list<array{nodeId:int,propertyKey:string,controlPath:string}>,properties:array<string, string>}
 */
function attachEditableTextPropertiesToTree(array &$tree, string $slug): array
{
    $schema = ['targets' => [], 'properties' => []];
    if (isset($tree['root']) && is_array($tree['root'])) {
        attachEditableTextPropertiesToNode($tree['root'], $slug, $schema);
        return $schema;
    }

    attachEditableTextPropertiesToNode($tree, $slug, $schema);

    return $schema;
}

/**
 * @param array<string, mixed> $node
 * @param array{targets:list<array{nodeId:int,propertyKey:string,controlPath:string}>,properties:array<string, string>} $schema
 */
function attachEditableTextPropertiesToNode(array &$node, string $slug, array &$schema): void
{
    $properties = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];
    $text = nestedString($properties, ['content', 'content', 'text']);
    $nodeId = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : 0;

    if (($node['data']['type'] ?? null) === 'OxygenElements\\Text' && $nodeId > 0 && shouldExposeTextAsComponentProperty($node, $text)) {
        $propertyKey = 'maximus_' . sanitize_key($slug) . '_text_' . $nodeId;
        $editableProperty = [
            'enabled' => true,
            'label' => componentEditableTextLabel($node, $text),
            'controlPath' => 'content.content.text',
            'propertyKey' => $propertyKey,
        ];

        if (!isset($node['data']['properties']) || !is_array($node['data']['properties'])) {
            $node['data']['properties'] = [];
        }
        if (!isset($node['data']['properties']['meta']) || !is_array($node['data']['properties']['meta'])) {
            $node['data']['properties']['meta'] = [];
        }
        if (!isset($node['data']['properties']['meta']['component']) || !is_array($node['data']['properties']['meta']['component'])) {
            $node['data']['properties']['meta']['component'] = [];
        }

        $existing = is_array($node['data']['properties']['meta']['component']['editableProperties'] ?? null)
            ? $node['data']['properties']['meta']['component']['editableProperties']
            : [];
        $existing[] = $editableProperty;
        $node['data']['properties']['meta']['component']['editableProperties'] = dedupeEditableProperties($existing);

        $schema['targets'][] = [
            'nodeId' => $nodeId,
            'propertyKey' => $propertyKey,
            'controlPath' => 'content.content.text',
        ];
        $schema['properties'][$propertyKey] = $text;
    }

    if (!isset($node['children']) || !is_array($node['children'])) {
        return;
    }

    foreach ($node['children'] as &$child) {
        if (is_array($child)) {
            attachEditableTextPropertiesToNode($child, $slug, $schema);
        }
    }
    unset($child);
}

/**
 * @param list<array<string, mixed>> $editableProperties
 * @return list<array<string, mixed>>
 */
function dedupeEditableProperties(array $editableProperties): array
{
    $byKey = [];
    foreach ($editableProperties as $property) {
        if (!is_array($property) || !is_string($property['propertyKey'] ?? null)) {
            continue;
        }

        $byKey[$property['propertyKey']] = $property;
    }

    return array_values($byKey);
}

/**
 * @param array<string, mixed> $node
 */
function shouldExposeTextAsComponentProperty(array $node, string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $classes = nodeClasses($node);
    if (in_array('material-symbols-outlined', $classes, true) || in_array('maximus-icon', $classes, true)) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $node
 */
function componentEditableTextLabel(array $node, string $text): string
{
    $tag = nodeTag($node);
    $prefix = match ($tag) {
        'h1' => 'Hero heading',
        'h2' => 'Section heading',
        'h3' => 'Card heading',
        'a' => 'Link text',
        'li' => 'List item',
        default => 'Text',
    };

    $normalized = humanTitleText($text);
    if ($normalized === '') {
        return $prefix;
    }

    $words = preg_split('/\s+/', $normalized) ?: [];
    $preview = implode(' ', array_slice($words, 0, 5));

    return trim($prefix . ': ' . $preview);
}

/**
 * @param array<string, mixed> $node
 * @param list<string> $path
 */
function nestedString(array $node, array $path): string
{
    $current = $node;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return '';
        }

        $current = $current[$segment];
    }

    return is_string($current) ? $current : '';
}

/**
 * @param array<string, array<string, mixed>> $pageResults
 * @param list<array<string, mixed>> $sectionComponentResults
 * @return array<string, mixed>
 */
function componentizeImportedPages(array &$pageResults, array $sectionComponentResults): array
{
    $componentsByFixture = groupSectionComponentsByFixture($sectionComponentResults);
    $summary = [
        'pagesProcessed' => 0,
        'pagesComponentized' => 0,
        'componentInstancesCreated' => 0,
        'editableTextPropertiesAttached' => 0,
        'pages' => [],
    ];

    foreach ($pageResults as $key => &$pageResult) {
        $postId = (int) ($pageResult['postId'] ?? 0);
        if ($postId < 1 || !isset($componentsByFixture[$key])) {
            continue;
        }

        $documentTree = readPersistedDocumentTree($postId);
        if ($documentTree === []) {
            continue;
        }

        $summary['pagesProcessed']++;
        $nextNodeId = isset($documentTree['_nextNodeId']) && is_numeric($documentTree['_nextNodeId'])
            ? (int) $documentTree['_nextNodeId']
            : (new OxygenDocumentTree())->calculateNextNodeId(is_array($documentTree['root'] ?? null) ? $documentTree['root'] : []);

        $replacements = $key === 'diagnosis'
            ? componentizeDiagnosisPageTree($documentTree, $componentsByFixture[$key], $nextNodeId)
            : componentizeStandardPageTree($documentTree, $componentsByFixture[$key], $nextNodeId);

        $componentInstances = count($replacements);
        $editableTextProperties = 0;
        foreach ($replacements as $replacement) {
            $editableTextProperties += (int) ($replacement['editableTextProperties'] ?? 0);
        }

        if ($componentInstances > 0) {
            $documentTree['_nextNodeId'] = max(
                $nextNodeId,
                (new OxygenDocumentTree())->calculateNextNodeId(is_array($documentTree['root'] ?? null) ? $documentTree['root'] : [])
            );
            persistDocumentTree($postId, $documentTree);
            $componentStylePersistence = syncComponentStylesToPage($postId, $replacements);
            updateMaximusManifestAfterComponentization($postId, $documentTree, $componentInstances);
            refreshRenderCache($postId);
            $summary['pagesComponentized']++;
            $summary['componentInstancesCreated'] += $componentInstances;
            $summary['editableTextPropertiesAttached'] += $editableTextProperties;
        } else {
            $componentStylePersistence = [
                'saved' => false,
                'bytes' => 0,
                'hash' => '',
                'componentCssBytes' => 0,
                'componentCssAssets' => 0,
            ];
        }

        $pageResult['componentization'] = [
            'componentized' => $componentInstances > 0,
            'componentInstances' => $componentInstances,
            'editableTextProperties' => $editableTextProperties,
            'replacements' => $replacements,
        ];
        $pageResult['componentStylePersistence'] = $componentStylePersistence;
        if (!empty($componentStylePersistence['saved'])) {
            $pageResult['pageStylePersistence'] = [
                'saved' => true,
                'bytes' => (int) ($componentStylePersistence['bytes'] ?? 0),
                'hash' => (string) ($componentStylePersistence['hash'] ?? ''),
            ];
        }
        $summary['pages'][$key] = $pageResult['componentization'];
    }
    unset($pageResult);

    return $summary;
}

/**
 * @param list<array<string, mixed>> $replacements
 * @return array<string, mixed>
 */
function syncComponentStylesToPage(int $postId, array $replacements): array
{
    $repository = new PageStyleRepository();
    $parts = [$repository->getCssForPost($postId)];
    $componentCssBytes = 0;
    $componentCssAssets = 0;
    $componentIds = [];

    foreach ($replacements as $replacement) {
        $componentId = (int) ($replacement['componentId'] ?? 0);
        if ($componentId < 1 || in_array($componentId, $componentIds, true)) {
            continue;
        }

        $componentCss = $repository->getCssForPost($componentId);
        if ($componentCss === '') {
            continue;
        }

        $parts[] = $componentCss;
        $componentCssBytes += strlen($componentCss);
        $componentCssAssets++;
        $componentIds[] = $componentId;
    }

    $combinedCss = joinUniqueCss($parts);
    $result = $repository->saveForPost($postId, ['pageScopedCss' => $combinedCss]);
    $result['componentCssBytes'] = $componentCssBytes;
    $result['componentCssAssets'] = $componentCssAssets;
    $result['componentIds'] = $componentIds;

    return $result;
}

/**
 * @param list<string> $parts
 */
function joinUniqueCss(array $parts): string
{
    $unique = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $unique[sha1($part)] = $part;
    }

    return implode("\n\n", array_values($unique));
}

/**
 * @param list<array<string, mixed>> $sectionComponentResults
 * @return array<string, array<int, array<string, mixed>>>
 */
function groupSectionComponentsByFixture(array $sectionComponentResults): array
{
    $grouped = [];
    foreach ($sectionComponentResults as $component) {
        $fixture = is_string($component['sourceFixture'] ?? null) ? $component['sourceFixture'] : '';
        $index = isset($component['sourceSectionIndex']) && is_numeric($component['sourceSectionIndex'])
            ? (int) $component['sourceSectionIndex']
            : 0;
        if ($fixture === '' || $index < 1) {
            continue;
        }

        $grouped[$fixture][$index] = $component;
    }

    foreach ($grouped as &$components) {
        ksort($components);
    }
    unset($components);

    return $grouped;
}

/**
 * @param array<string, mixed> $documentTree
 * @param array<int, array<string, mixed>> $componentsByIndex
 * @return list<array<string, mixed>>
 */
function componentizeStandardPageTree(array &$documentTree, array $componentsByIndex, int &$nextNodeId): array
{
    if (!isset($documentTree['root']) || !is_array($documentTree['root'])) {
        return [];
    }

    $orderedComponents = array_values($componentsByIndex);
    $replacements = [];
    $done = false;
    replaceSectionsInsideFirstMainNode($documentTree['root'], $orderedComponents, $nextNodeId, $replacements, $done);

    return $replacements;
}

/**
 * @param array<string, mixed> $node
 * @param list<array<string, mixed>> $orderedComponents
 * @param list<array<string, mixed>> $replacements
 */
function replaceSectionsInsideFirstMainNode(array &$node, array $orderedComponents, int &$nextNodeId, array &$replacements, bool &$done): void
{
    if ($done) {
        return;
    }

    if (hasNodeClass($node, 'maximus-main') && isset($node['children']) && is_array($node['children'])) {
        $componentIndex = 0;
        foreach ($node['children'] as &$child) {
            if (!is_array($child) || !isReusableSectionNode($child) || !isset($orderedComponents[$componentIndex])) {
                continue;
            }

            $sourceNodeId = isset($child['id']) && is_numeric($child['id']) ? (int) $child['id'] : 0;
            $component = $orderedComponents[$componentIndex];
            $child = buildOxygenComponentInstanceNode($nextNodeId++, $component);
            $replacements[] = componentReplacementRecord($sourceNodeId, $component);
            $componentIndex++;
        }
        unset($child);
        $done = true;
        return;
    }

    if (!isset($node['children']) || !is_array($node['children'])) {
        return;
    }

    foreach ($node['children'] as &$child) {
        if (is_array($child)) {
            replaceSectionsInsideFirstMainNode($child, $orderedComponents, $nextNodeId, $replacements, $done);
        }
    }
    unset($child);
}

/**
 * @param array<string, mixed> $documentTree
 * @param array<int, array<string, mixed>> $componentsByIndex
 * @return list<array<string, mixed>>
 */
function componentizeDiagnosisPageTree(array &$documentTree, array $componentsByIndex, int &$nextNodeId): array
{
    if (!isset($documentTree['root']) || !is_array($documentTree['root'])) {
        return [];
    }

    $orderedComponents = array_values($componentsByIndex);
    $componentIndex = 0;
    $replacements = [];
    $done = false;
    replaceDiagnosisComponentTargets($documentTree['root'], $orderedComponents, $componentIndex, $nextNodeId, $replacements, $done);

    return $replacements;
}

/**
 * @param array<string, mixed> $node
 * @param list<array<string, mixed>> $orderedComponents
 * @param list<array<string, mixed>> $replacements
 */
function replaceDiagnosisComponentTargets(
    array &$node,
    array $orderedComponents,
    int &$componentIndex,
    int &$nextNodeId,
    array &$replacements,
    bool &$done
): void {
    if ($done) {
        return;
    }

    if (isset($node['children']) && is_array($node['children'])) {
        $mainIndex = firstChildIndexWithClass($node['children'], 'maximus-main');
        if ($mainIndex !== null) {
            for ($index = 0; $index < $mainIndex; $index++) {
                if (!isset($orderedComponents[$componentIndex]) || !isset($node['children'][$index]) || !is_array($node['children'][$index])) {
                    continue;
                }

                if (!isDiagnosisPreMainComponentTarget($node['children'][$index])) {
                    continue;
                }

                $sourceNodeId = isset($node['children'][$index]['id']) && is_numeric($node['children'][$index]['id'])
                    ? (int) $node['children'][$index]['id']
                    : 0;
                $component = $orderedComponents[$componentIndex];
                $node['children'][$index] = buildOxygenComponentInstanceNode($nextNodeId++, $component);
                $replacements[] = componentReplacementRecord($sourceNodeId, $component);
                $componentIndex++;
            }

            if (isset($node['children'][$mainIndex]['children']) && is_array($node['children'][$mainIndex]['children'])) {
                foreach ($node['children'][$mainIndex]['children'] as &$mainChild) {
                    if (!isset($orderedComponents[$componentIndex]) || !is_array($mainChild) || !isDiagnosisMainComponentTarget($mainChild)) {
                        continue;
                    }

                    $sourceNodeId = isset($mainChild['id']) && is_numeric($mainChild['id']) ? (int) $mainChild['id'] : 0;
                    $component = $orderedComponents[$componentIndex];
                    $mainChild = buildOxygenComponentInstanceNode($nextNodeId++, $component);
                    $replacements[] = componentReplacementRecord($sourceNodeId, $component);
                    $componentIndex++;
                }
                unset($mainChild);
            }

            $done = true;
            return;
        }
    }

    if (!isset($node['children']) || !is_array($node['children'])) {
        return;
    }

    foreach ($node['children'] as &$child) {
        if (is_array($child)) {
            replaceDiagnosisComponentTargets($child, $orderedComponents, $componentIndex, $nextNodeId, $replacements, $done);
        }
    }
    unset($child);
}

/**
 * @param list<array<string, mixed>> $children
 */
function firstChildIndexWithClass(array $children, string $className): ?int
{
    foreach ($children as $index => $child) {
        if (is_array($child) && hasNodeClass($child, $className)) {
            return (int) $index;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $node
 */
function isDiagnosisPreMainComponentTarget(array $node): bool
{
    $tag = nodeTag($node);
    if (in_array($tag, ['header', 'footer', 'nav'], true)) {
        return false;
    }

    return ($node['data']['type'] ?? null) === ElementTypes::CONTAINER;
}

/**
 * @param array<string, mixed> $node
 */
function isDiagnosisMainComponentTarget(array $node): bool
{
    if (($node['data']['type'] ?? null) !== ElementTypes::CONTAINER) {
        return false;
    }

    $tag = nodeTag($node);
    return !in_array($tag, ['header', 'footer', 'nav'], true);
}

/**
 * @param array<string, mixed> $node
 */
function isReusableSectionNode(array $node): bool
{
    return nodeTag($node) === 'section' || hasNodeClass($node, 'maximus-section');
}

/**
 * @param array<string, mixed> $node
 */
function hasNodeClass(array $node, string $className): bool
{
    return in_array($className, nodeClasses($node), true);
}

/**
 * @param array<string, mixed> $node
 * @return list<string>
 */
function nodeClasses(array $node): array
{
    $classes = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
    if (!is_array($classes)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $classes), static fn (string $className): bool => $className !== ''));
}

/**
 * @param array<string, mixed> $node
 */
function nodeTag(array $node): string
{
    $properties = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];
    $tag = $properties['design']['tag'] ?? $properties['settings']['advanced']['tag'] ?? '';

    return is_string($tag) ? strtolower($tag) : '';
}

/**
 * @param array<string, mixed> $component
 * @return array<string, mixed>
 */
function buildOxygenComponentInstanceNode(int $nodeId, array $component): array
{
    $componentId = (int) ($component['postId'] ?? 0);
    $schema = is_array($component['componentProperties'] ?? null) ? $component['componentProperties'] : [];
    $targets = is_array($schema['targets'] ?? null) ? array_values($schema['targets']) : [];
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

    return [
        'id' => $nodeId,
        'data' => [
            'type' => 'OxygenElements\\Component',
            'properties' => [
                'content' => [
                    'content' => [
                        'block' => [
                            'componentId' => $componentId,
                            'targets' => $targets,
                            'properties' => $properties,
                        ],
                    ],
                ],
                'design' => [
                    'container' => [
                        'width' => [
                            'number' => 100,
                            'unit' => '%',
                            'style' => '100%',
                        ],
                    ],
                ],
                'settings' => [
                    'advanced' => [
                        'classes' => [
                            'maximus-component-instance',
                            'maximus-section-component-instance',
                        ],
                    ],
                ],
                'meta' => [
                    'classes' => [
                        deterministicSelectorUuid('maximus-component-instance'),
                        deterministicSelectorUuid('maximus-section-component-instance'),
                    ],
                ],
            ],
        ],
        'children' => [],
    ];
}

/**
 * @param array<string, mixed> $component
 * @return array<string, mixed>
 */
function componentReplacementRecord(int $sourceNodeId, array $component): array
{
    $schema = is_array($component['componentProperties'] ?? null) ? $component['componentProperties'] : [];

    return [
        'sourceNodeId' => $sourceNodeId,
        'componentId' => (int) ($component['postId'] ?? 0),
        'componentTitle' => (string) ($component['title'] ?? ''),
        'editableTextProperties' => count(is_array($schema['targets'] ?? null) ? $schema['targets'] : []),
    ];
}

/**
 * @return array<string, mixed>
 */
function readPersistedDocumentTree(int $postId): array
{
    $raw = get_post_meta($postId, oxygenMetaPrefix() . 'data', true);
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) {
        return [];
    }

    $treeJson = is_string($decoded['tree_json_string'] ?? null) ? $decoded['tree_json_string'] : '';
    if ($treeJson === '') {
        return [];
    }

    $documentTree = json_decode($treeJson, true);

    return is_array($documentTree) ? $documentTree : [];
}

/**
 * @param array<string, mixed> $documentTree
 */
function updateMaximusManifestAfterComponentization(int $postId, array $documentTree, int $componentInstances): void
{
    $raw = get_post_meta($postId, OxygenPageImporter::MANIFEST_META_KEY, true);
    $manifest = is_string($raw) ? json_decode(wp_unslash($raw), true) : $raw;
    if (!is_array($manifest)) {
        $manifest = ['version' => 1, 'source' => 'maximus-site-build'];
    }

    $manifest['componentizedAt'] = gmdate('c');
    $manifest['componentInstances'] = $componentInstances;
    $manifest['treeHash'] = sha1(wp_json_encode($documentTree) ?: '{}');

    update_post_meta($postId, OxygenPageImporter::MANIFEST_META_KEY, wp_slash(wp_json_encode($manifest)));
}

/**
 * @return list<array{html:string,label:string}>
 */
function extractMaximusReusableSectionFragments(string $html, string $fixtureKey): array
{
    $dom = loadHtmlDocument($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body instanceof DOMElement) {
        return [];
    }

    $fragments = [];
    foreach ($body->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        if ($tag === 'section') {
            $fragments[] = fragmentRecord($dom, $child);
            continue;
        }

        if ($fixtureKey === 'diagnosis' && $tag === 'div' && isBeforeFirstMain($child)) {
            $fragments[] = fragmentRecord($dom, $child, 'Progress Steps');
            continue;
        }

        if ($tag !== 'main') {
            continue;
        }

        foreach ($child->childNodes as $mainChild) {
            if (!$mainChild instanceof DOMElement) {
                continue;
            }

            $mainChildTag = strtolower($mainChild->tagName);
            if ($mainChildTag === 'section') {
                $fragments[] = fragmentRecord($dom, $mainChild);
            } elseif ($fixtureKey === 'diagnosis' && in_array($mainChildTag, ['div', 'form'], true)) {
                $fragments[] = fragmentRecord($dom, $mainChild);
            }
        }
    }

    return $fragments;
}

/**
 * @return array{html:string,label:string}
 */
function fragmentRecord(DOMDocument $dom, DOMElement $node, string $fallbackLabel = ''): array
{
    return [
        'html' => (string) $dom->saveHTML($node),
        'label' => componentLabelFromNode($node, $fallbackLabel),
    ];
}

function componentLabelFromNode(DOMElement $node, string $fallbackLabel = ''): string
{
    foreach (['h1', 'h2', 'h3'] as $tag) {
        $headings = $node->getElementsByTagName($tag);
        if ($headings->length > 0) {
            $text = humanTitleText((string) $headings->item(0)?->textContent);
            if ($text !== '') {
                return $text;
            }
        }
    }

    if ($fallbackLabel !== '') {
        return $fallbackLabel;
    }

    $text = humanTitleText((string) $node->textContent);
    if ($text === '') {
        return 'Section';
    }

    $words = preg_split('/\s+/', $text) ?: [];
    return implode(' ', array_slice($words, 0, 5));
}

function humanTitleText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = is_string($text) ? $text : '';
    $text = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    return ucwords(strtolower($text));
}

function maximusFixtureComponentLabel(string $key): string
{
    return [
        'home' => 'Home',
        'for_whom' => 'For Whom',
        'offer' => 'Offer',
        'starters' => 'Starters',
        'membership' => 'Membership',
        'locations' => 'Locations',
        'diagnosis' => 'Diagnosis',
    ][$key] ?? ucwords(str_replace('_', ' ', $key));
}

function createTemplateContentAreaTemplate(): array
{
    $postId = createOrUpdatePost('oxygen_template', 'Maximus Page Template', 'maximus-page-template');
    $root = [
        'id' => 1,
        'data' => [
            'type' => ElementTypes::CONTAINER,
            'properties' => [
                'design' => [
                    'tag' => 'div',
                    'layout' => [
                        'display' => 'flex',
                        'flex_direction' => 'column',
                        'min_height' => ['number' => 100, 'unit' => 'vh', 'style' => '100vh'],
                    ],
                ],
            ],
        ],
        'children' => [[
            'id' => 2,
            'data' => [
                'type' => 'OxygenElements\\TemplateContentArea',
                'properties' => [],
            ],
            'children' => [],
        ]],
    ];
    $documentTree = (new OxygenDocumentTree())->build($root);
    persistDocumentTree($postId, $documentTree);
    persistTemplateSettings($postId, [
        'type' => 'page',
        'ruleGroups' => [],
        'priority' => 20,
    ]);
    refreshRenderCache($postId);

    return [
        'postId' => $postId,
        'postType' => 'oxygen_template',
        'title' => 'Maximus Page Template',
        'slug' => 'maximus-page-template',
        'editUrl' => builderUrl($postId),
        'settings' => [
            'type' => 'page',
            'priority' => 20,
        ],
    ];
}

function createOrUpdatePost(string $postType, string $title, string $slug): int
{
    $existing = get_page_by_path($slug, OBJECT, $postType);
    $payload = [
        'post_type' => $postType,
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => sanitize_title($slug),
        'post_content' => '<!-- Imported by Oxygen HTML Converter Maximus site build -->',
    ];

    if ($existing instanceof WP_Post) {
        $payload['ID'] = (int) $existing->ID;
        $postId = wp_update_post($payload, true);
    } else {
        $postId = wp_insert_post($payload, true);
    }

    if (is_wp_error($postId)) {
        throw new RuntimeException($postId->get_error_message());
    }

    if (!is_numeric($postId) || (int) $postId < 1) {
        throw new RuntimeException('WordPress did not return a valid post id for ' . $title);
    }

    return (int) $postId;
}

function persistPayloadForPost(int $postId, array $payload, ?array $templateSettings): void
{
    $documentTree = is_array($payload['documentTree'] ?? null)
        ? (new OxygenDocumentTree())->build($payload['documentTree'])
        : (new OxygenDocumentTree())->build(is_array($payload['element'] ?? null) ? $payload['element'] : []);

    (new OxygenSelectorRepository())->savePayload(is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : []);
    (new GlobalStyleRepository())->saveFromPayload($payload);
    (new PageStyleRepository())->saveForPost($postId, $payload);
    (new OxygenVariableRepository())->saveFromPayload($payload);
    (new OxygenGlobalSettingsRepository())->saveFromPayload($payload);

    persistDocumentTree($postId, $documentTree);
    if ($templateSettings !== null) {
        persistTemplateSettings($postId, $templateSettings);
    }

    update_post_meta($postId, OxygenPageImporter::MANIFEST_META_KEY, wp_slash(wp_json_encode([
        'version' => 1,
        'importedAt' => gmdate('c'),
        'source' => 'maximus-site-build',
        'treeHash' => sha1(wp_json_encode($documentTree) ?: '{}'),
        'styleScope' => ($payload['pageScopedCss'] ?? '') !== '' ? 'page' : 'global',
        'nativeCoverage' => $payload['importPlan']['nativeCoverage'] ?? [],
    ])));

    refreshRenderCache($postId);
}

function persistDocumentTree(int $postId, array $documentTree): void
{
    $encodedTree = wp_json_encode($documentTree);
    $encodedTree = is_string($encodedTree) ? $encodedTree : '{}';

    if (function_exists('\Breakdance\Data\set_meta')) {
        \Breakdance\Data\set_meta($postId, oxygenMetaPrefix() . 'data', ['tree_json_string' => $encodedTree]);
        return;
    }

    update_post_meta($postId, oxygenMetaPrefix() . 'data', wp_slash(wp_json_encode(['tree_json_string' => $encodedTree])));
}

function persistTemplateSettings(int $postId, array $settings): void
{
    $settingsJson = wp_json_encode($settings);
    $settingsJson = is_string($settingsJson) ? $settingsJson : '{}';

    if (function_exists('\Breakdance\Data\set_meta')) {
        \Breakdance\Data\set_meta($postId, oxygenMetaPrefix() . 'template_settings', $settingsJson);
        return;
    }

    update_post_meta($postId, oxygenMetaPrefix() . 'template_settings', wp_slash(wp_json_encode($settingsJson)));
}

function oxygenMetaPrefix(): string
{
    if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
        return \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix');
    }

    return '_oxygen_';
}

function buildEverywhereExceptPostSettings(int $postId, int $priority): array
{
    $settings = [
        'type' => 'everywhere',
        'ruleGroups' => [],
        'priority' => $priority,
    ];

    if ($postId > 0) {
        $settings['ruleGroups'] = [[
            [
                'ruleSlug' => 'post-id',
                'operand' => 'is not',
                'value' => (string) $postId,
            ],
        ]];
    }

    return $settings;
}

function summarizeImportedPost(int $postId, string $postType, string $title, string $slug, array $payload, ?array $templateSettings): array
{
    $componentProperties = is_array($payload['componentProperties'] ?? null) ? $payload['componentProperties'] : [];
    $componentTargets = is_array($componentProperties['targets'] ?? null) ? $componentProperties['targets'] : [];

    return [
        'postId' => $postId,
        'postType' => $postType,
        'title' => $title,
        'slug' => $slug,
        'editUrl' => builderUrl($postId),
        'permalink' => get_permalink($postId),
        'templateSettings' => $templateSettings,
        'nativeCoverage' => $payload['importPlan']['nativeCoverage'] ?? [],
        'pageCssBytes' => strlen((string) ($payload['pageScopedCss'] ?? '')),
        'globalCssBytes' => strlen((string) ($payload['globalCss'] ?? '')),
        'selectorCount' => count(is_array($payload['selectorPayload']['selectors'] ?? null) ? $payload['selectorPayload']['selectors'] : []),
        'componentProperties' => [
            'editableTextProperties' => count($componentTargets),
            'targets' => $componentTargets,
            'properties' => is_array($componentProperties['properties'] ?? null) ? $componentProperties['properties'] : [],
        ],
    ];
}

function builderUrl(int $postId): string
{
    if (function_exists('\Breakdance\Admin\get_builder_loader_url')) {
        return (string) \Breakdance\Admin\get_builder_loader_url((string) $postId);
    }

    if (function_exists('\Breakdance\Themeless\get_builder_loader_url')) {
        return (string) \Breakdance\Themeless\get_builder_loader_url($postId);
    }

    return admin_url('post.php?post=' . $postId . '&action=edit');
}

function configureWordPressSite(array $pageResults, array $links): void
{
    update_option('blogname', 'Maximus');
    update_option('blogdescription', 'Physical Culture Club');

    if (isset($pageResults['home']['postId'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $pageResults['home']['postId']);
    }

    $menuId = wp_create_nav_menu('Maximus Main Navigation');
    if (!is_wp_error($menuId)) {
        foreach (['home', 'for_whom', 'offer', 'starters', 'membership', 'locations', 'diagnosis'] as $key) {
            if (!isset($pageResults[$key]['postId'])) {
                continue;
            }

            wp_update_nav_menu_item((int) $menuId, 0, [
                'menu-item-title' => (string) $pageResults[$key]['title'],
                'menu-item-object' => 'page',
                'menu-item-object-id' => (int) $pageResults[$key]['postId'],
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
            ]);
        }

        $locations = get_theme_mod('nav_menu_locations', []);
        $registered = get_registered_nav_menus();
        if (is_array($registered) && $registered !== []) {
            $firstLocation = array_key_first($registered);
            if (is_string($firstLocation)) {
                $locations[$firstLocation] = (int) $menuId;
                set_theme_mod('nav_menu_locations', $locations);
            }
        }
    }

    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }

    unset($links);
}

function collectSiteCounts(): array
{
    $counts = [];
    foreach (['page', 'post', 'oxygen_header', 'oxygen_footer', 'oxygen_template', 'oxygen_block'] as $postType) {
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $counts[$postType] = count($posts);
    }

    return $counts;
}

function collectGlobalStateSummary(): array
{
    $selectors = (new OxygenSelectorRepository())->getExistingSelectors();
    $variables = (new OxygenVariableRepository())->getExistingVariables();
    $settings = (new OxygenGlobalSettingsRepository())->getCurrentSettings();
    $globalStyles = (new GlobalStyleRepository())->getLibrary();

    return [
        'selectors' => count($selectors),
        'variables' => count($variables),
        'variableCollections' => (new OxygenVariableRepository())->getExistingCollections(),
        'paletteColors' => count($settings['settings']['colors']['palette']['colors'] ?? []),
        'globalStyleAssets' => count($globalStyles['styles'] ?? []),
    ];
}

/**
 * @param list<array<string, mixed>> $componentResults
 */
function collectMaximusDesignSystemSummary(array $componentResults): array
{
    $selectors = (new OxygenSelectorRepository())->getExistingSelectors();
    $semanticSelectors = array_values(array_filter($selectors, static function (array $selector): bool {
        return ($selector['collection'] ?? null) === maximusDesignSystemCollectionName();
    }));

    $sectionComponents = array_values(array_filter($componentResults, static function (array $component): bool {
        return ($component['componentKind'] ?? null) === 'section';
    }));

    return [
        'selectorCollection' => maximusDesignSystemCollectionName(),
        'semanticSelectors' => count($semanticSelectors),
        'sectionComponents' => count($sectionComponents),
        'sharedClassUsage' => collectMaximusSharedClassUsage(),
    ];
}

/**
 * @return array<string, int>
 */
function collectMaximusSharedClassUsage(): array
{
    $classes = [
        'maximus-section',
        'maximus-section-inner',
        'maximus-container',
        'maximus-grid',
        'maximus-card',
        'maximus-button',
        'maximus-heading',
        'maximus-body-copy',
        'maximus-component-instance',
        'maximus-section-component-instance',
        'maximus-nav',
        'maximus-questionnaire',
        'maximus-question-block',
        'maximus-progress-track',
        'maximus-progress-line',
        'maximus-progress-step',
        'maximus-progress-dot',
    ];
    $usage = array_fill_keys($classes, 0);
    $posts = get_posts([
        'post_type' => ['page', 'oxygen_header', 'oxygen_footer', 'oxygen_template', 'oxygen_block'],
        'post_status' => 'any',
        'posts_per_page' => -1,
    ]);

    foreach ($posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $raw = get_post_meta((int) $post->ID, oxygenMetaPrefix() . 'data', true);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $treeJson = is_array($decoded) && is_string($decoded['tree_json_string'] ?? null) ? $decoded['tree_json_string'] : '';
        foreach ($classes as $className) {
            $usage[$className] += substr_count($treeJson, '"' . $className . '"');
        }
    }

    return $usage;
}

function buildAcceptanceSummary(array $pageResults, array $headerResult, array $footerResult, array $templateResult, array $componentResults, array $componentizationResults): array
{
    $pageStylesSaved = 0;
    foreach ($pageResults as $page) {
        if (!empty($page['pageStylePersistence']['saved'])) {
            $pageStylesSaved++;
        }
    }

    return [
        'pagesPublished' => count($pageResults),
        'hasHeaderPostType' => !empty($headerResult['postId']),
        'hasFooterPostType' => !empty($footerResult['postId']),
        'hasPageTemplate' => !empty($templateResult['postId']),
        'componentsCreated' => count($componentResults),
        'sectionComponentsCreated' => count(array_filter($componentResults, static fn (array $component): bool => ($component['componentKind'] ?? null) === 'section')),
        'componentInstancesCreated' => (int) ($componentizationResults['componentInstancesCreated'] ?? 0),
        'pagesComponentized' => (int) ($componentizationResults['pagesComponentized'] ?? 0),
        'editableTextPropertiesAttached' => (int) ($componentizationResults['editableTextPropertiesAttached'] ?? 0),
        'pageScopedCssMovedOutOfCanvas' => $pageStylesSaved,
        'oxygenNativeClassMode' => get_option('oxy_html_converter_class_mode') === 'native',
        'frontPageAssigned' => (int) get_option('page_on_front') > 0,
    ];
}

function refreshRenderCache(int $postId): void
{
    delete_post_meta($postId, oxygenMetaPrefix() . 'dependency_cache');
    delete_post_meta($postId, oxygenMetaPrefix() . 'css_file_paths_cache');
    clean_post_cache($postId);

    if (is_callable('\Breakdance\Render\generateCacheForPost')) {
        \Breakdance\Render\generateCacheForPost($postId);
    }
}

function enrichWithMaximusBrandPayload(array $payload): array
{
    $tokens = maximusDesignTokens();
    $payload['designDocument'] = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
    $payload['designDocument']['tokens'] = mergeTokenGroups(
        is_array($payload['designDocument']['tokens'] ?? null) ? $payload['designDocument']['tokens'] : [],
        $tokens
    );
    $payload['oxygenGlobalSettings'] = maximusGlobalSettings();
    $payload['globalCss'] = is_string($payload['globalCss'] ?? null) ? (string) $payload['globalCss'] : '';

    return $payload;
}

function mergeTokenGroups(array $left, array $right): array
{
    foreach (['colors', 'spacing', 'fonts'] as $group) {
        $items = [];
        foreach ([$left[$group] ?? [], $right[$group] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $left[$group] = dedupeTokens($items);
    }

    return $left;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function dedupeTokens(array $items): array
{
    $seen = [];
    $deduped = [];
    foreach ($items as $item) {
        $key = strtolower((string) ($item['suggestedName'] ?? '') . ':' . (string) ($item['value'] ?? ''));
        if ($key === ':' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $item;
    }

    return $deduped;
}

function maximusDesignTokens(): array
{
    $colors = [
        'surface' => '#FFF8F5',
        'surface-container' => '#F7ECE6',
        'on-surface' => '#201A17',
        'outline' => '#89716F',
        'primary' => '#540306',
        'primary-container' => '#731B19',
        'secondary' => '#7A5827',
        'secondary-container' => '#FECF93',
        'tertiary' => '#002A3A',
        'oxblood-primary' => '#731B19',
        'oxblood-deep' => '#4D0907',
        'ivory-base' => '#F3EDE4',
        'paper-bright' => '#FCF9F4',
        'paper-soft' => '#E8DED0',
        'ink-black' => '#17120F',
        'ink-soft' => '#544B45',
        'brass-accent' => '#9A7440',
        'copper-highlight' => '#BE8656',
    ];
    $spacing = [
        'space-unit' => '8px',
        'space-margin-page' => '64px',
        'space-gutter-grid' => '24px',
        'space-section-gap' => '120px',
        'space-component-padding' => '16px',
    ];
    $fonts = [
        'font-hero-serif' => 'Noto Serif',
        'font-body-main' => 'Inter',
    ];

    return [
        'colors' => array_map(static fn (string $name, string $value): array => [
            'value' => $value,
            'uses' => 1,
            'suggestedName' => $name,
        ], array_keys($colors), $colors),
        'spacing' => array_map(static fn (string $name, string $value): array => [
            'value' => $value,
            'uses' => 1,
            'suggestedName' => $name,
        ], array_keys($spacing), $spacing),
        'fonts' => array_map(static fn (string $name, string $value): array => [
            'value' => $value,
            'uses' => 1,
            'suggestedName' => $name,
        ], array_keys($fonts), $fonts),
    ];
}

function maximusGlobalSettings(): array
{
    $palette = [];
    foreach (maximusDesignTokens()['colors'] as $token) {
        $name = strtolower((string) $token['suggestedName']);
        $palette[] = [
            'label' => ucwords(str_replace('-', ' ', $name)),
            'cssVariableName' => 'ohc-' . $name,
            'value' => (string) $token['value'],
        ];
    }

    return [
        'settings' => [
            'colors' => [
                'brand' => '#731B19',
                'text' => '#201A17',
                'headings' => '#17120F',
                'links' => '#731B19',
                'background' => '#FFF8F5',
                'palette' => [
                    'colors' => $palette,
                ],
            ],
            'typography' => [
                'body' => [
                    'font_family' => 'Inter',
                    'font_size' => '16px',
                    'line_height' => '1.6',
                ],
                'headings' => [
                    'font_family' => 'Noto Serif',
                    'line_height' => '1.15',
                ],
            ],
            'containers' => [
                'container_width' => '1536px',
            ],
        ],
    ];
}

function maximusFontFaceCss(): string
{
    return implode("\n", [
        '@font-face { font-family: "Inter"; font-style: normal; font-weight: 400 600; font-display: swap; src: url("https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }',
        '@font-face { font-family: "Inter"; font-style: normal; font-weight: 400 600; font-display: swap; src: url("https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa25L7SUc.woff2") format("woff2"); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }',
        '@font-face { font-family: "Noto Serif"; font-style: normal; font-weight: 400 900; font-display: swap; src: url("https://fonts.gstatic.com/s/notoserif/v33/ga6daw1J5X9T9RW6j9bNVls-hfgvz8JcMofYTYf6D30.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }',
        '@font-face { font-family: "Noto Serif"; font-style: normal; font-weight: 400 900; font-display: swap; src: url("https://fonts.gstatic.com/s/notoserif/v33/ga6daw1J5X9T9RW6j9bNVls-hfgvz8JcMofYTYf0D33Esw.woff2") format("woff2"); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }',
        '@font-face { font-family: "Noto Serif"; font-style: italic; font-weight: 400; font-display: swap; src: url("https://fonts.gstatic.com/s/notoserif/v33/ga6saw1J5X9T9RW6j9bNfFIMZhhWnFTyNZIQD1-_FXP0RgnaOg9MYBNLg_cIrqs.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }',
        '@font-face { font-family: "Noto Serif"; font-style: italic; font-weight: 400; font-display: swap; src: url("https://fonts.gstatic.com/s/notoserif/v33/ga6saw1J5X9T9RW6j9bNfFIMZhhWnFTyNZIQD1-_FXP0RgnaOg9MYBNLg_cGrqvyzw.woff2") format("woff2"); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }',
        '@font-face { font-family: "Material Symbols Outlined"; font-style: normal; font-weight: 300; font-display: swap; src: url("https://fonts.gstatic.com/s/materialsymbolsoutlined/v338/kJF1BvYX7BgnkSrUwT8OhrdQw4oELdPIeeII9v6oDMzByHX9rA6RzaxHMPdY43zj-jCxv3fzvRNU22ZXGJpEpjC_1v-p_4MrImHCIJIZrDDxHOem.ttf") format("truetype"); }',
    ]);
}

function maximusGlobalAssetCss(): string
{
    return implode("\n", [
        '/* Maximus base site-kit global asset. */',
        maximusFontFaceCss(),
        ':root { --ohc-surface: #FFF8F5; --ohc-surface-container: #F7ECE6; --ohc-on-surface: #201A17; --ohc-outline: #89716F; --ohc-primary: #540306; --ohc-primary-container: #731B19; --ohc-secondary: #7A5827; --ohc-secondary-container: #FECF93; --ohc-tertiary: #002A3A; --ohc-oxblood-primary: #731B19; --ohc-oxblood-deep: #4D0907; --ohc-ivory-base: #F3EDE4; --ohc-paper-bright: #FCF9F4; --ohc-paper-soft: #E8DED0; --ohc-ink-black: #17120F; --ohc-ink-soft: #544B45; --ohc-brass-accent: #9A7440; --ohc-copper-highlight: #BE8656; --ohc-space-unit: 8px; --ohc-space-margin-page: 64px; --ohc-space-gutter-grid: 24px; --ohc-space-section-gap: 120px; --ohc-space-component-padding: 16px; --ohc-font-hero-serif: "Noto Serif", Georgia, serif; --ohc-font-body-main: Inter, "Segoe UI", sans-serif; }',
        'body.oxygen, body.oxygen .bde-themeless-template-content-area, .maximus-page, .maximus-main { font-family: var(--ohc-font-body-main) !important; color: var(--ohc-on-surface) !important; background-color: var(--ohc-surface) !important; }',
        '.material-symbols-outlined { font-family: "Material Symbols Outlined" !important; font-weight: normal !important; font-style: normal !important; line-height: 1 !important; letter-spacing: normal !important; text-transform: none !important; display: inline-block !important; white-space: nowrap !important; word-wrap: normal !important; direction: ltr !important; font-feature-settings: "liga" !important; -webkit-font-feature-settings: "liga" !important; -webkit-font-smoothing: antialiased !important; font-variation-settings: "FILL" 0, "wght" 300, "GRAD" 0, "opsz" 24; }',
        '.material-symbols-outlined.fill, .material-symbols-outlined.icon-fill { font-variation-settings: "FILL" 1, "wght" 300, "GRAD" 0, "opsz" 24; }',
        '.font-hero-serif { font-family: "Noto Serif", Georgia, serif !important; }',
        '.text-hero-serif { font-size: 64px !important; line-height: 1.1 !important; letter-spacing: 0 !important; }',
        '.font-section-headline { font-family: "Noto Serif", Georgia, serif !important; }',
        '.text-section-headline { font-size: 32px !important; line-height: 1.2 !important; }',
        '.font-body-main { font-family: Inter, "Segoe UI", sans-serif !important; }',
        '.text-body-main { font-size: 16px !important; line-height: 1.6 !important; }',
        '.font-metadata-label { font-family: Inter, "Segoe UI", sans-serif !important; font-weight: 600 !important; }',
        '.text-metadata-label { font-size: 12px !important; line-height: 1.4 !important; letter-spacing: 0.1em !important; }',
        '.font-wordmark-aux { font-family: "Noto Serif", Georgia, serif !important; font-weight: 500 !important; }',
        '.text-wordmark-aux { font-size: 14px !important; line-height: 1 !important; letter-spacing: 0.2em !important; }',
        '.maximus-page { font-family: var(--ohc-font-body-main) !important; color: var(--ohc-on-surface) !important; background-color: var(--ohc-surface) !important; }',
        '.maximus-main { display: flex !important; flex-direction: column !important; width: 100% !important; }',
        '.maximus-shell { width: 100% !important; background-color: var(--ohc-surface) !important; }',
        '.maximus-site-header { display: flex !important; justify-content: space-between !important; align-items: center !important; width: 100% !important; position: sticky !important; top: 0 !important; z-index: 50 !important; background-color: var(--ohc-surface) !important; border-bottom: 1px solid var(--ohc-paper-soft) !important; }',
        '.maximus-site-header-inner { display: flex !important; justify-content: space-between !important; align-items: center !important; width: 100% !important; max-width: 1536px !important; padding: 1.5rem 2rem !important; margin-left: auto !important; margin-right: auto !important; }',
        '.maximus-nav { display: flex !important; align-items: center !important; gap: 2rem !important; width: auto !important; }',
        '.maximus-site-nav { font-family: var(--ohc-font-hero-serif) !important; font-size: 12px !important; line-height: 1 !important; font-weight: 500 !important; text-transform: uppercase !important; }',
        '.maximus-site-nav a, .maximus-footer-nav a { color: #78716c !important; text-decoration: none !important; transition: color 150ms ease, background-color 150ms ease, border-color 150ms ease !important; }',
        '.maximus-site-nav a:hover, .maximus-footer-nav a:hover { color: var(--ohc-oxblood-primary) !important; }',
        '.maximus-site-actions { display: none !important; align-items: center !important; gap: 1.5rem !important; width: auto !important; }',
        '.maximus-site-actions a:first-child { color: var(--ohc-oxblood-primary) !important; text-transform: uppercase !important; text-decoration: none !important; }',
        '.maximus-wordmark { font-family: var(--ohc-font-hero-serif) !important; font-weight: 900 !important; color: var(--ohc-oxblood-primary) !important; letter-spacing: 0 !important; }',
        '.maximus-site-header .maximus-wordmark { font-size: 1.875rem !important; line-height: 2.25rem !important; }',
        '.maximus-site-footer .maximus-wordmark { font-size: 1.25rem !important; line-height: 1.75rem !important; }',
        '.maximus-mobile-menu-icon { display: none !important; color: var(--ohc-oxblood-primary) !important; }',
        '.maximus-site-footer { width: 100% !important; border-top: 1px solid var(--ohc-paper-soft) !important; background-color: var(--ohc-surface) !important; }',
        '.maximus-site-footer-inner { display: flex !important; justify-content: space-between !important; align-items: center !important; gap: 2rem !important; width: 100% !important; max-width: 1536px !important; padding: 4rem 3rem !important; margin-left: auto !important; margin-right: auto !important; }',
        '.maximus-footer-nav { display: flex !important; flex-wrap: wrap !important; justify-content: center !important; gap: 1.5rem !important; font-family: var(--ohc-font-hero-serif) !important; font-size: 10px !important; letter-spacing: 0.2em !important; text-transform: uppercase !important; }',
        '.maximus-footer-legal { font-family: var(--ohc-font-hero-serif) !important; font-size: 10px !important; letter-spacing: 0.2em !important; text-transform: uppercase !important; text-align: right !important; color: var(--ohc-oxblood-primary) !important; }',
        '.maximus-component-instance, .maximus-section-component-instance { display: block !important; width: 100% !important; }',
        '.maximus-section { width: 100% !important; max-width: none !important; display: flex !important; flex-direction: column !important; justify-content: center !important; align-items: center !important; padding-top: var(--ohc-space-section-gap) !important; padding-bottom: var(--ohc-space-section-gap) !important; }',
        '.maximus-section.oxy-container, .maximus-main > .oxy-container.maximus-section { width: 100% !important; max-width: none !important; }',
        '.maximus-questionnaire { display: flex !important; flex-direction: column !important; gap: 5rem !important; width: 100% !important; }',
        '.maximus-question-block { display: block !important; width: 100% !important; padding: 0 !important; }',
        '.maximus-progress-track { display: flex !important; position: relative !important; justify-content: space-between !important; align-items: center !important; width: 100% !important; }',
        '.maximus-progress-line { position: absolute !important; left: 0 !important; top: 50% !important; width: 100% !important; height: 1px !important; transform: translateY(-50%) !important; background-color: rgba(137, 113, 111, 0.4) !important; z-index: 0 !important; }',
        '.maximus-progress-step { display: flex !important; position: relative !important; z-index: 1 !important; flex-direction: column !important; align-items: center !important; gap: 0.75rem !important; width: auto !important; padding-left: 1rem !important; padding-right: 1rem !important; padding-top: 0 !important; padding-bottom: 0 !important; background-color: var(--ohc-ivory-base) !important; border: 0 !important; }',
        '.maximus-progress-dot { width: 0.625rem !important; height: 0.625rem !important; border-radius: 9999px !important; box-shadow: 0 0 0 6px var(--ohc-ivory-base) !important; }',
        '.maximus-section-inner { width: 100% !important; max-width: 1536px !important; }',
        '.maximus-container { width: 100% !important; }',
        '.maximus-grid { display: grid !important; gap: var(--ohc-space-gutter-grid) !important; width: 100% !important; }',
        '.maximus-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }',
        '.maximus-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }',
        '.maximus-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }',
        '.maximus-heading { font-family: var(--ohc-font-hero-serif) !important; color: var(--ohc-ink-black) !important; letter-spacing: 0 !important; }',
        '.maximus-heading-xl { font-size: 64px !important; line-height: 1.1 !important; font-weight: 700 !important; }',
        '.maximus-heading-lg { font-size: 40px !important; line-height: 1.2 !important; font-weight: 700 !important; }',
        '.maximus-heading-md { font-size: 24px !important; line-height: 1.25 !important; font-weight: 600 !important; }',
        '.maximus-body-copy { font-family: var(--ohc-font-body-main) !important; font-size: 16px !important; line-height: 1.6 !important; color: var(--ohc-ink-soft) !important; }',
        '.maximus-card { background-color: var(--ohc-paper-soft) !important; border: 1px solid var(--ohc-outline) !important; padding: var(--ohc-space-component-padding) !important; }',
        '.maximus-card-basic { background-color: var(--ohc-paper-soft) !important; }',
        '.maximus-card-program, .maximus-card-path, .maximus-card-location { background-color: var(--ohc-paper-bright) !important; }',
        '.maximus-card-selected { background-color: var(--ohc-primary-container) !important; color: #FFFFFF !important; }',
        '.maximus-card-selected .maximus-heading, .maximus-card-selected h1, .maximus-card-selected h2, .maximus-card-selected h3, .maximus-card-selected h4, .maximus-card-selected .text-ink-black, .maximus-card-selected .text-surface-container-highest { color: #FFFFFF !important; }',
        '.maximus-card-selected .maximus-body-copy, .maximus-card-selected p, .maximus-card-selected .text-ink-soft, .maximus-card-selected .text-surface-container-high { color: rgba(255, 255, 255, 0.82) !important; }',
        '.maximus-progress-step.maximus-card { width: auto !important; padding-left: 1rem !important; padding-right: 1rem !important; padding-top: 0 !important; padding-bottom: 0 !important; border: 0 !important; background-color: var(--ohc-ivory-base) !important; }',
        '.maximus-button { display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 12px !important; text-decoration: none !important; text-align: center !important; border-radius: 0 !important; }',
        '.maximus-button .maximus-icon, .maximus-button .material-symbols-outlined { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 1em !important; height: 1em !important; min-width: 1em !important; line-height: 1 !important; vertical-align: middle !important; }',
        '.maximus-icon { font-family: "Material Symbols Outlined" !important; font-weight: 300 !important; line-height: 1 !important; font-feature-settings: "liga" !important; -webkit-font-feature-settings: "liga" !important; }',
        '.flex { display: flex !important; } .inline-flex { display: inline-flex !important; } .grid { display: grid !important; } .block { display: block !important; } .hidden { display: none !important; }',
        '.flex-col { flex-direction: column !important; } .items-center { align-items: center !important; } .items-end { align-items: flex-end !important; } .justify-between { justify-content: space-between !important; } .justify-center { justify-content: center !important; } .justify-end { justify-content: flex-end !important; }',
        '.relative { position: relative !important; } .absolute { position: absolute !important; } .left-0 { left: 0 !important; } .top-1\\/2 { top: 50% !important; } .-translate-y-1\\/2 { transform: translateY(-50%) !important; } .-z-10 { z-index: -10 !important; }',
        '.w-full { width: 100% !important; } .h-full { height: 100% !important; } .h-\\[1px\\] { height: 1px !important; } .w-2\\.5 { width: 0.625rem !important; } .h-2\\.5 { height: 0.625rem !important; } .w-5 { width: 1.25rem !important; } .h-5 { height: 1.25rem !important; }',
        '.max-w-lg { max-width: 32rem !important; } .max-w-3xl { max-width: 48rem !important; } .max-w-4xl { max-width: 56rem !important; } .mx-auto { margin-left: auto !important; margin-right: auto !important; }',
        '.gap-2 { gap: 0.5rem !important; } .gap-3 { gap: 0.75rem !important; } .gap-4 { gap: 1rem !important; } .gap-6 { gap: 1.5rem !important; } .gap-8 { gap: 2rem !important; } .gap-20 { gap: 5rem !important; }',
        '.grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; } .grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } .grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }',
        '.text-center { text-align: center !important; } .uppercase { text-transform: uppercase !important; } .tracking-widest { letter-spacing: 0.1em !important; } .rounded-full { border-radius: 9999px !important; }',
        '.border { border-width: 1px !important; border-style: solid !important; } .border-b { border-bottom-width: 1px !important; border-bottom-style: solid !important; } .border-t { border-top-width: 1px !important; border-top-style: solid !important; } .border-y { border-top-width: 1px !important; border-bottom-width: 1px !important; border-top-style: solid !important; border-bottom-style: solid !important; }',
        '.p-8 { padding: 2rem !important; } .p-10 { padding: 2.5rem !important; } .px-4 { padding-left: 1rem !important; padding-right: 1rem !important; } .px-6 { padding-left: 1.5rem !important; padding-right: 1.5rem !important; } .px-8 { padding-left: 2rem !important; padding-right: 2rem !important; } .px-10 { padding-left: 2.5rem !important; padding-right: 2.5rem !important; }',
        '.py-5 { padding-top: 1.25rem !important; padding-bottom: 1.25rem !important; } .py-6 { padding-top: 1.5rem !important; padding-bottom: 1.5rem !important; } .py-8 { padding-top: 2rem !important; padding-bottom: 2rem !important; } .pt-12 { padding-top: 3rem !important; } .pt-16 { padding-top: 4rem !important; } .pb-4 { padding-bottom: 1rem !important; } .pb-12 { padding-bottom: 3rem !important; } .pb-32 { padding-bottom: 8rem !important; }',
        '.mb-1 { margin-bottom: 0.25rem !important; } .mb-2 { margin-bottom: 0.5rem !important; } .mb-4 { margin-bottom: 1rem !important; } .mb-6 { margin-bottom: 1.5rem !important; } .mb-8 { margin-bottom: 2rem !important; } .mb-12 { margin-bottom: 3rem !important; } .mb-16 { margin-bottom: 4rem !important; } .mb-20 { margin-bottom: 5rem !important; } .mt-auto { margin-top: auto !important; }',
        '.pt-margin-page { padding-top: var(--ohc-space-margin-page) !important; }',
        '.pb-section-gap { padding-bottom: var(--ohc-space-section-gap) !important; }',
        '.py-section-gap { padding-top: var(--ohc-space-section-gap) !important; padding-bottom: var(--ohc-space-section-gap) !important; }',
        '.px-gutter-grid { padding-left: var(--ohc-space-gutter-grid) !important; padding-right: var(--ohc-space-gutter-grid) !important; }',
        '.gap-gutter-grid { gap: var(--ohc-space-gutter-grid) !important; }',
        '.p-component-padding { padding: var(--ohc-space-component-padding) !important; }',
        '.bg-ivory-base { background-color: #F3EDE4 !important; }',
        '.bg-paper-bright { background-color: #FCF9F4 !important; }',
        '.bg-paper-soft { background-color: #E8DED0 !important; }',
        '.bg-oxblood-primary { background-color: #731B19 !important; }',
        '.bg-oxblood-deep { background-color: #4D0907 !important; }',
        '.bg-ink-black { background-color: #17120F !important; }',
        '.bg-primary { background-color: #540306 !important; }',
        '.bg-primary\\/5 { background-color: rgba(84, 3, 6, 0.05) !important; }',
        '.bg-outline-variant { background-color: rgba(137, 113, 111, 0.4) !important; }',
        '.bg-outline-variant\\/40 { background-color: rgba(137, 113, 111, 0.4) !important; }',
        '.text-oxblood-primary { color: #731B19 !important; }',
        '.text-oxblood-deep { color: #4D0907 !important; }',
        '.text-primary { color: #540306 !important; }',
        '.text-outline { color: #89716F !important; }',
        '.text-ink-black { color: #17120F !important; }',
        '.text-ink-soft { color: #544B45 !important; }',
        '.text-brass-accent { color: #9A7440 !important; }',
        '.text-surface-container-highest { color: #FFFFFF !important; }',
        '.text-surface-container-high { color: rgba(255, 255, 255, 0.82) !important; }',
        '.text-on-primary { color: #FFFFFF !important; }',
        '.text-on-primary\\/70 { color: rgba(255, 255, 255, 0.7) !important; }',
        '.text-on-primary\\/80 { color: rgba(255, 255, 255, 0.8) !important; }',
        '.text-on-surface, .text-on-background { color: #201A17 !important; }',
        '.border-primary { border-color: #540306 !important; }',
        '.border-outline-variant { border-color: rgba(137, 113, 111, 0.4) !important; }',
        '.border-outline-variant\\/30 { border-color: rgba(137, 113, 111, 0.3) !important; }',
        '.border-outline-variant\\/40 { border-color: rgba(137, 113, 111, 0.4) !important; }',
        '.border-paper-soft { border-color: #E8DED0 !important; }',
        '.border-oxblood-deep { border-color: #4D0907 !important; }',
        '.border-brass-accent { border-color: #9A7440 !important; }',
        '.ring-ivory-base { box-shadow: 0 0 0 6px #F3EDE4 !important; }',
        '.opacity-0 { opacity: 0 !important; }',
        '.maximus-button-outline, .maximus-button-secondary { display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 0.5rem !important; min-height: 52px !important; padding-top: 1rem !important; padding-right: 2rem !important; padding-bottom: 1rem !important; padding-left: 2rem !important; border: 1px solid rgba(84, 75, 69, 0.32) !important; background-color: transparent !important; color: var(--ohc-ink-black) !important; line-height: 1.4 !important; text-decoration: none !important; box-shadow: none !important; }',
        '.maximus-main .maximus-button.maximus-button-outline, .maximus-main .maximus-button.maximus-button-secondary, .maximus-page .maximus-button.maximus-button-outline, .maximus-page .maximus-button.maximus-button-secondary { padding-top: 1rem !important; padding-right: 2rem !important; padding-bottom: 1rem !important; padding-left: 2rem !important; min-height: 52px !important; }',
        '.maximus-card .maximus-button.maximus-button-outline, .maximus-card .maximus-button.maximus-button-secondary { width: 100% !important; margin-top: auto !important; padding-top: 1rem !important; padding-right: 2rem !important; padding-bottom: 1rem !important; padding-left: 2rem !important; border-color: rgba(137, 113, 111, 0.24) !important; }',
        '.maximus-card-selected .maximus-button-outline, .maximus-card-selected .maximus-button-secondary { color: #FFFFFF !important; border-color: rgba(255, 255, 255, 0.62) !important; }',
        '.maximus-card-selected .maximus-button-outline .maximus-icon, .maximus-card-selected .maximus-button-outline .material-symbols-outlined, .maximus-card-selected .maximus-button-secondary .maximus-icon, .maximus-card-selected .maximus-button-secondary .material-symbols-outlined { color: #FFFFFF !important; }',
        '.maximus-button.bg-brass-accent, button.bg-brass-accent, a.bg-brass-accent { color: #000000 !important; }',
        '.maximus-button-primary:hover { background-color: #731B19 !important; }',
        '.maximus-button-outline:hover, .maximus-button-secondary:hover { color: #731B19 !important; border-color: #731B19 !important; background-color: rgba(115, 27, 25, 0.04) !important; }',
        '.maximus-card-selected .maximus-button-outline:hover, .maximus-card-selected .maximus-button-secondary:hover { color: #FFFFFF !important; border-color: rgba(255, 255, 255, 0.72) !important; background-color: rgba(255, 255, 255, 0.06) !important; }',
        '@media (min-width: 640px) { .grid-cols-1.sm\\:grid-cols-3, .maximus-grid.grid-cols-1.sm\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } }',
        '@media (min-width: 768px) { .md\\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } .md\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .md\\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; } .md\\:block { display: block !important; } .md\\:flex { display: flex !important; } .md\\:px-12 { padding-left: 3rem !important; padding-right: 3rem !important; } }',
        '@media (min-width: 768px) { .maximus-site-actions { display: flex !important; } .maximus-site-nav { display: flex !important; } .maximus-mobile-menu-icon { display: none !important; } }',
        '@media (min-width: 768px) { .grid-cols-1.md\\:grid-cols-2, .maximus-grid.grid-cols-1.md\\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } .grid-cols-1.md\\:grid-cols-3, .maximus-grid.grid-cols-1.md\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .grid-cols-1.md\\:grid-cols-4, .maximus-grid.grid-cols-1.md\\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; } .grid-cols-1.md\\:grid-cols-12, .maximus-grid.grid-cols-1.md\\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; } .md\\:col-span-8.md\\:col-start-3 { grid-column: 3 / span 8 !important; } }',
        '@media (min-width: 1024px) { .grid-cols-1.lg\\:grid-cols-3, .maximus-grid.grid-cols-1.lg\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .grid-cols-1.lg\\:grid-cols-12, .maximus-grid.grid-cols-1.lg\\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; } .lg\\:col-span-5.lg\\:col-start-2 { grid-column: 2 / span 5 !important; } .lg\\:col-span-5.lg\\:col-start-8 { grid-column: 8 / span 5 !important; } .lg\\:col-span-7.lg\\:col-start-6 { grid-column: 6 / span 7 !important; } .lg\\:col-span-8.lg\\:col-start-1 { grid-column: 1 / span 8 !important; } .lg\\:col-span-6.lg\\:col-start-7 { grid-column: 7 / span 6 !important; } .lg\\:col-span-4.lg\\:col-start-9 { grid-column: 9 / span 4 !important; } }',
        '@media (max-width: 767px) { .text-hero-serif, .maximus-heading-xl { font-size: 48px !important; line-height: 1.08 !important; } .py-section-gap, .maximus-section { padding-top: 72px !important; padding-bottom: 72px !important; } .maximus-question-block { padding-top: 0 !important; padding-bottom: 0 !important; } .pb-section-gap { padding-bottom: 72px !important; } .pt-margin-page { padding-top: 48px !important; } .maximus-grid-2, .maximus-grid-3, .maximus-grid-4 { grid-template-columns: 1fr !important; } .grid-cols-4.maximus-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; } .maximus-site-header { justify-content: flex-start !important; overflow: hidden !important; } .maximus-site-header-inner { padding: 2rem !important; } .maximus-site-header .maximus-nav, .maximus-site-actions { display: none !important; } .maximus-mobile-menu-icon { display: block !important; margin-left: auto !important; } .maximus-site-footer-inner { flex-direction: column !important; justify-content: center !important; text-align: center !important; } .maximus-footer-legal { text-align: center !important; } .maximus-button { width: 100% !important; } }',
    ]);
}

function loadHtmlDocument(string $html): DOMDocument
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    return $dom;
}

function saveHtmlDocument(DOMDocument $dom): string
{
    $html = (string) $dom->saveHTML();
    return preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $html) ?? $html;
}

function normalizeText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = is_string($text) ? strtolower($text) : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}
