<?php

declare(strict_types=1);

define('OHC_MAXIMUS_BUILD_LIB_ONLY', true);

$maximusBuildLib = getenv('OHC_MAXIMUS_BUILD_LIB');
if (!is_string($maximusBuildLib) || $maximusBuildLib === '') {
    $maximusBuildLib = '/tmp/ohc-build-maximus-site.php';
}
if (!is_file($maximusBuildLib)) {
    $maximusBuildLib = __DIR__ . '/build-maximus-site.php';
}
if (!is_file($maximusBuildLib)) {
    fwrite(STDERR, 'Unable to locate Maximus build library.' . PHP_EOL);
    exit(1);
}

require_once $maximusBuildLib;

$options = parseCliOptions(array_slice($argv, 1));
$fixtureRoot = is_string($options['fixtures'] ?? null) && trim((string) $options['fixtures']) !== ''
    ? rtrim((string) $options['fixtures'], '/')
    : '/tmp/ohc-new-maximus-fixtures';
$remoteReportPath = is_string($options['report'] ?? null) && trim((string) $options['report']) !== ''
    ? (string) $options['report']
    : '/tmp/ohc-maximus-v2-import-report.json';

try {
    if (!is_dir($fixtureRoot)) {
        throw new RuntimeException('Fixture root not found: ' . $fixtureRoot);
    }

    update_option('oxy_html_converter_class_mode', 'native');
    update_option('oxy_html_converter_element_mapping_mode', 'oxygen');

    $before = collectMaximusV2ProtectionSnapshot();
    seedMaximusBrandSystem();
    $v2UtilityFallback = seedMaximusV2UtilityFallbackCss();

    $fixtures = maximusV2FixtureDefinitions($fixtureRoot);
    $links = buildMaximusV2InitialLinkMap($fixtures);
    $pageImporter = new \OxyHtmlConverter\Services\OxygenPageImporter();
    $pageResults = [];

    foreach ($fixtures as $key => $fixture) {
        $sourceHtml = readFixtureHtml($fixture['path']);
        $pageHtml = rewriteMaximusV2HtmlLinks($sourceHtml, $links);
        $pageHtml = stripDocumentShell($pageHtml);

        $payload = buildMaximusV2ConversionPayload($pageHtml, $fixture['title'], $fixture['slug'], 'page');
        $payload['replaceExisting'] = true;
        $payload['postStatus'] = 'publish';
        $payload['sourceHash'] = sha1($sourceHtml);
        $payload = routeCanvasCssOutOfTree($payload, 'page');
        $payload = enrichWithMaximusBrandPayload($payload);

        $result = $pageImporter->import($payload);
        if (empty($result['success'])) {
            throw new RuntimeException('V2 page import failed for ' . $fixture['slug'] . ': ' . (string) ($result['message'] ?? 'unknown'));
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
            'sourceFixture' => $fixture['relativePath'],
        ];

        if (is_string($result['permalink'] ?? null) && $result['permalink'] !== '') {
            $links[$key] = (string) $result['permalink'];
        }
    }

    $layoutResults = importMaximusV2InactiveLayouts($fixtures, $links);
    $sectionComponentResults = importMaximusV2SectionComponents($fixtures, $links);
    $componentizationResults = componentizeImportedPages($pageResults, $sectionComponentResults);

    $allImportedPostIds = array_values(array_filter(array_map('intval', array_merge(
        array_column($pageResults, 'postId'),
        array_column($layoutResults['headers'], 'postId'),
        array_column($layoutResults['footers'], 'postId'),
        array_column($sectionComponentResults, 'postId')
    )), static fn (int $postId): bool => $postId > 0));
    $brandNormalization = normalizeMaximusV2PersistedBrandText(array_merge($allImportedPostIds, maximusV2SharedBrandPostIds()));

    $after = collectMaximusV2ProtectionSnapshot();
    $report = [
        'ok' => true,
        'generatedAt' => gmdate('c'),
        'mode' => 'additive',
        'fixtureRoot' => $fixtureRoot,
        'dataSafety' => [
            'resetPerformed' => false,
            'existingPostIdsBefore' => $before['postIds'],
            'existingPostIdsMissingAfter' => array_values(array_diff($before['postIds'], $after['postIds'])),
            'postCountsBefore' => $before['counts'],
            'postCountsAfter' => $after['counts'],
        ],
        'pages' => $pageResults,
        'layout' => $layoutResults,
        'components' => $sectionComponentResults,
        'componentization' => $componentizationResults,
        'globals' => collectGlobalStateSummary(),
        'designSystem' => collectMaximusV2DesignSystemSummary($allImportedPostIds),
        'v2UtilityFallback' => $v2UtilityFallback,
        'brandNormalization' => $brandNormalization,
        'codeBlocks' => summarizeMaximusV2CodeBlocks($allImportedPostIds),
        'acceptance' => buildMaximusV2AcceptanceSummary($pageResults, $layoutResults, $sectionComponentResults, $componentizationResults, $before, $after, $allImportedPostIds),
        'notes' => [
            'headersAndFootersImportedInactive' => true,
            'sourceFixturesModified' => false,
            'formHandling' => 'Contact source form controls were converted into native visual Oxygen-editable form fields. Functional submit wiring is intentionally not guessed.',
            'tailwindHandling' => 'Tailwind utilities remain as source parity hints; semantic Maximus classes were added as the primary editable design-system layer.',
        ],
    ];

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode V2 report JSON.');
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

/**
 * @return array<string, array{path:string,relativePath:string,title:string,slug:string,label:string}>
 */
function maximusV2FixtureDefinitions(string $fixtureRoot): array
{
    $definitions = [
        'v2_for_whom' => [
            'relativePath' => 'code.html',
            'title' => 'Maximus Premium - Dla kogo',
            'slug' => 'maximus-premium-dla-kogo',
            'label' => 'Dla kogo',
        ],
        'v2_programs' => [
            'relativePath' => 'programy_maximus/code.html',
            'title' => 'Maximus Premium - Programy',
            'slug' => 'maximus-premium-programy',
            'label' => 'Programy',
        ],
        'v2_starters' => [
            'relativePath' => 'startery_maximus/code.html',
            'title' => 'Maximus Premium - Startery',
            'slug' => 'maximus-premium-startery',
            'label' => 'Startery',
        ],
        'v2_about' => [
            'relativePath' => 'o_nas_maximus/code.html',
            'title' => 'Maximus Premium - O nas',
            'slug' => 'maximus-premium-o-nas',
            'label' => 'O nas',
        ],
        'v2_contact' => [
            'relativePath' => 'kontakt_maximus/code.html',
            'title' => 'Maximus Premium - Kontakt',
            'slug' => 'maximus-premium-kontakt',
            'label' => 'Kontakt',
        ],
    ];

    foreach ($definitions as $key => $definition) {
        $path = $fixtureRoot . '/' . $definition['relativePath'];
        if (!is_file($path)) {
            throw new RuntimeException('Missing New Maximus fixture: ' . $path);
        }
        $definitions[$key]['path'] = $path;
    }

    return $definitions;
}

/**
 * @param array<string, array{slug:string}> $fixtures
 * @return array<string, string>
 */
function buildMaximusV2InitialLinkMap(array $fixtures): array
{
    $links = [
        'home' => home_url('/'),
        'membership' => home_url('/czlonkostwo-i-uslugi-premium/'),
        'locations' => home_url('/lokalizacje-klubu/'),
        'diagnosis' => home_url('/diagnoza-i-dobor-programu/'),
    ];

    foreach ($fixtures as $key => $fixture) {
        $links[$key] = home_url('/' . trim((string) $fixture['slug'], '/') . '/');
    }

    return $links;
}

/**
 * @param array<string, string> $links
 */
function rewriteMaximusV2HtmlLinks(string $html, array $links): string
{
    $dom = loadHtmlDocument($html);
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }

        $target = linkForMaximusV2AnchorText(normalizeText($anchor->textContent), $links);
        if ($target !== '') {
            $anchor->setAttribute('href', $target);
        }
    }

    return saveHtmlDocument($dom);
}

