<?php

namespace OxyHtmlConverter\Tests\Unit\Validation;

use OxyHtmlConverter\Validation\OutputValidator;
use PHPUnit\Framework\TestCase;

class OutputValidatorTest extends TestCase
{
    public function testEssentialButtonContractWarningWhenLinkPathIsMissing(): void
    {
        $validator = new OutputValidator();
        $valid = $validator->validateElement([
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

        $this->assertFalse($valid);

        $joined = implode("\n", $validator->getErrors());
        $this->assertStringContainsString('Missing contract path', $joined);
        $this->assertStringContainsString('content.content.link.url', $joined);
    }

    public function testHtml5VideoContractWarningWhenVideoPathIsMissing(): void
    {
        $validator = new OutputValidator();
        $valid = $validator->validateElement([
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

        $this->assertFalse($valid);

        $joined = implode("\n", $validator->getErrors());
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

    public function testValidatorFailsUnsafeRenderedContentProperties(): void
    {
        $validator = new OutputValidator();

        $valid = $validator->validateConversionResult([
            'success' => true,
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'attributes' => [
                                    ['name' => 'onclick', 'value' => 'alert(1)'],
                                    ['name' => 'data-oxy-at-click', 'value' => 'run()'],
                                    ['name' => 'ping', 'value' => '/track'],
                                ],
                            ],
                            'interactions' => [
                                'interactions' => [[
                                    'trigger' => 'click',
                                    'actions' => [[
                                        'name' => 'javascript_function',
                                        'js_function_name' => 'runUnsafe',
                                    ]],
                                ]],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'id' => 2,
                    'data' => [
                        'type' => 'OxygenElements\\TextLink',
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'text' => '<span onclick="alert(1)">Open</span><script>alert(1)</script>',
                                    'url' => 'jav&#x61;script:alert(1)',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'stats' => [
                'elements' => 2,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'info' => [],
            ],
        ]);

        $this->assertFalse($valid);
        $errors = implode("\n", $validator->getErrors());
        $this->assertStringContainsString('Unsafe rendered content', $errors);
        $this->assertStringContainsString('Unsafe URL', $errors);
        $this->assertStringContainsString('Unsafe advanced attribute', $errors);
        $this->assertStringContainsString('Unsafe interaction action', $errors);
    }

    public function testValidatorFailsUnsafeMediaAndAuxiliaryElements(): void
    {
        $validator = new OutputValidator();

        $valid = $validator->validateConversionResult([
            'success' => true,
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Image',
                    'properties' => [
                        'content' => [
                            'image' => [
                                'from' => 'url',
                                'url' => 'data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+',
                                'lazy_load' => true,
                                'custom_alt_when_from_url' => '<b>x</b>' . chr(1),
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
            'cssElement' => [
                'id' => 2,
                'data' => [
                    'type' => 'OxygenElements\\CssCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'css_code' => '.x{background:url(javascript:alert(1));}',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
            'headLinkElements' => [[
                'id' => 3,
                'data' => [
                    'type' => 'OxygenElements\\HtmlCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ]],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'stats' => [
                'elements' => 3,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'info' => [],
            ],
        ]);

        $this->assertFalse($valid);
        $errors = implode("\n", $validator->getErrors());
        $this->assertStringContainsString('Unsafe URL', $errors);
        $this->assertStringContainsString('Unsafe CSS', $errors);
        $this->assertStringContainsString('Unsafe rendered content', $errors);
    }
}
