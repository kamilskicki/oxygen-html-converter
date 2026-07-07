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

    public function testMinimalComponentInstanceValidatesAndRejectsStaticChildren(): void
    {
        $validator = new OxygenSchemaValidator();
        $componentNode = [
            'id' => 8,
            'data' => [
                'type' => 'OxygenElements\\Component',
                'properties' => [
                    'content' => [
                        'content' => [
                            'block' => [
                                'componentId' => 42,
                                'targets' => [],
                                'properties' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];

        $this->assertTrue($validator->validateComponentInstance($componentNode)['valid']);

        $componentNode['children'][] = [
            'id' => 9,
            'data' => [
                'type' => 'OxygenElements\\Text',
                'properties' => [],
            ],
            'children' => [],
        ];
        $result = $validator->validateComponentInstance($componentNode);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.children', 'empty array');
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
            'status' => '',
        ]);

        $this->assertFalse($result['valid']);
        $errors = $result['errors'];
        $this->assertError($errors, '$.status', 'non-empty string');
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

    public function testSelectorValidationRejectsUnknownNativePathsButAllowsCustomCssFallback(): void
    {
        $validator = new OxygenSchemaValidator();

        $valid = $validator->validateSelectors([[
            'id' => 'selector-1',
            'name' => 'card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'layout' => [
                        'display' => 'flex',
                        'flex_align' => [
                            'primary_axis' => 'center',
                            'cross_axis' => 'flex-start',
                        ],
                        'gap' => [
                            'row' => ['number' => 24, 'unit' => 'px', 'style' => '24px'],
                            'column' => ['number' => 12, 'unit' => 'px', 'style' => '12px'],
                        ],
                    ],
                    'typography' => [
                        'style' => [
                            'font_style' => 'italic',
                        ],
                    ],
                    'spacing' => [
                        'spacing' => [
                            'margin' => [
                                'top' => ['number' => 1, 'unit' => 'rem', 'style' => '1rem'],
                                'right' => ['number' => 2, 'unit' => 'rem', 'style' => '2rem'],
                                'bottom' => ['number' => 1, 'unit' => 'rem', 'style' => '1rem'],
                                'left' => ['number' => 2, 'unit' => 'rem', 'style' => '2rem'],
                            ],
                            'padding' => [
                                'top' => ['number' => 16, 'unit' => 'px', 'style' => '16px'],
                                'right' => ['number' => 20, 'unit' => 'px', 'style' => '20px'],
                                'bottom' => ['number' => 16, 'unit' => 'px', 'style' => '16px'],
                                'left' => ['number' => 20, 'unit' => 'px', 'style' => '20px'],
                            ],
                        ],
                    ],
                    'custom_css' => [
                        'custom_css' => ':selector { scroll-margin-top: 80px; }',
                    ],
                ],
            ],
        ], [
            'id' => 'selector-legacy-flat-gap',
            'name' => 'legacy-flat-gap',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'layout' => [
                        'justify_content' => 'center',
                        'align_items' => 'stretch',
                        'gap' => ['number' => 18, 'unit' => 'px', 'style' => '18px'],
                    ],
                    'spacing' => [
                        'spacing' => [
                            'padding' => [
                                'top' => '18px',
                                'bottom' => '18px',
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'id' => 'selector-legacy-string-gap',
            'name' => 'legacy-string-gap',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'layout' => [
                        'gap' => '24px',
                    ],
                ],
            ],
        ]], ['Imported HTML']);

        $this->assertTrue($valid['valid'], implode("\n", array_column($valid['errors'], 'message')));

        $invalid = $validator->validateSelectors([[
            'id' => 'selector-2',
            'name' => 'bad-card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'layout' => [
                        'unsupported_justify' => 'center',
                        'flex_align' => [
                            'content_axis' => 'space-between',
                        ],
                    ],
                    'typography' => [
                        'font_style' => 'italic',
                    ],
                    'custom_css' => [
                        'scroll_margin_top' => '80px',
                    ],
                ],
            ],
        ]], ['Imported HTML']);

        $this->assertFalse($invalid['valid']);
        $this->assertError($invalid['errors'], '$[0].properties.breakpoint_base.layout.unsupported_justify', 'known Oxygen selector property path');
        $this->assertError($invalid['errors'], '$[0].properties.breakpoint_base.layout.flex_align.content_axis', 'known Oxygen selector property path');
        $this->assertError($invalid['errors'], '$[0].properties.breakpoint_base.typography.font_style', 'known Oxygen selector property path');
        $this->assertError($invalid['errors'], '$[0].properties.breakpoint_base.custom_css.scroll_margin_top', 'known Oxygen selector property path');
    }

    public function testSelectorValidationRejectsUnsupportedBreakpointAndNestedChildShapes(): void
    {
        $result = (new OxygenSchemaValidator())->validateSelectors([[
            'id' => 'selector-1',
            'name' => 'card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'children' => [
                [
                    'id' => 'selector-1-hover',
                    'name' => ':hover',
                    'pseudo' => true,
                    'properties' => [
                        '@media (max-width: 767px)' => [
                            'typography' => [
                                'color' => '#2563EBFF',
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => 'selector-1-title',
                    'name' => '.title',
                    'properties' => [
                        'breakpoint_base' => [
                            'typography' => [
                                'font_weight' => 700,
                            ],
                        ],
                    ],
                ],
            ],
            'properties' => [
                'Phone Landscape' => [
                    'spacing' => [
                        'spacing' => [
                            'padding' => [
                                'top' => ['number' => 12, 'unit' => 'px', 'style' => '12px'],
                            ],
                        ],
                    ],
                ],
            ],
        ]], ['Imported HTML']);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$[0].properties.Phone Landscape', 'known Oxygen selector property path');
        $this->assertError($result['errors'], '$[0].children[0].children', 'id, name, locked, properties, or pseudo');
        $this->assertError($result['errors'], '$[0].children[0].name', 'same-element pseudo selector prefixed with &');
        $this->assertError($result['errors'], '$[0].children[0].properties.@media (max-width: 767px)', 'known Oxygen selector property path');
        $this->assertError($result['errors'], '$[0].children[1].name', 'nested selector name prefixed with &');
    }

    public function testVariableValidationAllowsMissingDynamicDataAndRejectsCssVariableNameWithLeadingDashes(): void
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
        $this->assertError($result['errors'], '$[0].cssVariableName', 'CSS variable name without leading --');
    }

    public function testVariableValidationRejectsMalformedTypeSpecificValues(): void
    {
        $result = (new OxygenSchemaValidator())->validateVariables([
            [
                'id' => 'var-image',
                'cssVariableName' => 'ohc-image',
                'label' => 'Image',
                'value' => ['src' => 'https://example.test/image.jpg'],
                'type' => 'image_url',
                'dynamicData' => null,
                'collection' => 'Imported HTML',
                'extra' => true,
            ],
            [
                'id' => 'var-unit',
                'cssVariableName' => 'ohc-unit',
                'label' => 'Unit',
                'value' => '24px',
                'type' => 'unit',
                'dynamicData' => null,
                'collection' => 'Missing Collection',
            ],
            [
                'id' => 'var-number',
                'cssVariableName' => 'ohc-number',
                'label' => 'Number',
                'value' => '1.25',
                'type' => 'number',
                'dynamicData' => null,
                'collection' => 'Imported HTML',
            ],
            [
                'id' => 'var-shadow',
                'cssVariableName' => 'ohc-shadow',
                'label' => 'Shadow',
                'value' => '0 10px 30px #000',
                'type' => 'box_shadow',
                'dynamicData' => null,
                'collection' => 'Imported HTML',
            ],
        ], ['Imported HTML']);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$[0].extra', 'no additional Oxygen variable fields');
        $this->assertError($result['errors'], '$[0].dynamicData', 'object when present');
        $this->assertError($result['errors'], '$[0].value.url', 'non-empty string');
        $this->assertError($result['errors'], '$[1].value', 'measurement object');
        $this->assertError($result['errors'], '$[1].collection', 'known variable collection');
        $this->assertError($result['errors'], '$[2].value', 'number');
        $this->assertError($result['errors'], '$[3].type', 'supported Oxygen variable type');
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

        $editable = $validator->validateNode([
            'id' => 2,
            'data' => [
                'type' => 'OxygenElements\\Text',
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => 'Editable text',
                        ],
                    ],
                    'meta' => [
                        'component' => [
                            'editableProperties' => [[
                                'enabled' => 'yes',
                                'label' => '',
                                'controlPath' => '',
                                'propertyKey' => '',
                            ]],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ]);
        $this->assertFalse($editable['valid']);
        $this->assertError($editable['errors'], '$.data.properties.meta.component.editableProperties[0].enabled', 'boolean');
        $this->assertError($editable['errors'], '$.data.properties.meta.component.editableProperties[0].label', 'non-empty string');
        $this->assertError($editable['errors'], '$.data.properties.meta.component.editableProperties[0].controlPath', 'non-empty string');
        $this->assertError($editable['errors'], '$.data.properties.meta.component.editableProperties[0].propertyKey', 'non-empty string');
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

    public function testGlobalSettingsValidationRejectsMalformedOtherSection(): void
    {
        $result = (new OxygenSchemaValidator())->validateGlobalSettings([
            'settings' => [
                'other' => 'bad',
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.settings.other', 'object');
    }

    public function testGlobalSettingsValidationAcceptsRuntimeTypographyPresetReference(): void
    {
        $result = (new OxygenSchemaValidator())->validateGlobalSettings([
            'settings' => [
                'typography' => [
                    'global_typography' => [
                        'typography_presets' => [[
                            'preset' => [
                                'label' => 'Body',
                                'id' => 'ohc-body',
                            ],
                            'custom' => [
                                'customTypography' => [],
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['valid'], wp_json_encode($result['errors']));
    }

    public function testGlobalSettingsValidationRejectsRuntimeTypographyPresetWithoutId(): void
    {
        $result = (new OxygenSchemaValidator())->validateGlobalSettings([
            'settings' => [
                'typography' => [
                    'global_typography' => [
                        'typography_presets' => [[
                            'preset' => [
                                'label' => 'Body',
                            ],
                            'custom' => [
                                'customTypography' => [],
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.settings.typography.global_typography.typography_presets[0].preset.id', 'non-empty string');
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

    public function testTemplateSettingsValidationAcceptsMilestoneFiveConditionExamples(): void
    {
        $validator = new OxygenSchemaValidator();
        $examples = [
            'site-wide' => [
                'type' => 'everywhere',
                'ruleGroups' => [],
                'triggers' => [],
                'priority' => 1,
                'fallback' => false,
            ],
            'singular-post' => [
                'type' => 'all-singles',
                'ruleGroups' => [[[
                    'operand' => 'is one of',
                    'ruleCategorySlug' => 'singular',
                    'ruleSlug' => 'post-type',
                    'ruleDynamic' => '',
                    'value' => ['post'],
                ]]],
                'triggers' => [],
                'priority' => 10,
                'fallback' => false,
            ],
            'singular-page-type' => [
                'type' => 'page',
                'ruleGroups' => [],
                'triggers' => [],
                'priority' => 20,
                'fallback' => false,
            ],
            'front-page' => [
                'type' => 'front-page',
                'ruleGroups' => [],
                'triggers' => [],
                'priority' => 20,
                'fallback' => false,
            ],
            'archive' => [
                'type' => 'all-archives',
                'ruleGroups' => [[[
                    'operand' => 'is',
                    'ruleCategorySlug' => 'archive',
                    'ruleSlug' => 'post-type-archive',
                    'value' => ['post'],
                ]]],
                'triggers' => [],
                'priority' => 10,
                'fallback' => false,
            ],
            'parented-fallback-triggered' => [
                'parentId' => 42,
                'type' => 'post-type-archive',
                'ruleGroups' => [[[
                    'operand' => 'is',
                    'ruleCategorySlug' => 'archive',
                    'ruleSlug' => 'post-type-archive',
                    'value' => [['text' => 'Posts', 'value' => 'post']],
                ]]],
                'triggers' => [[
                    'slug' => 'click',
                    'options' => [
                        'selector' => '.site-menu-toggle',
                        'limit' => 1,
                    ],
                ]],
                'priority' => 30,
                'fallback' => true,
                'disabled' => false,
            ],
        ];

        foreach ($examples as $label => $settings) {
            $settingsJson = wp_json_encode($settings);
            $this->assertIsString($settingsJson);

            $result = $validator->validateTemplateSettingsJson($settingsJson);

            $this->assertTrue($result['valid'], $label . ': ' . wp_json_encode($result['errors']));
        }
    }

    public function testTemplateSettingsValidationRejectsInvalidTypesRuleValuesAndTriggers(): void
    {
        $settingsJson = wp_json_encode([
            'parentId' => 0,
            'type' => 'bad type',
            'ruleGroups' => [[[
                'operand' => 'is one of',
                'ruleSlug' => 'post-type',
                'value' => ['post', ['value' => 'page']],
            ]]],
            'triggers' => [[
                'slug' => 'launch',
                'options' => [
                    'selector' => 123,
                    'limit' => 'once',
                ],
            ]],
            'priority' => 10,
            'fallback' => false,
        ]);
        $this->assertIsString($settingsJson);

        $result = (new OxygenSchemaValidator())->validateTemplateSettingsJson($settingsJson);

        $this->assertFalse($result['valid']);
        $this->assertError($result['errors'], '$.parentId', 'integer >= 1');
        $this->assertError($result['errors'], '$.type', 'registered template type slug');
        $this->assertError($result['errors'], '$.ruleGroups[0][0].value', 'all strings or all objects with string value');
        $this->assertError($result['errors'], '$.triggers[0].slug', 'registered template trigger slug');
        $this->assertError($result['errors'], '$.triggers[0].options.selector', 'string');
        $this->assertError($result['errors'], '$.triggers[0].options.limit', 'integer');
    }

    public function testTemplateSettingsValidationRejectsUnknownConditionSlugWrongOperandAndTypePairing(): void
    {
        $validator = new OxygenSchemaValidator();

        $unknownConditionJson = wp_json_encode([
            'type' => 'all-singles',
            'ruleGroups' => [[[
                'operand' => 'is',
                'ruleSlug' => 'not-a-real-condition',
                'value' => 'post',
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]);
        $wrongOperandJson = wp_json_encode([
            'type' => 'post-type-archive',
            'ruleGroups' => [[[
                'operand' => 'is one of',
                'ruleCategorySlug' => 'archive',
                'ruleSlug' => 'post-type-archive',
                'value' => ['post'],
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]);
        $wrongTypeJson = wp_json_encode([
            'type' => 'front-page',
            'ruleGroups' => [[[
                'operand' => 'is',
                'ruleCategorySlug' => 'archive',
                'ruleSlug' => 'post-type-archive',
                'value' => ['post'],
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]);
        $this->assertIsString($unknownConditionJson);
        $this->assertIsString($wrongOperandJson);
        $this->assertIsString($wrongTypeJson);

        $unknownCondition = $validator->validateTemplateSettingsJson($unknownConditionJson);
        $wrongOperand = $validator->validateTemplateSettingsJson($wrongOperandJson);
        $wrongType = $validator->validateTemplateSettingsJson($wrongTypeJson);

        $this->assertFalse($unknownCondition['valid']);
        $this->assertError($unknownCondition['errors'], '$.ruleGroups[0][0].ruleSlug', 'registered template condition slug');
        $this->assertFalse($wrongOperand['valid']);
        $this->assertError($wrongOperand['errors'], '$.ruleGroups[0][0].operand', 'operand allowed for template condition');
        $this->assertFalse($wrongType['valid']);
        $this->assertError($wrongType['errors'], '$.ruleGroups[0][0].ruleSlug', 'condition available for template type');
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
