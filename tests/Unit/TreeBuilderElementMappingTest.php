<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\DesignDocumentBuilder;
use OxyHtmlConverter\Services\OxygenTokenBindingService;
use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TreeBuilderElementMappingTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        remove_all_filters();
        parent::tearDown();
    }

    public function testNativeNoCodeFixtureManifestCoversMilestoneScope(): void
    {
        $manifest = self::loadNativeNoCodeManifest();
        $required = array_map('strval', $manifest['requiredCoverage'] ?? []);
        $covered = [];

        foreach ($manifest['fixtures'] as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            foreach (($fixture['coverage'] ?? []) as $coverage) {
                $covered[(string) $coverage] = true;
            }
        }

        $this->assertSame([], array_values(array_diff($required, array_keys($covered))));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('nativeNoCodeFixtureProvider')]
    public function testNativeNoCodeFixtureMatchesAcceptanceContract(array $fixture): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $file = (string) ($fixture['file'] ?? '');
        $path = self::nativeNoCodeFixtureDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $html = file_get_contents($path);
        $this->assertIsString($html, $file);

        $result = (new TreeBuilder())->convert($html);
        $this->assertTrue((bool) ($result['success'] ?? false), $file);

        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $visibleTypes = [];
        $this->countVisibleElementTypes($result, $visibleTypes);
        $codeBlocks = $this->codeBlockCounts($visibleTypes);

        $this->assertSame($expected['visibleCodeBlocks'] ?? [], $codeBlocks, $file);

        if (!empty($fixture['supported'])) {
            $this->assertSame(0, $codeBlocks['total'], $file . ' must not emit visible code blocks.');
        }

        $unsupportedItems = is_array($result['stats']['unsupportedItems'] ?? null)
            ? $result['stats']['unsupportedItems']
            : [];
        $this->assertCount((int) ($expected['unsupportedCount'] ?? 0), $unsupportedItems, $file);

        $fallbackCategories = array_values(array_filter(array_map(
            static fn(array $item): string => (string) ($item['fallbackCategory'] ?? ''),
            $unsupportedItems
        )));
        foreach (($expected['fallbackCategories'] ?? []) as $category) {
            $this->assertContains((string) $category, $fallbackCategories, $file);
        }

        $this->assertSame((bool) ($expected['fallbackCss'] ?? false), $this->hasOwnedFallbackCss($result), $file);

        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $selectors = is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : [];
        $this->assertGreaterThanOrEqual((int) ($expected['minSelectors'] ?? 0), count($selectors), $file);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function nativeNoCodeFixtureProvider(): iterable
    {
        $manifest = self::loadNativeNoCodeManifest();
        foreach ($manifest['fixtures'] as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            yield (string) ($fixture['file'] ?? 'fixture') => [$fixture];
        }
    }

    public function testTreeBuilderKeepsOxygenButtonMappingByDefault(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<button>Click me</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
    }

    public function testTreeBuilderResultBindsSupportedTokensIntoNativeElementControls(): void
    {
        $html = '<style>.hero { background-image: url("https://example.test/assets/hero.jpg"); }</style>'
            . '<section class="hero" style="color:#731B19; padding:24px; font-family:Inter">Hero</section>';

        $builder = new TreeBuilder();
        $result = $builder->convert($html);
        $designDocument = (new DesignDocumentBuilder())->build($html, $result);
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => $designDocument,
        ]);

        $this->assertTrue($result['success']);

        $design = $result['element']['data']['properties']['design'];

        $this->assertSame('var(--ohc-color-731b19)', $design['typography']['color']);
        $this->assertSame('var(--ohc-space-24px)', $design['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('custom', $design['spacing']['spacing']['padding']['top']['unit']);
        $this->assertNull($design['spacing']['spacing']['padding']['top']['number']);
        $this->assertSame('var(--ohc-font-inter)', $design['typography']['font_family']);
        $this->assertSame('var(--ohc-image-hero)', $design['background']['backgrounds'][0]['image']['url']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
        $this->assertSame(4, $result['tokenUsage']['bound']);
        $this->assertSame($result['tokenUsage'], $result['stats']['tokenUsage']);
    }

    public function testTreeBuilderRejectsTemporaryMediaUrlFromNativeImageOutput(): void
    {
        $temporaryUrl = 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/hero.png?Expires=60';

        $result = (new TreeBuilder())->convert('<img src="' . $temporaryUrl . '" alt="AI hero">');

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['element']['data']['properties']['content']['image']['url']);
        $this->assertSame(1, $result['assetNormalization']['summary']['rejected']);
        $this->assertSame(1, $result['stats']['assetNormalization']['rejected']);

        $unsupportedItems = $result['stats']['unsupportedItems'];
        $this->assertSame('rejected_asset_url', $unsupportedItems[0]['fallbackCategory']);
        $this->assertStringContainsString('Temporary', $unsupportedItems[0]['safeModeImpact']);
    }

    public function testTreeBuilderCanPreferEssentialButtonMapping(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button onclick="location=\'https://example.com\'">Buy now</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('EssentialElements\\Button', $result['element']['data']['type']);
        $this->assertSame('Buy now', $result['element']['data']['properties']['content']['content']['text']);
        $this->assertSame(
            'https://example.com',
            $result['element']['data']['properties']['content']['content']['link']['url']
        );
        $this->assertEmpty($result['element']['children']);
    }

    public function testTreeBuilderKeepsStyledButtonsAsContainersEvenWhenEssentialElementsPreferred(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button class="bg-[#ff0084] text-white rounded-full">Buy now</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
    }

    public function testTreeBuilderPreservesComplexButtonChildrenWhenEssentialElementsPreferred(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button class="group"><span>Watch</span><i data-lucide="play"></i></button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertCount(2, $result['element']['children']);
    }

    public function testTreeBuilderDropsMappedTailwindClassesFromNativeModeOutput(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="flex items-center text-white bg-[#ff0084] custom-card">Card</div>');

        $this->assertTrue($result['success']);
        $this->assertSame('flex', $result['element']['data']['properties']['design']['layout']['display']);
        $this->assertSame('center', $result['element']['data']['properties']['design']['layout']['flex_align']['cross_axis']);
        $this->assertSame('#FFFFFFFF', $result['element']['data']['properties']['design']['typography']['color']);
        $this->assertSame('#FF0084FF', $result['element']['data']['properties']['design']['background']['background_color']);
        $this->assertArrayNotHasKey('align-items', $result['element']['data']['properties']['design']['layout']);
        $this->assertArrayNotHasKey('background-color', $result['element']['data']['properties']['design']['background']);
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'];
        $this->assertContains('custom-card', $classes);
        $this->assertNotContains('flex', $classes);
        $this->assertNotContains('items-center', $classes);
        $this->assertNotContains('text-white', $classes);
        $this->assertNotContains('bg-[#ff0084]', $classes);
    }

    public function testTreeBuilderPrunesFallbackCssForMappedTailwindUtilities(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="flex items-center text-white">Card</div>');

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('.flex', $result['extractedCss']);
        $this->assertStringNotContainsString('.items-center', $result['extractedCss']);
        $this->assertStringNotContainsString('.text-white', $result['extractedCss']);
    }

    public function testTreeBuilderDoesNotMirrorSupportedNativeDesignPropertiesIntoFallbackCss(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<section class="relative flex items-center overflow-hidden">'
            . '<div class="absolute inset-0 z-10"><img class="w-full h-full object-cover mix-blend-multiply" src="https://example.com/a.jpg" /></div>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $sectionClasses = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];
        $sectionMirrorClass = $this->firstNativeMirrorClass($sectionClasses);
        $this->assertNull($sectionMirrorClass);
        $this->assertStringNotContainsString('ohc-native-', $result['extractedCss']);
        $this->assertSame('relative', $result['element']['data']['properties']['design']['position']['position']);
        $this->assertSame('flex', $result['element']['data']['properties']['design']['layout']['display']);
        $this->assertSame('center', $result['element']['data']['properties']['design']['layout']['flex_align']['cross_axis']);
        $this->assertSame('hidden', $result['element']['data']['properties']['design']['size']['overflow']);

        $image = $result['element']['children'][0]['children'][0];
        $imageClasses = $image['data']['properties']['settings']['advanced']['classes'] ?? [];
        $this->assertNotContains('w-full', $imageClasses);
        $this->assertNotContains('h-full', $imageClasses);
        $this->assertNotContains('object-cover', $imageClasses);
        $this->assertSame('cover', $image['data']['properties']['design']['size']['object_fit']);
        $this->assertSame('multiply', $image['data']['properties']['design']['effects']['blend_mode']);
    }

    public function testFixedHeaderHeuristicUsesNativeSpacingPathWithoutMirrorCss(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $builder->enableAllHeuristics();
        $result = $builder->convert('<header class="fixed">Nav</header><section>Hero</section>');

        $this->assertTrue($result['success']);

        $section = $result['element']['children'][1];
        $spacing = $section['data']['properties']['design']['spacing'] ?? [];

        $this->assertSame('80px', $spacing['spacing']['padding']['top']['style'] ?? null);
        $this->assertArrayNotHasKey('padding-top', $spacing);
        $this->assertStringNotContainsString('ohc-native-', $result['extractedCss']);
    }

    public function testTreeBuilderMapsSimpleGridUtilitiesToNativeLayoutPaths(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-3 gap-4"><span>One</span></div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertSame('3', $layout['grid']['simple_grid_template_columns'] ?? null);
        $this->assertSame('1rem', $layout['gap']['row']['style'] ?? null);
        $this->assertSame('1rem', $layout['gap']['column']['style'] ?? null);
        $this->assertArrayNotHasKey('template_columns', $layout['grid'] ?? []);
        $this->assertStringNotContainsString('ohc-native-', $result['extractedCss']);
    }

    public function testTreeBuilderMapsBaseUtilityClassesDirectlyToNativeProperties(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<div class="grid grid-cols-3 gap-4 p-4 text-6xl font-bold opacity-50 border-b max-w-screen-2xl md:grid-cols-4">Card</div>'
        );

        $this->assertTrue($result['success']);

        $design = $result['element']['data']['properties']['design'] ?? [];
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];

        $this->assertSame('grid', $design['layout']['display'] ?? null);
        $this->assertSame('3', $design['layout']['grid']['simple_grid_template_columns'] ?? null);
        $this->assertSame('1rem', $design['layout']['gap']['row']['style'] ?? null);
        $this->assertSame('1rem', $design['spacing']['spacing']['padding']['top']['style'] ?? null);
        $this->assertSame('3.75rem', $design['typography']['font_size']['style'] ?? null);
        $this->assertSame(700, $design['typography']['font_weight'] ?? null);
        $this->assertSame(50, $design['effects']['opacity'] ?? null);
        $this->assertSame('1px', $design['borders']['borders']['bottom']['width']['style'] ?? null);
        $this->assertSame('1536px', $design['size']['max_width']['style'] ?? null);

        $this->assertContains('md:grid-cols-4', $classes);
        $this->assertNotContains('p-4', $classes);
        $this->assertNotContains('text-6xl', $classes);
        $this->assertNotContains('grid-cols-3', $classes);
        $this->assertStringContainsString('.md\\:grid-cols-4', $result['extractedCss']);
        $this->assertStringNotContainsString('.p-4 {', $result['extractedCss']);
    }

    public function testTreeBuilderMapsArbitraryGridColumnsToAdvancedNativeLayoutPath(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-[200px_1fr]"><span>One</span></div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertTrue($layout['grid']['enable_advanced_mode'] ?? false);
        $this->assertSame('200px 1fr', $layout['grid_template_columns'][0]['size']['style'] ?? null);
        $this->assertArrayNotHasKey('template_columns', $layout['grid'] ?? []);
        $this->assertStringNotContainsString('ohc-native-', $result['extractedCss']);
    }

    public function testTreeBuilderPreservesInvalidArbitraryGridUtilityAsClass(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-[nonsense]"><span>One</span></div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertContains('grid-cols-[nonsense]', $classes);
    }

    public function testTreeBuilderDoesNotMapInvalidAdvancedGridTrackList(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.grid-card { display:grid; grid-template-columns: 1px cats; }</style><div class="grid-card">One</div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertStringContainsString('grid-template-columns: 1px cats', $result['extractedCss']);
    }

    public function testTreeBuilderDoesNotMapInvalidMinmaxTrackList(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.grid-card { display:grid; grid-template-columns: minmax(nonsense,1fr); }</style><div class="grid-card">One</div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertStringContainsString('grid-template-columns: minmax(nonsense,1fr)', $result['extractedCss']);
    }

    public function testTreeBuilderDoesNotMapInvalidNestedRepeatTrackList(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.grid-card { display:grid; grid-template-columns: repeat(3, minmax(nonsense, 1fr)); }</style><div class="grid-card">One</div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertArrayNotHasKey('simple_grid_template_columns', $layout['grid'] ?? []);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(nonsense, 1fr))', $result['extractedCss']);
    }

    public function testTreeBuilderPreservesInvalidArbitraryMinmaxGridUtilityAsClass(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-[minmax(nonsense,1fr)]"><span>One</span></div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertContains('grid-cols-[minmax(nonsense,1fr)]', $classes);
    }

    public function testTreeBuilderPreservesInvalidArbitraryRepeatGridUtilityAsClass(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-[repeat(3,minmax(nonsense,1fr))]"><span>One</span></div>');

        $this->assertTrue($result['success']);

        $layout = $result['element']['data']['properties']['design']['layout'] ?? [];
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];

        $this->assertSame('grid', $layout['display'] ?? null);
        $this->assertArrayNotHasKey('grid_template_columns', $layout);
        $this->assertArrayNotHasKey('simple_grid_template_columns', $layout['grid'] ?? []);
        $this->assertContains('grid-cols-[repeat(3,minmax(nonsense,1fr))]', $classes);
    }

    public function testTreeBuilderOnlyPrunesFullySupportedCssRules(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>.card { display:flex; justify-content:center; } .mask { clip-path: circle(50%); color:red; }</style>'
            . '<div class="card">One</div><div class="mask">Two</div>'
        );

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('.card', $result['extractedCss']);
        $this->assertStringContainsString('.mask', $result['extractedCss']);
        $this->assertMatchesRegularExpression('/\.mask\s*\{\s*clip-path:\s*circle\(50%\);\s*\}/', $result['extractedCss']);
        $this->assertStringContainsString('clip-path', $result['extractedCss']);
    }

    public function testTreeBuilderCssCleanupPreservesSemicolonsInsideResidualDeclarations(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>.mask { color:red; clip-path: path("M0;1"); --payload: "a;b"; }</style>'
            . '<div class="mask">Two</div>'
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('.mask', $result['extractedCss']);
        $this->assertStringContainsString('clip-path: path("M0;1")', $result['extractedCss']);
        $this->assertStringContainsString('--payload: "a;b"', $result['extractedCss']);
        $this->assertDoesNotMatchRegularExpression('/\.mask\s*\{[^}]*color\s*:/', $result['extractedCss']);
    }

    public function testTreeBuilderCssCleanupPreservesBracesInsideQuotedValues(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>.hero { color:red; --payload: "A}B"; clip-path: path("M0}1"); }</style>'
            . '<div class="hero">Two</div>'
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('.hero', $result['extractedCss']);
        $this->assertStringContainsString('--payload: "A}B"', $result['extractedCss']);
        $this->assertStringContainsString('clip-path: path("M0}1")', $result['extractedCss']);
        $this->assertStringNotContainsString("\nB\"; clip-path", $result['extractedCss']);
        $this->assertDoesNotMatchRegularExpression('/\.hero\s*\{[^}]*color\s*:/', $result['extractedCss']);
    }

    public function testTreeBuilderCssCleanupPreservesUnsupportedNestedCssBlocks(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>.card { color:#123456; & .title { color:blue; } }</style>'
            . '<div class="card"><span class="title">Title</span></div>'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('#123456FF', $result['element']['data']['properties']['design']['typography']['color']);
        $this->assertStringContainsString('& .title { color:blue;', $result['extractedCss']);
    }

    public function testTreeBuilderMovesInlineStylesUnderNativeDesignProperties(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<div style="padding: 10px; width: 120px; color: #123456;">Card</div>'
        );

        $this->assertTrue($result['success']);
        $properties = $result['element']['data']['properties'];

        $this->assertSame('10px', $properties['design']['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('10px', $properties['design']['spacing']['spacing']['padding']['right']['style']);
        $this->assertSame('120px', $properties['design']['size']['width']['style']);
        $this->assertSame('#123456FF', $properties['design']['typography']['color']);
        $this->assertArrayNotHasKey('spacing', $properties);
        $this->assertArrayNotHasKey('size', $properties);
        $this->assertArrayNotHasKey('typography', $properties);
    }

    public function testInvalidSupportedCssValueIsRemovedFromFallbackCss(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.card { width: url(javascript:alert(1)); color: #123456; }</style><div class="card">Card</div>');

        $this->assertTrue($result['success']);

        $this->assertStringNotContainsString('javascript:alert(1)', $result['extractedCss']);
        $this->assertStringContainsString('width: url("")', $result['extractedCss']);
        $this->assertStringNotContainsString('color: #123456', $result['extractedCss']);
        $this->assertSame(1, $result['assetNormalization']['summary']['rejected']);
        $this->assertArrayNotHasKey('width', $result['element']['data']['properties']['design']['size'] ?? []);
        $this->assertSame('#123456FF', $result['element']['data']['properties']['design']['typography']['color']);
    }

    public function testUnitlessLineHeightRemainsUnitlessWhenMappedNative(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.hero { line-height: 1.6; }</style><p class="hero">Copy</p>');

        $this->assertTrue($result['success']);

        $lineHeight = $result['element']['data']['properties']['design']['typography']['line_height'] ?? [];

        $this->assertSame('1.6', $lineHeight['style'] ?? null);
        $this->assertSame('custom', $lineHeight['unit'] ?? null);
        $this->assertStringNotContainsString('line-height: 1.6', $result['extractedCss']);
    }

    public function testTreeBuilderExportsResidualClassesAsNativeSelectorReferences(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.card { color: red; }</style><div class="flex card">Card</div>');

        $this->assertTrue($result['success']);
        $this->assertContains('card', $result['element']['data']['properties']['settings']['advanced']['classes']);
        $this->assertNotEmpty($result['element']['data']['properties']['meta']['classes']);
        $this->assertNotEmpty($result['selectorPayload']['selectors']);
        $this->assertSame('.card', $result['selectorPayload']['selectors'][0]['selector']);
        $this->assertSame('card', $result['selectorPayload']['selectors'][0]['name']);
        $this->assertSame([
            'requiresTreeJsonString' => true,
            'requiresOxygenSelectorPersistence' => true,
            'requiresBreakdanceClassesJsonString' => false,
            'persistsBreakdanceClassesJsonString' => true,
            'oxygenSelectorsOptionName' => 'oxygen_oxy_selectors_json_string',
            'oxygenSelectorCollectionsOptionName' => 'oxygen_oxy_selectors_collections_json_string',
            'breakdanceClassesOptionName' => 'breakdance_classes_json_string',
        ], $result['selectorPayload']['persistence']);
    }

    public function testTreeBuilderDedupeRepeatedStylePatternsIntoSemanticSelector(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>'
            . '.pricing-card { color:#123456; padding:24px; }'
            . '.feature-card { padding:24px; color:#123456; }'
            . '</style>'
            . '<section>'
            . '<article class="pricing-card">One</article>'
            . '<article class="feature-card">Two</article>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $children = $result['element']['children'];
        $this->assertSame(['ohc-card'], $children[0]['data']['properties']['settings']['advanced']['classes']);
        $this->assertSame(['ohc-card'], $children[1]['data']['properties']['settings']['advanced']['classes']);
        $this->assertCount(1, $result['selectorPayload']['selectors']);
        $this->assertSame('ohc-card', $result['selectorPayload']['selectors'][0]['name']);
        $this->assertSame('.ohc-card', $result['selectorPayload']['selectors'][0]['selector']);
        $this->assertArrayNotHasKey('typography', $children[0]['data']['properties']['design'] ?? []);
        $this->assertArrayNotHasKey('typography', $children[1]['data']['properties']['design'] ?? []);
        $this->assertStringNotContainsString('.pricing-card', $result['extractedCss']);
        $this->assertStringNotContainsString('.feature-card', $result['extractedCss']);
    }

    public function testTreeBuilderRemovesDedupedPseudoAndResponsiveSourceCss(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>'
            . '.pricing-card { color:#123456; }'
            . '.feature-card { color:#123456; }'
            . '.pricing-card:hover { color:#000000; }'
            . '.feature-card:hover { color:#000000; }'
            . '@media (max-width: 767px) { .pricing-card { padding:12px; } .feature-card { padding:12px; } }'
            . '</style>'
            . '<section>'
            . '<article class="pricing-card">One</article>'
            . '<article class="feature-card">Two</article>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $selector = $result['selectorPayload']['selectors'][0];
        $childrenByName = [];
        foreach ($selector['children'] as $child) {
            $childrenByName[$child['name']] = $child;
        }

        $this->assertSame('#000000FF', $childrenByName['&:hover']['properties']['breakpoint_base']['typography']['color']);
        $this->assertSame('12px', $selector['properties']['breakpoint_phone_landscape']['spacing']['spacing']['padding']['top']['style']);
        $this->assertStringNotContainsString('.pricing-card:hover', $result['extractedCss']);
        $this->assertStringNotContainsString('.feature-card:hover', $result['extractedCss']);
        $this->assertStringNotContainsString('@media', $result['extractedCss']);
    }

    public function testTreeBuilderKeepsUnsupportedSemanticAliasCssInFallback(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>'
            . '.pricing-card { color:#123456; }'
            . '.feature-card { color:#123456; }'
            . '.pricing-card + .badge { color:#ff0000; }'
            . '.feature-card + .badge { color:#ff0000; }'
            . '.pricing-card::before { content:"x"; color:#00ff00; }'
            . '.feature-card::before { content:"x"; color:#00ff00; }'
            . '@media (prefers-color-scheme: dark) { .pricing-card { color:#ffffff; } .feature-card { color:#ffffff; } }'
            . '</style>'
            . '<section>'
            . '<article class="pricing-card">One</article>'
            . '<span class="badge">Badge</span>'
            . '<article class="feature-card">Two</article>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $selectorNames = array_map(
            static fn (array $selector): string => (string) ($selector['name'] ?? ''),
            $result['selectorPayload']['selectors']
        );

        $this->assertContains('ohc-card', $selectorNames);
        $this->assertStringNotContainsString('.pricing-card { color:#123456;', $result['extractedCss']);
        $this->assertStringNotContainsString('.feature-card { color:#123456;', $result['extractedCss']);
        $this->assertStringContainsString('.pricing-card + .badge', $result['extractedCss']);
        $this->assertStringContainsString('.feature-card + .badge', $result['extractedCss']);
        $this->assertStringContainsString('.pricing-card::before', $result['extractedCss']);
        $this->assertStringContainsString('.feature-card::before', $result['extractedCss']);
        $this->assertStringContainsString('prefers-color-scheme', $result['extractedCss']);
    }

    public function testTreeBuilderDoesNotDuplicateNestedSemanticAliasStylesOnChildElements(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>'
            . '.pricing-card { color:#123456; }'
            . '.feature-card { color:#123456; }'
            . '.pricing-card .title { font-weight:700; }'
            . '.feature-card .title { font-weight:700; }'
            . '</style>'
            . '<section>'
            . '<article class="pricing-card"><h2 class="title">One</h2></article>'
            . '<article class="feature-card"><h2 class="title">Two</h2></article>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $selector = $result['selectorPayload']['selectors'][0];
        $childrenByName = [];
        foreach ($selector['children'] as $child) {
            $childrenByName[$child['name']] = $child;
        }

        $this->assertSame('ohc-card', $selector['name']);
        $this->assertSame(700, $childrenByName['& .title']['properties']['breakpoint_base']['typography']['font_weight']);
        $this->assertStringNotContainsString('.pricing-card .title', $result['extractedCss']);
        $this->assertStringNotContainsString('.feature-card .title', $result['extractedCss']);

        $firstTitle = $result['element']['children'][0]['children'][0];
        $secondTitle = $result['element']['children'][1]['children'][0];

        $this->assertArrayNotHasKey('font_weight', $firstTitle['data']['properties']['design']['typography'] ?? []);
        $this->assertArrayNotHasKey('font_weight', $secondTitle['data']['properties']['design']['typography'] ?? []);
    }

    public function testTreeBuilderExportsResponsiveStateAndNestedSelectorPayload(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>'
            . '.card { padding:32px; }'
            . '@media (max-width: 767px) { .card { padding:12px; } }'
            . '@media (max-width: 767px) and (orientation: landscape) { .card { margin:88px; } }'
            . '.card:hover { color:#2563eb; }'
            . '.card .title { font-weight:700; }'
            . '</style>'
            . '<div class="card"><h2 class="title">A</h2></div>'
        );

        $this->assertTrue($result['success']);

        $selector = $result['selectorPayload']['selectors'][0];
        $this->assertSame('32px', $selector['properties']['breakpoint_base']['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('12px', $selector['properties']['breakpoint_phone_landscape']['spacing']['spacing']['padding']['top']['style']);
        $this->assertArrayNotHasKey('margin', $selector['properties']['breakpoint_phone_landscape']['spacing']['spacing'] ?? []);

        $childrenByName = [];
        foreach ($selector['children'] as $child) {
            $childrenByName[$child['name']] = $child;
        }

        $this->assertSame('#2563EBFF', $childrenByName['&:hover']['properties']['breakpoint_base']['typography']['color']);
        $this->assertSame(700, $childrenByName['& .title']['properties']['breakpoint_base']['typography']['font_weight']);
    }

    public function testNativeSelectorsSerializeAutoAndNoneAsOxygenUnitObjects(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.nav { width: auto; max-width: none; }</style><nav class="nav">Nav</nav>');

        $this->assertTrue($result['success']);

        $size = $result['selectorPayload']['selectors'][0]['properties']['breakpoint_base']['size'];
        $this->assertSame([
            'number' => null,
            'unit' => 'auto',
            'style' => 'auto',
        ], $size['width']);
        $this->assertSame([
            'number' => null,
            'unit' => 'none',
            'style' => 'none',
        ], $size['max_width']);
    }

    public function testWindPressModeDoesNotMirrorTailwindUtilitiesIntoOxygenSelectors(): void
    {
        $this->enableWindPressIntegration();
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'windpress';

        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.custom-card { color: red; }</style><div class="flex p-4 custom-card">Card</div>');

        $this->assertTrue($result['success']);

        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'];
        $this->assertContains('flex', $classes);
        $this->assertContains('p-4', $classes);
        $this->assertContains('custom-card', $classes);

        $selectorNames = array_map(
            static fn (array $selector): string => (string) ($selector['name'] ?? ''),
            $result['selectorPayload']['selectors']
        );

        $this->assertContains('custom-card', $selectorNames);
        $this->assertNotContains('flex', $selectorNames);
        $this->assertNotContains('p-4', $selectorNames);
        $this->assertCount(1, $result['element']['data']['properties']['meta']['classes'] ?? []);
    }

    public function testWindPressModeLeavesUtilityOnlyMarkupWithoutSelectorRefs(): void
    {
        $this->enableWindPressIntegration();
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'windpress';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="flex items-center p-4 text-white">Card</div>');

        $this->assertTrue($result['success']);

        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'];
        $this->assertContains('flex', $classes);
        $this->assertContains('items-center', $classes);
        $this->assertContains('p-4', $classes);
        $this->assertContains('text-white', $classes);
        $this->assertEmpty($result['selectorPayload']['selectors']);
        $this->assertSame([
            'requiresTreeJsonString' => true,
            'requiresOxygenSelectorPersistence' => false,
            'requiresBreakdanceClassesJsonString' => false,
            'persistsBreakdanceClassesJsonString' => false,
            'oxygenSelectorsOptionName' => 'oxygen_oxy_selectors_json_string',
            'oxygenSelectorCollectionsOptionName' => 'oxygen_oxy_selectors_collections_json_string',
            'breakdanceClassesOptionName' => 'breakdance_classes_json_string',
        ], $result['selectorPayload']['persistence']);
        $this->assertArrayNotHasKey('meta', $result['element']['data']['properties']);
        $this->assertStringNotContainsString('Tailwind utility fallback', $result['extractedCss']);
        $this->assertStringNotContainsString('ohc-native-', $result['extractedCss']);
        $this->assertSame('', $result['globalCss']);
        $this->assertStringContainsString('Tailwind utility fallback', $result['pageScopedCss']);
        $this->assertSame('page_scoped_styles', $result['styleRouting']['routes'][0]['destination']);
    }

    public function testWindPressModeKeepsMappedUtilityRulesInPageScopedCss(): void
    {
        $this->enableWindPressIntegration();
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'windpress';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid grid-cols-3 p-4 text-6xl">One</div>');

        $this->assertTrue($result['success']);

        $this->assertStringContainsString('Tailwind utility fallback', $result['pageScopedCss']);
        $this->assertStringContainsString('.grid { display: grid !important; }', $result['pageScopedCss']);
        $this->assertStringContainsString('.grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }', $result['pageScopedCss']);
        $this->assertStringContainsString('.p-4 { padding: 1rem !important; }', $result['pageScopedCss']);
        $this->assertStringContainsString('.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; color: inherit !important; }', $result['pageScopedCss']);
        $design = $result['element']['data']['properties']['design'] ?? [];
        $this->assertArrayNotHasKey('layout', $design);
        $this->assertArrayNotHasKey('spacing', $design);
        $this->assertArrayNotHasKey('typography', $design);
    }

    private function enableWindPressIntegration(): void
    {
        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_integration'] = true;
            $flags['windpress_class_mode'] = true;
            return $flags;
        });
    }

    public function testTreeBuilderDoesNotEmitUnsupportedButtonContainerTag(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<button>Click me</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotSame('button', $result['element']['data']['properties']['design']['tag'] ?? null);
        $this->assertNotSame('button', $result['element']['data']['properties']['settings']['advanced']['tag'] ?? null);
    }

    public function testTreeBuilderDropsInvalidTagWhenContainerBecomesText(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<section>Hello</section>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Text', $result['element']['data']['type']);
        $this->assertNotSame('section', $result['element']['data']['properties']['design']['tag'] ?? null);
        $this->assertNotSame('section', $result['element']['data']['properties']['settings']['advanced']['tag'] ?? null);
    }

    private function firstNativeMirrorClass(array $classes): ?string
    {
        foreach ($classes as $className) {
            if (is_string($className) && preg_match('/^ohc-native-\d+$/', $className) === 1) {
                return $className;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadNativeNoCodeManifest(): array
    {
        $path = self::nativeNoCodeFixtureDir() . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('Native no-code fixture manifest could not be read.');
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest) || !is_array($manifest['fixtures'] ?? null)) {
            throw new \RuntimeException('Native no-code fixture manifest is invalid.');
        }

        return $manifest;
    }

    private static function nativeNoCodeFixtureDir(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'html'
            . DIRECTORY_SEPARATOR . 'native-no-code';
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int> $types
     */
    private function countVisibleElementTypes(array $result, array &$types): void
    {
        if (isset($result['element']) && is_array($result['element'])) {
            $this->countElementTypes($result['element'], $types);
        }

        foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
            if (empty($result[$key]) || !is_array($result[$key])) {
                continue;
            }

            foreach ($result[$key] as $item) {
                if (is_array($item)) {
                    $this->countElementTypes($item, $types);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, int> $types
     */
    private function countElementTypes(array $node, array &$types): void
    {
        $type = $node['data']['type'] ?? null;
        if (is_string($type)) {
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->countElementTypes($child, $types);
            }
        }
    }

    /**
     * @param array<string, int> $types
     * @return array{total:int, html:int, css:int, javascript:int}
     */
    private function codeBlockCounts(array $types): array
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
    private function hasOwnedFallbackCss(array $result): bool
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
}