/**
 * @param array<string, string> $links
 */
function linkForMaximusV2AnchorText(string $text, array $links): string
{
    $rules = [
        'dla kogo' => 'v2_for_whom',
        'wybierz swoj program' => 'v2_for_whom',
        'programy' => 'v2_programs',
        'sprawdz programy' => 'v2_programs',
        'startery' => 'v2_starters',
        'wybierz starter' => 'v2_contact',
        'membership' => 'membership',
        'czlonkostwo' => 'membership',
        'premium' => 'membership',
        'lokalizacje' => 'locations',
        'kontakt' => 'v2_contact',
        'dolacz' => 'v2_contact',
        'umow konsultacje' => 'v2_contact',
        'wyslij zgloszenie' => 'v2_contact',
        'oferta b2b' => 'v2_contact',
        'zacznij od diagnozy' => 'diagnosis',
        'diagnoza' => 'diagnosis',
        'maximus' => 'home',
        'physical culture' => 'home',
    ];

    foreach ($rules as $needle => $key) {
        if (str_contains($text, $needle) && isset($links[$key])) {
            return $links[$key];
        }
    }

    return '';
}

function buildMaximusV2ConversionPayload(string $html, string $title, string $slug, string $scope): array
{
    $conversionHtml = normalizeMaximusV2BrandText($html);
    $conversionHtml = stripMaximusRuntimeHeadAssets($conversionHtml);
    $conversionHtml = materializeMaximusV2ArbitraryUtilities($conversionHtml);
    $conversionHtml = transformMaximusV2FormsForNativeEditing($conversionHtml);
    $conversionHtml = applyMaximusDesignSystemClasses($conversionHtml, $slug);
    $conversionHtml = applyMaximusV2DesignSystemClasses($conversionHtml, $slug);

    $result = (new \OxyHtmlConverter\TreeBuilder())->convert($conversionHtml);
    if (empty($result['success']) || !is_array($result['element'] ?? null)) {
        throw new RuntimeException('V2 conversion failed for ' . $slug . ': ' . (string) ($result['error'] ?? 'unknown'));
    }

    $result['headLinkElements'] = [];
    $result['headScriptElements'] = [];
    $result['iconScriptElements'] = [];

    $response = (new \OxyHtmlConverter\Services\ConvertPayloadBuilder(
        new \OxyHtmlConverter\Services\OxygenDocumentTree(),
        new \OxyHtmlConverter\Services\ConversionAuditBuilder(),
        new \OxyHtmlConverter\Validation\OutputValidator()
    ))->build($result, [
        'wrapInContainer' => true,
        'includeCssElement' => false,
        'strictNative' => false,
        'startingNodeId' => 1,
        'scope' => $scope,
    ], $conversionHtml);

    if (empty($response['success']) || !is_array($response['data'] ?? null)) {
        throw new RuntimeException('V2 payload build failed for ' . $slug . ': ' . implode('; ', (array) ($response['data']['errors'] ?? [])));
    }

    $payload = $response['data'];
    $payload['title'] = $title;
    $payload['slug'] = $slug;
    $payload = namespaceGeneratedNativeClasses($payload, $slug);
    $payload = attachMaximusDesignSystemSelectors($payload);

    return attachMaximusV2DesignSystemSelectors($payload);
}

function normalizeMaximusV2BrandText(string $html): string
{
    return str_replace(
        [
            'MAXIMUS PHYSICAL CULTURE CLUB',
            'Maximus Physical Culture Club',
            'PHYSICAL CULTURE CLUB',
            'Physical Culture Club',
            'PHYSICAL CULTURE',
            'Physical Culture',
        ],
        [
            'MAXIMUS',
            'Maximus',
            'MAXIMUS',
            'Maximus',
            'MAXIMUS',
            'Maximus',
        ],
        $html
    );
}

/**
 * @return array<string, mixed>
 */
function seedMaximusV2UtilityFallbackCss(): array
{
    $pruned = pruneMaximusGeneratedGlobalStyles([
        '/* Maximus V2 source utility fallback.',
        '/* Extracted from <style> tag */ .material-symbols-outlined',
        '.ohc-maximus-v2-header-',
        '.ohc-maximus-v2-footer-',
    ]);
    $saved = (new \OxyHtmlConverter\Services\GlobalStyleRepository())->saveFromPayload([
        'globalCss' => maximusV2UtilityFallbackCss(),
    ]);

    $saved['pruned'] = $pruned;

    return $saved;
}

