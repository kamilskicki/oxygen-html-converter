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
        $this->assertStringNotContainsString('<form', $sanitized);
        $this->assertStringNotContainsString('action=', $sanitized);
    }

    public function testSanitizeFragmentNormalizesObfuscatedJavaScriptUrls(): void
    {
        $sanitizer = new HtmlCodeSanitizer();

        $sanitized = $sanitizer->sanitizeFragment(
            '<a href="jav&#x61;script:alert(1)">A</a><a href="java&#10;script:alert(1)">B</a>'
        );

        $this->assertStringNotContainsString('javascript:', strtolower($sanitized));
        $this->assertStringNotContainsString('java&#10;script:', strtolower($sanitized));
        $this->assertSame(2, substr_count($sanitized, 'href="#"'));
    }

    public function testSanitizeFragmentBlocksSvgDataUrlsAndExecutableFormAttributes(): void
    {
        $sanitizer = new HtmlCodeSanitizer();

        $sanitized = $sanitizer->sanitizeFragment(
            '<form action="https://attacker.test/post"><img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" onerror="alert(1)"><button formaction="javascript:alert(1)">Send</button><input name="x" autofocus></form>'
        );

        $lower = strtolower($sanitized);
        $this->assertStringNotContainsString('<form', $lower);
        $this->assertStringNotContainsString('<input', $lower);
        $this->assertStringNotContainsString('action=', $lower);
        $this->assertStringNotContainsString('formaction=', $lower);
        $this->assertStringNotContainsString('data:image/svg+xml', $lower);
        $this->assertStringNotContainsString('onerror=', $lower);
        $this->assertStringContainsString('src="#"', $sanitized);
    }

    public function testSanitizeFragmentBlocksSchemeRelativeAndMailtoHeaderInjectionUrls(): void
    {
        $sanitizer = new HtmlCodeSanitizer();

        $sanitized = $sanitizer->sanitizeFragment(
            '<a href="//attacker.test/path">scheme</a><a href="mailto:user@example.com?bcc=attacker@example.com">mail</a>'
        );

        $this->assertStringNotContainsString('href="//attacker.test/path"', $sanitized);
        $this->assertStringNotContainsString('bcc=', strtolower($sanitized));
        $this->assertSame(2, substr_count($sanitized, 'href="#"'));
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
