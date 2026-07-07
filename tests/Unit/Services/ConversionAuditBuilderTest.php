<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
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
                'unsupportedItems' => [[
                    'location' => 'html > body > iframe',
                    'selector' => 'iframe',
                    'sourceSnippet' => '<iframe src="https://example.test"></iframe>',
                    'reason' => 'Unsupported embed requires HtmlCode fallback.',
                    'severity' => 'blocking',
                    'fallbackCategory' => 'unsupported_embed',
                    'safeModeImpact' => 'Safe Mode sanitizes or removes unsafe embed markup.',
                    'owner' => 'Core native profile',
                    'remediation' => 'Replace with a native-safe embed integration.',
                ]],
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
        $this->assertSame(1, $audit['summary']['unsupportedCount']);
        $this->assertSame('iframe', $audit['diagnostics']['unsupportedItems'][0]['selector']);
        $this->assertSame('unsupported_embed', $audit['diagnostics']['unsupportedItems'][0]['fallbackCategory']);
        $this->assertStringContainsString('Safe Mode', $audit['diagnostics']['unsupportedItems'][0]['safeModeImpact']);
    }

    public function testBuildExposesPluginDependentStyleRoutes(): void
    {
        $audit = (new ConversionAuditBuilder())->build([
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 1,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'customClasses' => [],
            'selectorPayload' => ['selectors' => [], 'collections' => []],
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
                'fallbacks' => [],
                'styleRoutes' => [[
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                    'owner' => 'runtime_plugin_dependency',
                    'cascadeOrder' => 30,
                    'exportBehavior' => 'requires_runtime_plugin',
                    'rollbackStore' => 'page_styles',
                    'pluginDependency' => [
                        'slug' => 'windpress',
                        'name' => 'WindPress',
                        'required' => true,
                        'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
                    ],
                ]],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $this->assertSame(1, $audit['summary']['pluginDependentCssCount']);
        $this->assertSame('windpress', $audit['transformed']['importPlan']['pluginDependentStyleRoutes'][0]['pluginDependency']['slug']);
        $this->assertContains('Review plugin-dependent CSS runtime requirements before importing.', $audit['followUp']);
    }

    public function testBuildExposesProductBoundaryDeferralsWithTargetsAndRemediation(): void
    {
        $audit = (new ConversionAuditBuilder())->build([
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'customClasses' => [],
            'selectorPayload' => ['selectors' => [], 'collections' => []],
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
                'nativeCoverage' => ['percent' => 85.0],
                'fallbacks' => [[
                    'type' => 'advanced_component_scope_deferred',
                    'label' => 'Advanced component pattern deferred: forms',
                    'category' => 'advanced_component_unsupported',
                    'route' => 'component_scope_report',
                    'target' => 'component_scope_report',
                    'owner' => 'Core component scope boundary',
                    'extensionPoint' => 'oxy_html_converter_component_form_mapper',
                    'remediation' => 'Use static Core output, defer the pattern, or implement oxy_html_converter_component_form_mapper in a verified extension.',
                    'persistence' => [
                        'target' => 'component_scope_report',
                        'action' => 'report_only',
                    ],
                ], [
                    'type' => 'site_operation_scope_deferred',
                    'label' => 'Site operation deferred: WooCommerce areas',
                    'category' => 'site_operation_pro',
                    'route' => 'product_boundary_report',
                    'target' => 'product_boundary_report',
                    'owner' => 'Core product boundary',
                    'extensionPoint' => 'oxy_html_converter_pro_woocommerce_mapper',
                    'remediation' => 'Keep static Core output, defer the operation, or implement oxy_html_converter_pro_woocommerce_mapper in a verified extension.',
                    'persistence' => [
                        'target' => 'product_boundary_report',
                        'action' => 'report_only',
                    ],
                ], [
                    'type' => 'unsupported_item',
                    'category' => 'unsupported_embed',
                    'route' => 'unsupported_report',
                ]],
                'styleRoutes' => [],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $deferrals = $audit['transformed']['importPlan']['productBoundaryDeferrals'];

        $this->assertSame(3, $audit['summary']['fallbackCount']);
        $this->assertSame(2, $audit['summary']['productBoundaryDeferralCount']);
        $this->assertCount(2, $deferrals);
        $this->assertSame('component_scope_report', $deferrals[0]['route']);
        $this->assertSame('component_scope_report', $deferrals[0]['target']);
        $this->assertSame('component_scope_report', $deferrals[0]['persistence']['target']);
        $this->assertSame('oxy_html_converter_component_form_mapper', $deferrals[0]['extensionPoint']);
        $this->assertStringContainsString('verified extension', $deferrals[0]['remediation']);
        $this->assertSame('product_boundary_report', $deferrals[1]['route']);
        $this->assertSame('product_boundary_report', $deferrals[1]['target']);
        $this->assertSame('product_boundary_report', $deferrals[1]['persistence']['target']);
        $this->assertSame('oxy_html_converter_pro_woocommerce_mapper', $deferrals[1]['extensionPoint']);
        $this->assertStringContainsString('Core product boundary', $deferrals[1]['owner']);
    }

    public function testBuildDoesNotTruncateProductBoundaryDeferralDetails(): void
    {
        $fallbacks = [];
        for ($index = 0; $index < 10; $index++) {
            $fallbacks[] = [
                'type' => 'site_operation_scope_deferred',
                'label' => 'Site operation deferred: dynamic binding ' . $index,
                'category' => 'site_operation_pro',
                'route' => 'product_boundary_report',
                'target' => 'product_boundary_report',
                'owner' => 'Core product boundary',
                'extensionPoint' => 'oxy_html_converter_pro_dynamic_binding_mapper',
                'remediation' => 'Keep static Core output, defer the operation, or implement oxy_html_converter_pro_dynamic_binding_mapper in a verified extension.',
                'persistence' => [
                    'target' => 'product_boundary_report',
                    'action' => 'report_only',
                ],
            ];
        }

        $audit = (new ConversionAuditBuilder())->build([
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'customClasses' => [],
            'selectorPayload' => ['selectors' => [], 'collections' => []],
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
                'nativeCoverage' => ['percent' => 85.0],
                'fallbacks' => $fallbacks,
                'styleRoutes' => [],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $this->assertSame(10, $audit['summary']['productBoundaryDeferralCount']);
        $this->assertCount(8, $audit['transformed']['importPlan']['fallbacks']);
        $this->assertCount(10, $audit['transformed']['importPlan']['productBoundaryDeferrals']);
        $this->assertSame(
            'Site operation deferred: dynamic binding 9',
            $audit['transformed']['importPlan']['productBoundaryDeferrals'][9]['label']
        );
    }

    public function testBuildExposesAssetNormalizationSummaryAndFollowUp(): void
    {
        $audit = (new ConversionAuditBuilder())->build([
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'customClasses' => [],
            'selectorPayload' => ['selectors' => [], 'collections' => []],
            'assetNormalization' => [
                'summary' => [
                    'total' => 14,
                    'localized' => 0,
                    'stable' => 12,
                    'rejected' => 1,
                    'manualFollowUp' => 1,
                ],
                'assets' => array_merge(
                    array_map(
                        static fn (int $index): array => [
                            'type' => 'image',
                            'source' => '/wp-content/uploads/stable-' . $index . '.jpg',
                            'status' => 'stable_reference',
                        ],
                        range(1, 12)
                    ),
                    [[
                        'type' => 'image',
                        'source' => 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/hero.png',
                        'status' => 'rejected',
                    ], [
                        'type' => 'font',
                        'source' => 'https://fonts.googleapis.com/css2?family=Inter',
                        'status' => 'manual_follow_up',
                    ]]
                ),
            ],
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
                'fallbacks' => [],
                'styleRoutes' => [],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $this->assertSame(14, $audit['summary']['assetCount']);
        $this->assertSame(1, $audit['summary']['rejectedAssetCount']);
        $this->assertSame(1, $audit['summary']['manualAssetFollowUpCount']);
        $this->assertSame('rejected', $audit['preserved']['assetNormalization']['byStatus']['rejected'][0]['status']);
        $this->assertSame('manual_follow_up', $audit['preserved']['assetNormalization']['byStatus']['manual_follow_up'][0]['status']);
        $this->assertTrue($audit['preserved']['assetNormalization']['truncated']);
        $this->assertContains('Replace rejected temporary or unsafe assets before production import.', $audit['followUp']);
        $this->assertContains('Review external asset licensing, localization, and cache policy before importing.', $audit['followUp']);
    }

    public function testBuildCountsRealOxygenSurfaceShape(): void
    {
        $audit = (new ConversionAuditBuilder())->build([
            ...$this->oxygenSurfaceResultOverrides(),
            'stats' => [
                'elements' => 99,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
                'unsupportedItems' => [[
                    'location' => 'body > iframe',
                    'selector' => 'iframe',
                    'reason' => 'Unsupported embed.',
                    'fallbackCategory' => 'unsupported_embed',
                ]],
            ],
            'customClasses' => [],
            'selectorPayload' => [
                'selectors' => [['selector' => '.hero']],
                'collections' => [],
            ],
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
                'fallbacks' => [],
                'styleRoutes' => [],
                'persistence' => [],
                'actions' => [],
                'blockers' => [],
            ],
        ], []);

        $this->assertSame(11, $audit['summary']['elements']);
        $this->assertSame(6, $audit['summary']['codeBlockCount']);
        $this->assertSame(4, $audit['summary']['htmlCodeBlocks']);
        $this->assertSame(1, $audit['summary']['cssCodeBlocks']);
        $this->assertSame(1, $audit['summary']['javascriptCodeBlocks']);
        $this->assertSame(1, $audit['summary']['componentNodes']);
        $this->assertSame(2, $audit['summary']['assetNodes']);
        $this->assertSame(1, $audit['summary']['imageNodes']);
        $this->assertSame(1, $audit['summary']['videoNodes']);
        $this->assertSame(4, $audit['summary']['classAssignments']);
        $this->assertSame(1, $audit['summary']['selectorCount']);
        $this->assertSame(1, $audit['summary']['unsupportedCount']);
        $this->assertSame(11, $audit['transformed']['convertedSurface']['totalNodes']);
        $this->assertSame(6, $audit['transformed']['convertedSurface']['codeBlocks']['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function oxygenSurfaceResultOverrides(): array
    {
        return [
            'element' => [
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'classes' => ['layout', 'hero'],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'classes' => ['nested'],
                                ],
                            ],
                        ],
                    ],
                    'children' => [
                        ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
                        ['data' => ['type' => ElementTypes::JAVASCRIPT_CODE], 'children' => []],
                        ['data' => ['type' => ElementTypes::COMPONENT], 'children' => []],
                        [
                            'data' => [
                                'type' => ElementTypes::IMAGE,
                                'properties' => [
                                    'settings' => [
                                        'advanced' => [
                                            'classes' => ['media'],
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        ['data' => ['type' => ElementTypes::HTML5_VIDEO], 'children' => []],
                    ],
                ]],
            ],
            'cssElement' => ['data' => ['type' => ElementTypes::CSS_CODE], 'children' => []],
            'headLinkElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
            'headScriptElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
            'iconScriptElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
        ];
    }
}