function maximusV2UtilityFallbackCss(): string
{
    return implode("\n", [
        '/* Maximus V2 source utility fallback. Kept separate from the base Maximus site-kit asset. */',
        '.maximus-premium-main.oxy-container, .maximus-v2-section.oxy-container { width: 100% !important; max-width: none !important; }',
        '.pt-32 { padding-top: 8rem !important; } .pt-16 { padding-top: 4rem !important; } .pt-6 { padding-top: 1.5rem !important; } .pt-4 { padding-top: 1rem !important; }',
        '.pb-section-gap { padding-bottom: var(--ohc-space-section-gap) !important; } .pb-6 { padding-bottom: 1.5rem !important; } .pb-4 { padding-bottom: 1rem !important; } .pb-2 { padding-bottom: 0.5rem !important; } .pb-1 { padding-bottom: 0.25rem !important; }',
        '.py-section-gap { padding-top: var(--ohc-space-section-gap) !important; padding-bottom: var(--ohc-space-section-gap) !important; } .py-20 { padding-top: 5rem !important; padding-bottom: 5rem !important; } .py-12 { padding-top: 3rem !important; padding-bottom: 3rem !important; } .py-6 { padding-top: 1.5rem !important; padding-bottom: 1.5rem !important; } .py-4 { padding-top: 1rem !important; padding-bottom: 1rem !important; } .py-3 { padding-top: 0.75rem !important; padding-bottom: 0.75rem !important; } .py-2 { padding-top: 0.5rem !important; padding-bottom: 0.5rem !important; }',
        '.px-margin-page { padding-left: var(--ohc-space-margin-page) !important; padding-right: var(--ohc-space-margin-page) !important; } .px-gutter-grid { padding-left: var(--ohc-space-gutter-grid) !important; padding-right: var(--ohc-space-gutter-grid) !important; } .px-8 { padding-left: 2rem !important; padding-right: 2rem !important; } .px-6 { padding-left: 1.5rem !important; padding-right: 1.5rem !important; } .px-0 { padding-left: 0 !important; padding-right: 0 !important; }',
        '.p-component-padding { padding: var(--ohc-space-component-padding) !important; } .p-12 { padding: 3rem !important; } .p-8 { padding: 2rem !important; } .p-6 { padding: 1.5rem !important; } .p-4 { padding: 1rem !important; }',
        '.pl-6 { padding-left: 1.5rem !important; } .pl-4 { padding-left: 1rem !important; }',
        '.mt-section-gap { margin-top: var(--ohc-space-section-gap) !important; } .mb-section-gap { margin-bottom: var(--ohc-space-section-gap) !important; } .-mt-12 { margin-top: -3rem !important; } .mt-12 { margin-top: 3rem !important; } .mt-8 { margin-top: 2rem !important; } .mt-4 { margin-top: 1rem !important; } .mt-1 { margin-top: 0.25rem !important; } .m-4 { margin: 1rem !important; }',
        '.mb-16 { margin-bottom: 4rem !important; } .mb-8 { margin-bottom: 2rem !important; } .mb-6 { margin-bottom: 1.5rem !important; } .mb-4 { margin-bottom: 1rem !important; } .mb-3 { margin-bottom: 0.75rem !important; } .mb-2 { margin-bottom: 0.5rem !important; }',
        '.space-y-12 > :not([hidden]) ~ :not([hidden]) { margin-top: 3rem !important; } .space-y-8 > :not([hidden]) ~ :not([hidden]) { margin-top: 2rem !important; } .space-y-6 > :not([hidden]) ~ :not([hidden]) { margin-top: 1.5rem !important; }',
        '.gap-gutter-grid { gap: var(--ohc-space-gutter-grid) !important; } .gap-8 { gap: 2rem !important; } .gap-6 { gap: 1.5rem !important; } .gap-4 { gap: 1rem !important; } .gap-3 { gap: 0.75rem !important; } .gap-2 { gap: 0.5rem !important; } .gap-1 { gap: 0.25rem !important; }',
        '.max-w-\\[1600px\\] { max-width: 1600px !important; } .max-w-screen-2xl, .max-w-7xl { max-width: 80rem !important; } .max-w-5xl { max-width: 64rem !important; } .max-w-2xl { max-width: 42rem !important; } .max-w-lg { max-width: 32rem !important; } .max-w-full { max-width: 100% !important; } .mx-auto { margin-left: auto !important; margin-right: auto !important; }',
        '.w-full { width: 100% !important; } .w-1\\/2 { width: 50% !important; } .w-24 { width: 6rem !important; } .w-16 { width: 4rem !important; } .w-12 { width: 3rem !important; } .w-8 { width: 2rem !important; } .w-\\[1px\\] { width: 1px !important; }',
        '.h-full { height: 100% !important; } .h-\\[600px\\] { height: 600px !important; } .h-\\[500px\\] { height: 500px !important; } .h-64 { height: 16rem !important; } .h-24 { height: 6rem !important; } .h-20 { height: 5rem !important; } .h-16 { height: 4rem !important; } .h-1 { height: 0.25rem !important; } .h-\\[2px\\] { height: 2px !important; } .h-\\[1px\\], .h-px { height: 1px !important; }',
        '.aspect-\\[16\\/9\\] { aspect-ratio: 16 / 9 !important; } .aspect-\\[4\\/5\\] { aspect-ratio: 4 / 5 !important; } .aspect-\\[4\\/3\\] { aspect-ratio: 4 / 3 !important; }',
        '.fixed { position: fixed !important; } .absolute { position: absolute !important; } .relative { position: relative !important; } .inset-0 { inset: 0 !important; } .top-0 { top: 0 !important; } .right-0 { right: 0 !important; } .right-12 { right: 3rem !important; } .bottom-0 { bottom: 0 !important; } .left-0 { left: 0 !important; } .-left-6 { left: -1.5rem !important; } .-bottom-6 { bottom: -1.5rem !important; } .z-50 { z-index: 50 !important; } .z-10 { z-index: 10 !important; }',
        '.grid { display: grid !important; } .flex { display: flex !important; } .inline-flex { display: inline-flex !important; } .block { display: block !important; } .inline-block { display: inline-block !important; } .hidden { display: none !important; }',
        '.grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; } .order-1 { order: 1 !important; } .order-2 { order: 2 !important; }',
        '.flex-col { flex-direction: column !important; } .flex-wrap { flex-wrap: wrap !important; } .flex-grow { flex-grow: 1 !important; } .items-start { align-items: flex-start !important; } .items-center { align-items: center !important; } .items-end { align-items: flex-end !important; } .justify-between { justify-content: space-between !important; } .justify-around { justify-content: space-around !important; } .justify-center { justify-content: center !important; } .justify-end { justify-content: flex-end !important; } .self-start { align-self: flex-start !important; }',
        '.overflow-hidden { overflow: hidden !important; } .object-cover { object-fit: cover !important; } .bg-cover { background-size: cover !important; } .bg-center { background-position: center !important; } .pointer-events-none { pointer-events: none !important; }',
        '.bg-ivory-base { background-color: var(--ohc-ivory-base) !important; } .bg-paper-soft { background-color: var(--ohc-paper-soft) !important; } .bg-paper-bright { background-color: var(--ohc-paper-bright) !important; } .bg-oxblood-primary { background-color: var(--ohc-oxblood-primary) !important; } .bg-oxblood-deep { background-color: var(--ohc-oxblood-deep) !important; } .bg-ink-black { background-color: var(--ohc-ink-black) !important; } .bg-ink-soft { background-color: var(--ohc-ink-soft) !important; } .bg-brass-accent { background-color: var(--ohc-brass-accent) !important; } .bg-copper-highlight { background-color: var(--ohc-copper-highlight) !important; } .bg-surface-container { background-color: var(--ohc-surface-container) !important; } .bg-background { background-color: var(--ohc-surface) !important; } .bg-transparent { background-color: transparent !important; } .bg-stone-50 { background-color: #fafaf9 !important; } .bg-black\\/20 { background-color: rgba(0,0,0,0.2) !important; } .bg-oxblood-deep\\/10 { background-color: rgba(77,9,7,0.1) !important; }',
        '.text-ink-black { color: var(--ohc-ink-black) !important; } .text-ink-soft { color: var(--ohc-ink-soft) !important; } .text-oxblood-primary { color: var(--ohc-oxblood-primary) !important; } .text-oxblood-deep { color: var(--ohc-oxblood-deep) !important; } .text-brass-accent { color: var(--ohc-brass-accent) !important; } .text-ivory-base { color: var(--ohc-ivory-base) !important; } .text-paper-bright { color: var(--ohc-paper-bright) !important; } .text-paper-soft { color: var(--ohc-paper-soft) !important; } .text-surface-bright { color: var(--ohc-surface) !important; } .text-surface-variant { color: var(--ohc-outline) !important; } .text-outline-variant { color: #DDC0BD !important; } .text-white { color: #fff !important; } .text-red-900 { color: #7f1d1d !important; } .text-red-800 { color: #991b1b !important; } .text-stone-500 { color: #78716c !important; } .text-stone-400 { color: #a8a29e !important; }',
        '.border { border-width: 1px !important; border-style: solid !important; } .border-0 { border-width: 0 !important; } .border-b { border-bottom-width: 1px !important; border-bottom-style: solid !important; } .border-b-2 { border-bottom-width: 2px !important; border-bottom-style: solid !important; } .border-t { border-top-width: 1px !important; border-top-style: solid !important; } .border-l { border-left-width: 1px !important; border-left-style: solid !important; } .border-r { border-right-width: 1px !important; border-right-style: solid !important; }',
        '.border-brass-accent { border-color: var(--ohc-brass-accent) !important; } .border-brass-accent\\/10 { border-color: rgba(154,116,64,0.1) !important; } .border-brass-accent\\/20 { border-color: rgba(154,116,64,0.2) !important; } .border-brass-accent\\/30 { border-color: rgba(154,116,64,0.3) !important; } .border-brass-accent\\/40 { border-color: rgba(154,116,64,0.4) !important; } .border-ink-soft { border-color: var(--ohc-ink-soft) !important; } .border-ink-soft\\/10 { border-color: rgba(84,75,69,0.1) !important; } .border-ink-soft\\/20 { border-color: rgba(84,75,69,0.2) !important; } .border-outline-variant { border-color: #DDC0BD !important; } .border-outline-variant\\/20 { border-color: rgba(221,192,189,0.2) !important; } .border-oxblood-primary { border-color: var(--ohc-oxblood-primary) !important; } .border-red-900, .border-red-900\\/10 { border-color: rgba(127,29,29,0.1) !important; }',
        '.font-hero-serif, .font-section-headline, .font-wordmark-aux { font-family: var(--ohc-font-hero-serif) !important; } .font-body-main, .font-metadata-label { font-family: var(--ohc-font-body-main) !important; } .font-serif { font-family: Georgia, Cambria, "Times New Roman", Times, serif !important; } .font-bold { font-weight: 700 !important; }',
        '.text-hero-serif { font-size: 64px !important; line-height: 1.1 !important; letter-spacing: -0.02em !important; font-weight: 400 !important; } .text-section-headline { font-size: 32px !important; line-height: 1.2 !important; font-weight: 400 !important; } .text-body-main { font-size: 16px !important; line-height: 1.6 !important; font-weight: 400 !important; } .text-metadata-label { font-size: 12px !important; line-height: 1.4 !important; letter-spacing: 0.1em !important; font-weight: 600 !important; } .text-wordmark-aux { font-size: 14px !important; line-height: 1 !important; letter-spacing: 0.2em !important; font-weight: 500 !important; }',
        '.text-4xl { font-size: 2.25rem !important; line-height: 2.5rem !important; } .text-3xl { font-size: 1.875rem !important; line-height: 2.25rem !important; } .text-2xl { font-size: 1.5rem !important; line-height: 2rem !important; } .text-lg { font-size: 1.125rem !important; line-height: 1.75rem !important; } .text-sm { font-size: 0.875rem !important; line-height: 1.25rem !important; } .text-xs { font-size: 0.75rem !important; line-height: 1rem !important; } .text-\\[24px\\] { font-size: 24px !important; } .text-\\[10px\\] { font-size: 10px !important; }',
        '.maximus-method-panel .maximus-heading, .maximus-method-panel h1, .maximus-method-panel h2, .maximus-method-panel h3, .maximus-featured-card .maximus-heading, .maximus-featured-card h1, .maximus-featured-card h2, .maximus-featured-card h3 { color: var(--ohc-ivory-base) !important; }',
        '.maximus-method-panel .maximus-body-copy, .maximus-method-panel p:not(.maximus-eyebrow), .maximus-featured-card .maximus-body-copy, .maximus-featured-card p:not(.maximus-eyebrow) { color: rgba(243, 237, 228, 0.88) !important; }',
        '.maximus-button.bg-brass-accent, button.bg-brass-accent, a.bg-brass-accent { color: #000000 !important; }',
        '.uppercase { text-transform: uppercase !important; } .text-center { text-align: center !important; } .tracking-tight { letter-spacing: -0.025em !important; } .tracking-wider { letter-spacing: 0.05em !important; } .tracking-widest { letter-spacing: 0.1em !important; } .tracking-\\[0\\.2em\\] { letter-spacing: 0.2em !important; } .leading-tight { line-height: 1.25 !important; } .leading-relaxed { line-height: 1.625 !important; }',
        '.opacity-10 { opacity: 0.1 !important; } .opacity-20 { opacity: 0.2 !important; } .opacity-40 { opacity: 0.4 !important; } .opacity-50 { opacity: 0.5 !important; } .opacity-60 { opacity: 0.6 !important; } .opacity-80 { opacity: 0.8 !important; } .opacity-90 { opacity: 0.9 !important; } .opacity-100 { opacity: 1 !important; }',
        '.shadow-2xl { box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25) !important; } .shadow-ink-black\\/20 { box-shadow: 0 25px 50px -12px rgba(23,18,15,0.2) !important; } .grayscale { filter: grayscale(100%) !important; } .contrast-125 { filter: contrast(1.25) !important; } .mix-blend-multiply { mix-blend-mode: multiply !important; } .transform { transform: translateZ(0) !important; } .scale-95 { transform: scale(0.95) !important; }',
        '.transition-colors { transition-property: color, background-color, border-color, text-decoration-color, fill, stroke !important; } .transition-transform { transition-property: transform !important; } .transition-all { transition-property: all !important; } .duration-200 { transition-duration: 200ms !important; } .duration-300 { transition-duration: 300ms !important; } .duration-500 { transition-duration: 500ms !important; } .duration-700 { transition-duration: 700ms !important; } .ease-in-out { transition-timing-function: cubic-bezier(0.4,0,0.2,1) !important; }',
        '.appearance-none { appearance: none !important; } .resize-none { resize: none !important; } .cursor-pointer { cursor: pointer !important; } .sr-only { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0,0,0,0) !important; white-space: nowrap !important; border-width: 0 !important; }',
        '.material-symbols-outlined { font-family: "Material Symbols Outlined" !important; font-weight: normal !important; font-style: normal !important; font-size: 24px !important; line-height: 1 !important; display: inline-block !important; text-transform: none !important; letter-spacing: normal !important; word-wrap: normal !important; white-space: nowrap !important; direction: ltr !important; font-feature-settings: "liga" !important; -webkit-font-feature-settings: "liga" !important; -webkit-font-smoothing: antialiased !important; font-variation-settings: "FILL" 0, "wght" 300, "GRAD" 0, "opsz" 24 !important; }',
        '@media (min-width: 640px) { .sm\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } }',
        '@media (min-width: 768px) { .md\\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; } .md\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .md\\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } .md\\:col-span-7 { grid-column: span 7 / span 7 !important; } .md\\:col-span-5 { grid-column: span 5 / span 5 !important; } .md\\:col-start-1 { grid-column-start: 1 !important; } .md\\:col-start-2 { grid-column-start: 2 !important; } .md\\:col-start-6 { grid-column-start: 6 !important; } .md\\:col-start-7 { grid-column-start: 7 !important; } .md\\:col-span-5.md\\:col-start-2 { grid-column: 2 / span 5 !important; } .md\\:col-span-7.md\\:col-start-6 { grid-column: 6 / span 7 !important; } .md\\:col-span-7.md\\:col-start-1 { grid-column: 1 / span 7 !important; } .md\\:col-span-5.md\\:col-start-7 { grid-column: 7 / span 5 !important; } .md\\:order-1 { order: 1 !important; } .md\\:order-2 { order: 2 !important; } .md\\:flex { display: flex !important; } .md\\:block { display: block !important; } .md\\:hidden { display: none !important; } .md\\:flex-row { flex-direction: row !important; } .md\\:gap-0 { gap: 0 !important; } .md\\:p-12 { padding: 3rem !important; } .md\\:pb-0 { padding-bottom: 0 !important; } .md\\:mt-0 { margin-top: 0 !important; } .md\\:w-5\\/12 { width: 41.666667% !important; } .md\\:w-7\\/12 { width: 58.333333% !important; } .md\\:text-right { text-align: right !important; } .md\\:-translate-y-4 { transform: translateY(-1rem) !important; } }',
        '@media (min-width: 1024px) { .lg\\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; } .lg\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .lg\\:col-span-8 { grid-column: span 8 / span 8 !important; } .lg\\:col-span-7 { grid-column: span 7 / span 7 !important; } .lg\\:col-span-6 { grid-column: span 6 / span 6 !important; } .lg\\:col-span-5 { grid-column: span 5 / span 5 !important; } .lg\\:col-span-4 { grid-column: span 4 / span 4 !important; } .lg\\:col-span-2 { grid-column: span 2 / span 2 !important; } .lg\\:col-start-1 { grid-column-start: 1 !important; } .lg\\:col-start-6 { grid-column-start: 6 !important; } .lg\\:col-start-7 { grid-column-start: 7 !important; } .lg\\:col-start-9 { grid-column-start: 9 !important; } .lg\\:col-span-8.lg\\:col-start-1 { grid-column: 1 / span 8 !important; } .lg\\:col-span-7.lg\\:col-start-6 { grid-column: 6 / span 7 !important; } .lg\\:col-span-6.lg\\:col-start-7 { grid-column: 7 / span 6 !important; } .lg\\:col-span-4.lg\\:col-start-9 { grid-column: 9 / span 4 !important; } .lg\\:order-1 { order: 1 !important; } .lg\\:order-2 { order: 2 !important; } .lg\\:p-16 { padding: 4rem !important; } .lg\\:p-24 { padding: 6rem !important; } .lg\\:pr-12 { padding-right: 3rem !important; } }',
        '@media (max-width: 767px) { .px-margin-page { padding-left: 1.5rem !important; padding-right: 1.5rem !important; } .pt-32 { padding-top: 6rem !important; } .pb-section-gap { padding-bottom: 4.5rem !important; } .mb-section-gap { margin-bottom: 4.5rem !important; } .mt-section-gap { margin-top: 4.5rem !important; } .py-section-gap { padding-top: 4.5rem !important; padding-bottom: 4.5rem !important; } .text-hero-serif { font-size: 48px !important; line-height: 1.08 !important; } .h-\\[600px\\], .h-\\[500px\\] { height: 360px !important; } }',
    ]);
}

