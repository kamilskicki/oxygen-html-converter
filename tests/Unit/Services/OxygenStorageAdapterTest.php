<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Services\OxygenStorageAdapterFactory;
use OxyHtmlConverter\Services\OxygenStorageContract;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class OxygenStorageAdapterTest extends TestCase
{
    public function testFactorySelectsSupportedOxygenSixAdapterDeterministically(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();

        $this->assertInstanceOf(OxygenStorageAdapter::class, $adapter);
        $this->assertSame(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION, $adapter->getContractVersion());
        $this->assertSame('oxygen6', $adapter->getAdapterId());
        $this->assertSame(array_keys(OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES), $adapter->getContract()->getContractNames());
    }

    public function testFactoryDefaultContractDirectoryIsRuntimePackaged(): void
    {
        $fixtureDir = OxygenStorageContract::defaultFixtureDirectory();

        $this->assertDirectoryExists($fixtureDir);
        $this->assertStringContainsString(
            DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR,
            $fixtureDir
        );

        $adapter = (new OxygenStorageAdapterFactory())->create();

        $this->assertSame('oxygen6', $adapter->getAdapterId());
        $this->assertSame(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION, $adapter->getContractVersion());
    }

    public function testAdapterInterfaceNamesAllPlannedStorageMethods(): void
    {
        $methods = get_class_methods(OxygenStorageAdapter::class);

        foreach ([
            'supports',
            'getAdapterId',
            'getContractVersion',
            'getContract',
            'buildDocumentTree',
            'validateDocumentTree',
            'validatePageDocumentEnvelope',
            'readPageDocument',
            'writePageDocument',
            'createOrUpdateDocumentPost',
            'readSelectors',
            'writeSelectors',
            'readVariables',
            'writeVariables',
            'readGlobalSettings',
            'writeGlobalSettings',
            'readTemplateSettings',
            'validateTemplate',
            'writeTemplate',
            'validateBlock',
            'writeBlock',
            'writeComponentInstance',
            'readGlobalStyles',
            'writeGlobalStyles',
            'readPageStyles',
            'writePageStyles',
            'captureRollbackSnapshot',
            'restoreRollbackSnapshot',
            'invalidateDocumentCaches',
            'invalidateGlobalCaches',
            'resetOptionalIntegrationCaches',
        ] as $method) {
            $this->assertContains($method, $methods);
        }
    }

    public function testAdapterCapturesAndRestoresRollbackSnapshotForOptions(): void
    {
        $GLOBALS['__wp_options'] = [];
        update_option('oxygen_global_settings_json_string', 'old-global-settings');

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $snapshot = $adapter->captureRollbackSnapshot([
            'global_settings',
            'option:custom_missing_before_import',
        ]);

        $this->assertTrue($snapshot['success']);
        $this->assertNotEmpty($snapshot['stores']);

        update_option('oxygen_global_settings_json_string', 'new-global-settings');
        update_option('custom_missing_before_import', 'new-custom');

        $restore = $adapter->restoreRollbackSnapshot($snapshot);

        $this->assertTrue($restore['success'], implode(' ', $restore['errors']));
        $this->assertSame('old-global-settings', get_option('oxygen_global_settings_json_string'));
        $this->assertArrayNotHasKey('custom_missing_before_import', $GLOBALS['__wp_options']);
    }

    public function testSelectorFallbackWritesStablePhysicalBreakdanceClassOption(): void
    {
        $GLOBALS['__wp_options'] = [];

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $result = $adapter->writeSelectors([$this->selectorRecord('card')], ['Imported HTML']);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame([
            'oxygen_oxy_selectors_json_string',
            'oxygen_oxy_selectors_collections_json_string',
            'oxygen_breakdance_classes_json_string',
        ], $result['optionNames']);
        $this->assertArrayHasKey('oxygen_breakdance_classes_json_string', $GLOBALS['__wp_options']);
        $this->assertArrayNotHasKey('breakdance_classes_json_string', $GLOBALS['__wp_options']);

        $classes = json_decode((string) get_option('oxygen_breakdance_classes_json_string'), true);
        $this->assertSame('.card', $classes[0]['name']);
    }

    #[RunInSeparateProcess]
    public function testSelectorWriteUsesStableDataApiLogicalKeyWhenAvailable(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__breakdance_data_api_writes'] = [];

        eval('namespace Breakdance\\Data; function set_global_option($fieldName, $value) { $GLOBALS["__breakdance_data_api_writes"][] = [$fieldName, $value]; update_option("oxygen_" . $fieldName, $value); }');

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $result = $adapter->writeSelectors([$this->selectorRecord('card')], ['Imported HTML']);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame([
            'oxy_selectors_collections_json_string',
            'oxy_selectors_json_string',
            'breakdance_classes_json_string',
        ], array_column($GLOBALS['__breakdance_data_api_writes'], 0));
        $this->assertArrayHasKey('oxygen_breakdance_classes_json_string', $GLOBALS['__wp_options']);
        $this->assertArrayNotHasKey('breakdance_classes_json_string', $GLOBALS['__wp_options']);
    }

    public function testSelectorRollbackSnapshotUsesStablePhysicalBreakdanceClassOption(): void
    {
        $GLOBALS['__wp_options'] = [];
        update_option('oxygen_breakdance_classes_json_string', 'old-classes');

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $snapshot = $adapter->captureRollbackSnapshot(['selectors']);
        $keys = array_column($snapshot['stores'], 'key');

        $this->assertContains('oxygen_breakdance_classes_json_string', $keys);
        $this->assertNotContains('breakdance_classes_json_string', $keys);

        update_option('oxygen_breakdance_classes_json_string', 'new-classes');
        $restore = $adapter->restoreRollbackSnapshot($snapshot);

        $this->assertTrue($restore['success'], implode(' ', $restore['errors']));
        $this->assertSame('old-classes', get_option('oxygen_breakdance_classes_json_string'));
    }

    public function testStableMetadataSnapshotRestoresFutureLayerAiTrackingAndIntegrationStores(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $postId = 74;

        update_post_meta($postId, '_oxygen_futurelayer_meta', 'old-future-layer');
        update_post_meta($postId, '_oxygen_ai_settings', 'old-ai');
        update_option('oxygen_enable_tracking', 'yes');
        update_option('oxygen_breakdance_settings_disable_view_tracking_cookies', 'no');
        update_option('oxygen_settings_hide_builder_integration', 'no');

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $snapshot = $adapter->captureRollbackSnapshot(['stable_metadata:' . $postId]);
        $keys = array_column($snapshot['stores'], 'key');

        $this->assertContains('_oxygen_futurelayer_meta', $keys);
        $this->assertContains('_oxygen_ai_settings', $keys);
        $this->assertContains('oxygen_enable_tracking', $keys);
        $this->assertContains('oxygen_breakdance_settings_disable_view_tracking_cookies', $keys);
        $this->assertContains('oxygen_settings_hide_builder_integration', $keys);

        update_post_meta($postId, '_oxygen_futurelayer_meta', 'new-future-layer');
        update_post_meta($postId, '_oxygen_ai_settings', 'new-ai');
        update_option('oxygen_enable_tracking', 'no');
        update_option('oxygen_breakdance_settings_disable_view_tracking_cookies', 'yes');
        update_option('oxygen_settings_hide_builder_integration', 'yes');

        $restore = $adapter->restoreRollbackSnapshot($snapshot);

        $this->assertTrue($restore['success'], implode(' ', $restore['errors']));
        $this->assertSame('old-future-layer', get_post_meta($postId, '_oxygen_futurelayer_meta', true));
        $this->assertSame('old-ai', get_post_meta($postId, '_oxygen_ai_settings', true));
        $this->assertSame('yes', get_option('oxygen_enable_tracking'));
        $this->assertSame('no', get_option('oxygen_breakdance_settings_disable_view_tracking_cookies'));
        $this->assertSame('no', get_option('oxygen_settings_hide_builder_integration'));
    }
    public function testFactoryFailsClosedForUnsupportedFixtureVersion(): void
    {
        $tmp = $this->copyFixturesToTempDir();
        $pageTreeFile = $tmp . DIRECTORY_SEPARATOR . OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES['page-tree'];
        $pageTree = json_decode((string) file_get_contents($pageTreeFile), true);
        $pageTree['oxygenVersion'] = '7.0.0';
        file_put_contents($pageTreeFile, json_encode($pageTree, JSON_PRETTY_PRINT));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Oxygen storage contract version');

        (new OxygenStorageAdapterFactory(null, $tmp))->create();
    }

    public function testFactoryFailsClosedForUnsupportedRuntimeVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Oxygen runtime version "7.0.0"');

        (new OxygenStorageAdapterFactory(null, $this->fixtureDir(), '7.0.0'))->create();
    }

    public function testFactoryAcceptsStableRuntimeVersionForPinnedOxygenSixContract(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir(), '6.1.0'))->create();

        $this->assertSame('oxygen6', $adapter->getAdapterId());
        $this->assertSame(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION, $adapter->getContractVersion());
        $this->assertTrue($adapter->supports('6.1.0'));
    }

    public function testFactorySupportsOnlyPinnedOxygenVersion(): void
    {
        $factory = new OxygenStorageAdapterFactory(null, $this->fixtureDir());

        $this->assertTrue($factory->supports(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION));
        $this->assertTrue($factory->supports('6.1.0'));
        $this->assertFalse($factory->supports('7.0.0'));
    }

    public function testAdapterValidatesDocumentTreeShape(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();

        $valid = $adapter->validateDocumentTree([
            'root' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [],
                ],
                'children' => [],
            ],
            '_nextNodeId' => 2,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['errors']);

        $invalid = $adapter->validateDocumentTree([
            'root' => [
                'id' => 'bad',
                'data' => ['type' => 'OxygenElements\\Container'],
                'children' => 'bad',
            ],
            '_nextNodeId' => 2,
            'status' => false,
        ]);
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.data.properties must be an array', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.children must be an array', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('status must be a non-empty string', implode(' ', $invalid['errors']));
    }

    public function testAdapterValidatesPageDocumentEnvelopeAndTreeJsonString(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $tree = $adapter->buildDocumentTree([
            'id' => 1,
            'data' => ['type' => 'OxygenElements\\Container'],
            'children' => [],
            'status' => 'exported',
        ]);
        $encodedTree = wp_json_encode($tree);
        $this->assertIsString($encodedTree);

        $valid = $adapter->validatePageDocumentEnvelope([
            'tree_json_string' => $encodedTree,
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['errors']);

        $invalid = $adapter->validatePageDocumentEnvelope([
            'tree_json_string' => '{"root":{"id":"bad"}}',
            'extra' => true,
        ]);
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('Unexpected _oxygen_data field "extra"', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.data must be an array', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('_nextNodeId must be a positive integer', implode(' ', $invalid['errors']));
    }

    public function testAdapterValidatesTemplateFixtureAndWritesTemplatePostAndMeta(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('template-settings.json');
        $tree = $this->decodeTree($payload['_oxygen_data']);

        $valid = $adapter->validateTemplate(
            (string) $payload['post_type'],
            $tree,
            (string) $payload['_oxygen_template_settings']
        );
        $this->assertTrue($valid['valid'], implode(' ', $valid['errors']));

        $write = $adapter->writeTemplate(
            (string) $payload['post_type'],
            $tree,
            (string) $payload['_oxygen_template_settings']
        );
        $this->assertTrue($write['success'], implode(' ', $write['errors'] ?? []));
        $this->assertSame(200, $write['status']);
        $this->assertSame('created', $write['action']);
        $this->assertSame($payload['post_type'], $write['postType']);
        $this->assertSame(['_oxygen_data', '_oxygen_template_settings'], $write['metaKeys']);

        $postId = (int) $write['postId'];
        $this->assertGreaterThan(0, $postId);
        $this->assertArrayHasKey($postId, $GLOBALS['__wp_posts']);
        $this->assertSame($payload['post_type'], $GLOBALS['__wp_posts'][$postId]->post_type);

        $storedData = $this->decodeStoredMetaObject((string) get_post_meta($postId, '_oxygen_data', true));
        $this->assertArrayHasKey('tree_json_string', $storedData);
        $storedTree = json_decode((string) $storedData['tree_json_string'], true);
        $this->assertIsArray($storedTree);
        $this->assertSame('root', $storedTree['root']['data']['type']);
        $this->assertSame(3, $storedTree['_nextNodeId']);
        $this->assertSame('exported', $storedTree['status']);

        $storedSettings = $this->decodeStoredJsonString((string) get_post_meta($postId, '_oxygen_template_settings', true));
        $this->assertIsString($storedSettings);
        $this->assertSame($payload['_oxygen_template_settings'], $storedSettings);

        $readSettings = $adapter->readTemplateSettings($postId);
        $this->assertTrue($readSettings['success'], implode(' ', $readSettings['errors'] ?? []));
        $this->assertSame($payload['_oxygen_template_settings'], $readSettings['settingsJson']);

        $invalid = $adapter->writeTemplate('page', ['root' => ['id' => 'bad']], '{"type":""}');
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $invalid['errors']));
    }

    public function testAdapterReadsTemplateMetaStoredInRealWordPressShape(): void
    {
        $GLOBALS['__wp_post_meta'] = [];

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $postId = 91;
        $treeJson = wp_json_encode($adapter->buildDocumentTree($this->validTree()));
        $settingsJson = wp_json_encode([
            'type' => 'everywhere',
            'ruleGroups' => [],
            'triggers' => [],
            'priority' => 1,
            'fallback' => false,
        ]);
        $this->assertIsString($treeJson);
        $this->assertIsString($settingsJson);

        $GLOBALS['__wp_post_meta'][$postId]['_oxygen_data'] = wp_json_encode([
            'tree_json_string' => $treeJson,
        ]);
        $GLOBALS['__wp_post_meta'][$postId]['_oxygen_template_settings'] = wp_json_encode($settingsJson);

        $document = $adapter->readPageDocument($postId);
        $this->assertTrue($document['success'], implode(' ', $document['errors'] ?? []));
        $this->assertSame($treeJson, $document['payload']['tree_json_string']);
        $this->assertSame(sha1($treeJson), $document['treeHash']);

        $settings = $adapter->readTemplateSettings($postId);
        $this->assertTrue($settings['success'], implode(' ', $settings['errors'] ?? []));
        $this->assertSame($settingsJson, $settings['settingsJson']);
        $this->assertSame(sha1($settingsJson), $settings['settingsHash']);
    }

    public function testCreateOrUpdateDocumentPostRejectsUnsupportedPostTypeWithValidationContract(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('template-settings.json');
        $payload['post_type'] = 'page';

        $result = $adapter->createOrUpdateDocumentPost($payload);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('page', $result['postType']);
        $this->assertSame(['_oxygen_data', '_oxygen_template_settings'], $result['metaKeys']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $result['errors']));
    }

    public function testCreateOrUpdateDocumentPostRejectsOxygenPartWhenStableCopyOptionIsDisabled(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('template-settings.json');
        $payload['post_type'] = 'oxygen_part';

        $result = $adapter->createOrUpdateDocumentPost($payload);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('oxygen_part requires Oxygen stable is_copy_from_frontend_enabled to be "yes"', implode(' ', $result['errors']));
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testCreateOrUpdateDocumentPostAllowsOxygenPartWhenStableCopyOptionIsEnabled(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        update_option('oxygen_is_copy_from_frontend_enabled', wp_json_encode('yes'));

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('template-settings.json');
        $payload['post_type'] = 'oxygen_part';

        $result = $adapter->createOrUpdateDocumentPost($payload);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame('oxygen_part', $result['postType']);
        $this->assertSame('oxygen_part', $GLOBALS['__wp_posts'][(int) $result['postId']]->post_type);
    }
    public function testAdapterRejectsMalformedGlobalSettingsAndTemplateRuleContracts(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();

        $global = $adapter->writeGlobalSettings([
            'settings' => [
                'colors' => [
                    'palette' => [
                        'gradients' => [[
                            'label' => 'Hero gradient',
                            'cssVariableName' => 'ohc-hero-gradient',
                            'value' => [
                                'value' => 'linear-gradient(135deg, #2563EB 0%, #14B8A6 100%)',
                            ],
                        ]],
                    ],
                ],
            ],
        ]);
        $this->assertFalse($global['success']);
        $this->assertStringContainsString('$.settings.colors.palette.gradients[0].value.svgValue expected non-empty string', implode(' ', $global['errors']));

        $unsupportedCode = $adapter->writeGlobalSettings([
            'settings' => [
                'code' => [
                    'head' => '<meta name="x" content="y">',
                ],
            ],
        ]);
        $this->assertFalse($unsupportedCode['success']);
        $this->assertStringContainsString('$.settings.code.head expected stylesheets or scripts', implode(' ', $unsupportedCode['errors']));

        $template = $adapter->validateTemplate('oxygen_template', $this->validTree(), wp_json_encode([
            'type' => 'all-singles',
            'ruleGroups' => [[[
                'operand' => 'is one of',
                'ruleSlug' => 'post-type',
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]));
        $this->assertFalse($template['valid']);
        $this->assertStringContainsString('$.ruleGroups[0][0].value expected field required', implode(' ', $template['errors']));
    }

    public function testAdapterValidatesBlockFixtureAndWritesBlockPostAndMetaWithRollback(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];
        $GLOBALS['__breakdance_generated_cache_posts'] = [];
        if (!function_exists('Breakdance\\Render\\generateCacheForPost')) {
            eval('namespace Breakdance\\Render; function generateCacheForPost($postId) { $GLOBALS["__breakdance_generated_cache_posts"][] = (int) $postId; }');
        }

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('block.json');
        $tree = $this->decodeTree($payload['_oxygen_data']);
        $settings = is_array($payload['_breakdance_block_settings'] ?? null) ? $payload['_breakdance_block_settings'] : [];

        $valid = $adapter->validateBlock($tree, $settings);
        $this->assertTrue($valid['valid'], implode(' ', $valid['errors']));

        $settings['_post'] = [
            'post_title' => 'Imported Card Block',
            'post_name' => 'imported-card-block',
        ];
        $write = $adapter->writeBlock($tree, $settings);
        $this->assertTrue($write['success'], implode(' ', $write['errors'] ?? []));
        $this->assertSame(200, $write['status']);
        $this->assertSame('created', $write['action']);
        $this->assertSame('oxygen_block', $write['postType']);
        $this->assertSame(['_oxygen_data', '_breakdance_block_settings'], $write['metaKeys']);
        $this->assertTrue($write['rollback']['post']);
        $this->assertSame('wp_delete_post', $write['rollback']['postStore']['restoreOperation']);
        $this->assertTrue($write['cacheRegenerated']);

        $postId = (int) $write['postId'];
        $this->assertGreaterThan(0, $postId);
        $this->assertSame('oxygen_block', $GLOBALS['__wp_posts'][$postId]->post_type);
        $this->assertSame('Imported Card Block', $GLOBALS['__wp_posts'][$postId]->post_title);

        $storedData = $this->decodeStoredMetaObject((string) get_post_meta($postId, '_oxygen_data', true));
        $this->assertArrayHasKey('tree_json_string', $storedData);
        $storedSettings = $this->decodeStoredMetaObject((string) get_post_meta($postId, '_breakdance_block_settings', true));
        $this->assertArrayHasKey('preview', $storedSettings);
        $this->assertArrayNotHasKey('_post', $storedSettings);

        $settings['_post'] = [
            'ID' => $postId,
            'post_title' => 'Updated Card Block',
            'post_name' => 'updated-card-block',
        ];
        $update = $adapter->writeBlock($tree, $settings);
        $this->assertTrue($update['success'], implode(' ', $update['errors'] ?? []));
        $this->assertSame('updated', $update['action']);
        $this->assertTrue($update['rollback']['post']);
        $this->assertTrue($update['rollback']['oxygenData']);
        $this->assertTrue($update['rollback']['blockSettings']);
        $this->assertSame([$postId, $postId], $GLOBALS['__breakdance_generated_cache_posts']);
        $this->assertNotSame('', get_post_meta($postId, '_oxy_html_converter_previous_oxygen_block_post', true));
        $this->assertNotSame('', get_post_meta($postId, '_oxy_html_converter_previous_oxygen_data', true));
        $this->assertNotSame('', get_post_meta($postId, '_oxy_html_converter_previous_breakdance_block_settings', true));

        $invalid = $adapter->writeBlock(['root' => ['id' => 'bad']], ['preview' => 'bad']);
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('_breakdance_block_settings.preview must be an object', implode(' ', $invalid['errors']));
    }

    public function testAdapterDeletesCreatedBlockWhenSettingsWriteFailsAfterPostInsert(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;

        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('block.json');
        $tree = $this->decodeTree($payload['_oxygen_data']);
        $settings = [
            'preview' => [],
            '_post' => [
                'post_title' => 'Broken Block',
            ],
        ];
        $settings['recursive'] = &$settings;

        $write = $adapter->writeBlock($tree, $settings);

        $this->assertFalse($write['success']);
        $this->assertSame(500, $write['status']);
        $this->assertSame(1, $write['postId']);
        $this->assertTrue($write['rollback']['deletedCreatedPost']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'oxygen6-contracts';
    }

    /**
     * @return array<string, mixed>
     */
    private function selectorRecord(string $name): array
    {
        return [
            'id' => '11111111-1111-5111-8111-111111111111',
            'name' => $name,
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => new \stdClass(),
        ];
    }
    /**
     * @return array<string, mixed>
     */
    private function validTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [],
            ],
            '_nextNodeId' => 1,
            'exportedLookupTable' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixturePayload(string $fileName): array
    {
        $fixture = json_decode((string) file_get_contents($this->fixtureDir() . DIRECTORY_SEPARATOR . $fileName), true);
        $this->assertIsArray($fixture);
        $payload = $fixture['payload'] ?? null;
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param mixed $oxygenData
     * @return array<string, mixed>
     */
    private function decodeTree($oxygenData): array
    {
        $this->assertIsArray($oxygenData);
        $treeJson = $oxygenData['tree_json_string'] ?? null;
        $this->assertIsString($treeJson);
        $tree = json_decode($treeJson, true);
        $this->assertIsArray($tree);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredMetaObject(string $raw): array
    {
        foreach ([$raw, stripslashes($raw)] as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function decodeStoredJsonString(string $raw): string
    {
        foreach ([$raw, stripslashes($raw)] as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return '';
    }

    private function copyFixturesToTempDir(): string
    {
        $source = $this->fixtureDir();
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ohc-storage-adapter-' . bin2hex(random_bytes(4));
        mkdir($target);

        foreach (glob($source . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            copy($file, $target . DIRECTORY_SEPARATOR . basename($file));
        }

        return $target;
    }
}
