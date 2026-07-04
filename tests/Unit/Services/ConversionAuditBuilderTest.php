<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\ConversionAuditBuilder;
use PHPUnit\Framework\TestCase;

class ConversionAuditBuilderTest extends TestCase
{
    public function testBuildExposesFallbackCategoryAndRouteMetadata(): void
    {
        $audit = (new ConversionAuditBuilder())->build([
            'stats' => [
                'elements' => 2,
                'tailwindClasses' => 0,
                'customClasses' => 1,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'detectedIconLibraries' => [],
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'customClasses' => ['hero-card'],
            'selectorPayload' => ['selectors' => [], 'collections' => []],
            'extractedCss' => '.hero-card{backdrop-filter:blur(12px);}',
            'designDocument' => [
                'summary' => [],
                'tokens' => [],
                'classStrategy' => ['recommendation' => 'native'],
                'followUp' => [],
            ],
            'importPlan' => [
                'version' => 1,
                'status' => 'needs_review',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100.0],
                'fallbacks' => [
                    [
                        'type' => 'extracted_css',
                        'label' => 'extracted CSS fallback',
                        'category' => 'page_fallback',
                        'route' => 'page_css_code',
                        'persistence' => [
                            'target' => 'page_css_code',
                            'action' => 'insert_with_page',
                        ],
                    ],
                    [
                        'type' => 'global_style_asset',
                        'label' => 'Material Symbols global style',
                        'category' => 'global_asset',
                        'route' => 'global_stylesheet',
                        'persistence' => [
                            'target' => 'oxygen_global_styles',
                            'action' => 'save_or_update',
                        ],
                    ],
                ],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $fallbacks = $audit['transformed']['importPlan']['fallbacks'];

        $this->assertSame('page_fallback', $fallbacks[0]['category']);
        $this->assertSame('page_css_code', $fallbacks[0]['route']);
        $this->assertSame('global_asset', $fallbacks[1]['category']);
        $this->assertSame('global_stylesheet', $fallbacks[1]['route']);
        $this->assertSame('oxygen_global_styles', $fallbacks[1]['persistence']['target']);
    }
}