function materializeMaximusV2ArbitraryUtilities(string $html): string
{
    $dom = loadHtmlDocument($html);

    foreach ($dom->getElementsByTagName('*') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        foreach (array_keys(classTokenSet($node)) as $className) {
            $decoded = html_entity_decode($className, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^bg-\[url\((["\']?)(.*?)\1\)\]$/', $decoded, $matches) === 1) {
                appendInlineStyle($node, 'background-image', 'url("' . str_replace('"', '%22', $matches[2]) . '")');
            }
        }
    }

    return saveHtmlDocument($dom);
}

function appendInlineStyle(DOMElement $node, string $property, string $value): void
{
    $style = trim($node->getAttribute('style'));
    if ($style !== '' && !str_ends_with($style, ';')) {
        $style .= ';';
    }

    $style .= ' ' . $property . ': ' . $value . ';';
    $node->setAttribute('style', trim($style));
}

/**
 * @param list<int> $postIds
 * @return list<array{postId:int,title:string,replacements:int}>
 */
function normalizeMaximusV2PersistedBrandText(array $postIds): array
{
    $updates = [];

    foreach (array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $postId): bool => $postId > 0))) as $postId) {
        $tree = readPersistedDocumentTree($postId);
        if ($tree === []) {
            continue;
        }

        $replacements = 0;
        normalizeMaximusV2PersistedValue($tree, $replacements);
        if ($replacements < 1) {
            continue;
        }

        persistDocumentTree($postId, $tree);
        refreshRenderCache($postId);
        $updates[] = [
            'postId' => $postId,
            'title' => get_the_title($postId),
            'replacements' => $replacements,
        ];
    }

    return $updates;
}

