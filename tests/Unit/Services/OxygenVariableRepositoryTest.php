<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use Mockery;
use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Services\OxygenVariableRepository;
use OxyHtmlConverter\Tests\TestCase;

class OxygenVariableRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
    }

    public function testSaveFromPayloadPersistsDetectedTokensAsOxygenVariables(): void
    {
        $repository = new OxygenVariableRepository();

        $result = $repository->saveFromPayload($this->payloadFixture());

        $this->assertTrue($result['saved']);
        $this->assertSame(3, $result['changes']);
        $this->assertSame(3, $result['created']);
        $this->assertSame([
            'Imported HTML Colors',
            'Imported HTML Spacing',
            'Imported HTML Fonts',
        ], $result['collections']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertCount(3, $variables);
        $this->assertSame(['color', 'unit', 'font_family'], array_column($variables, 'type'));
        $this->assertSame('ohc-color-731b19', $variables[0]['cssVariableName']);
        $this->assertSame('#731B19', $variables[0]['value']);
        $this->assertArrayNotHasKey('dynamicData', $variables[0]);
        $this->assertSame(['number' => 24, 'unit' => 'px', 'style' => '24px'], $variables[1]['value']);
        $this->assertSame('Inter', $variables[2]['value']);
    }

    public function testSaveFromPayloadPersistsExpandedTokenCoverageAndDynamicData(): void
    {
        $result = (new OxygenVariableRepository())->saveFromPayload([
            'importPlan' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#2563eb',
                        'uses' => 3,
                        'suggestedName' => 'color-primary',
                        'dynamicData' => [
                            'path' => 'post.meta.brand_color',
                            'fallback' => '#2563eb',
                        ],
                    ]],
                    'spacing' => [[
                        'value' => '24px',
                        'uses' => 4,
                        'suggestedName' => 'space-24px',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 2,
                        'suggestedName' => 'font-inter',
                    ]],
                    'images' => [[
                        'value' => 'https://example.test/wp-content/uploads/hero.jpg',
                        'uses' => 1,
                        'suggestedName' => 'hero-image',
                    ]],
                    'measurements' => [[
                        'value' => '18px',
                        'uses' => 5,
                        'suggestedName' => 'measure-body-font-size',
                    ]],
                    'numbers' => [[
                        'value' => '1.25',
                        'uses' => 1,
                        'suggestedName' => 'ratio-card',
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame(6, $result['created']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertIsArray($variables);
        $this->assertSame(['color', 'unit', 'font_family', 'image_url', 'unit', 'number'], array_column($variables, 'type'));

        foreach ($variables as $variable) {
            foreach (['id', 'cssVariableName', 'label', 'value', 'type', 'collection'] as $field) {
                $this->assertArrayHasKey($field, $variable);
            }
            $this->assertStringStartsNotWith('--', $variable['cssVariableName']);
        }

        $this->assertSame(
            ['path' => 'post.meta.brand_color', 'fallback' => '#2563eb'],
            $variables[0]['dynamicData']
        );
        $this->assertSame(['url' => 'https://example.test/wp-content/uploads/hero.jpg'], $variables[3]['value']);
        $this->assertSame(['number' => 18, 'unit' => 'px', 'style' => '18px'], $variables[4]['value']);
        $this->assertSame(1.25, $variables[5]['value']);
        $this->assertContains('Imported HTML Images', $result['collections']);
        $this->assertContains('Imported HTML Measurements', $result['collections']);
        $this->assertContains('Imported HTML Numbers', $result['collections']);
    }

    public function testBuildTokenReferencesExposeDeterministicVariableBindings(): void
    {
        $references = (new OxygenVariableRepository())->buildTokenReferencesFromPayload([
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 2,
                        'suggestedName' => 'color-primary',
                    ]],
                    'spacing' => [[
                        'value' => '24px',
                        'uses' => 3,
                        'suggestedName' => 'space-card',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 1,
                        'suggestedName' => 'font-body',
                    ]],
                    'images' => [[
                        'value' => 'https://example.test/assets/hero.jpg',
                        'uses' => 1,
                        'suggestedName' => 'image-hero',
                    ]],
                ],
            ],
        ]);

        $this->assertCount(4, $references['items']);
        $this->assertSame(4, $references['summary']['supported']);
        $this->assertSame('ohc-color-primary', $references['items'][0]['cssVariableName']);
        $this->assertSame(
            'ohc-var-' . substr(sha1('color:ohc-color-primary'), 0, 16),
            $references['items'][0]['variableId']
        );
        $this->assertSame('var(--ohc-color-primary)', $references['items'][0]['cssReference']);
        $this->assertSame('{var-' . $references['items'][0]['variableId'] . '}', $references['items'][0]['selectorReference']);
        $this->assertStringStartsNotWith('--', $references['items'][0]['cssVariableName']);
        $this->assertSame('image_url', $references['items'][3]['variableType']);
        $this->assertSame('https://example.test/assets/hero.jpg', $references['items'][3]['normalizedValue']);
    }

    public function testUnsupportedTokenTypesAreReportedAndNotPersisted(): void
    {
        $result = (new OxygenVariableRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [
                    'images' => [[
                        'value' => 'javascript:alert(1)',
                        'uses' => 1,
                        'suggestedName' => 'bad-image',
                    ]],
                    'shadows' => [[
                        'value' => '0 10px 30px rgba(0,0,0,.2)',
                        'uses' => 1,
                        'suggestedName' => 'shadow-card',
                    ]],
                ],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame(2, $result['skipped']['proposed']);
        $this->assertSame(0, $result['skipped']['persistable']);
        $this->assertSame(2, $result['skipped']['unsupported']);
        $this->assertSame(['images', 'shadows'], array_column($result['skipped']['items'], 'group'));
        $this->assertArrayNotHasKey(OxygenVariableRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }

    public function testSaveFromPayloadDelegatesVariableWriteToStorageAdapter(): void
    {
        $adapter = Mockery::mock(OxygenStorageAdapter::class);
        $adapter->shouldReceive('writeVariables')
            ->once()
            ->withArgs(function (array $variables, array $collections): bool {
                $this->assertCount(3, $variables);
                $this->assertSame([
                    'Imported HTML Colors',
                    'Imported HTML Spacing',
                    'Imported HTML Fonts',
                ], $collections);

                foreach ($variables as $variable) {
                    $this->assertArrayNotHasKey('dynamicData', $variable);
                }

                return true;
            })
            ->andReturn([
                'success' => true,
                'cacheRegenerated' => true,
            ]);

        $result = (new OxygenVariableRepository($adapter))->saveFromPayload($this->payloadFixture());

        $this->assertTrue($result['saved']);
        $this->assertTrue($result['cacheRegenerated']);
    }

    public function testSaveFromPayloadIsIdempotent(): void
    {
        $repository = new OxygenVariableRepository();

        $repository->saveFromPayload($this->payloadFixture());
        $result = $repository->saveFromPayload($this->payloadFixture());

        $this->assertFalse($result['saved']);
        $this->assertSame(0, $result['changes']);
        $this->assertSame(3, $result['total']);
    }

    public function testExistingUserVariableWithSameNameIsLinkedNotOverwritten(): void
    {
        update_option(OxygenVariableRepository::OPTION_NAME, wp_json_encode([[
            'id' => 'user-owned',
            'type' => 'color',
            'label' => 'Color 731b19',
            'cssVariableName' => 'ohc-color-731b19',
            'collection' => 'User Tokens',
            'value' => '#000000',
        ]]));

        $result = (new OxygenVariableRepository())->saveFromPayload($this->payloadFixture());

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['linkedExisting']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertSame('#000000', $variables[0]['value']);
    }

    public function testMalformedExistingVariablesAreNotCarriedIntoNextWrite(): void
    {
        update_option(OxygenVariableRepository::OPTION_NAME, wp_json_encode([
            [
                'id' => 'valid-existing',
                'cssVariableName' => 'ohc-existing',
                'label' => 'Existing',
                'value' => '#111111',
                'type' => 'color',
                'dynamicData' => null,
                'collection' => 'Imported HTML Colors',
            ],
            [
                'id' => 'malformed-missing-fields',
                'type' => 'color',
                'cssVariableName' => 'ohc-malformed-missing-fields',
            ],
            [
                'id' => 'malformed-empty-color',
                'cssVariableName' => 'ohc-malformed-empty-color',
                'label' => 'Malformed empty color',
                'value' => '',
                'type' => 'color',
                'dynamicData' => null,
                'collection' => 'Imported HTML Colors',
            ],
            [
                'id' => 'malformed-unsupported-type',
                'cssVariableName' => 'ohc-malformed-unsupported-type',
                'label' => 'Malformed unsupported type',
                'value' => '0 10px 30px #000',
                'type' => 'box_shadow',
                'dynamicData' => null,
                'collection' => 'Imported HTML Effects',
            ],
            [
                'id' => 'malformed-unit',
                'cssVariableName' => 'ohc-malformed-unit',
                'label' => 'Malformed unit',
                'value' => '24px',
                'type' => 'unit',
                'dynamicData' => null,
                'collection' => 'Imported HTML Spacing',
            ],
            [
                'id' => 'malformed-extra',
                'cssVariableName' => 'ohc-malformed-extra',
                'label' => 'Malformed extra',
                'value' => '#222222',
                'type' => 'color',
                'dynamicData' => null,
                'collection' => 'Imported HTML Colors',
                'extra' => true,
            ],
            [
                'id' => 'malformed-image-url',
                'cssVariableName' => 'ohc-malformed-image-url',
                'label' => 'Malformed image URL',
                'value' => ['url' => 'javascript:alert(1)'],
                'type' => 'image_url',
                'dynamicData' => null,
                'collection' => 'Imported HTML Images',
            ],
        ]));

        $result = (new OxygenVariableRepository())->saveFromPayload($this->payloadFixture());

        $this->assertTrue($result['saved']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertCount(4, $variables);
        $this->assertContains('valid-existing', array_column($variables, 'id'));
        $this->assertNotContains('malformed-missing-fields', array_column($variables, 'id'));
        $this->assertNotContains('malformed-empty-color', array_column($variables, 'id'));
        $this->assertNotContains('malformed-unsupported-type', array_column($variables, 'id'));
        $this->assertNotContains('malformed-unit', array_column($variables, 'id'));
        $this->assertNotContains('malformed-extra', array_column($variables, 'id'));
        $this->assertNotContains('malformed-image-url', array_column($variables, 'id'));
    }

    public function testComplexUnitsUseCustomUnitShape(): void
    {
        $result = (new OxygenVariableRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [
                    'spacing' => [[
                        'value' => 'clamp(2rem, 4vw, 6rem)',
                        'uses' => 1,
                        'suggestedName' => 'space-fluid',
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($result['saved']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertSame('unit', $variables[0]['type']);
        $this->assertSame('custom', $variables[0]['value']['unit']);
        $this->assertSame('clamp(2rem, 4vw, 6rem)', $variables[0]['value']['style']);
    }

    public function testIconFontFamiliesAreNotPersistedAsBrandVariables(): void
    {
        $result = (new OxygenVariableRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [
                    'fonts' => [
                        [
                            'value' => 'Material Symbols Outlined',
                            'uses' => 1,
                            'suggestedName' => 'font-material-symbols-outlined',
                        ],
                        [
                            'value' => 'Inter',
                            'uses' => 1,
                            'suggestedName' => 'font-inter',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['skipped']['proposed']);
        $this->assertSame(1, $result['skipped']['persistable']);
        $this->assertSame(1, $result['skipped']['unsupported']);

        $variables = json_decode((string) $GLOBALS['__wp_options'][OxygenVariableRepository::OPTION_NAME], true);
        $this->assertSame(['ohc-font-inter'], array_column($variables, 'cssVariableName'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFixture(): array
    {
        return [
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
        ];
    }
}
