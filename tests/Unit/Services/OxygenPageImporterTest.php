<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\BrandLibraryRepository;
use OxyHtmlConverter\Services\GlobalStyleRepository;
use OxyHtmlConverter\Services\OxygenGlobalSettingsRepository;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\OxygenVariableRepository;
use OxyHtmlConverter\Services\PageStyleRepository;
use PHPUnit\Framework\TestCase;

class OxygenPageImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];
        $GLOBALS['__wp_current_user_can'] = true;
        unset($GLOBALS['__wp_current_user_can_last_capability'], $GLOBALS['__wp_current_user_can_last_args']);
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
        $tree = json_decode((string) $oxygenMeta['tree_json_string'], true);
        $this->assertArrayNotHasKey('status', $tree);
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
                ], [
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                    'label' => 'Tailwind utility fallback safety CSS for WindPress',
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

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1][OxygenPageImporter::MANIFEST_META_KEY]), true);
        $this->assertTrue($manifest['globalStylePersistence']['saved']);
        $this->assertSame(1, $manifest['globalStylePersistence']['changes']);
        $this->assertSame(1, $manifest['globalStylePersistence']['total']);
        $this->assertTrue($manifest['pageStylePersistence']['saved']);
        $this->assertStringContainsString(
            '.text-6xl',
            stripslashes((string) $GLOBALS['__wp_post_meta'][1]['_oxy_html_converter_page_styles'])
        );
        $this->assertFalse($manifest['windPressCacheReset']['attempted']);
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
}
