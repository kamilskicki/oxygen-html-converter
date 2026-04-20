<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\HtmlCodeSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlCodeSanitizerTest extends TestCase
{
    public function testSanitizeFragmentRemovesScriptsAndUnsafeAttributes(): void
    {
        $sanitizer = new HtmlCodeSanitizer();

        $sanitized = $sanitizer->sanitizeFragment(
            '<form action="javascript:alert(1)" onsubmit="evil()"><input onclick="evil()" /><script>alert(1)</script><button type="submit">Go</button></form>'
        );

        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('onclick=', $sanitized);
        $this->assertStringNotContainsString('onsubmit=', $sanitized);
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringContainsString('action="#"', $sanitized);
    }

    public function testSanitizeElementReturnsFalseWhenNoSafeMarkupRemains(): void
    {
        $sanitizer = new HtmlCodeSanitizer();
        $element = [
            'data' => [
                'properties' => [
                    'content' => [
                        'content' => [
                            'html_code' => '<script>alert(1)</script>',
                        ],
                    ],
                ],
            ],
        ];

        $this->assertFalse($sanitizer->sanitizeElement($element));
    }
}
