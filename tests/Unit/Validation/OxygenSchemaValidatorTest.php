<?php

namespace OxyHtmlConverter\Tests\Unit\Validation;

use OxyHtmlConverter\Validation\OxygenSchemaValidator;
use PHPUnit\Framework\TestCase;

class OxygenSchemaValidatorTest extends TestCase
{
    public function testContractFixturesValidateAgainstOxygenSchema(): void
    {
        $validator = new OxygenSchemaValidator();

        $pageTree = $this->decodeTree($this->fixturePayload('page-tree.json')['_oxygen_data']);
        $this->assertTrue($validator->validateTree($pageTree)['valid']);

        $selectors = $this->fixturePayload('selectors.json');
        $this->assertTrue($validator->validateSelectors(
            $selectors['oxygen_oxy_selectors_json_string'],
            $selectors['oxygen_oxy_selectors_collections_json_string']
        )['valid']);

        $variables = $this->fixturePayload('variables.json');
        $this->assertTrue($validator->validateVariables(
            $variables['oxygen_variables_json_string'],
            $variables['oxygen_variables_collections_json_string']
        )['valid']);

        $globalSettings = $this->fixturePayload('global-settings.json');
        $this->assertTrue($validator->validateGlobalSettings($globalSettings['oxygen_global_settings_json_string'])['valid']);

        $template = $this->fixturePayload('template-settings.json');
        $this->assertTrue($validator->validateTemplateSettingsJson($template['_oxygen_template_settings'])['valid']);

        $block = $this->fixturePayload('block.json');
        $this->assertTrue($validator->validateBlockSettings($block['_breakdance_block_settings'])['valid']);

        $component = $this->fixturePayload('component-instance.json');
        $this->assertTrue($validator->validateComponentInstance($component['componentNode'])['valid']);
    }

    public function testTreeValidationRejectsInternalFieldsAndReportsStructuredErrors(): void
    {
        $validator = new OxygenSchemaValidator();

        $result = $validator->validateTree([
            'root' => [
                'id' => 'bad',
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'native' => true,
                ],
                'children' => [],
            ],
            '_nextNodeId' => 0,
            'status' => 'exported',
        ]);

        $this->assertFalse($result['valid']);
        $errors = $result['errors'];
        $this->assertError($errors, '$.status', 'no additional Oxygen tree fields');
        $this->assertError($errors, '$.root.id', 'integer >= 0');
        $this->assertError($errors, '$.root.data.properties', 'object');
        $this->assertError($errors, '$.root.data.native', 'no additional node data fields');
        $this->assertArrayHasKey('remediation', $errors[0]);
    }

    public function testSelectorValidationRejectsImporterOnlyFieldsAndBadNames(): void
    {
        $result = (new OxygenSchemaValidator())->validateSelectors([[
            'id' => 'selector-1',
            'name' => '.card',
            'selector' => '.card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => [],
        ]], ['Imported HTML']);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$[0].selector', 'no importer-only selector field');
        $this->assertError($result['errors'], '$[0].name', 'class name without leading dot');
    }

    public function testVariableValidationRequiresDynamicDataAndCssVariableNameWithoutLeadingDashes(): void
    {
        $result = (new OxygenSchemaValidator())->validateVariables([[
            'id' => 'var-1',
            'cssVariableName' => '--bad',
            'label' => 'Bad',
            'value' => '#000000',
            'type' => 'color',
            'collection' => 'Imported HTML',
        ]], ['Imported HTML']);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$[0].dynamicData', 'field present');
        $this->assertError($result['errors'], '$[0].cssVariableName', 'CSS variable name without leading --');
    }

    public function testGlobalTemplateBlockAndComponentValidationRejectInvalidShapes(): void
    {
        $validator = new OxygenSchemaValidator();

        $global = $validator->validateGlobalSettings(['settings' => ['colors' => 'bad']]);
        $this->assertFalse($global['valid']);
        $this->assertError($global['errors'], '$.settings.colors', 'object');

        $template = $validator->validateTemplateSettingsJson('{"type":"","ruleGroups":"bad","priority":"high"}');
        $this->assertFalse($template['valid']);
        $this->assertError($template['errors'], '$.type', 'non-empty string');
        $this->assertError($template['errors'], '$.ruleGroups', 'array');
        $this->assertError($template['errors'], '$.priority', 'integer');

        $block = $validator->validateBlockSettings(['preview' => 'bad']);
        $this->assertFalse($block['valid']);
        $this->assertError($block['errors'], '$.preview', 'object');

        $component = $validator->validateComponentInstance([
            'id' => 8,
            'data' => [
                'type' => 'OxygenElements\\Component',
                'properties' => [
                    'content' => [
                        'content' => [
                            'block' => [
                                'targets' => [['nodeId' => 2]],
                                'properties' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ]);
        $this->assertFalse($component['valid']);
        $this->assertError($component['errors'], '$.data.properties.content.content.block.componentId', 'integer');
        $this->assertError($component['errors'], '$.data.properties.content.content.block.targets[0].propertyKey', 'non-empty string');
        $this->assertError($component['errors'], '$.data.properties.content.content.block.targets[0].controlPath', 'non-empty string');
    }

    public function testGlobalSettingsValidationRejectsMalformedGradientPaletteRecords(): void
    {
        $result = (new OxygenSchemaValidator())->validateGlobalSettings([
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

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.settings.colors.palette.gradients[0].value.svgValue', 'non-empty string');
    }

    public function testTemplateSettingsValidationRejectsMalformedRuleGroups(): void
    {
        $settingsJson = wp_json_encode([
            'type' => 'all-singles',
            'ruleGroups' => [[[
                'operand' => 'is one of',
                'ruleCategorySlug' => 'singular',
                'ruleSlug' => 'post-type',
                'ruleDynamic' => '',
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]);
        $this->assertIsString($settingsJson);

        $result = (new OxygenSchemaValidator())->validateTemplateSettingsJson($settingsJson);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.ruleGroups[0][0].value', 'field required');
    }

    public function testOutputValidatorUsesOxygenSchemaErrorsForNodeShape(): void
    {
        $validator = new \OxyHtmlConverter\Validation\OutputValidator();

        $valid = $validator->validateElement([
            'id' => 1,
            'data' => [
                'type' => 'OxygenElements\\Container',
                'properties' => [
                    'native' => ['converterOnly' => true],
                ],
            ],
            'children' => [],
        ]);

        $this->assertFalse($valid);
        $this->assertStringContainsString('$.data.properties.native', implode("\n", $validator->getErrors()));
    }

    /**
     * @param list<array{path:string,expected:string,actual:string,remediation:string,message:string}> $errors
     */
    private function assertError(array $errors, string $path, string $expected): void
    {
        foreach ($errors as $error) {
            if ($error['path'] === $path && str_contains($error['expected'], $expected)) {
                $this->assertIsString($error['actual']);
                $this->assertIsString($error['remediation']);
                $this->assertIsString($error['message']);
                return;
            }
        }

        $this->fail('Expected schema error at ' . $path . ' containing expected text "' . $expected . '".');
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

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'oxygen6-contracts';
    }
}
