<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\DesignDocumentBuilder;
use OxyHtmlConverter\Services\ImportPlanBuilder;
use OxyHtmlConverter\Services\OxygenTokenBindingService;
use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class OxygenTokenBindingServiceTest extends TestCase
{
    public function testSelectorExpressionsUseCssVariablesWhileWholeControlsStayNative(): void
    {
        $measurement = static fn(string $style): array => ['number' => null, 'unit' => 'custom', 'style' => $style];
        $result = ['selectorPayload' => ['selectors' => [[
            'name' => 'fluid-layout',
            'properties' => [
                'spacing' => ['spacing' => ['padding' => [
                    'left' => $measurement('clamp(1rem, 4vw, 3rem)'),
                    'top' => $measurement('1rem'),
                ]]],
                'layout' => ['gap' => ['row' => $measurement('calc(1rem + 4vw)')]],
                'background' => ['backgrounds' => [['gradient' => ['value' => 'linear-gradient(#123456, #fff)']]]],
            ],
            'children' => [['name' => 'hover', 'properties' => [
                'layout' => ['gap' => ['column' => $measurement('max(1rem, 4vw)')]],
            ]]],
        ]]]];
        $payload = ['designDocument' => ['tokens' => [
            'spacing' => [
                ['value' => '1rem', 'uses' => 1, 'suggestedName' => 'space'],
                ['value' => '4vw', 'uses' => 1, 'suggestedName' => 'fluid'],
            ],
            'colors' => [['value' => '#123456', 'uses' => 1, 'suggestedName' => 'tint']],
        ]]];
        $binder = new OxygenTokenBindingService();
        $result = $binder->applyToConversionResult($result, $payload);
        $selector = $result['selectorPayload']['selectors'][0];
        $properties = $selector['properties'];

        $this->assertSame('clamp(var(--ohc-space), var(--ohc-fluid), 3rem)', $properties['spacing']['spacing']['padding']['left']['style']);
        $space = array_values(array_filter($binder->buildReferences($payload), static fn(array $reference): bool => $reference['cssVariableName'] === 'ohc-space'))[0];
        $this->assertSame($space['selectorReference'], $properties['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('calc(var(--ohc-space) + var(--ohc-fluid))', $properties['layout']['gap']['row']['style']);
        $this->assertSame('linear-gradient(var(--ohc-tint), #fff)', $properties['background']['backgrounds'][0]['gradient']['value']);
        $this->assertSame('max(var(--ohc-space), var(--ohc-fluid))', $selector['children'][0]['properties']['layout']['gap']['column']['style']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testBindsNativeNumbersGradientsAndCompoundMeasurements(): void
    {
        $result = (new TreeBuilder())->convert('<div>Tokens</div>');
        $result['element']['data']['properties']['design'] = [
            'effects' => ['opacity' => 40],
            'typography' => ['font_weight' => 600, 'font_size' => ['number' => null, 'unit' => 'custom', 'style' => 'clamp(1rem, 2vw, 3rem)']],
            'background' => ['backgrounds' => [['gradient' => ['value' => 'linear-gradient(rgba(1, 2, 3, 0.4), #fff)']]]],
        ];
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, ['designDocument' => ['tokens' => [
            'numbers' => [['value' => '0.4', 'uses' => 1, 'suggestedName' => 'opacity'], ['value' => '600', 'uses' => 1, 'suggestedName' => 'weight']],
            'measurements' => [['value' => '2vw', 'uses' => 1, 'suggestedName' => 'fluid']],
            'colors' => [['value' => 'rgba(1,2,3,0.4)', 'uses' => 1, 'suggestedName' => 'tint']],
        ]]]);
        $design = $result['element']['data']['properties']['design'];
        $this->assertSame('var(--ohc-opacity)', $design['effects']['opacity']);
        $this->assertSame('var(--ohc-weight)', $design['typography']['font_weight']);
        $this->assertSame('clamp(1rem, var(--ohc-fluid), 3rem)', $design['typography']['font_size']['style']);
        $this->assertSame('linear-gradient(var(--ohc-tint), #fff)', $design['background']['backgrounds'][0]['gradient']['value']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testSourceOnlyTokensAreReportedButPresentUnboundControlsStillBlock(): void
    {
        $result = (new TreeBuilder())->convert('<div>Visible</div>');
        $result['element']['data']['properties']['design']['unknown_supported_control'] = '#123456';
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, ['designDocument' => ['tokens' => [
            'colors' => [['value' => '#abcdef', 'uses' => 1, 'suggestedName' => 'overridden'], ['value' => '#123456', 'uses' => 1, 'suggestedName' => 'missing-binding']],
        ]]]);
        $this->assertSame(1, $result['tokenUsage']['sourceOnlyCount']);
        $this->assertSame(1, $result['tokenUsage']['orphanCount']);
        $plan = (new ImportPlanBuilder())->build($result, [], []);
        $this->assertSame('blocked', $plan['status']);
    }

    public function testCssBindingKeepsSpacingAndMeasurementTokensInTheirOwnProperties(): void
    {
        $result = (new TreeBuilder())->convert('<div>Tokens</div>');
        $result['extractedCss'] = '.card { padding: 1rem; width: 1rem; }';
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, ['designDocument' => ['tokens' => [
            'spacing' => [['value' => '1rem', 'uses' => 1, 'suggestedName' => 'space']],
            'measurements' => [['value' => '1rem', 'uses' => 1, 'suggestedName' => 'size']],
        ]]]);
        $this->assertStringContainsString('padding: var(--ohc-space)', $result['extractedCss']);
        $this->assertStringContainsString('width: var(--ohc-size)', $result['extractedCss']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testRichTextInlineStylesAndFontCustomPropertiesAreBound(): void
    {
        $result = (new TreeBuilder())->convert('<div>Tokens</div>');
        $result['element']['data']['properties']['content']['content']['text'] = '<span style="font-weight:400;color:#123456">ZaĹĽĂłĹ‚Ä‡ #123456</span>';
        $result['extractedCss'] = ':root { --font-body: "Inter", sans-serif; }';
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, ['designDocument' => ['tokens' => [
            'colors' => [['value' => '#123456', 'uses' => 1, 'suggestedName' => 'brand']],
            'numbers' => [['value' => '400', 'uses' => 1, 'suggestedName' => 'weight']],
            'fonts' => [['value' => 'Inter', 'uses' => 1, 'suggestedName' => 'body']],
        ]]]);
        $html = $result['element']['data']['properties']['content']['content']['text'];
        $this->assertStringContainsString('font-weight:var(--ohc-weight)', $html);
        $this->assertStringContainsString('color:var(--ohc-brand)', $html);
        $this->assertStringContainsString('ZaĹĽĂłĹ‚Ä‡ #123456', $html);
        $this->assertStringContainsString('--font-body: var(--ohc-body), sans-serif', $result['extractedCss']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testImageElementTokensDoNotBecomeRequiredOrphans(): void
    {
        $html = '<img src="https://example.test/assets/photo.jpg" alt="Photo">';
        $result = (new TreeBuilder())->convert($html);
        $designDocument = (new DesignDocumentBuilder())->build($html, $result);
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => $designDocument,
        ]);
        $plan = (new ImportPlanBuilder())->build($result, $designDocument, []);

        $this->assertSame(
            'https://example.test/assets/photo.jpg',
            $result['element']['data']['properties']['content']['image']['url']
        );
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
        $this->assertNotSame('blocked', $plan['status']);
    }

    public function testCssBackgroundImageTokensAreRequiredAndBound(): void
    {
        $result = [
            'success' => true,
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [],
                ],
                'children' => [],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
            'extractedCss' => '.hero { background-image:url("https://example.test/assets/hero.jpg"); }',
            'globalCss' => '',
            'pageScopedCss' => '',
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ];

        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => [
                'tokens' => [
                    'images' => [[
                        'value' => 'https://example.test/assets/hero.jpg',
                        'uses' => 1,
                        'suggestedName' => 'image-hero',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(1, $result['tokenUsage']['bindingRequired']);
        $this->assertSame(1, $result['tokenUsage']['bound']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
        $this->assertStringContainsString('background-image:var(--ohc-image-hero)', $result['extractedCss']);
    }

    public function testFontFamilyStacksBindToDetectedFontToken(): void
    {
        $html = '<section style="font-family: Inter, sans-serif">Copy</section>';
        $result = (new TreeBuilder())->convert($html);
        $designDocument = (new DesignDocumentBuilder())->build($html, $result);
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => $designDocument,
        ]);

        $this->assertSame('var(--ohc-font-inter)', $result['element']['data']['properties']['design']['typography']['font_family']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testSpacingAndMeasurementTokensWithSameValueBindByControlContext(): void
    {
        $html = '<section style="padding:24px;font-size:24px">Copy</section>';
        $result = (new TreeBuilder())->convert($html);
        $designDocument = (new DesignDocumentBuilder())->build($html, $result);
        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => $designDocument,
        ]);

        $design = $result['element']['data']['properties']['design'];

        $this->assertSame('var(--ohc-space-24px)', $design['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('var(--ohc-measure-24px)', $design['typography']['font_size']['style']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
        $this->assertSame(2, $result['tokenUsage']['bound']);
    }

    public function testResidualCssReplacementUsesTokenBoundaries(): void
    {
        $result = [
            'success' => true,
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [],
                ],
                'children' => [],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
            'extractedCss' => '.card { color:#fff; background:#fff8f5; width:16px; opacity:1; font-family: Inter, sans-serif; } .Inter-card { color:#111; } /* #fff Inter 1 */',
            'globalCss' => '',
            'pageScopedCss' => '',
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ];

        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#fff',
                        'uses' => 1,
                        'suggestedName' => 'color-white',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 1,
                        'suggestedName' => 'font-inter',
                    ]],
                    'numbers' => [[
                        'value' => '1',
                        'uses' => 1,
                        'suggestedName' => 'number-one',
                    ]],
                ],
            ],
        ]);

        $this->assertStringContainsString('color:var(--ohc-color-white)', $result['extractedCss']);
        $this->assertStringContainsString('background:#fff8f5', $result['extractedCss']);
        $this->assertStringContainsString('width:16px', $result['extractedCss']);
        $this->assertStringContainsString('opacity:var(--ohc-number-one)', $result['extractedCss']);
        $this->assertStringContainsString('font-family: var(--ohc-font-inter), sans-serif', $result['extractedCss']);
        $this->assertStringContainsString('.Inter-card', $result['extractedCss']);
        $this->assertStringContainsString('/* #fff Inter 1 */', $result['extractedCss']);
        $this->assertSame(0, $result['tokenUsage']['orphanCount']);
    }

    public function testResidualCssWithPercentageDoesNotBreakMeasurementTokenBinding(): void
    {
        $result = [
            'success' => true,
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [],
                ],
                'children' => [],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
            'extractedCss' => '.fallback-card { width:50%; }',
            'globalCss' => '',
            'pageScopedCss' => '',
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ];

        $result = (new OxygenTokenBindingService())->applyToConversionResult($result, [
            'designDocument' => [
                'tokens' => [
                    'measurements' => [[
                        'value' => '50%',
                        'uses' => 1,
                        'suggestedName' => 'measure-50-percent',
                    ]],
                ],
            ],
        ]);

        $this->assertStringContainsString('width:var(--ohc-measure-50-percent)', $result['extractedCss']);
        $this->assertSame(1, $result['tokenUsage']['bound']);
    }
}
