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
                ],
                'components' => [[
                    'suggestedName' => 'card',
                    'signature' => 'div[h3,p]',
                    'occurrences' => 3,
                    'classes' => ['card'],
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame(3, $result['tokenChanges']);
        $this->assertSame(1, $result['componentChanges']);
        $this->assertArrayHasKey(BrandLibraryRepository::OPTION_NAME, $GLOBALS['__wp_options']);

        $library = json_decode((string) $GLOBALS['__wp_options'][BrandLibraryRepository::OPTION_NAME], true);
        $this->assertSame('#731B19', $library['tokens']['colors'][0]['value']);
        $this->assertSame('Inter', $library['tokens']['fonts'][0]['value']);
        $this->assertSame('24px', $library['tokens']['spacing'][0]['value']);
        $this->assertSame('card', $library['components'][0]['suggestedName']);
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
}
