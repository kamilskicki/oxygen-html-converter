<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use Mockery;
use OxyHtmlConverter\Services\OxygenGlobalSettingsRepository;
use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Tests\TestCase;
use OxyHtmlConverter\Validation\OxygenSchemaValidator;

class OxygenGlobalSettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
    }

    public function testSaveFromPayloadMergesExplicitSettingsAndTokenPalette(): void
    {
        update_option(OxygenGlobalSettingsRepository::OPTION_NAME, wp_json_encode([
            'settings' => [
                'colors' => [
                    'brand' => '#111111',
                    'palette' => [
                        'colors' => [[
                            'label' => 'Existing',
                            'cssVariableName' => 'existing',
                            'value' => '#222222',
                        ]],
                    ],
                ],
                'typography' => [
                    'body' => ['fontSize' => '16px'],
                ],
            ],
        ]));

        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'options' => [
                'inferDormantGlobalSettingsFromTokens' => true,
            ],
            'oxygenGlobalSettings' => [
                'settings' => [
                    'typography' => [
                        'headings' => ['fontWeight' => '700'],
                    ],
                    'code' => [
                        'stylesheets' => [[
                            'name' => 'Imported root custom properties',
                            'code' => ':root { --ohc-radius: 12px; }',
                        ]],
                        'scripts' => [],
                    ],
                    'other' => [
                        'transition_duration' => [
                            'number' => 180,
                            'unit' => 'ms',
                            'style' => '180ms',
                        ],
                    ],
                ],
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
        ]);

        $this->assertTrue($result['saved']);
        $this->assertContains('colors', $result['sections']);
        $this->assertContains('typography', $result['sections']);
        $this->assertContains('code', $result['sections']);
        $this->assertContains('other', $result['sections']);

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $this->assertSame('#111111', $settings['settings']['colors']['brand']);
        $this->assertSame('16px', $settings['settings']['typography']['body']['fontSize']);
        $this->assertSame('700', $settings['settings']['typography']['headings']['fontWeight']);
        $this->assertSame(':root { --ohc-radius: 12px; }', $settings['settings']['code']['stylesheets'][0]['code']);
        $this->assertSame('180ms', $settings['settings']['other']['transition_duration']['style']);
        $this->assertSame(['existing', 'ohc-color-731b19'], array_column($settings['settings']['colors']['palette']['colors'], 'cssVariableName'));
    }

    public function testSaveFromPayloadDelegatesGlobalSettingsWriteToStorageAdapter(): void
    {
        $adapter = Mockery::mock(OxygenStorageAdapter::class);
        $adapter->shouldReceive('writeGlobalSettings')
            ->once()
            ->withArgs(function (array $settings): bool {
                $this->assertSame('700', $settings['settings']['typography']['headings']['fontWeight']);
                $this->assertSame(
                    ['ohc-color-731b19'],
                    array_column($settings['settings']['colors']['palette']['colors'], 'cssVariableName')
                );

                return true;
            })
            ->andReturn([
                'success' => true,
                'cacheRegenerated' => true,
            ]);

        $result = (new OxygenGlobalSettingsRepository($adapter))->saveFromPayload([
            'options' => [
                'inferDormantGlobalSettingsFromTokens' => true,
            ],
            'oxygenGlobalSettings' => [
                'settings' => [
                    'typography' => [
                        'headings' => ['fontWeight' => '700'],
                    ],
                ],
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
        ]);

        $this->assertTrue($result['saved']);
        $this->assertTrue($result['cacheRegenerated']);
    }

    public function testSaveFromPayloadInfersExpandedGlobalSettingsFromDesignDocumentTokens(): void
    {
        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'options' => [
                'inferDormantGlobalSettingsFromTokens' => true,
            ],
            'designDocument' => [
                'tokens' => [
                    'colors' => [
                        [
                            'value' => '#731B19',
                            'uses' => 3,
                            'suggestedName' => 'color-primary',
                        ],
                        [
                            'value' => 'linear-gradient(135deg, #731B19 0%, #14B8A6 100%)',
                            'uses' => 1,
                            'suggestedName' => 'gradient-hero-gradient',
                        ],
                    ],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 2,
                        'suggestedName' => 'font-inter',
                    ]],
                    'spacing' => [
                        [
                            'value' => '96px',
                            'uses' => 1,
                            'suggestedName' => 'space-section-y',
                        ],
                        [
                            'value' => '24px',
                            'uses' => 3,
                            'suggestedName' => 'space-page-x',
                        ],
                        [
                            'value' => '32px',
                            'uses' => 2,
                            'suggestedName' => 'space-column-gap',
                        ],
                    ],
                    'measurements' => [
                        [
                            'value' => '16px',
                            'uses' => 1,
                            'suggestedName' => 'measure-base-size',
                        ],
                        [
                            'value' => '1120px',
                            'uses' => 1,
                            'suggestedName' => 'measure-container-width',
                        ],
                    ],
                    'numbers' => [[
                        'value' => '1.2',
                        'uses' => 1,
                        'suggestedName' => 'ratio-major-second',
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertContains('colors', $result['sections']);
        $this->assertContains('typography', $result['sections']);
        $this->assertContains('containers', $result['sections']);
        $this->assertContains('code', $result['sections']);

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $validation = (new OxygenSchemaValidator())->validateGlobalSettings($settings);
        $this->assertTrue($validation['valid'], wp_json_encode($validation['errors']));

        $this->assertSame(['ohc-color-primary'], array_column($settings['settings']['colors']['palette']['colors'], 'cssVariableName'));
        $this->assertSame(['ohc-gradient-hero-gradient'], array_column($settings['settings']['colors']['palette']['gradients'], 'cssVariableName'));
        $this->assertSame('linear-gradient(135deg, #731B19 0%, #14B8A6 100%)', $settings['settings']['colors']['palette']['gradients'][0]['value']['value']);
        $this->assertStringContainsString('<linearGradient', $settings['settings']['colors']['palette']['gradients'][0]['value']['svgValue']);
        $this->assertSame('Inter', $settings['settings']['typography']['body_font']);
        $this->assertSame('16px', $settings['settings']['typography']['base_size']['style']);
        $this->assertSame(1.2, $settings['settings']['typography']['ratio']);
        $this->assertSame('ohc-body', $settings['settings']['typography']['global_typography']['typography_presets'][0]['preset']['id']);
        $this->assertSame('1120px', $settings['settings']['containers']['sections']['container_width']['style']);
        $this->assertSame('96px', $settings['settings']['containers']['sections']['vertical_padding']['style']);
        $this->assertSame('24px', $settings['settings']['containers']['sections']['horizontal_padding']['style']);
        $this->assertSame('32px', $settings['settings']['containers']['column_gap']['style']);
        $this->assertSame([], $settings['settings']['code']['stylesheets']);
        $this->assertSame([], $settings['settings']['code']['scripts']);
    }

    public function testSaveFromPayloadKeepsTokenOnlyGlobalSettingsDormantByDefault(): void
    {
        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 3,
                        'suggestedName' => 'color-primary',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 2,
                        'suggestedName' => 'font-inter',
                    ]],
                ],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame('no_global_settings_or_tokens', $result['skippedReason']);
        $this->assertArrayNotHasKey(OxygenGlobalSettingsRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }

    public function testSaveFromPayloadPreservesExistingSettingsUnlessOverwriteIsApproved(): void
    {
        update_option(OxygenGlobalSettingsRepository::OPTION_NAME, wp_json_encode([
            'settings' => [
                'colors' => [
                    'palette' => [
                        'colors' => [[
                            'label' => 'Primary',
                            'cssVariableName' => 'ohc-color-primary',
                            'value' => '#000000',
                        ]],
                        'gradients' => [[
                            'label' => 'Hero Gradient',
                            'cssVariableName' => 'ohc-gradient-hero-gradient',
                            'value' => [
                                'value' => 'linear-gradient(90deg, #000000 0%, #111111 100%)',
                                'svgValue' => '<symbol id="%%GRADIENTID%%"></symbol>',
                            ],
                        ]],
                    ],
                ],
                'typography' => [
                    'body_font' => 'Existing Sans',
                ],
                'containers' => [
                    'sections' => [
                        'container_width' => [
                            'number' => 960,
                            'unit' => 'px',
                            'style' => '960px',
                        ],
                    ],
                ],
            ],
        ]));

        $payload = [
            'options' => [
                'inferDormantGlobalSettingsFromTokens' => true,
            ],
            'designDocument' => [
                'tokens' => [
                    'colors' => [
                        [
                            'value' => '#731B19',
                            'uses' => 3,
                            'suggestedName' => 'color-primary',
                        ],
                        [
                            'value' => 'linear-gradient(135deg, #731B19 0%, #14B8A6 100%)',
                            'uses' => 1,
                            'suggestedName' => 'gradient-hero-gradient',
                        ],
                    ],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 2,
                        'suggestedName' => 'font-inter',
                    ]],
                    'measurements' => [[
                        'value' => '1120px',
                        'uses' => 1,
                        'suggestedName' => 'measure-container-width',
                    ]],
                ],
            ],
        ];

        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload($payload);
        $this->assertTrue($result['saved']);

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $this->assertSame('#000000', $settings['settings']['colors']['palette']['colors'][0]['value']);
        $this->assertSame('linear-gradient(90deg, #000000 0%, #111111 100%)', $settings['settings']['colors']['palette']['gradients'][0]['value']['value']);
        $this->assertSame('Existing Sans', $settings['settings']['typography']['body_font']);
        $this->assertSame('960px', $settings['settings']['containers']['sections']['container_width']['style']);

        $payload['overwriteGlobalSettings'] = true;
        (new OxygenGlobalSettingsRepository())->saveFromPayload($payload);

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $this->assertSame('#731B19', $settings['settings']['colors']['palette']['colors'][0]['value']);
        $this->assertSame('linear-gradient(135deg, #731B19 0%, #14B8A6 100%)', $settings['settings']['colors']['palette']['gradients'][0]['value']['value']);
        $this->assertSame('Inter', $settings['settings']['typography']['body_font']);
        $this->assertSame('1120px', $settings['settings']['containers']['sections']['container_width']['style']);
    }

    public function testSaveFromPayloadUsesImportPlanGlobalSettingsHandoff(): void
    {
        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'importPlan' => [
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
            ],
        ]);

        $this->assertTrue($result['saved']);

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $this->assertSame('linear-gradient(135deg, #731B19 0%, #14B8A6 100%)', $settings['settings']['colors']['palette']['gradients'][0]['value']['value']);
        $this->assertSame(':root { --ohc-radius: 12px; }', $settings['settings']['code']['stylesheets'][0]['code']);
    }

    public function testSaveFromPayloadSkipsWhenNoSettingsOrDesignTokensExist(): void
    {
        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame('no_global_settings_or_tokens', $result['skippedReason']);
        $this->assertArrayNotHasKey(OxygenGlobalSettingsRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }
}
