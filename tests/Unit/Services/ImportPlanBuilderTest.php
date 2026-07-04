<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\ImportPlanBuilder;
use PHPUnit\Framework\TestCase;

class ImportPlanBuilderTest extends TestCase
{
    public function testBuildCreatesTokenComponentAndPersistencePlan(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'selectorPayload' => [
                    'selectors' => [
                        ['selector' => '.hero'],
                        ['selector' => '.card'],
                    ],
                    'collections' => ['Imported HTML'],
                ],
            ]),
            $this->designDocumentFixture([
                'summary' => [
                    'sectionCount' => 2,
                    'componentCandidatesCount' => 1,
                    'colorTokenCount' => 1,
                    'fontTokenCount' => 1,
                    'spacingTokenCount' => 1,
                    'buttonVariantCount' => 1,
                    'fallbackCss' => false,
                    'htmlCodeBlocks' => 0,
                    'cssCodeBlocks' => 0,
                ],
            ]),
            ['strictNative' => false]
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame(100.0, $plan['nativeCoverage']['percent']);
        $this->assertSame(3, $plan['persistence']['variables']['proposed']);
        $this->assertSame('save_or_update', $plan['persistence']['variables']['action']);
        $this->assertSame('oxygen_variables', $plan['persistence']['variables']['target']);
        $this->assertSame('oxygen_variables_json_string', $plan['persistence']['variables']['repository']);
        $this->assertSame('save_or_update', $plan['persistence']['globalSettings']['action']);
        $this->assertSame('oxygen_global_settings', $plan['persistence']['globalSettings']['target']);
        $this->assertSame(2, $plan['persistence']['selectors']['proposed']);
        $this->assertSame(1, $plan['persistence']['components']['candidates']);
        $this->assertSame('map_or_create_variable', $plan['tokens']['colors'][0]['action']);
        $this->assertSame('review_component_candidate', $plan['components'][0]['action']);
        $this->assertContains('Create or update a draft page, then verify editability in Oxygen.', $plan['actions']);
    }

    public function testBuildBlocksStrictNativeWhenFallbackCodeExists(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'element' => [
                    'data' => ['type' => ElementTypes::CONTAINER],
                    'children' => [
                        [
                            'data' => ['type' => ElementTypes::HTML_CODE],
                            'children' => [],
                        ],
                        [
                            'data' => ['type' => ElementTypes::CSS_CODE],
                            'children' => [],
                        ],
                    ],
                ],
                'extractedCss' => '.hero{color:red;}',
                'stats' => [
                    'elements' => 10,
                    'tailwindClasses' => 0,
                    'customClasses' => 1,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                ],
            ]),
            $this->designDocumentFixture([
                'summary' => [
                    'sectionCount' => 1,
                    'componentCandidatesCount' => 0,
                    'colorTokenCount' => 0,
                    'fontTokenCount' => 0,
                    'spacingTokenCount' => 0,
                    'buttonVariantCount' => 0,
                    'fallbackCss' => true,
                    'htmlCodeBlocks' => 1,
                    'cssCodeBlocks' => 1,
                ],
            ]),
            ['strictNative' => true]
        );

        $this->assertSame('blocked', $plan['status']);
        $this->assertFalse($plan['canImport']);
        $this->assertSame(80.0, $plan['nativeCoverage']['percent']);
        $this->assertNotEmpty($plan['blockers']);
        $this->assertSame('html_code', $plan['fallbacks'][0]['type']);
        $this->assertFallbackDecisionFields($plan['fallbacks'][0]);
    }

    public function testBuildClassifiesPageCssFallbackAndGlobalMaterialSymbolsAsset(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'extractedCss' => <<<'CSS'
.hero-card { border-radius: 24px; backdrop-filter: blur(12px); }
CSS,
                'globalCss' => <<<'CSS'
@font-face { font-family: 'Material Symbols Outlined'; src: url(material.woff2) format('woff2'); }
.material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-variation-settings: 'FILL' 0, 'wght' 400; }
CSS,
                'styleRouting' => [
                    'summary' => [
                        'hasPageCss' => true,
                        'hasGlobalCss' => true,
                    ],
                    'routes' => [
                        [
                            'type' => 'source_style',
                            'destination' => 'page_css',
                            'label' => 'Source style CSS',
                        ],
                        [
                            'type' => 'global_asset',
                            'destination' => 'global_styles',
                            'label' => 'Global asset CSS',
                        ],
                    ],
                ],
            ]),
            $this->designDocumentFixture([
                'summary' => [
                    'fallbackCss' => true,
                    'htmlCodeBlocks' => 0,
                    'cssCodeBlocks' => 0,
                ],
            ]),
            ['strictNative' => false]
        );

        $pageFallback = $this->firstFallbackOfType($plan['fallbacks'], 'extracted_css');
        $globalAsset = $this->firstFallbackOfType($plan['fallbacks'], 'global_style_asset');

        $this->assertNotNull($pageFallback);
        $this->assertSame('page_css', $plan['styleRoutes'][0]['destination']);
        $this->assertSame('page_fallback', $pageFallback['category']);
        $this->assertSame('page_css_code', $pageFallback['route']);
        $this->assertSame('page_css_code', $pageFallback['persistence']['target']);
        $this->assertFallbackDecisionFields($pageFallback);
        $this->assertTrue($pageFallback['blockingInStrictNative']);

        $this->assertNotNull($globalAsset);
        $this->assertSame('Material Symbols global style', $globalAsset['label']);
        $this->assertSame('global_asset', $globalAsset['category']);
        $this->assertSame('global_stylesheet', $globalAsset['route']);
        $this->assertSame('oxygen_global_styles', $globalAsset['persistence']['target']);
        $this->assertFallbackDecisionFields($globalAsset);
        $this->assertSame('save_or_update', $plan['persistence']['globalStyles']['action']);
        $this->assertSame(1, $plan['persistence']['globalStyles']['proposed']);
        $this->assertSame('oxy_html_converter_global_styles', $plan['persistence']['globalStyles']['repository']);
        $this->assertFalse($globalAsset['blockingInStrictNative']);
    }

    public function testBuildClassifiesWindPressSafetyCssAsPageScopedAsset(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'pageScopedCss' => <<<'CSS'
/* Tailwind utility fallback */
.text-6xl { font-size: 3.75rem !important; }
CSS,
                'styleRouting' => [
                    'summary' => [
                        'hasPageCss' => false,
                        'hasGlobalCss' => false,
                        'hasPageScopedCss' => true,
                    ],
                    'routes' => [[
                        'type' => 'tailwind_utility_fallback',
                        'destination' => 'page_scoped_styles',
                        'label' => 'Tailwind utility fallback safety CSS for WindPress',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => false]
        );

        $pageStyleAsset = $this->firstFallbackOfType($plan['fallbacks'], 'page_scoped_style_asset');

        $this->assertNotNull($pageStyleAsset);
        $this->assertSame('page_scoped_asset', $pageStyleAsset['category']);
        $this->assertSame('post_meta_stylesheet', $pageStyleAsset['route']);
        $this->assertFallbackDecisionFields($pageStyleAsset);
        $this->assertSame('save_or_update', $plan['persistence']['pageStyles']['action']);
        $this->assertSame(1, $plan['persistence']['pageStyles']['proposed']);
        $this->assertSame('_oxy_html_converter_page_styles', $plan['persistence']['pageStyles']['metaKey']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function resultFixture(array $overrides = []): array
    {
        return array_replace_recursive([
            'success' => true,
            'element' => [
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'detectedIconLibraries' => [],
            'extractedCss' => '',
            'customClasses' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
            'stats' => [
                'elements' => 12,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function designDocumentFixture(array $overrides = []): array
    {
        return array_replace_recursive([
            'version' => 1,
            'summary' => [
                'sectionCount' => 1,
                'componentCandidatesCount' => 1,
                'colorTokenCount' => 1,
                'fontTokenCount' => 1,
                'spacingTokenCount' => 1,
                'buttonVariantCount' => 1,
                'fallbackCss' => false,
                'htmlCodeBlocks' => 0,
                'cssCodeBlocks' => 0,
            ],
            'tokens' => [
                'colors' => [
                    ['value' => '#731B19', 'uses' => 2, 'suggestedName' => 'color-731b19'],
                ],
                'fonts' => [
                    ['value' => 'Inter', 'uses' => 1, 'suggestedName' => 'font-inter'],
                ],
                'spacing' => [
                    ['value' => '24px', 'uses' => 3, 'suggestedName' => 'space-24px'],
                ],
            ],
            'componentCandidates' => [
                [
                    'signature' => 'div[h3,p]',
                    'tag' => 'div',
                    'count' => 3,
                    'suggestedName' => 'card',
                    'classes' => ['card'],
                ],
            ],
            'classStrategy' => [
                'nativeSelectorCount' => 0,
                'customClassCount' => 0,
                'tailwindClassCount' => 0,
                'recommendation' => 'native',
            ],
            'followUp' => [],
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function firstFallbackOfType(array $fallbacks, string $type): ?array
    {
        foreach ($fallbacks as $fallback) {
            if (($fallback['type'] ?? null) === $type) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fallback
     */
    private function assertFallbackDecisionFields(array $fallback): void
    {
        foreach (['location', 'reason', 'severity', 'owner', 'remediation'] as $field) {
            $this->assertArrayHasKey($field, $fallback);
            $this->assertIsString($fallback[$field]);
            $this->assertNotSame('', trim($fallback[$field]));
        }
    }
}
