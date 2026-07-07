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
        $this->assertSame('review_component_candidates', $plan['persistence']['components']['action']);
        $this->assertSame(1, $plan['persistence']['components']['candidates']);
        $this->assertSame(0, $plan['persistence']['components']['ready']);
        $this->assertSame(1, $plan['persistence']['components']['skipped']);
        $this->assertContains('missing_component_tree', $plan['persistence']['components']['reasons']);
        $this->assertSame('map_or_create_variable', $plan['tokens']['colors'][0]['action']);
        $this->assertSame('skip_component_candidate', $plan['components'][0]['action']);
        $this->assertSame('skipped', $plan['components'][0]['status']);
        $this->assertSame('missing_component_tree', $plan['components'][0]['reason']);
        $this->assertContains('Create or update a draft page, then verify editability in Oxygen.', $plan['actions']);
    }

    public function testBuildReportsPersistableComponentBlocksAndPersistenceOutcomes(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture([
                'componentCandidates' => [
                    [
                        'signature' => 'div[h3,p,a]',
                        'tag' => 'div',
                        'count' => 3,
                        'confidence' => 0.9,
                        'suggestedName' => 'feature-card',
                        'classes' => ['feature-card'],
                        'documentTree' => $this->minimalTemplateTree('section'),
                        'componentProperties' => $this->componentPropertiesFixture('feature_card_text'),
                    ],
                    [
                        'signature' => 'article[h3,p]',
                        'tag' => 'article',
                        'count' => 4,
                        'confidence' => 1.0,
                        'suggestedName' => 'testimonial-card',
                        'classes' => ['testimonial-card'],
                        'documentTree' => $this->minimalTemplateTree('article'),
                        'componentProperties' => $this->componentPropertiesFixture('testimonial_card_text'),
                        'persistenceResult' => [
                            'action' => 'created',
                            'postId' => 44,
                            'treeHash' => 'tree-hash',
                            'settingsHash' => 'settings-hash',
                        ],
                    ],
                    [
                        'signature' => 'li[h4,p]',
                        'tag' => 'li',
                        'count' => 1,
                        'confidence' => 0.3,
                        'suggestedName' => 'list-item',
                        'classes' => ['list-item'],
                        'documentTree' => $this->minimalTemplateTree('li'),
                    ],
                ],
            ]),
            ['strictNative' => false]
        );

        $this->assertSame('save_or_update_blocks', $plan['persistence']['components']['action']);
        $this->assertSame('oxygen_block', $plan['persistence']['components']['target']);
        $this->assertSame(['_oxygen_data', '_breakdance_block_settings'], $plan['persistence']['components']['metaKeys']);
        $this->assertSame(3, $plan['persistence']['components']['candidates']);
        $this->assertSame(1, $plan['persistence']['components']['ready']);
        $this->assertSame(1, $plan['persistence']['components']['created']);
        $this->assertSame(0, $plan['persistence']['components']['updated']);
        $this->assertSame(1, $plan['persistence']['components']['skipped']);
        $this->assertContains('below_occurrence_threshold', $plan['persistence']['components']['reasons']);

        $this->assertSame('save_or_update_oxygen_block', $plan['components'][0]['action']);
        $this->assertSame('ready', $plan['components'][0]['status']);
        $this->assertSame('persisted_oxygen_block', $plan['components'][1]['action']);
        $this->assertSame('created', $plan['components'][1]['status']);
        $this->assertSame(44, $plan['components'][1]['postId']);
        $this->assertSame('skip_component_candidate', $plan['components'][2]['action']);
        $this->assertSame('below_occurrence_threshold', $plan['components'][2]['reason']);
    }

    public function testBuildUsesConfiguredComponentThresholds(): void
    {
        $candidate = [
            'signature' => 'div[h3,p]',
            'tag' => 'div',
            'count' => 2,
            'suggestedName' => 'feature-card',
            'classes' => ['feature-card'],
            'documentTree' => $this->minimalTemplateTree('section'),
            'componentProperties' => $this->componentPropertiesFixture('feature_card_text'),
        ];

        $defaultPlan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            ['strictNative' => false]
        );
        $configuredPlan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            [
                'strictNative' => false,
                'componentMinOccurrences' => 2,
                'componentMinConfidence' => 0.5,
            ]
        );

        $this->assertSame('skipped', $defaultPlan['components'][0]['status']);
        $this->assertContains('below_occurrence_threshold', $defaultPlan['components'][0]['reasons']);
        $this->assertSame('ready', $configuredPlan['components'][0]['status']);
        $this->assertSame(2, $configuredPlan['components'][0]['threshold']['minOccurrences']);
        $this->assertSame(0.5, $configuredPlan['components'][0]['threshold']['minConfidence']);
    }

    public function testBuildReportsDeduplicatedInstancesAndSkippedCandidatesByEditableProperties(): void
    {
        $readyCandidate = [
            'signature' => 'article[h3,p,a]',
            'tag' => 'article',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'testimonial-card',
            'classes' => ['testimonial-card'],
            'documentTree' => $this->minimalTemplateTree('article'),
            'componentProperties' => [
                'targets' => [[
                    'nodeId' => 2,
                    'propertyKey' => 'testimonial_card_text',
                    'controlPath' => 'content.content.text',
                ]],
                'properties' => [
                    'testimonial_card_text' => 'First quote',
                ],
            ],
            'instances' => [
                ['nodeId' => 11, 'path' => '0', 'classes' => ['testimonial-card']],
                ['nodeId' => 12, 'path' => '1', 'classes' => ['testimonial-card']],
                ['nodeId' => 13, 'path' => '2', 'classes' => ['testimonial-card']],
            ],
        ];
        $decorativeCandidate = [
            'signature' => 'div[span]',
            'tag' => 'div',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'decorative',
            'classes' => ['decorative'],
            'documentTree' => $this->minimalTemplateTree('div'),
            'componentProperties' => [
                'targets' => [],
                'properties' => [],
            ],
            'instances' => [
                ['nodeId' => 21, 'path' => '3', 'classes' => ['decorative']],
                ['nodeId' => 22, 'path' => '4', 'classes' => ['decorative']],
                ['nodeId' => 23, 'path' => '5', 'classes' => ['decorative']],
            ],
        ];

        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$readyCandidate, $decorativeCandidate]]),
            ['strictNative' => false]
        );

        $this->assertSame('save_or_update_blocks', $plan['persistence']['components']['action']);
        $this->assertSame(3, $plan['persistence']['components']['deduplicatedInstances']);
        $this->assertCount(1, $plan['persistence']['components']['skippedCandidates']);
        $this->assertSame('decorative', $plan['persistence']['components']['skippedCandidates'][0]['suggestedName']);
        $this->assertSame('insufficient_editable_properties', $plan['persistence']['components']['skippedCandidates'][0]['reason']);

        $this->assertSame(1, $plan['components'][0]['editablePropertyCount']);
        $this->assertTrue($plan['components'][0]['editablePropertiesSufficient']);
        $this->assertSame(3, $plan['components'][0]['deduplicatedInstanceCount']);
        $this->assertSame([11, 12, 13], array_column($plan['components'][0]['deduplicatedInstances'], 'nodeId'));
        $this->assertSame(1, $plan['components'][0]['threshold']['minEditableProperties']);

        $this->assertSame('skipped', $plan['components'][1]['status']);
        $this->assertSame(0, $plan['components'][1]['editablePropertyCount']);
        $this->assertFalse($plan['components'][1]['editablePropertiesSufficient']);
        $this->assertSame([], $plan['components'][1]['deduplicatedInstances']);
        $this->assertContains('insufficient_editable_properties', $plan['components'][1]['reasons']);
    }

    public function testBuildReportsAdvancedComponentScopeDeferrals(): void
    {
        $candidate = [
            'signature' => 'div[h3,ul,form,style]',
            'tag' => 'div',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'advanced-card',
            'classes' => ['advanced-card'],
            'documentTree' => $this->advancedComponentScopeTree(),
            'advancedPatternTypes' => [
                'lists',
                'forms',
                'dynamic_data',
                'component_scoped_css',
            ],
        ];

        $reviewPlan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            ['strictNative' => false]
        );
        $strictPlan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            ['strictNative' => true]
        );

        $this->assertSame('needs_review', $reviewPlan['status']);
        $this->assertSame('skipped', $reviewPlan['components'][0]['status']);
        $this->assertContains('advanced_component_scope_deferred', $reviewPlan['components'][0]['reasons']);
        $this->assertSame(5, $reviewPlan['advancedComponentScope']['summary']['deferred']);
        $deferredByKey = [];
        foreach ($reviewPlan['advancedComponentScope']['deferred'] as $pattern) {
            $deferredByKey[$pattern['key']] = $pattern;
        }
        $detectedByKey = [];
        foreach ($reviewPlan['advancedComponentScope']['detected'] as $pattern) {
            $detectedByKey[$pattern['key']] = $pattern;
        }

        $this->assertSame('future', $deferredByKey['variants']['status']);
        $this->assertSame('future', $deferredByKey['repeated_regions']['status']);
        $this->assertSame('future', $deferredByKey['lists']['status']);
        $this->assertSame('unsupported', $deferredByKey['forms']['status']);
        $this->assertSame('pro', $deferredByKey['dynamic_data']['status']);
        $this->assertArrayNotHasKey('component_scoped_css', $deferredByKey);
        $this->assertSame('core', $detectedByKey['component_scoped_css']['status']);
        $this->assertSame('oxy_html_converter_pro_dynamic_component_mapper', $deferredByKey['dynamic_data']['extensionPoint']);
        $this->assertSame('advanced_component_scope_deferred', $reviewPlan['fallbacks'][0]['type']);
        $this->assertSame('component_scope_report', $reviewPlan['fallbacks'][0]['route']);
        $this->assertFallbackDecisionFields($reviewPlan['fallbacks'][0]);

        $variantFallback = null;
        foreach ($reviewPlan['fallbacks'] as $fallback) {
            if (($fallback['extensionPoint'] ?? '') === 'oxy_html_converter_component_variant_mapper') {
                $variantFallback = $fallback;
            }
        }

        $this->assertIsArray($variantFallback);
        $this->assertFalse($variantFallback['blockingInStrictNative']);

        $formFallback = null;
        foreach ($reviewPlan['fallbacks'] as $fallback) {
            if (($fallback['extensionPoint'] ?? '') === 'oxy_html_converter_component_form_mapper') {
                $formFallback = $fallback;
            }
        }

        $this->assertIsArray($formFallback);
        $this->assertTrue($formFallback['blockingInStrictNative']);
        $this->assertSame('blocked', $strictPlan['status']);
        $this->assertStringContainsString('Advanced component pattern deferred: forms', implode(' ', $strictPlan['blockers']));
    }

    public function testBuildTreatsComponentScopedCssAsCoreSupportedAfterHostMergeMilestone(): void
    {
        $candidate = [
            'signature' => 'div[h3,style]',
            'tag' => 'div',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'feature-card',
            'classes' => ['feature-card'],
            'documentTree' => $this->componentCssOnlyTree(),
            'componentProperties' => $this->componentPropertiesFixture('feature_card_text'),
            'advancedPatternTypes' => [
                'component_scoped_css',
            ],
        ];

        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            ['strictNative' => false]
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('ready', $plan['components'][0]['status']);
        $this->assertSame([], $plan['components'][0]['deferredPatterns']);
        $this->assertSame('core', $plan['advancedComponentScope']['detected'][0]['status']);
        $this->assertSame(0, $plan['advancedComponentScope']['summary']['deferred']);
    }

    public function testBuildCountsExpandedVariableTokenGroups(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture([
                'tokens' => [
                    'images' => [
                        ['value' => 'https://example.test/assets/hero.jpg', 'uses' => 1, 'suggestedName' => 'hero-image'],
                    ],
                    'measurements' => [
                        ['value' => '18px', 'uses' => 2, 'suggestedName' => 'measure-body-font-size'],
                    ],
                    'numbers' => [
                        ['value' => '1.25', 'uses' => 1, 'suggestedName' => 'ratio-card'],
                    ],
                ],
            ]),
            ['strictNative' => false]
        );

        $this->assertSame(6, $plan['persistence']['variables']['proposed']);
        $this->assertSame('map_or_create_variable', $plan['tokens']['images'][0]['action']);
        $this->assertSame('image', $plan['tokens']['images'][0]['type']);
        $this->assertSame('measurement', $plan['tokens']['measurements'][0]['type']);
        $this->assertSame('number', $plan['tokens']['numbers'][0]['type']);
    }

    public function testBuildCarriesOxygenGlobalSettingsHandoff(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture([
                'oxygenGlobalSettings' => [
                    'settings' => [
                        'colors' => [
                            'palette' => [
                                'gradients' => [[
                                    'label' => 'Hero Gradient',
                                    'cssVariableName' => 'ohc-hero-gradient',
                                    'value' => [
                                        'value' => 'linear-gradient(135deg, #731B19 0%, #14B8A6 100%)',
                                        'svgValue' => '<symbol id="%%GRADIENTID%%"><linearGradient id="g"/></symbol>',
                                    ],
                                ]],
                            ],
                        ],
                        'code' => [
                            'stylesheets' => [[
                                'name' => 'Imported root custom properties',
                                'code' => ':root { --ohc-radius: 12px; }',
                            ]],
                            'scripts' => [],
                        ],
                    ],
                ],
            ]),
            ['strictNative' => false]
        );

        $this->assertSame('save_or_update', $plan['persistence']['globalSettings']['action']);
        $this->assertSame('linear-gradient(135deg, #731B19 0%, #14B8A6 100%)', $plan['oxygenGlobalSettings']['settings']['colors']['palette']['gradients'][0]['value']['value']);
        $this->assertSame(':root { --ohc-radius: 12px; }', $plan['oxygenGlobalSettings']['settings']['code']['stylesheets'][0]['code']);
    }

    public function testBuildBlocksSupportedTokenOrphans(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'tokenUsage' => [
                    'totalSupported' => 1,
                    'bound' => 0,
                    'bindingCount' => 0,
                    'orphanCount' => 1,
                    'orphans' => [[
                        'group' => 'colors',
                        'cssVariableName' => 'ohc-color-unused',
                        'variableId' => 'ohc-var-unused',
                        'value' => '#999999',
                        'uses' => 1,
                    ]],
                    'bindings' => [],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => false]
        );

        $this->assertSame('blocked', $plan['status']);
        $this->assertFalse($plan['canImport']);
        $this->assertSame(1, $plan['tokenUsage']['orphanCount']);
        $this->assertContains('Token binding left 1 supported token variable(s) unused.', $plan['blockers']);
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

    public function testBuildReportsConvertedSurfaceFromRealOxygenTreeShape(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                ...$this->oxygenSurfaceResultOverrides(),
                'selectorPayload' => [
                    'selectors' => [['selector' => '.hero']],
                    'collections' => [],
                ],
                'stats' => [
                    'elements' => 99,
                    'tailwindClasses' => 0,
                    'customClasses' => 0,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                    'unsupportedItems' => [[
                        'selector' => 'iframe',
                        'location' => 'body > iframe',
                        'reason' => 'Unsupported embed.',
                        'fallbackCategory' => 'unsupported_embed',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => false]
        );

        $this->assertSame(11, $plan['convertedSurface']['totalNodes']);
        $this->assertSame(6, $plan['convertedSurface']['codeBlocks']['total']);
        $this->assertSame(4, $plan['convertedSurface']['codeBlocks']['html']);
        $this->assertSame(1, $plan['convertedSurface']['codeBlocks']['css']);
        $this->assertSame(1, $plan['convertedSurface']['codeBlocks']['javascript']);
        $this->assertSame(1, $plan['convertedSurface']['components']);
        $this->assertSame(2, $plan['convertedSurface']['assetNodes']);
        $this->assertSame(1, $plan['convertedSurface']['imageNodes']);
        $this->assertSame(1, $plan['convertedSurface']['videoNodes']);
        $this->assertSame(4, $plan['convertedSurface']['classAssignments']);
        $this->assertSame(1, $plan['convertedSurface']['selectors']);
        $this->assertSame(1, $plan['convertedSurface']['unsupportedItems']);
    }

    public function testBuildCarriesUnsupportedItemsAsStrictNativeFallbacks(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'stats' => [
                    'elements' => 3,
                    'tailwindClasses' => 0,
                    'customClasses' => 0,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                    'unsupportedItems' => [[
                        'location' => 'html > body > form#lead',
                        'selector' => 'form#lead',
                        'sourceSnippet' => '<form id="lead">...</form>',
                        'reason' => 'Unsupported HTML structure requires HtmlCode fallback.',
                        'severity' => 'blocking',
                        'fallbackCategory' => 'unsupported_form',
                        'safeModeImpact' => 'Safe Mode sanitizes the HtmlCode payload.',
                        'owner' => 'Core native profile',
                        'remediation' => 'Use an approved form integration.',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => true]
        );

        $fallback = $this->firstFallbackOfType($plan['fallbacks'], 'unsupported_item');

        $this->assertSame('blocked', $plan['status']);
        $this->assertFalse($plan['canImport']);
        $this->assertNotNull($fallback);
        $this->assertSame('unsupported_form', $fallback['category']);
        $this->assertSame('unsupported_report', $fallback['route']);
        $this->assertSame('form#lead', $fallback['selector']);
        $this->assertSame('<form id="lead">...</form>', $fallback['sourceSnippet']);
        $this->assertSame('Safe Mode sanitizes the HtmlCode payload.', $fallback['safeModeImpact']);
        $this->assertSame('report_only', $fallback['persistence']['target']);
        $this->assertTrue($fallback['blockingInStrictNative']);
        $this->assertContains('Strict native mode blocks ' . $fallback['label'] . '.', $plan['blockers']);
    }

    public function testBuildBlocksStrictNativeForHeadStylesheetUnsupportedItem(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'stats' => [
                    'elements' => 1,
                    'tailwindClasses' => 0,
                    'customClasses' => 0,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                    'unsupportedItems' => [[
                        'location' => 'html > head > link',
                        'selector' => 'link',
                        'sourceSnippet' => '<link rel="stylesheet" href="https://cdn.example.test/app.css">',
                        'reason' => 'Head link asset requires an unsafe visible HtmlCode fallback.',
                        'severity' => 'blocking',
                        'fallbackCategory' => 'unsafe_head_stylesheet_fallback',
                        'safeModeImpact' => 'Safe Mode would remove this head asset.',
                        'owner' => 'Core native profile',
                        'remediation' => 'Persist supported assets through owned style stores.',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => true]
        );

        $fallback = $this->firstFallbackOfType($plan['fallbacks'], 'unsupported_item');

        $this->assertSame('blocked', $plan['status']);
        $this->assertNotNull($fallback);
        $this->assertSame('unsafe_head_stylesheet_fallback', $fallback['category']);
        $this->assertSame('unsupported_report', $fallback['route']);
        $this->assertSame('report_only', $fallback['persistence']['target']);
        $this->assertTrue($fallback['blockingInStrictNative']);
    }

    public function testBuildUsesSingleActionableFallbackForHeadScriptUnsupportedItem(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'stats' => [
                    'elements' => 1,
                    'tailwindClasses' => 0,
                    'customClasses' => 0,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                    'unsupportedItems' => [[
                        'location' => 'html > head > script',
                        'selector' => 'script',
                        'sourceSnippet' => '<script src="https://cdn.example.test/app.js">[removed script]</script>',
                        'reason' => 'Head script asset requires an unsafe visible HtmlCode fallback.',
                        'severity' => 'blocking',
                        'fallbackCategory' => 'unsafe_head_script_fallback',
                        'safeModeImpact' => 'Safe Mode would remove this head asset.',
                        'owner' => 'Core native profile',
                        'remediation' => 'Persist supported assets through owned script stores.',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => true]
        );

        $fallbackTypes = array_map(
            static fn (array $fallback): string => (string) ($fallback['type'] ?? ''),
            $plan['fallbacks']
        );
        $unsupportedFallbacks = array_values(array_filter(
            $plan['fallbacks'],
            static fn (array $fallback): bool => ($fallback['type'] ?? '') === 'unsupported_item'
        ));

        $this->assertSame('blocked', $plan['status']);
        $this->assertCount(1, $unsupportedFallbacks);
        $this->assertContains('unsupported_item', $fallbackTypes);
        $this->assertNotContains('external_script', $fallbackTypes);
        $this->assertCount(1, $plan['blockers']);
        $this->assertSame('unsafe_head_script_fallback', $unsupportedFallbacks[0]['category']);
        $this->assertSame('report_only', $unsupportedFallbacks[0]['persistence']['target']);
    }

    public function testBuildPreservesUnsupportedItemOccurrenceCounts(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'stats' => [
                    'elements' => 4,
                    'tailwindClasses' => 0,
                    'customClasses' => 0,
                    'warnings' => [],
                    'errors' => [],
                    'info' => [],
                    'unsupportedItems' => [
                        [
                            'location' => 'html > body > form',
                            'selector' => 'form.lead',
                            'sourceSnippet' => '<form class="lead"></form>',
                            'reason' => 'Unsupported form.',
                            'severity' => 'blocking',
                            'fallbackCategory' => 'unsupported_form',
                            'safeModeImpact' => 'Safe Mode sanitizes the form.',
                            'owner' => 'Core native profile',
                            'remediation' => 'Use an approved form integration.',
                        ],
                        [
                            'location' => 'html > body > form',
                            'selector' => 'form.lead',
                            'sourceSnippet' => '<form class="lead"></form>',
                            'reason' => 'Unsupported form.',
                            'severity' => 'blocking',
                            'fallbackCategory' => 'unsupported_form',
                            'safeModeImpact' => 'Safe Mode sanitizes the form.',
                            'owner' => 'Core native profile',
                            'remediation' => 'Use an approved form integration.',
                        ],
                    ],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => false]
        );

        $fallbacks = array_values(array_filter(
            $plan['fallbacks'],
            static fn (array $fallback): bool => ($fallback['type'] ?? '') === 'unsupported_item'
        ));

        $this->assertCount(2, $fallbacks);
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
                            'owner' => 'global',
                            'cascadeOrder' => 10,
                            'exportBehavior' => 'export_with_global_styles',
                            'rollbackStore' => 'global_styles',
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
        $this->assertSame('page', $plan['styleRoutes'][0]['owner']);
        $this->assertGreaterThan(0, $plan['styleRoutes'][0]['cascadeOrder']);
        $this->assertSame('export_with_page_manifest', $plan['styleRoutes'][0]['exportBehavior']);
        $this->assertSame('page_styles', $plan['styleRoutes'][0]['rollbackStore']);
        $this->assertSame('page_fallback', $pageFallback['category']);
        $this->assertSame('post_meta_stylesheet', $pageFallback['route']);
        $this->assertSame('post_meta_stylesheet', $pageFallback['persistence']['target']);
        $this->assertSame('save_or_update', $pageFallback['persistence']['action']);
        $this->assertFallbackDecisionFields($pageFallback);
        $this->assertTrue($pageFallback['blockingInStrictNative']);
        $this->assertSame('save_or_update', $plan['persistence']['pageStyles']['action']);
        $this->assertSame(1, $plan['persistence']['pageStyles']['proposed']);

        $this->assertNotNull($globalAsset);
        $this->assertSame('global', $plan['styleRoutes'][1]['owner']);
        $this->assertSame(10, $plan['styleRoutes'][1]['cascadeOrder']);
        $this->assertSame('export_with_global_styles', $plan['styleRoutes'][1]['exportBehavior']);
        $this->assertSame('global_styles', $plan['styleRoutes'][1]['rollbackStore']);
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
        $this->assertSame('runtime_plugin_dependency', $plan['styleRoutes'][0]['owner']);
        $this->assertSame(30, $plan['styleRoutes'][0]['cascadeOrder']);
        $this->assertSame('requires_runtime_plugin', $plan['styleRoutes'][0]['exportBehavior']);
        $this->assertSame('windpress', $plan['styleRoutes'][0]['pluginDependency']['slug']);
        $this->assertSame('save_or_update', $plan['persistence']['pageStyles']['action']);
        $this->assertSame(1, $plan['persistence']['pageStyles']['proposed']);
        $this->assertSame('_oxy_html_converter_page_styles', $plan['persistence']['pageStyles']['metaKey']);
    }

    public function testBuildDoesNotDoubleOwnPageCssWhenVisibleCssCodeIsIncluded(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture([
                'element' => [
                    'data' => ['type' => ElementTypes::CONTAINER],
                    'children' => [[
                        'data' => ['type' => ElementTypes::CSS_CODE],
                        'children' => [],
                    ]],
                ],
                'styleRouting' => [
                    'pageCss' => '.hero { color: red; }',
                    'summary' => [
                        'hasPageCss' => true,
                    ],
                    'routes' => [[
                        'type' => 'source_style',
                        'destination' => 'page_css',
                        'label' => 'Source style CSS',
                    ]],
                ],
            ]),
            $this->designDocumentFixture(),
            ['strictNative' => false, 'includeCssElement' => true]
        );

        $cssFallback = $this->firstFallbackOfType($plan['fallbacks'], 'css_code');

        $this->assertNotNull($cssFallback);
        $this->assertSame('page_css_code', $cssFallback['persistence']['target']);
        $this->assertSame('page_css_code', $plan['styleRoutes'][0]['destination']);
        $this->assertSame('page_css_code', $plan['styleRoutes'][0]['persistence']['target']);
        $this->assertSame('insert_with_page', $plan['styleRoutes'][0]['persistence']['action']);
        $this->assertSame('page_document', $plan['styleRoutes'][0]['rollbackStore']);
        $this->assertSame('none', $plan['persistence']['pageStyles']['action']);
        $this->assertSame(0, $plan['persistence']['pageStyles']['proposed']);
    }

    public function testBuildCarriesSiteKitManifestSectionsForTemplatesAndChrome(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(),
            [
                'strictNative' => false,
                'siteKitManifest' => [
                    'pages' => [[
                        'id' => 'home',
                        'title' => 'Home',
                        'slug' => 'home',
                        'documentTree' => $this->contentHeaderTree(),
                    ]],
                    'templates' => [[
                        'id' => 'single-post',
                        'title' => 'Single Post',
                        'documentTree' => $this->minimalTemplateTree('main'),
                        'templateSettings' => ['type' => 'all-singles'],
                    ]],
                    'headers' => [[
                        'id' => 'site-header',
                        'title' => 'Site Header',
                        'documentTree' => $this->minimalTemplateTree('header'),
                        'templateSettings' => ['type' => 'everywhere'],
                    ]],
                    'footers' => [[
                        'id' => 'site-footer',
                        'title' => 'Site Footer',
                        'documentTree' => $this->minimalTemplateTree('footer'),
                        'templateSettings' => ['type' => 'everywhere'],
                    ]],
                    'parts' => [[
                        'id' => 'cta-part',
                        'title' => 'CTA Part',
                        'documentTree' => $this->minimalTemplateTree('section'),
                        'templateSettings' => null,
                    ]],
                ],
            ]
        );

        $sections = $plan['manifestSections'];

        $this->assertSame(['pages', 'templates', 'headers', 'footers', 'parts'], array_keys($sections));
        $this->assertSame('page', $sections['pages'][0]['postType']);
        $this->assertSame('oxygen_template', $sections['templates'][0]['postType']);
        $this->assertSame('oxygen_header', $sections['headers'][0]['postType']);
        $this->assertSame('oxygen_footer', $sections['footers'][0]['postType']);
        $this->assertSame('oxygen_part', $sections['parts'][0]['postType']);
        $this->assertTrue($sections['headers'][0]['hasDocumentTree']);
        $this->assertContains(ElementTypes::CONTAINER, $sections['pages'][0]['elementTypes']);
        $this->assertContains('header', $sections['pages'][0]['semanticTags']);
        $this->assertNotContains('OxygenElements\\Header', $sections['pages'][0]['elementTypes']);
        $this->assertSame('review_manifest_sections', $plan['persistence']['templates']['action']);
        $this->assertSame(4, $plan['persistence']['templates']['proposed']);
    }

    public function testBuildReportsSiteOperationScopeForCoreAndDeferredManifestOperations(): void
    {
        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(),
            [
                'strictNative' => false,
                'siteKitManifest' => [
                    'pages' => [[
                        'id' => 'home',
                        'title' => 'Home',
                        'slug' => 'home',
                        'documentTree' => $this->minimalTemplateTree('main'),
                    ]],
                    'homepage' => [
                        'pageId' => 'home',
                    ],
                    'menus' => [[
                        'id' => 'primary',
                        'name' => 'Primary',
                        'location' => 'primary',
                        'items' => [[
                            'label' => 'Home',
                            'targetPageId' => 'home',
                        ]],
                    ]],
                    'templates' => [[
                        'id' => 'single-post',
                        'title' => 'Single Post',
                        'documentTree' => $this->minimalTemplateTree('main'),
                        'templateSettings' => [
                            'type' => 'all-singles',
                            'ruleGroups' => [[[
                                'ruleCategorySlug' => 'singular',
                            ]]],
                        ],
                    ], [
                        'id' => 'post-archive',
                        'title' => 'Posts Archive',
                        'documentTree' => $this->minimalTemplateTree('main'),
                        'templateSettings' => [
                            'type' => 'post-type-archive',
                            'ruleGroups' => [[[
                                'ruleCategorySlug' => 'archive',
                            ]]],
                        ],
                    ]],
                    'unsupportedItems' => [[
                        'id' => 'post-title-binding',
                        'operation' => 'dynamic_bindings',
                    ], [
                        'id' => 'latest-posts',
                        'operation' => 'loops',
                    ], [
                        'id' => 'product-card',
                        'operation' => 'woocommerce',
                    ]],
                ],
            ]
        );

        $this->assertSame('needs_review', $plan['status']);
        $this->assertSame('apply_site_configuration', $plan['persistence']['siteConfiguration']['action']);
        $this->assertTrue($plan['persistence']['siteConfiguration']['homepage']);
        $this->assertSame(1, $plan['persistence']['siteConfiguration']['menus']);
        $this->assertSame('single_template', $plan['manifestSections']['templates'][0]['operationScope']);
        $this->assertSame('archive_template', $plan['manifestSections']['templates'][1]['operationScope']);

        $detectedByKey = [];
        foreach ($plan['siteOperationScope']['detected'] as $operation) {
            $detectedByKey[$operation['key']] = $operation;
        }

        $this->assertSame(7, $plan['siteOperationScope']['summary']['detected']);
        $this->assertSame(3, $plan['siteOperationScope']['summary']['deferred']);
        $this->assertSame('core', $detectedByKey['homepage']['status']);
        $this->assertSame('core', $detectedByKey['menus']['status']);
        $this->assertSame('core', $detectedByKey['single_templates']['status']);
        $this->assertSame('core', $detectedByKey['archive_templates']['status']);
        $this->assertSame('pro', $detectedByKey['dynamic_bindings']['status']);
        $this->assertSame('pro', $detectedByKey['loops']['status']);
        $this->assertSame('pro', $detectedByKey['woocommerce']['status']);
        $this->assertSame('oxy_html_converter_pro_dynamic_binding_mapper', $detectedByKey['dynamic_bindings']['extensionPoint']);
        $this->assertSame('oxy_html_converter_pro_loop_mapper', $detectedByKey['loops']['extensionPoint']);
        $this->assertSame('oxy_html_converter_pro_woocommerce_mapper', $detectedByKey['woocommerce']['extensionPoint']);

        $siteOperationFallbacks = array_values(array_filter(
            $plan['fallbacks'],
            static fn (array $fallback): bool => ($fallback['type'] ?? '') === 'site_operation_scope_deferred'
        ));

        $this->assertCount(3, $siteOperationFallbacks);
        $this->assertSame('product_boundary_report', $siteOperationFallbacks[0]['route']);
        $this->assertSame('product_boundary_report', $siteOperationFallbacks[0]['target']);
        $this->assertSame('product_boundary_report', $siteOperationFallbacks[0]['persistence']['target']);
        $this->assertSame('site_operation_pro', $siteOperationFallbacks[0]['category']);
        $this->assertFallbackDecisionFields($siteOperationFallbacks[0]);
    }

    public function testCoreOnlyModeReportsProductBoundaryDeferralsWithoutActivatingThem(): void
    {
        $candidate = [
            'signature' => 'div[h3,form]',
            'tag' => 'div',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'lead-card',
            'classes' => ['lead-card'],
            'documentTree' => $this->advancedComponentScopeTree(),
            'advancedPatternTypes' => [
                'forms',
                'dynamic_data',
            ],
        ];

        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            [
                'strictNative' => false,
                'siteKitManifest' => [
                    'dynamicBindings' => [[
                        'id' => 'featured-post-title',
                        'source' => 'post.title',
                    ]],
                    'loops' => [[
                        'id' => 'latest-posts-loop',
                    ]],
                    'woocommerce' => [[
                        'id' => 'product-card',
                    ]],
                    'unsupportedItems' => [[
                        'id' => 'post-title-binding',
                        'operation' => 'dynamic_bindings',
                    ], [
                        'id' => 'latest-posts',
                        'operation' => 'loops',
                    ], [
                        'id' => 'product-card',
                        'operation' => 'woocommerce',
                    ]],
                ],
            ]
        );

        $this->assertSame('needs_review', $plan['status']);
        $this->assertGreaterThanOrEqual(2, $plan['advancedComponentScope']['summary']['deferred']);
        $this->assertSame(3, $plan['siteOperationScope']['summary']['deferred']);

        $advancedDeferredByKey = [];
        foreach ($plan['advancedComponentScope']['deferred'] as $pattern) {
            $advancedDeferredByKey[$pattern['key']] = $pattern;
            $this->assertNotSame('core', $pattern['status']);
            $this->assertNotSame('', $pattern['extensionPoint']);
        }

        $this->assertSame('unsupported', $advancedDeferredByKey['forms']['status']);
        $this->assertSame('pro', $advancedDeferredByKey['dynamic_data']['status']);

        foreach ($plan['siteOperationScope']['deferred'] as $operation) {
            $this->assertSame('pro', $operation['status']);
            $this->assertStringStartsWith('oxy_html_converter_pro_', $operation['extensionPoint']);
            $this->assertGreaterThanOrEqual(2, $operation['count']);
        }

        $productBoundaryFallbacks = array_values(array_filter(
            $plan['fallbacks'],
            static fn (array $fallback): bool => in_array(
                (string) ($fallback['type'] ?? ''),
                ['advanced_component_scope_deferred', 'site_operation_scope_deferred'],
                true
            )
        ));

        $this->assertCount(
            $plan['advancedComponentScope']['summary']['deferred'] + $plan['siteOperationScope']['summary']['deferred'],
            $productBoundaryFallbacks
        );
        foreach ($productBoundaryFallbacks as $fallback) {
            $this->assertContains($fallback['route'], ['component_scope_report', 'product_boundary_report']);
            $this->assertSame($fallback['route'], $fallback['target']);
            $this->assertSame($fallback['route'], $fallback['persistence']['target']);
            $this->assertSame('report_only', $fallback['persistence']['action']);
            $this->assertNotSame('', $fallback['extensionPoint']);
            $this->assertStringContainsString('verified extension', $fallback['remediation']);
            $this->assertContains($fallback['owner'], ['Core component scope boundary', 'Core product boundary']);
        }
    }

    public function testBuildDetectsProductBoundaryPatternsFromComponentTree(): void
    {
        $candidate = [
            'signature' => 'div[form,ul,p]',
            'tag' => 'div',
            'count' => 3,
            'confidence' => 1.0,
            'suggestedName' => 'dynamic-lead-card',
            'classes' => ['dynamic-lead-card'],
            'documentTree' => $this->functionalProductBoundaryTree(),
        ];

        $plan = (new ImportPlanBuilder())->build(
            $this->resultFixture(),
            $this->designDocumentFixture(['componentCandidates' => [$candidate]]),
            ['strictNative' => false]
        );

        $deferredByKey = [];
        foreach ($plan['advancedComponentScope']['deferred'] as $pattern) {
            $deferredByKey[$pattern['key']] = $pattern;
        }

        $this->assertSame('unsupported', $deferredByKey['forms']['status']);
        $this->assertSame('future', $deferredByKey['lists']['status']);
        $this->assertSame('pro', $deferredByKey['dynamic_data']['status']);
        $this->assertContains('advanced_component_scope_deferred', $plan['components'][0]['reasons']);
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

    /**
     * @return array<string, mixed>
     */
    private function contentHeaderTree(): array
    {
        return $this->minimalTemplateTree('header');
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPropertiesFixture(string $propertyKey): array
    {
        return [
            'targets' => [[
                'nodeId' => 1,
                'propertyKey' => $propertyKey,
                'controlPath' => 'content.content.text',
            ]],
            'properties' => [
                $propertyKey => 'Editable text',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTemplateTree(string $tag): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => $tag,
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ],
            '_nextNodeId' => 2,
            'exportedLookupTable' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function advancedComponentScopeTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'div',
                                    'classes' => ['advanced-card', 'variant-primary'],
                                    'attributes' => [[
                                        'name' => 'data-repeat',
                                        'value' => 'items',
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ],
            '_nextNodeId' => 2,
            'exportedLookupTable' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentCssOnlyTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'div',
                                    'classes' => ['feature-card'],
                                ],
                            ],
                        ],
                    ],
                    'children' => [[
                        'id' => 2,
                        'data' => [
                            'type' => ElementTypes::CSS_CODE,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'css_code' => '.feature-card { padding: 32px; }',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ]],
                ]],
            ],
            '_nextNodeId' => 3,
            'exportedLookupTable' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalProductBoundaryTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'div',
                                ],
                            ],
                        ],
                    ],
                    'children' => [[
                        'id' => 2,
                        'data' => [
                            'type' => ElementTypes::CONTAINER,
                            'properties' => [
                                'settings' => [
                                    'advanced' => [
                                        'tag' => 'form',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ], [
                        'id' => 3,
                        'data' => [
                            'type' => ElementTypes::CONTAINER,
                            'properties' => [
                                'settings' => [
                                    'advanced' => [
                                        'tag' => 'ul',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ], [
                        'id' => 4,
                        'data' => [
                            'type' => ElementTypes::TEXT,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'text' => '{{ post.title }}',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ]],
                ]],
            ],
            '_nextNodeId' => 5,
            'exportedLookupTable' => [],
        ];
    }
}
