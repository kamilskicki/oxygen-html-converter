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
        $this->assertArrayHasKey('dynamicData', $variables[0]);
        $this->assertNull($variables[0]['dynamicData']);
        $this->assertSame(['number' => 24, 'unit' => 'px', 'style' => '24px'], $variables[1]['value']);
        $this->assertSame('Inter', $variables[2]['value']);
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
                    $this->assertArrayHasKey('dynamicData', $variable);
                    $this->assertNull($variable['dynamicData']);
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
        $this->assertSame(['proposed' => 2, 'persistable' => 1], $result['skipped']);

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
