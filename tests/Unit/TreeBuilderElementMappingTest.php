<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class TreeBuilderElementMappingTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        parent::tearDown();
    }

    public function testTreeBuilderKeepsOxygenButtonMappingByDefault(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<button>Click me</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
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

    public function testInvalidSupportedCssValueRemainsInFallbackCss(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<style>.card { width: url(javascript:alert(1)); color: #123456; }</style><div class="card">Card</div>');

        $this->assertTrue($result['success']);

        $this->assertStringContainsString('width: url(javascript:alert(1))', $result['extractedCss']);
        $this->assertStringNotContainsString('color: #123456', $result['extractedCss']);
        $this->assertArrayNotHasKey('width', $result['element']['data']['properties']['design']['size'] ?? []);
        $this->assertSame('#123456FF', $result['element']['data']['properties']['design']['typography']['color']);
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
}
