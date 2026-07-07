<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\BrandLibraryRepository;
use OxyHtmlConverter\Services\GlobalStyleRepository;
use OxyHtmlConverter\Services\OxygenGlobalSettingsRepository;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\OxygenVariableRepository;
use OxyHtmlConverter\Services\PageStyleRepository;
use OxyHtmlConverter\Services\WindPressCacheResetService;
use PHPUnit\Framework\TestCase;

class OxygenPageImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_nav_menus'] = [];
        $GLOBALS['__wp_nav_menu_items'] = [];
        $GLOBALS['__wp_next_nav_menu_id'] = 1;
        $GLOBALS['__wp_next_nav_menu_item_id'] = 1000;
        $GLOBALS['__wp_theme_mods'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];
        $GLOBALS['__wp_current_user_can'] = true;
        remove_all_filters();
        unset(
            $GLOBALS['__wp_current_user_can_last_capability'],
            $GLOBALS['__wp_current_user_can_last_args'],
            $GLOBALS['__wp_update_nav_menu_item_result']
        );
    }

    public function testImportCreatesDraftPageAndPersistsTreeSelectorsAndManifest(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported Landing Page',
            'slug' => 'Imported Landing Page',
            'postStatus' => 'draft',
            'documentTree' => [
                'root' => [
                    'id' => 1,
                    'data' => ['type' => ElementTypes::CONTAINER],
                    'children' => [],
                ],
                '_nextNodeId' => 2,
                'status' => 'exported',
            ],
            'selectorPayload' => [
                'selectors' => [[
                    'id' => 'selector-1',
                    'name' => 'hero',
                    'type' => 'class',
                    'collection' => 'Imported HTML',
                    'locked' => false,
                    'children' => [],
                    'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                ]],
                'collections' => ['Imported HTML'],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 2,
                        'suggestedName' => 'color-731b19',
                    ]],
                    'spacing' => [[
                        'value' => '24px',
                        'uses' => 3,
                        'suggestedName' => 'space-24px',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 1,
                        'suggestedName' => 'font-inter',
                    ]],
                ],
            ],
            'sourceHash' => 'source-1',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['postId']);
        $this->assertSame('created', $result['postAction']);
        $this->assertSame('draft', $result['postStatus']);
        $this->assertSame('_oxygen_data', $result['metaKey']);

        $this->assertArrayHasKey(1, $GLOBALS['__wp_posts']);
        $this->assertSame('Imported Landing Page', $GLOBALS['__wp_posts'][1]->post_title);
        $this->assertSame('imported-landing-page', $GLOBALS['__wp_posts'][1]->post_name);

        $oxygenMeta = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1]['_oxygen_data']), true);
        $this->assertIsArray($oxygenMeta);
        $this->assertArrayHasKey('tree_json_string', $oxygenMeta);
        $treeJson = (string) $oxygenMeta['tree_json_string'];
        $this->assertStringContainsString('"status":"exported"', $treeJson);
        $this->assertStringContainsString('"exportedLookupTable":{}', $treeJson);
        $this->assertStringContainsString('"properties":{}', $treeJson);
        $tree = json_decode($treeJson, true);
        $this->assertSame('exported', $tree['status']);
        $this->assertSame([], $tree['exportedLookupTable']);
        $this->assertSame([], $tree['root']['data']['properties']);

        $selectors = json_decode((string) $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'], true);
        $this->assertSame(['selector-1'], array_column($selectors, 'id'));

        $variables = json_decode((string) $GLOBALS['__wp_options']['oxygen_variables_json_string'], true);
        $this->assertSame(['color', 'unit', 'font_family'], array_column($variables, 'type'));

        $globalSettings = json_decode((string) $GLOBALS['__wp_options']['oxygen_global_settings_json_string'], true);
        $this->assertSame('ohc-color-731b19', $globalSettings['settings']['colors']['palette']['colors'][0]['cssVariableName']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame('source-1', $manifest['sourceHash']);
        $this->assertSame(1, $manifest['postId']);
        $this->assertSame(1, $manifest['selectorPersistence']['saved']);
        $this->assertSame(3, $manifest['variablePersistence']['created']);
        $this->assertSame(0, $manifest['componentPersistence']['candidates']);
        $this->assertTrue($manifest['oxygenGlobalSettingsPersistence']['saved']);
        $this->assertTrue($manifest['brandLibraryPersistence']['saved']);
        $this->assertContains('colors', $manifest['oxygenGlobalSettingsPersistence']['sections']);
        $this->assertFalse($manifest['windPressCacheReset']['attempted']);
        $this->assertTrue($manifest['rollback']['available']);
        $this->assertNotEmpty($manifest['rollback']['snapshot']['stores']);
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'page_document', '_oxygen_data');
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'oxygen_selectors', 'oxygen_oxy_selectors_json_string');
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'brand_library', BrandLibraryRepository::OPTION_NAME);
        $this->assertContains(1, $GLOBALS['__wp_cleaned_post_cache']);
    }

    public function testImportUsesWindPressCacheResetGateInsteadOfDirectReset(): void
    {
        $windPressReset = new class extends WindPressCacheResetService {
            public bool $directResetCalled = false;

            public function resetIfEnabled(?bool $enabled = null): array
            {
                unset($enabled);

                return [
                    'enabled' => false,
                    'attempted' => false,
                    'active' => false,
                    'cacheFileDeleted' => false,
                    'objectCacheFlushed' => false,
                    'path' => '',
                    'reason' => 'stubbed_gate_disabled',
                    'errors' => [],
                ];
            }

            public function resetIfAvailable(): array
            {
                $this->directResetCalled = true;

                return [
                    'enabled' => true,
                    'attempted' => true,
                    'active' => true,
                    'cacheFileDeleted' => true,
                    'objectCacheFlushed' => true,
                    'path' => '',
                    'reason' => 'direct_reset_called',
                    'errors' => [],
                ];
            }
        };

        $result = (new OxygenPageImporter(
            null,
            null,
            null,
            null,
            $windPressReset
        ))->import([
            'title' => 'Imported Without WindPress Side Effects',
            'documentTree' => [
                'root' => [
                    'id' => 1,
                    'data' => ['type' => ElementTypes::CONTAINER],
                    'children' => [],
                ],
                '_nextNodeId' => 2,
                'status' => 'exported',
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($windPressReset->directResetCalled);
        $this->assertSame('stubbed_gate_disabled', $result['windPressCacheReset']['reason']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertFalse($manifest['windPressCacheReset']['enabled']);
        $this->assertFalse($manifest['windPressCacheReset']['attempted']);
        $this->assertSame('stubbed_gate_disabled', $manifest['windPressCacheReset']['reason']);
    }

    public function testImportPersistsReadyComponentCandidatesAsOxygenBlocks(): void
    {
        $componentTree = $this->componentTreeFixture();

        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Component Candidate',
            'documentTree' => [
                'root' => [
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [],
                    ],
                    'children' => [],
                ],
                '_nextNodeId' => 2,
                'status' => 'exported',
            ],
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $componentTree,
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['componentPersistence']['created']);

        $blockId = (int) $result['componentPersistence']['createdBlocks'][0]['postId'];
        $this->assertSame(2, $blockId);
        $this->assertSame('oxygen_block', $GLOBALS['__wp_posts'][$blockId]->post_type);
        $this->assertNotSame('', get_post_meta($blockId, '_oxygen_data', true));
        $this->assertNotSame('', get_post_meta($blockId, '_breakdance_block_settings', true));

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame(1, $manifest['componentPersistence']['candidates']);
        $this->assertSame(1, $manifest['componentPersistence']['created']);
        $this->assertSame($blockId, $manifest['componentPersistence']['createdBlocks'][0]['postId']);
    }

    public function testImportReplacesRepeatedSubtreesWithNativeComponentInstances(): void
    {
        $componentTree = $this->featureCardDocumentTree(1);

        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Component Instances',
            'documentTree' => $this->featureCardDocumentTree(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $componentTree,
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['componentPersistence']['created']);
        $this->assertSame(3, $result['componentInstances']['replaced']);

        $blockId = (int) $result['componentPersistence']['createdBlocks'][0]['postId'];
        $tree = $this->decodeStoredOxygenTree(1);
        $children = $tree['root']['children'];
        $this->assertCount(3, $children);

        foreach ($children as $index => $componentNode) {
            $this->assertSame(ElementTypes::COMPONENT, $componentNode['data']['type']);
            $this->assertSame([], $componentNode['children']);
            $block = $componentNode['data']['properties']['content']['content']['block'];
            $this->assertSame($blockId, $block['componentId']);
            $this->assertSame('feature_card_text', $block['targets'][0]['propertyKey']);
            $this->assertSame('content.content.text', $block['targets'][0]['controlPath']);
            $this->assertSame('Feature ' . ($index + 1), $block['properties']['feature_card_text']);
            $this->assertSame('Feature copy', $block['properties']['feature_card_text_2']);
            $this->assertSame('Learn more', $block['properties']['feature_card_link_label']);
            $this->assertSame('#', $block['properties']['feature_card_link_url']);
        }

        $rawMeta = (string) $GLOBALS['__wp_post_meta'][1]['_oxygen_data'];
        $this->assertStringContainsString('\"properties\":{}', stripslashes($rawMeta));

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame(3, $manifest['componentInstances']['replaced']);
        $this->assertSame($blockId, $manifest['componentInstances']['replacements'][0]['componentId']);
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'component_block', 'wp_posts:' . $blockId);

        $rollback = (new OxygenPageImporter())->rollback(1);
        $this->assertTrue($rollback['success'], implode(' ', $rollback['errors'] ?? []));
        $this->assertArrayNotHasKey($blockId, $GLOBALS['__wp_posts']);
    }

    public function testImportReplacesSingleChildRepeatedNavItemsWhenPlanIsReady(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Nav Item Components',
            'documentTree' => $this->navItemDocumentTree(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'li[a]',
                    'tag' => 'li',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'nav-item',
                    'classes' => ['nav-item'],
                    'documentTree' => $this->navItemDocumentTree(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'components' => [[
                    'signature' => 'li[a]',
                    'suggestedName' => 'nav-item',
                    'status' => 'ready',
                    'action' => 'save_or_update_oxygen_block',
                    'eligible' => true,
                ]],
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['componentPersistence']['created']);
        $this->assertSame(3, $result['componentInstances']['replaced']);

        $tree = $this->decodeStoredOxygenTree(1);
        $this->assertSame(ElementTypes::COMPONENT, $tree['root']['children'][0]['data']['type']);
        $this->assertSame(ElementTypes::COMPONENT, $tree['root']['children'][1]['data']['type']);
        $this->assertSame(ElementTypes::COMPONENT, $tree['root']['children'][2]['data']['type']);
    }

    public function testImportDoesNotPersistOrReplaceCandidatesSkippedByImportPlan(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Skipped Component Candidate',
            'documentTree' => $this->featureCardDocumentTree(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTree(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'needs_review',
                'canImport' => true,
                'components' => [[
                    'signature' => 'div[h3,p,a]',
                    'suggestedName' => 'feature-card',
                    'status' => 'skipped',
                    'action' => 'skip_component_candidate',
                    'eligible' => false,
                    'reason' => 'advanced_component_scope_deferred',
                    'reasons' => ['advanced_component_scope_deferred'],
                ]],
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(0, $result['componentPersistence']['created']);
        $this->assertSame(1, $result['componentPersistence']['skipped']);
        $this->assertSame('advanced_component_scope_deferred', $result['componentPersistence']['skippedCandidates'][0]['reason']);
        $this->assertSame(0, $result['componentInstances']['replaced']);

        $tree = $this->decodeStoredOxygenTree(1);
        $this->assertSame(ElementTypes::CONTAINER, $tree['root']['children'][0]['data']['type']);
    }

    public function testImportDoesNotLetSkippedSameSignatureCandidateReuseReadyBlock(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Same Signature Component Candidates',
            'documentTree' => $this->mixedSameSignatureCardTree(),
            'designDocument' => [
                'componentCandidates' => [
                    [
                        'signature' => 'div[h3,p,a]',
                        'tag' => 'div',
                        'count' => 3,
                        'confidence' => 1.0,
                        'suggestedName' => 'feature-card',
                        'classes' => ['feature-card'],
                        'documentTree' => $this->sameSignatureCardDocumentTree(1, 'feature-card', 'Feature'),
                    ],
                    [
                        'signature' => 'div[h3,p,a]',
                        'tag' => 'div',
                        'count' => 3,
                        'confidence' => 1.0,
                        'suggestedName' => 'testimonial-card',
                        'classes' => ['testimonial-card'],
                        'documentTree' => $this->sameSignatureCardDocumentTree(1, 'testimonial-card', 'Quote'),
                    ],
                ],
            ],
            'importPlan' => [
                'status' => 'needs_review',
                'canImport' => true,
                'components' => [
                    [
                        'signature' => 'div[h3,p,a]',
                        'suggestedName' => 'feature-card',
                        'status' => 'ready',
                        'action' => 'save_or_update_oxygen_block',
                        'eligible' => true,
                    ],
                    [
                        'signature' => 'div[h3,p,a]',
                        'suggestedName' => 'testimonial-card',
                        'status' => 'skipped',
                        'action' => 'skip_component_candidate',
                        'eligible' => false,
                        'reason' => 'advanced_component_scope_deferred',
                        'reasons' => ['advanced_component_scope_deferred'],
                    ],
                ],
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['componentPersistence']['created']);
        $this->assertSame(1, $result['componentPersistence']['skipped']);
        $this->assertSame('testimonial-card', $result['componentPersistence']['skippedCandidates'][0]['suggestedName']);
        $this->assertSame(3, $result['componentInstances']['replaced']);

        $tree = $this->decodeStoredOxygenTree(1);
        $children = $tree['root']['children'];
        $this->assertSame(ElementTypes::COMPONENT, $children[0]['data']['type']);
        $this->assertSame(ElementTypes::COMPONENT, $children[1]['data']['type']);
        $this->assertSame(ElementTypes::COMPONENT, $children[2]['data']['type']);
        $this->assertSame(ElementTypes::CONTAINER, $children[3]['data']['type']);
        $this->assertSame(['testimonial-card'], $children[3]['data']['properties']['settings']['advanced']['classes']);
    }

    public function testImportMergesComponentCssIntoHostPageStylesOnceAndRollbackRestores(): void
    {
        $componentTree = $this->featureCardDocumentTreeWithComponentCss(1);

        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Component CSS',
            'documentTree' => $this->featureCardDocumentTreeWithComponentCss(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a,style]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $componentTree,
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(3, $result['componentInstances']['replaced']);
        $this->assertSame(1, $result['componentPersistence']['created']);
        $this->assertSame('.feature-card { padding: 32px; }', $result['componentPersistence']['createdBlocks'][0]['componentCss'][0]['css']);

        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][PageStyleRepository::META_KEY]), true);
        $this->assertIsArray($pageStyles);
        $this->assertSame('component', $pageStyles['owner']);
        $this->assertSame(1, $pageStyles['ownerCounts']['component']);
        $this->assertSame(1, substr_count((string) $pageStyles['css'], '.feature-card { padding: 32px; }'));
        $this->assertSame('component_css_host_bridge', $pageStyles['routes'][0]['type']);
        $this->assertSame('component', $pageStyles['routes'][0]['owner']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame('component', $manifest['pageStylePersistence']['owner']);
        $this->assertSame(1, $manifest['styleRouting']['ownerCounts']['component']);
        $this->assertSame('component_css_host_bridge', $manifest['styleRouting']['routes'][0]['type']);
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'page_styles', PageStyleRepository::META_KEY);

        $blockId = (int) $result['componentPersistence']['createdBlocks'][0]['postId'];
        $rollback = (new OxygenPageImporter())->rollback(1);

        $this->assertTrue($rollback['success'], implode(' ', $rollback['errors'] ?? []));
        $this->assertArrayNotHasKey(1, $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey(1, $GLOBALS['__wp_post_meta']);
        $this->assertArrayNotHasKey($blockId, $GLOBALS['__wp_posts']);
    }

    public function testImportKeepsPageCssWhenComponentCssCodeNodeWasReplaced(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Page And Component CSS',
            'documentTree' => $this->featureCardDocumentTreeWithComponentCss(3),
            'styleRouting' => [
                'pageCss' => '.page-shell { display: grid; }',
                'routes' => [[
                    'type' => 'source_style',
                    'destination' => 'page_css',
                    'owner' => 'page',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_page_manifest',
                    'rollbackStore' => 'page_styles',
                    'hash' => 'page-route-hash',
                ]],
            ],
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a,style]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTreeWithComponentCss(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));

        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][PageStyleRepository::META_KEY]), true);
        $this->assertStringContainsString('.page-shell { display: grid; }', $pageStyles['css']);
        $this->assertStringContainsString('.feature-card { padding: 32px; }', $pageStyles['css']);
        $this->assertSame(1, $pageStyles['ownerCounts']['page']);
        $this->assertSame(1, $pageStyles['ownerCounts']['component']);
    }

    public function testRollbackRestoresExistingHostPageStylesAfterComponentCssMerge(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_title' => 'Existing Page',
            'post_name' => 'existing-page',
            'post_status' => 'draft',
        ], true);
        $this->assertIsInt($postId);
        update_post_meta($postId, PageStyleRepository::META_KEY, 'old-page-styles');

        $result = (new OxygenPageImporter())->import([
            'title' => 'Existing Page',
            'slug' => 'existing-page',
            'replaceExisting' => true,
            'documentTree' => $this->featureCardDocumentTreeWithComponentCss(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a,style]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTreeWithComponentCss(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame($postId, $result['postId']);
        $this->assertStringContainsString(
            '.feature-card',
            (string) $GLOBALS['__wp_post_meta'][$postId][PageStyleRepository::META_KEY]
        );

        $rollback = (new OxygenPageImporter())->rollback($postId);

        $this->assertTrue($rollback['success'], implode(' ', $rollback['errors'] ?? []));
        $this->assertArrayHasKey($postId, $GLOBALS['__wp_posts']);
        $this->assertSame('old-page-styles', $GLOBALS['__wp_post_meta'][$postId][PageStyleRepository::META_KEY]);
    }

    public function testImportReplacesComponentSubtreesWithHtmlFallbackChildren(): void
    {
        $componentTree = $this->htmlFallbackFeatureCardDocumentTree(1);

        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Unsafe Svg Fallback Components',
            'documentTree' => $this->htmlFallbackFeatureCardDocumentTree(3),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,a,img,html]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $componentTree,
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(3, $result['componentInstances']['replaced']);

        $tree = $this->decodeStoredOxygenTree(1);
        $this->assertCount(3, $tree['root']['children']);
        foreach ($tree['root']['children'] as $componentNode) {
            $this->assertSame(ElementTypes::COMPONENT, $componentNode['data']['type']);
            $this->assertSame([], $componentNode['children']);
            $block = $componentNode['data']['properties']['content']['content']['block'];
            $this->assertSame('feature_card_link_url', $block['targets'][2]['propertyKey']);
            $this->assertSame('feature_card_image_url', $block['targets'][3]['propertyKey']);
        }
    }

    public function testImportMapsComponentPropertiesSchemaToInstanceTargetsAndOverrides(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Component Overrides',
            'documentTree' => $this->featureCardDocumentTree(2),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTree(1),
                    'componentProperties' => [
                        'targets' => [[
                            'nodeId' => 2,
                            'propertyKey' => 'headline',
                            'controlPath' => 'content.content.text',
                        ]],
                        'properties' => [
                            'headline' => 'Feature override',
                        ],
                    ],
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));

        $tree = $this->decodeStoredOxygenTree(1);
        $firstBlock = $tree['root']['children'][0]['data']['properties']['content']['content']['block'];
        $secondBlock = $tree['root']['children'][1]['data']['properties']['content']['content']['block'];

        $this->assertSame(2, $firstBlock['targets'][0]['nodeId']);
        $this->assertSame('headline', $firstBlock['targets'][0]['propertyKey']);
        $this->assertSame('content.content.text', $firstBlock['targets'][0]['controlPath']);
        $this->assertSame(['headline' => 'Feature 1'], $firstBlock['properties']);
        $this->assertSame(['headline' => 'Feature 2'], $secondBlock['properties']);
    }

    public function testImportFailsWhenComponentPropertyTargetDoesNotResolve(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Page With Broken Component Target',
            'documentTree' => $this->featureCardDocumentTree(1),
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTree(1),
                    'componentProperties' => [
                        'targets' => [[
                            'nodeId' => 999,
                            'propertyKey' => 'headline',
                            'controlPath' => 'content.content.text',
                        ]],
                        'properties' => [
                            'headline' => 'Feature override',
                        ],
                    ],
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString(
            'Editable component target headline nodeId 999 must reference a node inside the component candidate tree',
            implode(' ', $result['errors'])
        );
        $this->assertTrue($result['restore']['success'], implode(' ', $result['restore']['errors'] ?? []));
    }

    public function testImportSiteKitReplacesPageAndTemplateComponentSubtrees(): void
    {
        $manifest = [
            'id' => 'site-kit-components',
            'pages' => [[
                'id' => 'home',
                'title' => 'Home',
                'documentTree' => $this->featureCardDocumentTree(1),
            ]],
            'templates' => [[
                'id' => 'single',
                'title' => 'Single',
                'documentTree' => $this->featureCardDocumentTree(1),
                'templateSettings' => [
                    'type' => 'all-singles',
                    'ruleGroups' => [],
                    'triggers' => [],
                    'priority' => 10,
                    'fallback' => false,
                ],
            ]],
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTree(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ];

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['componentPersistence']['created']);
        $this->assertSame(2, $result['componentInstances']['replaced']);
        $this->assertSame(1, $result['objects']['pages'][0]['componentInstances']['replaced']);
        $this->assertSame(1, $result['objects']['templates'][0]['componentInstances']['replaced']);

        $blockId = (int) $result['componentPersistence']['createdBlocks'][0]['postId'];
        $this->assertSame('oxygen_block', $GLOBALS['__wp_posts'][$blockId]->post_type);

        $pageTree = $this->decodeStoredOxygenTree((int) $result['objects']['pages'][0]['postId']);
        $templateTree = $this->decodeStoredOxygenTree((int) $result['objects']['templates'][0]['postId']);

        foreach ([$pageTree, $templateTree] as $tree) {
            $componentNode = $tree['root']['children'][0];
            $this->assertSame(ElementTypes::COMPONENT, $componentNode['data']['type']);
            $this->assertSame($blockId, $componentNode['data']['properties']['content']['content']['block']['componentId']);
            $this->assertSame([], $componentNode['children']);
        }

        $this->assertSame(2, $result['manifest']['componentInstances']['replaced']);
        $this->assertRollbackSnapshotEntry($result['manifest']['rollback']['snapshot']['stores'], 'component_block', 'wp_posts:' . $blockId);
    }

    public function testImportSiteKitMergesComponentCssIntoEachHostContext(): void
    {
        $manifest = [
            'id' => 'site-kit-component-css',
            'pages' => [[
                'id' => 'home',
                'title' => 'Home',
                'slug' => 'home',
                'documentTree' => $this->featureCardDocumentTreeWithComponentCss(1),
            ]],
            'templates' => [[
                'id' => 'archive',
                'title' => 'Archive',
                'slug' => 'archive',
                'templateSettings' => [
                    'type' => 'all-singles',
                    'ruleGroups' => [],
                ],
                'documentTree' => $this->featureCardDocumentTreeWithComponentCss(1),
            ]],
            'designDocument' => [
                'componentCandidates' => [[
                    'signature' => 'div[h3,p,a,style]',
                    'tag' => 'div',
                    'count' => 3,
                    'confidence' => 1.0,
                    'suggestedName' => 'feature-card',
                    'classes' => ['feature-card'],
                    'documentTree' => $this->featureCardDocumentTreeWithComponentCss(1),
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ];

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(2, $result['componentInstances']['replaced']);

        $pageId = (int) $result['objects']['pages'][0]['postId'];
        $templateId = (int) $result['objects']['templates'][0]['postId'];
        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][$pageId][PageStyleRepository::META_KEY]), true);
        $templateStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][$templateId][PageStyleRepository::META_KEY]), true);

        $this->assertSame(1, substr_count((string) $pageStyles['css'], '.feature-card { padding: 32px; }'));
        $this->assertSame(1, substr_count((string) $templateStyles['css'], '.feature-card { padding: 32px; }'));
        $this->assertSame('component_css_host_bridge', $pageStyles['routes'][0]['type']);
        $this->assertSame('component_css_host_bridge', $templateStyles['routes'][0]['type']);
        $this->assertRollbackSnapshotEntry($result['manifest']['rollback']['snapshot']['stores'], 'page_styles', PageStyleRepository::META_KEY);
    }

    public function testImportKeepsPersistedPageWhenRenderCacheGenerationFails(): void
    {
        add_filter('oxy_html_converter_skip_cli_cache_refresh', static fn (): bool => false);
        add_filter(
            'oxy_html_converter_cache_generator',
            static fn (): string => self::class . '::throwingRenderCacheGenerator'
        );

        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported With Cache Failure',
            'documentTree' => [
                'root' => [
                    'id' => 1,
                    'data' => ['type' => ElementTypes::CONTAINER],
                    'children' => [],
                ],
                '_nextNodeId' => 2,
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame(1, $result['postId']);
        $this->assertArrayHasKey('_oxygen_data', $GLOBALS['__wp_post_meta'][1]);
        $this->assertContains(1, $GLOBALS['__wp_cleaned_post_cache']);
    }

    public function testImportRejectsInvalidDocumentTreeBeforeWritingPost(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Invalid Import',
            'documentTree' => [
                'root' => [
                    'id' => 'bad',
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [],
                    ],
                    'children' => [],
                ],
                '_nextNodeId' => 2,
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $result['errors']));
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public static function throwingRenderCacheGenerator(int $postId): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal test exception, not rendered.
        throw new \RuntimeException('Cache generator failed for post ' . $postId . '.');
    }

    public function testImportRejectsBlockedImportPlanBeforeWritingPost(): void
    {
        $result = (new OxygenPageImporter())->import([
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'importPlan' => [
                'status' => 'blocked',
                'canImport' => false,
                'blockers' => ['Strict native mode blocks CSS code fallback block(s).'],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame(['Strict native mode blocks CSS code fallback block(s).'], $result['errors']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testImportPersistsGlobalCssAssetsAndRecordsThemInManifest(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported With Global CSS',
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'globalCss' => '.material-symbols-outlined { font-variation-settings: "FILL" 0; }',
            'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
            'styleRouting' => [
                'globalCss' => '.material-symbols-outlined { font-variation-settings: "FILL" 0; }',
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'global_asset',
                    'destination' => 'global_styles',
                    'label' => 'Material Symbols global style',
                    'owner' => 'global',
                    'cascadeOrder' => 10,
                    'exportBehavior' => 'export_with_global_styles',
                    'rollbackStore' => 'global_styles',
                ], [
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
            'importPlan' => [
                'status' => 'needs_review',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['globalStylePersistence']['saved']);
        $this->assertSame(1, $result['globalStylePersistence']['changes']);

        $library = json_decode((string) $GLOBALS['__wp_options']['oxy_html_converter_global_styles'], true);
        $this->assertSame('.material-symbols-outlined { font-variation-settings: "FILL" 0; }', $library['styles'][0]['css']);
        $this->assertSame('global', $library['styles'][0]['owner']);
        $this->assertSame(10, $library['styles'][0]['cascadeOrder']);
        $this->assertSame('global_styles', $library['styles'][0]['rollbackStore']);

        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][PageStyleRepository::META_KEY]), true);
        $this->assertSame('runtime_plugin_dependency', $pageStyles['owner']);
        $this->assertSame(30, $pageStyles['cascadeOrder']);
        $this->assertSame('requires_runtime_plugin', $pageStyles['exportBehavior']);
        $this->assertSame('windpress', $pageStyles['pluginDependency']['slug']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertTrue($manifest['globalStylePersistence']['saved']);
        $this->assertSame(1, $manifest['globalStylePersistence']['changes']);
        $this->assertSame(1, $manifest['globalStylePersistence']['total']);
        $this->assertSame('global', $manifest['globalStylePersistence']['styles'][0]['owner']);
        $this->assertSame('global_styles', $manifest['globalStylePersistence']['styles'][0]['rollbackStore']);
        $this->assertTrue($manifest['pageStylePersistence']['saved']);
        $this->assertSame('runtime_plugin_dependency', $manifest['pageStylePersistence']['owner']);
        $this->assertSame('windpress', $manifest['pageStylePersistence']['pluginDependency']['slug']);
        $this->assertTrue($manifest['styleRouting']['hasPluginDependentCss']);
        $this->assertSame('windpress', $manifest['styleRouting']['pluginDependencies'][0]['slug']);
        $this->assertSame('runtime_plugin_dependency', $manifest['styleRouting']['routes'][1]['owner']);
        $this->assertStringContainsString(
            '.text-6xl',
            stripslashes((string) $GLOBALS['__wp_post_meta'][1]['_oxy_html_converter_page_styles'])
        );
        $this->assertFalse($manifest['windPressCacheReset']['attempted']);
    }

    public function testImportPersistsRoutedPageCssAndRecordsOwnerInManifest(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported With Page CSS',
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'styleRouting' => [
                'pageCss' => '.hero { color: red; }',
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'source_style',
                    'destination' => 'page_css',
                    'label' => 'Source style CSS',
                    'owner' => 'page',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_page_manifest',
                    'rollbackStore' => 'page_styles',
                    'hash' => 'page-route-hash',
                ], [
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
            'importPlan' => [
                'status' => 'needs_review',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['pageStylePersistence']['saved']);

        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][PageStyleRepository::META_KEY]), true);
        $this->assertStringContainsString('.hero { color: red; }', $pageStyles['css']);
        $this->assertStringContainsString('.text-6xl', $pageStyles['css']);
        $this->assertSame('page', $pageStyles['owner']);
        $this->assertTrue($pageStyles['hasMixedOwners']);
        $this->assertSame(1, $pageStyles['ownerCounts']['page']);
        $this->assertSame(1, $pageStyles['ownerCounts']['runtime_plugin_dependency']);
        $this->assertSame('page_styles', $pageStyles['rollbackStore']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertTrue($manifest['pageStylePersistence']['saved']);
        $this->assertSame('page', $manifest['pageStylePersistence']['owner']);
        $this->assertTrue($manifest['pageStylePersistence']['hasMixedOwners']);
        $this->assertSame(1, $manifest['pageStylePersistence']['ownerCounts']['runtime_plugin_dependency']);
        $this->assertSame('page', $manifest['styleRouting']['routes'][0]['owner']);
        $this->assertSame('runtime_plugin_dependency', $manifest['styleRouting']['routes'][1]['owner']);
        $this->assertRollbackSnapshotEntry($manifest['rollback']['snapshot']['stores'], 'page_styles', PageStyleRepository::META_KEY);
    }

    public function testImportManifestCarriesSiteKitSectionsWithoutStaleHeaderElement(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported Home',
            'slug' => 'imported-home',
            'documentTree' => $this->minimalTreeWithSemanticTag('header'),
            'siteKitManifest' => [
                'pages' => [[
                    'id' => 'home',
                    'title' => 'Home',
                    'slug' => 'home',
                    'documentTree' => $this->minimalTreeWithSemanticTag('header'),
                ]],
                'templates' => [[
                    'id' => 'single-post',
                    'title' => 'Single Post',
                    'documentTree' => $this->minimalTreeWithSemanticTag('main'),
                    'templateSettings' => ['type' => 'all-singles'],
                ]],
                'headers' => [[
                    'id' => 'site-header',
                    'title' => 'Site Header',
                    'documentTree' => $this->minimalTreeWithSemanticTag('header'),
                    'templateSettings' => ['type' => 'everywhere'],
                ]],
                'footers' => [[
                    'id' => 'site-footer',
                    'title' => 'Site Footer',
                    'documentTree' => $this->minimalTreeWithSemanticTag('footer'),
                    'templateSettings' => ['type' => 'everywhere'],
                ]],
                'parts' => [[
                    'id' => 'reusable-cta',
                    'title' => 'Reusable CTA',
                    'documentTree' => $this->minimalTreeWithSemanticTag('section'),
                    'templateSettings' => null,
                ]],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame('page', $manifest['sections']['pages'][0]['postType']);
        $this->assertSame('oxygen_template', $manifest['sections']['templates'][0]['postType']);
        $this->assertSame('oxygen_header', $manifest['sections']['headers'][0]['postType']);
        $this->assertSame('oxygen_footer', $manifest['sections']['footers'][0]['postType']);
        $this->assertSame('oxygen_part', $manifest['sections']['parts'][0]['postType']);
        $this->assertContains(ElementTypes::CONTAINER, $manifest['sections']['pages'][0]['elementTypes']);
        $this->assertContains('header', $manifest['sections']['pages'][0]['semanticTags']);
        $this->assertNotContains('OxygenElements\\Header', $manifest['sections']['pages'][0]['elementTypes']);
        $this->assertSame('everywhere', $manifest['sections']['headers'][0]['settings']['type']);
        $this->assertTrue($manifest['sections']['headers'][0]['hasDocumentTree']);
    }

    public function testImportSiteKitManifestCreatesAllSupportedObjectsAndReport(): void
    {
        $manifest = $this->loadSiteKitManifestFixture();

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertNotEmpty($result['rollbackId']);
        $this->assertCount(1, $result['objects']['pages']);
        $this->assertCount(2, $result['objects']['templates']);
        $this->assertCount(1, $result['objects']['headers']);
        $this->assertCount(1, $result['objects']['footers']);
        $this->assertCount(1, $result['objects']['parts']);
        $this->assertSame('page', $result['objects']['pages'][0]['postType']);
        $this->assertSame('oxygen_template', $result['objects']['templates'][0]['postType']);
        $this->assertSame('oxygen_template', $result['objects']['templates'][1]['postType']);
        $this->assertSame('oxygen_header', $result['objects']['headers'][0]['postType']);
        $this->assertSame('oxygen_footer', $result['objects']['footers'][0]['postType']);
        $this->assertSame('oxygen_part', $result['objects']['parts'][0]['postType']);
        $this->assertSame('external-form', $result['unsupportedItems'][0]['id']);
        $this->assertSame('hero-image', $result['assets'][0]['id']);

        $this->assertSame('page', $GLOBALS['__wp_posts'][1]->post_type);
        $this->assertSame('oxygen_template', $GLOBALS['__wp_posts'][2]->post_type);
        $this->assertSame('oxygen_template', $GLOBALS['__wp_posts'][3]->post_type);
        $this->assertSame('oxygen_header', $GLOBALS['__wp_posts'][4]->post_type);
        $this->assertSame('oxygen_footer', $GLOBALS['__wp_posts'][5]->post_type);
        $this->assertSame('oxygen_part', $GLOBALS['__wp_posts'][6]->post_type);
        $this->assertArrayHasKey('_oxygen_data', $GLOBALS['__wp_post_meta'][1]);
        $this->assertArrayHasKey('_oxygen_template_settings', $GLOBALS['__wp_post_meta'][6]);
        $this->assertArrayHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
        $this->assertArrayHasKey(OxygenVariableRepository::OPTION_NAME, $GLOBALS['__wp_options']);
        $this->assertArrayHasKey(OxygenGlobalSettingsRepository::OPTION_NAME, $GLOBALS['__wp_options']);
        $this->assertArrayHasKey(PageStyleRepository::META_KEY, $GLOBALS['__wp_post_meta'][1]);
        $this->assertSame('page', $GLOBALS['__wp_options']['show_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_options']['page_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary']);
        $this->assertSame('created', $result['siteConfigurationPersistence']['menus'][0]['action']);
        $this->assertSame('created', $result['siteConfigurationPersistence']['menus'][0]['items'][0]['action']);
        $this->assertSame('primary', $result['siteConfigurationPersistence']['placements'][0]['location']);

        $storedManifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame('site-kit', $storedManifest['kind']);
        $this->assertSame($result['rollbackId'], $storedManifest['rollbackId']);
        $this->assertSame(1, $storedManifest['objectCounts']['pages']);
        $this->assertSame(2, $storedManifest['objectCounts']['templates']);
        $this->assertSame(1, $storedManifest['objectCounts']['headers']);
        $this->assertSame('single_template', $storedManifest['sections']['templates'][0]['operationScope']);
        $this->assertSame('archive_template', $storedManifest['sections']['templates'][1]['operationScope']);
        $this->assertSame('home', $storedManifest['homepage']['pageId']);
        $this->assertSame('primary', $storedManifest['menus'][0]['id']);
        $this->assertSame('primary', $storedManifest['siteConfigurationPersistence']['placements'][0]['location']);
        $this->assertTrue($storedManifest['rollback']['available']);
        $this->assertSame($result['rollbackId'], $storedManifest['rollback']['id']);
        $this->assertArrayHasKey('adapterSnapshot', $storedManifest['rollback']['snapshot']);
    }

    public function testImportSiteKitManifestRejectsUnknownSectionsBeforeWrite(): void
    {
        $manifest = $this->loadSiteKitManifestFixture();
        $manifest['mysterySection'] = [];

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Unknown site-kit manifest section "mysterySection"', implode(' ', $result['errors']));
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public function testImportSiteKitManifestRejectsInvalidHomepageAndRollsBackCreatedObjects(): void
    {
        $manifest = $this->loadSiteKitManifestFixture();
        $manifest['homepage']['pageId'] = 'missing-page';

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Homepage target "missing-page" could not be resolved', implode(' ', $result['errors']));
        $this->assertTrue($result['restore']['success'], implode(' ', $result['restore']['errors'] ?? []));
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
        $this->assertArrayNotHasKey('show_on_front', $GLOBALS['__wp_options']);
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
    }

    public function testImportSiteKitManifestRestoresConfigurationWhenMenuItemCreationThrows(): void
    {
        $GLOBALS['__wp_update_nav_menu_item_result'] = new \WP_Error('Simulated menu item failure.');

        $result = (new OxygenPageImporter())->importSiteKit($this->loadSiteKitManifestFixture());

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('Simulated menu item failure.', implode(' ', $result['errors']));
        $this->assertTrue($result['restore']['success'], implode(' ', $result['restore']['errors'] ?? []));
        $this->assertTrue(
            $result['siteConfigurationPersistence']['restore']['success'],
            implode(' ', $result['siteConfigurationPersistence']['restore']['errors'] ?? [])
        );
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
        $this->assertArrayNotHasKey('show_on_front', $GLOBALS['__wp_options']);
        $this->assertArrayNotHasKey('page_on_front', $GLOBALS['__wp_options']);
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
        $this->assertSame([], $GLOBALS['__wp_nav_menu_items']);
    }

    public function testRollbackSiteKitImportRestoresHomepageAndMenuConfiguration(): void
    {
        $importer = new OxygenPageImporter();
        $import = $importer->importSiteKit($this->loadSiteKitManifestFixture());

        $this->assertTrue($import['success'], implode(' ', $import['errors'] ?? []));
        $this->assertSame('page', $GLOBALS['__wp_options']['show_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_options']['page_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);

        $rollback = $importer->rollback(1);

        $this->assertTrue($rollback['success'], implode(' ', $rollback['errors'] ?? []));
        $this->assertGreaterThan(0, $rollback['restoredSiteConfigurationStores']);
        $this->assertArrayNotHasKey('show_on_front', $GLOBALS['__wp_options']);
        $this->assertArrayNotHasKey('page_on_front', $GLOBALS['__wp_options']);
        $this->assertArrayNotHasKey('nav_menu_locations', $GLOBALS['__wp_theme_mods']);
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
        $this->assertSame([], $GLOBALS['__wp_nav_menu_items']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testRollbackSiteKitLeavesConfigurationUntouchedWhenMainSnapshotFails(): void
    {
        $importer = new OxygenPageImporter();
        $import = $importer->importSiteKit($this->loadSiteKitManifestFixture());

        $this->assertTrue($import['success'], implode(' ', $import['errors'] ?? []));
        $this->assertSame('page', $GLOBALS['__wp_options']['show_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_options']['page_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);
        $this->assertArrayHasKey(1, $GLOBALS['__wp_posts']);

        $storedManifest = json_decode(
            stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]),
            true
        );
        $this->assertIsArray($storedManifest);
        $storedManifest['rollback']['snapshot']['stores'] = [[
            'storeType' => 'unknown',
            'store' => 'corrupt_snapshot',
            'key' => 'corrupt',
            'oldExists' => false,
            'oldValue' => null,
            'newExists' => true,
            'newValue' => 'corrupt',
        ]];
        update_post_meta(1, OxygenPageImporter::MANIFEST_META_KEY, wp_slash(wp_json_encode($storedManifest)));

        $rollback = $importer->rollback(1);

        $this->assertFalse($rollback['success']);
        $this->assertSame(500, $rollback['status']);
        $this->assertSame(0, $rollback['siteConfigurationRestore']['restored']);
        $this->assertSame('page', $GLOBALS['__wp_options']['show_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_options']['page_on_front']);
        $this->assertSame(1, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);
        $this->assertArrayHasKey(1, $GLOBALS['__wp_posts']);
    }

    public function testImportSiteKitManifestRejectsMalformedRecordBeforeWrite(): void
    {
        $manifest = $this->loadSiteKitManifestFixture();
        unset($manifest['pages'][0]['documentTree']);

        $result = (new OxygenPageImporter())->importSiteKit($manifest);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('$.pages[0] missing documentTree', implode(' ', $result['errors']));
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public function testImportSiteKitManifestRollsBackPartialWritesOnPersistenceFailure(): void
    {
        $manifest = $this->loadSiteKitManifestFixture();
        $throwingGlobalStyles = new class extends GlobalStyleRepository {
            public function saveFromPayload(array $payload): array
            {
                throw new \RuntimeException('simulated site-kit global style failure');
            }
        };

        $result = (new OxygenPageImporter(
            null,
            null,
            $throwingGlobalStyles
        ))->importSiteKit($manifest);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('simulated site-kit global style failure', implode(' ', $result['errors']));
        $this->assertTrue($result['restore']['success'], implode(' ', $result['restore']['errors'] ?? []));
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testImportNormalizesMinimalPluginDependentRouteInPersistenceAndManifest(): void
    {
        $result = (new OxygenPageImporter())->import([
            'title' => 'Imported With Minimal Plugin CSS',
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'styleRouting' => [
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                ]],
            ],
            'importPlan' => [
                'status' => 'needs_review',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success']);

        $pageStyles = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][PageStyleRepository::META_KEY]), true);
        $this->assertSame('runtime_plugin_dependency', $pageStyles['owner']);
        $this->assertSame('requires_runtime_plugin', $pageStyles['exportBehavior']);
        $this->assertSame('windpress', $pageStyles['pluginDependency']['slug']);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertSame('runtime_plugin_dependency', $manifest['pageStylePersistence']['owner']);
        $this->assertSame('requires_runtime_plugin', $manifest['pageStylePersistence']['exportBehavior']);
        $this->assertSame('windpress', $manifest['pageStylePersistence']['pluginDependency']['slug']);
        $this->assertSame('runtime_plugin_dependency', $manifest['styleRouting']['routes'][0]['owner']);
        $this->assertSame('requires_runtime_plugin', $manifest['styleRouting']['routes'][0]['exportBehavior']);
        $this->assertSame('page_styles', $manifest['styleRouting']['routes'][0]['rollbackStore']);
        $this->assertSame('windpress', $manifest['styleRouting']['routes'][0]['pluginDependency']['slug']);
        $this->assertSame(1, $manifest['styleRouting']['ownerCounts']['runtime_plugin_dependency']);
        $this->assertTrue($manifest['styleRouting']['hasPluginDependentCss']);
    }

    public function testImportUpdatesExistingPageAndStoresRollbackMeta(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);
        update_post_meta((int) $postId, '_oxygen_data', 'previous-oxygen-payload');

        $result = (new OxygenPageImporter())->import([
            'title' => 'Existing Updated',
            'slug' => 'existing-page',
            'replaceExisting' => true,
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame((int) $postId, $result['postId']);
        $this->assertSame('updated', $result['postAction']);
        $this->assertSame('Existing Updated', $GLOBALS['__wp_posts'][(int) $postId]->post_title);
        $this->assertSame('previous-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId][OxygenPageImporter::ROLLBACK_META_KEY]);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][(int) $postId][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertTrue($manifest['rollback']['available']);
    }

    public function testImportRejectsExistingPageUpdateWithoutEditPostCapability(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);
        update_post_meta((int) $postId, '_oxygen_data', 'previous-oxygen-payload');

        $GLOBALS['__wp_current_user_can'] = static function (string $capability, ...$args) use ($postId): bool {
            if ($capability === 'edit_post' && (int) ($args[0] ?? 0) === (int) $postId) {
                return false;
            }

            return true;
        };

        $result = (new OxygenPageImporter())->import([
            'title' => 'Existing Updated',
            'slug' => 'existing-page',
            'replaceExisting' => true,
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Existing', $GLOBALS['__wp_posts'][(int) $postId]->post_title);
        $this->assertArrayNotHasKey(OxygenPageImporter::ROLLBACK_META_KEY, $GLOBALS['__wp_post_meta'][(int) $postId]);
    }

    public function testImportRejectsPublishStatusWithoutPublishCapability(): void
    {
        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            return $capability !== 'publish_pages';
        };

        $result = (new OxygenPageImporter())->import([
            'title' => 'Published Import',
            'postStatus' => 'publish',
            'element' => [
                'id' => 1,
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testRollbackRestoresPreviousOxygenPayloadAndUpdatesManifest(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);
        update_post_meta((int) $postId, '_oxygen_data', 'current-oxygen-payload');
        update_post_meta((int) $postId, OxygenPageImporter::ROLLBACK_META_KEY, 'previous-oxygen-payload');
        update_post_meta((int) $postId, OxygenPageImporter::MANIFEST_META_KEY, wp_slash(wp_json_encode([
            'version' => 1,
            'postId' => (int) $postId,
            'rollback' => ['available' => true],
        ])));

        $result = (new OxygenPageImporter())->rollback((int) $postId);

        $this->assertTrue($result['success']);
        $this->assertSame('previous-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId]['_oxygen_data']);
        $this->assertArrayNotHasKey(OxygenPageImporter::ROLLBACK_META_KEY, $GLOBALS['__wp_post_meta'][(int) $postId]);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][(int) $postId][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertFalse($manifest['rollback']['available']);
        $this->assertArrayHasKey('restoredAt', $manifest['rollback']);
    }

    public function testRollbackRestoresSnapshotAcrossImportSideEffects(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);

        update_post_meta((int) $postId, '_oxygen_data', 'old-oxygen-data');
        update_post_meta((int) $postId, PageStyleRepository::META_KEY, 'old-page-styles');
        update_option('oxygen_oxy_selectors_json_string', 'old-selectors');
        update_option('oxygen_oxy_selectors_collections_json_string', 'old-selector-collections');
        update_option('breakdance_classes_json_string', 'old-breakdance-classes');
        update_option(OxygenVariableRepository::OPTION_NAME, 'old-variables');
        update_option(OxygenVariableRepository::COLLECTIONS_OPTION_NAME, 'old-variable-collections');
        update_option(OxygenGlobalSettingsRepository::OPTION_NAME, 'old-global-settings');
        update_option(GlobalStyleRepository::OPTION_NAME, 'old-global-styles');
        update_option(BrandLibraryRepository::OPTION_NAME, 'old-brand-library');

        $import = (new OxygenPageImporter())->import([
            'title' => 'Existing Updated',
            'slug' => 'existing-page',
            'replaceExisting' => true,
            'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
            'globalCss' => '.material-symbols-outlined { font-family: "Material Symbols Outlined"; }',
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => [],
            ],
            'selectorPayload' => [
                'selectors' => [[
                    'id' => 'selector-1',
                    'name' => 'card',
                    'type' => 'class',
                    'collection' => 'Imported HTML',
                    'locked' => false,
                    'children' => [],
                    'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                ]],
                'collections' => ['Imported HTML'],
            ],
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 2,
                        'suggestedName' => 'color-731b19',
                    ]],
                ],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($import['success']);
        $this->assertNotSame('old-selectors', $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string']);
        $this->assertNotSame('old-page-styles', $GLOBALS['__wp_post_meta'][(int) $postId][PageStyleRepository::META_KEY]);
        $this->assertNotSame('old-brand-library', $GLOBALS['__wp_options'][BrandLibraryRepository::OPTION_NAME]);

        $rollback = (new OxygenPageImporter())->rollback((int) $postId);

        $this->assertTrue($rollback['success']);
        $this->assertGreaterThan(0, $rollback['restoredStores']);
        $this->assertSame('old-oxygen-data', $GLOBALS['__wp_post_meta'][(int) $postId]['_oxygen_data']);
        $this->assertSame('old-page-styles', $GLOBALS['__wp_post_meta'][(int) $postId][PageStyleRepository::META_KEY]);
        $this->assertSame('old-selectors', $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string']);
        $this->assertSame('old-selector-collections', $GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string']);
        $this->assertSame('old-breakdance-classes', $GLOBALS['__wp_options']['breakdance_classes_json_string']);
        $this->assertSame('old-variables', $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME]);
        $this->assertSame('old-variable-collections', $GLOBALS['__wp_options'][OxygenVariableRepository::COLLECTIONS_OPTION_NAME]);
        $this->assertSame('old-global-settings', $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME]);
        $this->assertSame('old-global-styles', $GLOBALS['__wp_options'][GlobalStyleRepository::OPTION_NAME]);
        $this->assertSame('old-brand-library', $GLOBALS['__wp_options'][BrandLibraryRepository::OPTION_NAME]);
    }

    public function testRollbackDeletesPageCreatedByImportAndRemovesUntouchedOptions(): void
    {
        $import = (new OxygenPageImporter())->import([
            'title' => 'Temporary Import',
            'slug' => 'temporary-import',
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => [],
            ],
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 2,
                        'suggestedName' => 'color-731b19',
                    ]],
                ],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertTrue($import['success']);
        $this->assertArrayHasKey((int) $import['postId'], $GLOBALS['__wp_posts']);
        $this->assertArrayHasKey(BrandLibraryRepository::OPTION_NAME, $GLOBALS['__wp_options']);

        $rollback = (new OxygenPageImporter())->rollback((int) $import['postId']);

        $this->assertTrue($rollback['success'], implode(' ', $rollback['errors'] ?? []));
        $this->assertArrayNotHasKey((int) $import['postId'], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey((int) $import['postId'], $GLOBALS['__wp_post_meta']);
        $this->assertArrayNotHasKey(BrandLibraryRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }

    public function testImportRestoresSnapshotWhenIntermediatePersistenceThrows(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => 'previous content',
        ], true);
        update_option('oxygen_oxy_selectors_json_string', 'old-selectors');
        update_option('oxygen_oxy_selectors_collections_json_string', 'old-selector-collections');
        update_option('breakdance_classes_json_string', 'old-breakdance-classes');

        $throwingGlobalStyles = new class extends GlobalStyleRepository {
            public function saveFromPayload(array $payload): array
            {
                throw new \RuntimeException('simulated global style failure');
            }
        };

        $result = (new OxygenPageImporter(
            null,
            null,
            $throwingGlobalStyles
        ))->import([
            'title' => 'Existing Updated',
            'slug' => 'existing-page',
            'replaceExisting' => true,
            'globalCss' => '.should-not-stick { color: red; }',
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => [],
            ],
            'selectorPayload' => [
                'selectors' => [[
                    'id' => 'selector-1',
                    'name' => 'card',
                    'type' => 'class',
                    'collection' => 'Imported HTML',
                    'locked' => false,
                    'children' => [],
                    'properties' => [],
                ]],
                'collections' => ['Imported HTML'],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('simulated global style failure', implode(' ', $result['errors']));
        $this->assertSame('Existing', $GLOBALS['__wp_posts'][(int) $postId]->post_title);
        $this->assertSame('existing-page', $GLOBALS['__wp_posts'][(int) $postId]->post_name);
        $this->assertSame('previous content', $GLOBALS['__wp_posts'][(int) $postId]->post_content);
        $this->assertSame('old-selectors', $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string']);
        $this->assertSame('old-selector-collections', $GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string']);
        $this->assertSame('old-breakdance-classes', $GLOBALS['__wp_options']['breakdance_classes_json_string']);
        $this->assertArrayNotHasKey(GlobalStyleRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }

    public function testRollbackRejectsUnauthorizedPostId(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);
        update_post_meta((int) $postId, '_oxygen_data', 'current-oxygen-payload');
        update_post_meta((int) $postId, OxygenPageImporter::ROLLBACK_META_KEY, 'previous-oxygen-payload');

        $GLOBALS['__wp_current_user_can'] = static function (string $capability, ...$args) use ($postId): bool {
            if ($capability === 'edit_post' && (int) ($args[0] ?? 0) === (int) $postId) {
                return false;
            }

            return true;
        };

        $result = (new OxygenPageImporter())->rollback((int) $postId);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('current-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId]['_oxygen_data']);
        $this->assertSame('previous-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId][OxygenPageImporter::ROLLBACK_META_KEY]);
    }

    public function testRollbackFailsWhenNoRollbackPayloadExists(): void
    {
        $result = (new OxygenPageImporter())->rollback(10);

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status']);
    }

    /**
     * @param array<int, array<string, mixed>> $stores
     */
    private function assertRollbackSnapshotEntry(array $stores, string $store, string $key): void
    {
        foreach ($stores as $entry) {
            if (($entry['store'] ?? null) === $store && ($entry['key'] ?? null) === $key) {
                $this->assertSame('oxygen-html-converter', $entry['owner']);
                $this->assertArrayHasKey('oldValue', $entry);
                $this->assertArrayHasKey('newValue', $entry);
                $this->assertIsString($entry['storeType']);
                $this->assertIsString($entry['restoreOperation']);
                return;
            }
        }

        $this->fail('Missing rollback snapshot entry for ' . $store . ':' . $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSiteKitManifestFixture(): array
    {
        $path = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'html'
            . DIRECTORY_SEPARATOR . 'site-kit' . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = file_get_contents($path);
        $this->assertIsString($json);
        $manifest = json_decode($json, true);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTreeWithSemanticTag(string $tag): array
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
    private function decodeStoredOxygenTree(int $postId): array
    {
        $oxygenMeta = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][$postId]['_oxygen_data']), true);
        $this->assertIsArray($oxygenMeta);
        $tree = json_decode((string) $oxygenMeta['tree_json_string'], true);
        $this->assertIsArray($tree);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function navItemDocumentTree(int $instances): array
    {
        $children = [];
        $nextId = 1;

        for ($index = 0; $index < $instances; $index++) {
            $children[] = [
                'id' => $nextId,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'li',
                                'classes' => ['nav-item'],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'id' => $nextId + 1,
                    'data' => [
                        'type' => ElementTypes::TEXT_LINK,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'text' => 'Item ' . ($index + 1),
                                    'url' => '/item-' . ($index + 1),
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ];
            $nextId += 2;
        }

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => $children,
            ],
            '_nextNodeId' => $nextId,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureCardDocumentTree(int $instances): array
    {
        $children = [];
        $nextId = 1;

        for ($index = 0; $index < $instances; $index++) {
            $children[] = $this->featureCardNode($nextId, 'Feature ' . ($index + 1));
            $nextId += 4;
        }

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => $children,
            ],
            '_nextNodeId' => $nextId,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mixedSameSignatureCardTree(): array
    {
        $feature = $this->sameSignatureCardChildren(1, 3, 'feature-card', 'Feature');
        $testimonial = $this->sameSignatureCardChildren(13, 3, 'testimonial-card', 'Quote');

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => array_merge($feature['children'], $testimonial['children']),
            ],
            '_nextNodeId' => 25,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sameSignatureCardDocumentTree(int $instances, string $className, string $titlePrefix): array
    {
        $group = $this->sameSignatureCardChildren(1, $instances, $className, $titlePrefix);

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => $group['children'],
            ],
            '_nextNodeId' => $group['nextId'],
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array{children:list<array<string,mixed>>,nextId:int}
     */
    private function sameSignatureCardChildren(int $startId, int $instances, string $className, string $titlePrefix): array
    {
        $children = [];
        $nextId = $startId;

        for ($index = 0; $index < $instances; $index++) {
            $children[] = [
                'id' => $nextId,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'div',
                                'classes' => [$className],
                            ],
                        ],
                    ],
                ],
                'children' => [
                    [
                        'id' => $nextId + 1,
                        'data' => [
                            'type' => ElementTypes::TEXT,
                            'properties' => [
                                'settings' => [
                                    'advanced' => [
                                        'tag' => 'h3',
                                    ],
                                ],
                                'content' => [
                                    'content' => [
                                        'text' => $titlePrefix . ' ' . ($index + 1),
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                    [
                        'id' => $nextId + 2,
                        'data' => [
                            'type' => ElementTypes::TEXT,
                            'properties' => [
                                'settings' => [
                                    'advanced' => [
                                        'tag' => 'p',
                                    ],
                                ],
                                'content' => [
                                    'content' => [
                                        'text' => $titlePrefix . ' copy',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                    [
                        'id' => $nextId + 3,
                        'data' => [
                            'type' => ElementTypes::TEXT_LINK,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'text' => 'Read',
                                        'url' => '#',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                ],
            ];
            $nextId += 4;
        }

        return [
            'children' => $children,
            'nextId' => $nextId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureCardDocumentTreeWithComponentCss(int $instances): array
    {
        $children = [];
        $nextId = 1;

        for ($index = 0; $index < $instances; $index++) {
            $children[] = $this->featureCardNodeWithComponentCss($nextId, 'Feature ' . ($index + 1));
            $nextId += 5;
        }

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => $children,
            ],
            '_nextNodeId' => $nextId,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureCardNode(int $id, string $title): array
    {
        return [
            'id' => $id,
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
            'children' => [
                [
                    'id' => $id + 1,
                    'data' => [
                        'type' => ElementTypes::TEXT,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'h3',
                                ],
                            ],
                            'content' => [
                                'content' => [
                                    'text' => $title,
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $id + 2,
                    'data' => [
                        'type' => ElementTypes::TEXT,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'p',
                                ],
                            ],
                            'content' => [
                                'content' => [
                                    'text' => 'Feature copy',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $id + 3,
                    'data' => [
                        'type' => ElementTypes::TEXT_LINK,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'text' => 'Learn more',
                                    'url' => '#',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureCardNodeWithComponentCss(int $id, string $title): array
    {
        $node = $this->featureCardNode($id, $title);
        $node['children'][] = [
            'id' => $id + 4,
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
        ];

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function htmlFallbackFeatureCardDocumentTree(int $instances): array
    {
        $children = [];
        $nextId = 1;

        for ($index = 0; $index < $instances; $index++) {
            $children[] = $this->htmlFallbackFeatureCardNode($nextId, 'Feature ' . ($index + 1));
            $nextId += 5;
        }

        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => $children,
            ],
            '_nextNodeId' => $nextId,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function htmlFallbackFeatureCardNode(int $id, string $title): array
    {
        return [
            'id' => $id,
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
            'children' => [
                [
                    'id' => $id + 1,
                    'data' => [
                        'type' => ElementTypes::TEXT,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'h3',
                                ],
                            ],
                            'content' => [
                                'content' => [
                                    'text' => $title,
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $id + 2,
                    'data' => [
                        'type' => ElementTypes::TEXT_LINK,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'text' => 'Open',
                                    'url' => '/open',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $id + 3,
                    'data' => [
                        'type' => ElementTypes::IMAGE,
                        'properties' => [
                            'content' => [
                                'image' => [
                                    'from' => 'url',
                                    'url' => '/feature.jpg',
                                    'alt_when_from_url' => 'custom',
                                    'custom_alt_when_from_url' => 'Feature image',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $id + 4,
                    'data' => [
                        'type' => ElementTypes::HTML_CODE,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'html_code' => '<svg viewBox="0 0 10 10"></svg>',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentTreeFixture(): array
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
                            'type' => ElementTypes::TEXT,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'text' => 'Feature title',
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
            'status' => 'exported',
        ];
    }
}
