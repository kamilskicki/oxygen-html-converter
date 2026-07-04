<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Services\OxygenStorageAdapterFactory;
use OxyHtmlConverter\Services\OxygenStorageContract;
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

    public function testFactorySupportsOnlyPinnedOxygenVersion(): void
    {
        $factory = new OxygenStorageAdapterFactory(null, $this->fixtureDir());

        $this->assertTrue($factory->supports(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION));
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
            'status' => 'exported',
        ]);
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.data.properties must be an array', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('root.children must be an array', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('Unexpected document tree top-level field "status"', implode(' ', $invalid['errors']));
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

    public function testAdapterValidatesTemplateFixtureAndKeepsTemplateWriteStubbed(): void
    {
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
        $this->assertFalse($write['success']);
        $this->assertSame('stub_not_exposed', $write['status']);
        $this->assertSame(['_oxygen_data', '_oxygen_template_settings'], $write['metaKeys']);

        $invalid = $adapter->writeTemplate('page', ['root' => ['id' => 'bad']], '{"type":""}');
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $invalid['errors']));
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
        $this->assertStringContainsString('settings.colors.palette.gradients.0.value.svgValue must be a non-empty string', implode(' ', $global['errors']));

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
        $this->assertStringContainsString('_oxygen_template_settings.ruleGroups.0.0.value is required', implode(' ', $template['errors']));
    }

    public function testAdapterValidatesBlockFixtureAndKeepsBlockWriteStubbed(): void
    {
        $adapter = (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create();
        $payload = $this->fixturePayload('block.json');
        $tree = $this->decodeTree($payload['_oxygen_data']);
        $settings = is_array($payload['_breakdance_block_settings'] ?? null) ? $payload['_breakdance_block_settings'] : [];

        $valid = $adapter->validateBlock($tree, $settings);
        $this->assertTrue($valid['valid'], implode(' ', $valid['errors']));

        $write = $adapter->writeBlock($tree, $settings);
        $this->assertFalse($write['success']);
        $this->assertSame('stub_not_exposed', $write['status']);
        $this->assertSame(['_oxygen_data', '_breakdance_block_settings'], $write['metaKeys']);

        $invalid = $adapter->writeBlock(['root' => ['id' => 'bad']], ['preview' => 'bad']);
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('_breakdance_block_settings.preview must be an object', implode(' ', $invalid['errors']));
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'oxygen6-contracts';
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
