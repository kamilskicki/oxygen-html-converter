<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\DesignDocumentBuilder;
use OxyHtmlConverter\Services\ImportPlanBuilder;
use OxyHtmlConverter\Services\OxygenTokenBindingService;
use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class OxygenTokenBindingServiceTest extends TestCase
{
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
