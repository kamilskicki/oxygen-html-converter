<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use Mockery;
use OxyHtmlConverter\Services\OxygenGlobalSettingsRepository;
use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Tests\TestCase;

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
            'oxygenGlobalSettings' => [
                'settings' => [
                    'typography' => [
                        'headings' => ['fontWeight' => '700'],
                    ],
                    'code' => [
                        'head' => '<meta name="x" content="y">',
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

        $settings = json_decode((string) $GLOBALS['__wp_options'][OxygenGlobalSettingsRepository::OPTION_NAME], true);
        $this->assertSame('#111111', $settings['settings']['colors']['brand']);
        $this->assertSame('16px', $settings['settings']['typography']['body']['fontSize']);
        $this->assertSame('700', $settings['settings']['typography']['headings']['fontWeight']);
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

    public function testSaveFromPayloadSkipsWhenNoSettingsOrColorTokensExist(): void
    {
        $result = (new OxygenGlobalSettingsRepository())->saveFromPayload([
            'designDocument' => [
                'tokens' => [
                    'spacing' => [[
                        'value' => '24px',
                        'suggestedName' => 'space-24px',
                    ]],
                ],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame('no_global_settings_or_color_tokens', $result['skippedReason']);
        $this->assertArrayNotHasKey(OxygenGlobalSettingsRepository::OPTION_NAME, $GLOBALS['__wp_options']);
    }
}