/**
 * @param mixed $value
 */
function normalizeMaximusV2PersistedValue(&$value, int &$replacements): void
{
    if (is_string($value)) {
        $normalized = normalizeMaximusV2BrandText($value);
        if ($normalized !== $value) {
            $replacements++;
            $value = $normalized;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as &$child) {
        normalizeMaximusV2PersistedValue($child, $replacements);
    }
    unset($child);
}

/**
 * @return list<int>
 */
function maximusV2SharedBrandPostIds(): array
{
    $slugs = [
        'maximus-site-header' => 'oxygen_header',
        'maximus-site-footer' => 'oxygen_footer',
        'maximus-header-component' => 'oxygen_block',
        'maximus-footer-component' => 'oxygen_block',
    ];
    $ids = [];

    foreach ($slugs as $slug => $postType) {
        $post = get_page_by_path($slug, OBJECT, $postType);
        if ($post instanceof WP_Post) {
            $ids[] = (int) $post->ID;
        }
    }

    return $ids;
}

function transformMaximusV2FormsForNativeEditing(string $html): string
{
    $dom = loadHtmlDocument($html);

    foreach (iterator_to_array($dom->getElementsByTagName('input')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $type = strtolower($node->getAttribute('type'));
        if ($type === 'radio') {
            $label = nearestAncestorByTag($node, 'label');
            if ($label instanceof DOMElement) {
                appendClass($label, ['maximus-form-option']);
                if ($node->hasAttribute('checked')) {
                    appendClass($label, ['maximus-form-option-selected']);
                }
            }
            $node->parentNode?->removeChild($node);
            continue;
        }

        $replacement = $dom->createElement('div');
        copyEditableContainerAttributes($node, $replacement);
        appendClass($replacement, ['maximus-form-field', 'maximus-form-field-input']);
        $value = trim($node->getAttribute('placeholder'));
        $replacement->appendChild($dom->createTextNode($value !== '' ? $value : 'Pole tekstowe'));
        $node->parentNode?->replaceChild($replacement, $node);
    }

    foreach (iterator_to_array($dom->getElementsByTagName('select')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $replacement = $dom->createElement('div');
        copyEditableContainerAttributes($node, $replacement);
        appendClass($replacement, ['maximus-form-field', 'maximus-form-field-select']);
        $replacement->appendChild($dom->createTextNode(selectedOptionText($node)));
        $node->parentNode?->replaceChild($replacement, $node);
    }

    foreach (iterator_to_array($dom->getElementsByTagName('textarea')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $replacement = $dom->createElement('div');
        copyEditableContainerAttributes($node, $replacement);
        appendClass($replacement, ['maximus-form-field', 'maximus-form-field-textarea']);
        $value = trim($node->getAttribute('placeholder'));
        $replacement->appendChild($dom->createTextNode($value !== '' ? $value : 'Wiadomość'));
        $node->parentNode?->replaceChild($replacement, $node);
    }

    foreach (iterator_to_array($dom->getElementsByTagName('form')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $replacement = $dom->createElement('div');
        copyEditableContainerAttributes($node, $replacement);
        appendClass($replacement, ['maximus-contact-form-fields']);
        while ($node->firstChild !== null) {
            $replacement->appendChild($node->firstChild);
        }
        $node->parentNode?->replaceChild($replacement, $node);
    }

    foreach (iterator_to_array($dom->getElementsByTagName('label')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if (!str_contains($node->getAttribute('class'), 'maximus-form-option')) {
            continue;
        }

        $replacement = $dom->createElement('div');
        copyEditableContainerAttributes($node, $replacement);
        while ($node->firstChild !== null) {
            $replacement->appendChild($node->firstChild);
        }
        $node->parentNode?->replaceChild($replacement, $node);
    }

    return saveHtmlDocument($dom);
}

function nearestAncestorByTag(DOMElement $node, string $tag): ?DOMElement
{
    $tag = strtolower($tag);
    $parent = $node->parentNode;
    while ($parent instanceof DOMElement) {
        if (strtolower($parent->tagName) === $tag) {
            return $parent;
        }
        $parent = $parent->parentNode;
    }

    return null;
}

function selectedOptionText(DOMElement $select): string
{
    $fallback = '';
    foreach ($select->getElementsByTagName('option') as $option) {
        if (!$option instanceof DOMElement) {
            continue;
        }
        $text = trim((string) $option->textContent);
        if ($fallback === '' && $text !== '') {
            $fallback = $text;
        }
        if ($option->hasAttribute('selected') && $text !== '') {
            return $text;
        }
    }

    return $fallback !== '' ? $fallback : 'Wybierz opcję';
}

function applyMaximusV2DesignSystemClasses(string $html, string $slug): string
{
    $dom = loadHtmlDocument($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body instanceof DOMElement) {
        appendClass($body, ['maximus-premium-page', 'maximus-v2-page']);
    }

    foreach ($dom->getElementsByTagName('*') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($node->tagName);
        $text = normalizeText($node->textContent);
        $classes = classTokenSet($node);
        $add = [];

        if ($tag === 'header' || ($tag === 'nav' && isset($classes['fixed'], $classes['top-0']))) {
            $add[] = 'maximus-premium-header';
        }

        if ($tag === 'header' && nearestAncestorByTag($node, 'main') instanceof DOMElement) {
            $add[] = 'maximus-content-header';
        }

        if ($tag === 'footer') {
            $add[] = 'maximus-premium-footer';
        }

        if ($tag === 'main') {
            $add[] = 'maximus-premium-main';
            $add[] = 'maximus-v2-page';
        }

        if ($tag === 'section') {
            removeClass($node, [
                'maximus-section',
                'maximus-section-hero',
                'maximus-section-editorial',
                'maximus-section-cards',
                'maximus-section-cta',
                'maximus-section-location',
                'maximus-section-diagnosis',
            ]);
            $add[] = 'maximus-v2-section';

            if (str_contains($text, 'wybierz swoja sciezke')) {
                $add[] = 'maximus-editorial-hero';
            } elseif (str_contains($text, 'dziedzictwo maximus')) {
                $add[] = 'maximus-about-hero';
                $add[] = 'maximus-editorial-hero';
            } elseif (str_contains($text, 'startery')) {
                $add[] = 'maximus-starter-hero';
                $add[] = 'maximus-editorial-hero';
            } elseif (str_contains($text, 'powiedz czego szukasz')) {
                $add[] = 'maximus-contact-hero';
                $add[] = 'maximus-editorial-hero';
                $add[] = 'maximus-contact-layout';
            } elseif (str_contains($text, 'rozpocznij proces') || ($slug === 'maximus-premium-kontakt' && str_contains($text, 'powiedz czego szukasz'))) {
                $add[] = 'maximus-contact-layout';
            } elseif (str_contains($text, 'najczestsze pytania')) {
                $add[] = 'maximus-faq-section';
            } elseif (str_contains($text, 'zacznij od diagnozy')) {
                $add[] = 'maximus-cta-banner';
            } elseif (isset($classes['grid'], $classes['md:grid-cols-12'])) {
                $add[] = 'maximus-program-feature';
            }
        }

        if (isset($classes['grid']) && (str_contains($text, 'rodzic') || str_contains($text, 'dorosly performance')) && str_contains($text, 'firma')) {
            $add[] = 'maximus-bento-grid';
        }

        if (isMaximusV2PersonaCard($node, $classes)) {
            $add[] = 'maximus-persona-card';
            if (isset($classes['lg:col-span-2'])) {
                $add[] = 'maximus-persona-card-featured';
            }
        }

        if (isMaximusV2ImagePanel($node, $classes)) {
            $add[] = 'maximus-image-panel';
        }

        if (isMaximusV2StarterCard($node, $classes)) {
            $add[] = 'maximus-starter-card';
            $add[] = 'maximus-pricing-card';
            if (isset($classes['bg-ink-black'])) {
                $add[] = 'maximus-featured-card';
            }
        }

        if (str_contains($text, 'rozpocznij proces') && isset($classes['bg-paper-soft'])) {
            $add[] = 'maximus-contact-form';
        }

        if (isset($classes['maximus-form-field'])) {
            $add[] = 'maximus-native-form-control';
        }

        if (isset($classes['maximus-form-option'])) {
            $add[] = 'maximus-native-form-option';
        }

        if (isset($classes['border-l'], $classes['pl-4'])) {
            $add[] = 'maximus-guidance-row';
        }

        if ((isset($classes['border-b']) || isset($classes['border-t'])) && str_contains($text, 'czy ')) {
            $add[] = 'maximus-faq-row';
        }

        if (str_contains($text, 'nasza metoda') && isset($classes['bg-oxblood-primary'])) {
            $add[] = 'maximus-method-panel';
        }

        if (isset($classes['border'], $classes['border-ink-soft/20']) && (str_contains($text, 'spolecznosc') || str_contains($text, 'wzor postepowania') || str_contains($text, 'mistrzowie'))) {
            $add[] = 'maximus-value-card';
        }

        if ($tag === 'span' && isset($classes['font-metadata-label'])) {
            $add[] = 'maximus-eyebrow';
        }

        if ($tag === 'a' && (str_contains($text, 'physical culture') || str_contains($text, 'maximus'))) {
            $add[] = 'maximus-wordmark';
        }

        appendClass($node, $add);
    }

    return saveHtmlDocument($dom);
}

/**
 * @param list<string> $classes
 */
function removeClass(DOMElement $node, array $classes): void
{
    $remove = array_fill_keys($classes, true);
    $existing = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    $kept = [];

    foreach ($existing as $className) {
        $className = trim($className);
        if ($className === '' || isset($remove[$className])) {
            continue;
        }
        $kept[] = $className;
    }

    if ($kept === []) {
        $node->removeAttribute('class');
        return;
    }

    $node->setAttribute('class', implode(' ', array_values(array_unique($kept))));
}

function isMaximusV2PersonaCard(DOMElement $node, array $classes): bool
{
    $text = normalizeText($node->textContent);

    return isset($classes['border'], $classes['p-8'])
        && (str_contains($text, 'rodzic') || str_contains($text, 'mlody zawodnik') || str_contains($text, 'dorosly performance') || str_contains($text, 'senior') || str_contains($text, 'firma'));
}

function isMaximusV2ImagePanel(DOMElement $node, array $classes): bool
{
    return isset($classes['relative'], $classes['overflow-hidden'])
        && (hasClassDescendant($node, 'object-cover') || hasClassDescendant($node, 'bg-cover'));
}

function isMaximusV2StarterCard(DOMElement $node, array $classes): bool
{
    $text = normalizeText($node->textContent);

    return isset($classes['p-8'])
        && (str_contains($text, 'konsultacja wstepna') || str_contains($text, 'starter pack') || str_contains($text, 'no ego beginner'));
}

function hasClassDescendant(DOMElement $node, string $className): bool
{
    foreach ($node->getElementsByTagName('*') as $child) {
        if ($child instanceof DOMElement && isset(classTokenSet($child)[$className])) {
            return true;
        }
    }

    return false;
}

function attachMaximusV2DesignSystemSelectors(array $payload): array
{
    $selectorPayload = is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : [];
    $existingSelectors = is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : [];
    $byId = [];

    foreach ($existingSelectors as $selector) {
        if (is_array($selector) && is_string($selector['id'] ?? null)) {
            $byId[$selector['id']] = $selector;
        }
    }

    foreach (maximusV2DesignSystemSelectors() as $selector) {
        $byId[$selector['id']] = $selector;
    }

    $collections = is_array($selectorPayload['collections'] ?? null) ? $selectorPayload['collections'] : [];
    $collections[] = maximusDesignSystemCollectionName();

    $payload['selectorPayload'] = array_merge($selectorPayload, [
        'selectors' => array_values($byId),
        'collections' => array_values(array_unique(array_filter(array_map('strval', $collections)))),
    ]);

    $payload['designSystem']['premiumSelectors'] = count(maximusV2DesignSystemSelectors());

    return $payload;
}

/**
 * @return list<array<string, mixed>>
 */
function maximusV2DesignSystemSelectors(): array
{
    $rules = [
        'maximus-v2-page' => [
            'background-color' => 'var(--ohc-surface)',
        ],
        'maximus-premium-main' => [
            'width' => '100%',
            'max-width' => 'none',
        ],
        'maximus-v2-section' => [
            'width' => '100%',
            'max-width' => 'none',
        ],
        'maximus-premium-header' => [
            'display' => 'flex',
            'align-items' => 'center',
            'justify-content' => 'space-between',
            'background-color' => 'var(--ohc-ivory-base)',
            'border-bottom' => '1px solid rgba(154, 116, 64, 0.2)',
        ],
        'maximus-content-header' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'align-items' => 'flex-start',
            'justify-content' => 'flex-start',
            'gap' => '24px',
            'width' => '100%',
            'background-color' => 'transparent',
            'border-bottom' => '0',
        ],
        'maximus-premium-footer' => [
            'background-color' => 'var(--ohc-paper-soft)',
            'border-top' => '1px solid rgba(154, 116, 64, 0.1)',
        ],
        'maximus-wordmark' => [
            'font-family' => 'var(--ohc-font-hero-serif)',
            'font-weight' => '700',
            'letter-spacing' => '0',
            'color' => 'var(--ohc-oxblood-deep)',
        ],
        'maximus-editorial-hero' => [
            'background-color' => 'var(--ohc-surface)',
        ],
        'maximus-bento-grid' => [
            'display' => 'grid',
            'gap' => '32px',
            'width' => '100%',
        ],
        'maximus-persona-card' => [
            'background-color' => 'var(--ohc-paper-soft)',
            'border' => '1px solid var(--ohc-outline-variant)',
            'padding' => 'var(--ohc-space-component-padding)',
        ],
        'maximus-persona-card-featured' => [
            'background-color' => 'var(--ohc-surface-container)',
        ],
        'maximus-program-feature' => [
            'display' => 'grid',
            'gap' => 'var(--ohc-space-gutter-grid)',
            'align-items' => 'center',
        ],
        'maximus-image-panel' => [
            'position' => 'relative',
            'overflow' => 'hidden',
            'background-color' => 'var(--ohc-ink-soft)',
        ],
        'maximus-starter-card' => [
            'background-color' => 'var(--ohc-paper-soft)',
            'border' => '1px solid rgba(154, 116, 64, 0.2)',
            'padding' => 'var(--ohc-space-component-padding)',
        ],
        'maximus-pricing-card' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'justify-content' => 'space-between',
        ],
        'maximus-featured-card' => [
            'background-color' => 'var(--ohc-ink-black)',
            'color' => 'var(--ohc-paper-bright)',
        ],
        'maximus-contact-layout' => [
            'align-items' => 'flex-start',
        ],
        'maximus-contact-form' => [
            'background-color' => 'var(--ohc-paper-soft)',
            'border' => '1px solid rgba(154, 116, 64, 0.1)',
        ],
        'maximus-contact-form-fields' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => '32px',
        ],
        'maximus-native-form-control' => [
            'width' => '100%',
            'border-bottom' => '1px solid var(--ohc-ink-soft)',
            'padding-top' => '8px',
            'padding-bottom' => '8px',
            'color' => 'var(--ohc-ink-soft)',
        ],
        'maximus-native-form-option' => [
            'border' => '1px solid var(--ohc-ink-soft)',
            'padding' => '16px',
            'text-align' => 'center',
        ],
        'maximus-form-option-selected' => [
            'border' => '1px solid var(--ohc-oxblood-primary)',
            'background-color' => 'rgba(115, 27, 25, 0.05)',
        ],
        'maximus-guidance-row' => [
            'border-left' => '1px solid var(--ohc-brass-accent)',
            'padding-left' => '16px',
        ],
        'maximus-faq-row' => [
            'border-top' => '1px solid rgba(84, 75, 69, 0.2)',
            'padding-top' => '24px',
            'padding-bottom' => '24px',
        ],
        'maximus-value-card' => [
            'border' => '1px solid rgba(84, 75, 69, 0.2)',
            'padding' => 'var(--ohc-space-component-padding)',
            'background-color' => 'transparent',
        ],
        'maximus-method-panel' => [
            'background-color' => 'var(--ohc-oxblood-primary)',
            'color' => '#FFFFFF',
        ],
        'maximus-cta-banner' => [
            'background-color' => 'var(--ohc-ink-black)',
            'color' => '#FFFFFF',
            'text-align' => 'center',
        ],
    ];

    $selectors = [];
    foreach ($rules as $className => $declarations) {
        $selectors[] = maximusV2SelectorRecord($className, $declarations);
    }

    return $selectors;
}

/**
 * @param array<string, string> $declarations
 */
function maximusV2SelectorRecord(string $className, array $declarations): array
{
    $record = maximusSelectorRecord($className, $declarations);

    if ($className === 'maximus-content-header') {
        $record['properties']['breakpoint_base']['custom_css']['custom_css'] = implode("\n", [
            '.maximus-v2-page .maximus-content-header {',
            '  display: flex !important;',
            '  flex-direction: column !important;',
            '  align-items: flex-start !important;',
            '  justify-content: flex-start !important;',
            '  gap: 24px !important;',
            '  width: 100% !important;',
            '  background-color: transparent !important;',
            '  border-bottom: 0 !important;',
            '}',
        ]);
    }

    return $record;
}

/**
 * @param array<string, array{path:string,title:string,slug:string,label:string}> $fixtures
 * @param array<string, string> $links
 * @return array{headers:list<array<string,mixed>>,footers:list<array<string,mixed>>}
 */
function importMaximusV2InactiveLayouts(array $fixtures, array $links): array
{
    $headers = [];
    $footers = [];

    foreach ($fixtures as $key => $fixture) {
        $sourceHtml = readFixtureHtml($fixture['path']);
        $sourceHtml = rewriteMaximusV2HtmlLinks($sourceHtml, $links);
        $label = (string) $fixture['label'];

        $headerFragment = extractFirstLayoutFragment($sourceHtml);
        if ($headerFragment !== '') {
            $result = importMaximusV2OxygenDocumentPost(
                'oxygen_header',
                'Maximus V2 Header - ' . $label,
                'maximus-v2-header-' . sanitize_title($key),
                makeDocumentFromFragment($sourceHtml, $headerFragment),
                null,
                'page'
            );
            clearOxygenTemplateSettings((int) $result['postId']);
            $result['active'] = false;
            $result['sourceFixture'] = $key;
            $headers[] = $result;
        }

        $footerFragment = extractLastTagFragment($sourceHtml, 'footer');
        if ($footerFragment !== '') {
            $result = importMaximusV2OxygenDocumentPost(
                'oxygen_footer',
                'Maximus V2 Footer - ' . $label,
                'maximus-v2-footer-' . sanitize_title($key),
                makeDocumentFromFragment($sourceHtml, $footerFragment),
                null,
                'page'
            );
            clearOxygenTemplateSettings((int) $result['postId']);
            $result['active'] = false;
            $result['sourceFixture'] = $key;
            $footers[] = $result;
        }
    }

    return ['headers' => $headers, 'footers' => $footers];
}

/**
 * @param array<string, array{path:string,title:string,slug:string,label:string}> $fixtures
 * @param array<string, string> $links
 * @return list<array<string, mixed>>
 */
function importMaximusV2SectionComponents(array $fixtures, array $links): array
{
    $results = [];

    foreach ($fixtures as $key => $fixture) {
        $sourceHtml = readFixtureHtml($fixture['path']);
        $pageHtml = rewriteMaximusV2HtmlLinks($sourceHtml, $links);
        $pageHtml = stripDocumentShell($pageHtml);
        $pageHtml = transformMaximusV2FormsForNativeEditing($pageHtml);
        $pageHtml = applyMaximusDesignSystemClasses($pageHtml, $fixture['slug']);
        $pageHtml = applyMaximusV2DesignSystemClasses($pageHtml, $fixture['slug']);

        foreach (extractMaximusReusableSectionFragments($pageHtml, $key) as $index => $section) {
            $number = $index + 1;
            $rawLabel = $section['label'] !== '' ? $section['label'] : ('Section ' . $number);
            $label = normalizeMaximusV2SectionLabel($rawLabel);
            $title = 'Maximus V2 Section - ' . $fixture['label'] . ' - ' . $label;
            $slug = 'maximus-v2-section-' . sanitize_title($key . '-' . $number . '-' . $rawLabel);
            $result = importMaximusV2OxygenDocumentPost(
                'oxygen_block',
                $title,
                $slug,
                makeDocumentFromFragment($sourceHtml, rewriteMaximusV2HtmlLinks($section['html'], $links)),
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

function normalizeMaximusV2SectionLabel(string $label): string
{
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
    $label = preg_replace('/([[:alpha:]])maximus\b/iu', '$1 Maximus', $label) ?? $label;
    $label = preg_replace('/\bmaximus([[:alpha:]])/iu', 'Maximus $1', $label) ?? $label;

    return trim($label);
}

function importMaximusV2OxygenDocumentPost(
    string $postType,
    string $title,
    string $slug,
    string $html,
    ?array $templateSettings,
    string $styleScope
): array {
    $payload = buildMaximusV2ConversionPayload($html, $title, $slug, $postType);
    $payload = routeCanvasCssOutOfTree($payload, $styleScope);
    $payload = enrichWithMaximusBrandPayload($payload);
    if ($postType === 'oxygen_block') {
        $payload = enableMaximusComponentProperties($payload, $slug);
    }

    $postId = createOrUpdatePost($postType, $title, $slug);
    persistPayloadForPost($postId, $payload, $templateSettings);

    return summarizeImportedPost($postId, $postType, $title, $slug, $payload, $templateSettings);
}

function clearOxygenTemplateSettings(int $postId): void
{
    delete_post_meta($postId, oxygenMetaPrefix() . 'template_settings');
    refreshRenderCache($postId);
}

/**
 * @return array{counts:array<string,int>,postIds:list<int>}
 */
function collectMaximusV2ProtectionSnapshot(): array
{
    $postTypes = ['page', 'post', 'oxygen_header', 'oxygen_footer', 'oxygen_template', 'oxygen_block'];
    $counts = [];
    $postIds = [];

    foreach ($postTypes as $postType) {
        $posts = get_posts([
            'post_type' => $postType,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        $ids = array_values(array_map('intval', is_array($posts) ? $posts : []));
        $counts[$postType] = count($ids);
        $postIds = array_merge($postIds, $ids);
    }

    sort($postIds);

    return [
        'counts' => $counts,
        'postIds' => array_values(array_unique($postIds)),
    ];
}

/**
 * @param list<int> $postIds
 * @return array<string, mixed>
 */
function collectMaximusV2DesignSystemSummary(array $postIds): array
{
    $semanticClasses = array_map(static fn (array $selector): string => (string) $selector['name'], maximusV2DesignSystemSelectors());
    $usage = array_fill_keys($semanticClasses, 0);

    foreach ($postIds as $postId) {
        $tree = readPersistedDocumentTree($postId);
        if ($tree === []) {
            continue;
        }

        walkMaximusV2Nodes($tree, function (array $node) use (&$usage): void {
            foreach (nodeClasses($node) as $className) {
                if (array_key_exists($className, $usage)) {
                    $usage[$className]++;
                }
            }
        });
    }

    return [
        'selectorCollection' => maximusDesignSystemCollectionName(),
        'premiumSemanticSelectors' => count($semanticClasses),
        'premiumSharedClassUsage' => $usage,
    ];
}

/**
 * @param list<int> $postIds
 * @return array<string, mixed>
 */
function summarizeMaximusV2CodeBlocks(array $postIds): array
{
    $badPosts = [];
    $totals = [
        'HtmlCode' => 0,
        'CssCode' => 0,
        'JavaScriptCode' => 0,
    ];

    foreach ($postIds as $postId) {
        $tree = readPersistedDocumentTree($postId);
        if ($tree === []) {
            continue;
        }

        $counts = [
            'HtmlCode' => 0,
            'CssCode' => 0,
            'JavaScriptCode' => 0,
        ];
        walkMaximusV2Nodes($tree, function (array $node) use (&$counts): void {
            $type = (string) ($node['data']['type'] ?? '');
            foreach (array_keys($counts) as $shortType) {
                if (str_contains($type, $shortType)) {
                    $counts[$shortType]++;
                }
            }
        });

        foreach ($counts as $type => $count) {
            $totals[$type] += $count;
        }

        if (array_sum($counts) > 0) {
            $badPosts[] = [
                'postId' => $postId,
                'title' => get_the_title($postId),
                'postType' => get_post_type($postId),
                'counts' => $counts,
            ];
        }
    }

    return [
        'totals' => $totals,
        'badPosts' => $badPosts,
    ];
}

/**
 * @param mixed $tree
 */
function walkMaximusV2Nodes($tree, callable $visitor): void
{
    if (!is_array($tree)) {
        return;
    }

    if (isset($tree['data']) && is_array($tree['data'])) {
        $visitor($tree);
    }

    if (isset($tree['root']) && is_array($tree['root'])) {
        walkMaximusV2Nodes($tree['root'], $visitor);
    }

    $children = $tree['children'] ?? null;
    if (is_array($children)) {
        foreach ($children as $child) {
            walkMaximusV2Nodes($child, $visitor);
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $pageResults
 * @param array{headers:list<array<string,mixed>>,footers:list<array<string,mixed>>} $layoutResults
 * @param list<array<string, mixed>> $sectionComponentResults
 * @param array<string, mixed> $componentizationResults
 * @param array{counts:array<string,int>,postIds:list<int>} $before
 * @param array{counts:array<string,int>,postIds:list<int>} $after
 * @param list<int> $postIds
 * @return array<string, mixed>
 */
function buildMaximusV2AcceptanceSummary(array $pageResults, array $layoutResults, array $sectionComponentResults, array $componentizationResults, array $before, array $after, array $postIds): array
{
    $codeBlocks = summarizeMaximusV2CodeBlocks($postIds);

    return [
        'pagesCreatedOrUpdated' => count($pageResults),
        'inactiveHeadersCreatedOrUpdated' => count($layoutResults['headers']),
        'inactiveFootersCreatedOrUpdated' => count($layoutResults['footers']),
        'sectionComponentsCreatedOrUpdated' => count($sectionComponentResults),
        'componentInstancesCreated' => (int) ($componentizationResults['componentInstancesCreated'] ?? 0),
        'pagesComponentized' => (int) ($componentizationResults['pagesComponentized'] ?? 0),
        'editableTextPropertiesAttached' => (int) ($componentizationResults['editableTextPropertiesAttached'] ?? 0),
        'existingPostIdsMissingAfter' => array_values(array_diff($before['postIds'], $after['postIds'])),
        'badCodePosts' => count($codeBlocks['badPosts']),
        'allImportedPostsNative' => count($codeBlocks['badPosts']) === 0,
    ];
}
