<?php

namespace OxyHtmlConverter\Tests\Unit\Validation;

use OxyHtmlConverter\Validation\OutputValidator;
use PHPUnit\Framework\TestCase;

class OutputValidatorTest extends TestCase
{
    public function testEssentialButtonContractWarningWhenLinkPathIsMissing(): void
    {
        $validator = new OutputValidator();
        $validator->validateElement([
            'id' => 1,
            'data' => [
                'type' => 'EssentialElements\\Button',
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => 'Buy now',
                        ],
                    ],
                ],
            ],
            'children' => [],
        ]);

        $warnings = $validator->getWarnings();
        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('Missing contract path', $joined);
        $this->assertStringContainsString('content.content.link.url', $joined);
    }

    public function testHtml5VideoContractWarningWhenVideoPathIsMissing(): void
    {
        $validator = new OutputValidator();
        $validator->validateElement([
            'id' => 1,
            'data' => [
                'type' => 'OxygenElements\\Html5Video',
                'properties' => [
                    'content' => [
                        'content' => [],
                    ],
                ],
            ],
            'children' => [],
        ]);

        $warnings = $validator->getWarnings();
        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('content.content.video_file_url', $joined);
    }

    public function testNoContractWarningForValidEssentialButtonProperties(): void
    {
        $validator = new OutputValidator();
        $validator->validateElement([
            'id' => 1,
            'data' => [
                'type' => 'EssentialElements\\Button',
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => 'Buy now',
                            'link' => [
                                'type' => 'url',
                                'url' => 'https://example.com',
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ]);

        $warnings = implode("\n", $validator->getWarnings());
        $this->assertStringNotContainsString('content.content.link.url', $warnings);
        $this->assertStringNotContainsString('content.content.text', $warnings);
    }
}

