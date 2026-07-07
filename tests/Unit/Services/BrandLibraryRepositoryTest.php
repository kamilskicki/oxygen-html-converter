<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\BrandLibraryRepository;
use PHPUnit\Framework\TestCase;

class BrandLibraryRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
    }

    public function testSaveFromPayloadPersistsTokensAndComponentCandidates(): void
    {
        $result = (new BrandLibraryRepository())->saveFromPayload([
            'importPlan' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 3,
                        'suggestedName' => 'color-731b19',
                        'status' => 'proposed',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 1,
                        'suggestedName' => 'font-inter',
                    ]],
                    'spacing' => [[
                        'value' => '24px',
                        'uses' => 2,
                        'suggestedName' => 'space-24px',
                    ]],
                    'images' => [[
                        'value' => 'https://example.test/assets/hero.jpg',
                        'uses' => 1,
                        'suggestedName' => 'hero-image',
                    ]],
                    'measurements' => [[
                        'value' => '18px',
                        'uses' => 4,
                        'suggestedName' => 'measure-body-font-size',
                        'dynamicData' => [
                            'path' => 'post.meta.body_font_size',
                        ],
                    ]],
                    'numbers' => [[
                        'value' => '1.25',
                        'uses' => 1,
                        'suggestedName' => 'ratio-card',
                    ]],
                ],
                'components' => [[
                    'suggestedName' => 'card',
                    'signature' => 'div[h3,p]',
                    'occurrences' => 3,
                    'classes' => ['card'],
                ]],
            ],
            'designDocument' => [
                'oxygenGlobalSettings' => [
                    'settings' => [
                        'typography' => [
                            'body_font' => 'Inter',
                            'base_size' => [
                                'number' => 16,
                                'unit' => 'px',
                                'style' => '16px',
                            ],
                        ],
                        'containers' => [
                            'sections' => [
                                'container_width' => [
                                    'number' => 1120,
                                    'unit' => 'px',
                                    'style' => '1120px',
                                ],
                            ],
                        ],
                    ],
                ],
                'designProfile' => [
                    'version' => 1,
                    'semanticClasses' => [[
                        'sourceClass' => 'pricing-card',
                        'semanticClass' => 'ohc-card',
                    ]],
                    'elementApplications' => [[
                        'sourceClasses' => ['pricing-card'],
                        'appliedClasses' => ['ohc-card'],
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame(6, $result['tokenChanges']);
        $this->assertSame(1, $result['componentChanges']);
        $this->assertSame(1, $result['globalSettingsChanges']);
        $this->assertSame(1, $result['designProfileChanges']);
        $this->assertArrayHasKey(BrandLibraryRepository::OPTION_NAME, $GLOBALS['__wp_options']);

        $library = json_decode((string) $GLOBALS['__wp_options'][BrandLibraryRepository::OPTION_NAME], true);
        $this->assertSame('#731B19', $library['tokens']['colors'][0]['value']);
        $this->assertSame('Inter', $library['tokens']['fonts'][0]['value']);
        $this->assertSame('24px', $library['tokens']['spacing'][0]['value']);
        $this->assertSame('https://example.test/assets/hero.jpg', $library['tokens']['images'][0]['value']);
        $this->assertSame('18px', $library['tokens']['measurements'][0]['value']);
        $this->assertSame(['path' => 'post.meta.body_font_size'], $library['tokens']['measurements'][0]['dynamicData']);
        $this->assertSame('1.25', $library['tokens']['numbers'][0]['value']);
        $this->assertSame('card', $library['components'][0]['suggestedName']);
        $this->assertSame('Inter', $library['globalSettings']['settings']['typography']['body_font']);
        $this->assertSame('1120px', $library['globalSettings']['settings']['containers']['sections']['container_width']['style']);
        $this->assertSame('ohc-card', $library['designProfile']['semanticClasses'][0]['semanticClass']);
    }

    public function testSaveFromPayloadMergesRepeatedTokensAndComponents(): void
    {
        $repository = new BrandLibraryRepository();
        $payload = [
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 1,
                        'suggestedName' => 'color-731b19',
                    ]],
                ],
                'componentCandidates' => [[
                    'suggestedName' => 'card',
                    'signature' => 'div[h3,p]',
                    'count' => 2,
                    'classes' => ['card'],
                ]],
            ],
        ];

        $repository->saveFromPayload($payload);
        $repository->saveFromPayload($payload);

        $library = $repository->getLibrary();
        $this->assertCount(1, $library['tokens']['colors']);
        $this->assertSame(2, $library['tokens']['colors'][0]['uses']);
        $this->assertCount(1, $library['components']);
        $this->assertSame(4, $library['components'][0]['occurrences']);
    }

    public function testSaveFromPayloadDedupesDesignProfileEntriesAcrossRepeatedSaves(): void
    {
        $repository = new BrandLibraryRepository();
        $payload = [
            'designDocument' => [
                'designProfile' => [
                    'version' => 1,
                    'semanticClasses' => [[
                        'sourceClass' => 'pricing-card',
                        'semanticClass' => 'ohc-card',
                        'styleSignature' => 'color:#123456',
                    ]],
                    'duplicateStylePatterns' => [[
                        'semanticClass' => 'ohc-card',
                        'styleSignature' => 'color:#123456',
                        'sourceClasses' => ['pricing-card', 'feature-card'],
                    ]],
                    'elementApplications' => [[
                        'index' => 1,
                        'sourceClasses' => ['pricing-card'],
                        'appliedClasses' => ['ohc-card'],
                    ]],
                ],
            ],
        ];

        $repository->saveFromPayload($payload);
        $repository->saveFromPayload($payload);

        $library = $repository->getLibrary();
        $this->assertCount(1, $library['designProfile']['semanticClasses']);
        $this->assertCount(1, $library['designProfile']['duplicateStylePatterns']);
        $this->assertCount(1, $library['designProfile']['elementApplications']);
    }
}
