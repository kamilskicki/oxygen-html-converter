<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class TreeBuilderSecurityTest extends TestCase
{
    public function testSafeModeStripsScriptsAndExternalHeadAssets(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.example.com/style.css">
  <script>window.demo = true;</script>
</head>
<body>
  <div data-lucide="menu">Icon</div>
  <script>alert('x')</script>
  <script src="https://cdn.example.com/app.js"></script>
</body>
</html>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['headLinkElements']);
        $this->assertSame([], $result['headScriptElements']);
        $this->assertSame([], $result['iconScriptElements']);
        $this->assertSame([], $result['detectedIconLibraries']);

        $types = [];
        $this->collectElementTypes($result['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);
    }

    public function testSafeModeRemovesInlineEventHandlers(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert('<button onclick="doThing()">Click</button>');

        $this->assertTrue($result['success']);

        $attributes = $result['element']['data']['properties']['settings']['advanced']['attributes'] ?? [];
        foreach ($attributes as $attribute) {
            $this->assertFalse(strpos((string) ($attribute['name'] ?? ''), 'on') === 0);
        }

        $interactions = $result['element']['data']['properties']['settings']['interactions']['interactions'] ?? [];
        $this->assertSame([], $interactions);
    }

    public function testDisablingInlineStylesSkipsDesignStyleExtraction(): void
    {
        $html = '<style>.title{color:red;}</style><h1 class="title" style="font-size:32px">Hello</h1>';
        $builder = new TreeBuilder();
        $builder->setInlineStyles(false);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['cssElement']);

        $typography = $result['element']['data']['properties']['design']['typography'] ?? [];
        $this->assertArrayNotHasKey('font-size', $typography);
        $this->assertArrayNotHasKey('color', $typography);
    }

    public function testDangerousLinkSchemeIsReplacedWithSafeFallback(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<a href="javascript:alert(1)">Click</a>');

        $this->assertTrue($result['success']);
        $this->assertSame('#', $result['element']['data']['properties']['content']['content']['url']);
    }

    public function testUnsupportedImageDataUriIsBlocked(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">');

        $this->assertTrue($result['success']);
        $this->assertSame('#', $result['element']['data']['properties']['content']['image']['url']);
    }

    public function testSafeModeSanitizesHtmlCodePayload(): void
    {
        $html = '<form action="javascript:alert(1)" onsubmit="evil()"><input type="text" onclick="evil()"><button type="submit">Go</button><script>alert(1)</script></form>';
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\HtmlCode', $result['element']['data']['type']);

        $payload = $result['element']['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringNotContainsString('<script', $payload);
        $this->assertStringNotContainsString('onclick=', $payload);
        $this->assertStringNotContainsString('onsubmit=', $payload);
        $this->assertStringNotContainsString('javascript:', $payload);
        $this->assertStringContainsString('action="#"', $payload);
    }

    private function collectElementTypes(array $element, array &$types): void
    {
        $types[] = $element['data']['type'] ?? '';

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectElementTypes($child, $types);
            }
        }
    }

    public function testExtractsHeadScriptsWithoutDuplicatingIconCdnScripts(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { theme: { extend: { colors: { brand: '#ff0084' } } } };</script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
  <div data-lucide="menu">Icon</div>
</body>
</html>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $headScripts = $result['headScriptElements'] ?? [];
        $this->assertCount(2, $headScripts);

        $payloads = array_map(
            static fn (array $element): string => (string) ($element['data']['properties']['content']['content']['html_code'] ?? ''),
            $headScripts
        );

        $combinedPayload = implode("\n", $payloads);
        $this->assertStringContainsString('https://cdn.tailwindcss.com', $combinedPayload);
        $this->assertStringContainsString('tailwind.config', $combinedPayload);
        $this->assertStringNotContainsString('https://unpkg.com/lucide@latest', $combinedPayload);

        $iconElements = $result['iconScriptElements'] ?? [];
        $this->assertCount(1, $iconElements);

        $iconPayload = (string) ($iconElements[0]['data']['properties']['content']['content']['html_code'] ?? '');
        $this->assertStringContainsString('https://unpkg.com/lucide@latest', $iconPayload);
    }

    public function testAppendsTailwindFallbackCssForTypographyUtilities(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<h1 class="text-6xl md:text-8xl text-white leading-[0.9] tracking-tight uppercase">Hello</h1>');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['cssElement']);

        $css = (string) ($result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '');
        $this->assertStringContainsString('.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; }', $css);
        $this->assertStringContainsString('.text-white { color: #ffffff !important; }', $css);
        $this->assertStringContainsString('.leading-\\[0\\.9\\] { line-height: 0.9 !important; }', $css);
        $this->assertStringContainsString('.tracking-tight { letter-spacing: -0.025em !important; }', $css);
        $this->assertStringContainsString('.uppercase { text-transform: uppercase !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('.md\\:text-8xl { font-size: 6rem !important; line-height: 1 !important; }', $css);
    }

    public function testScrollRevealBaseClassIsPreservedWhenEntranceAnimationIsAdded(): void
    {
        $html = <<<HTML
<style>
.reveal {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}
</style>
<div class="hero__title reveal reveal-delay-2">Animated content</div>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];
        $animation = $result['element']['data']['properties']['settings']['animations']['entrance_animation'] ?? null;

        $this->assertNotNull($animation);
        $this->assertContains('reveal', $classes);
        $this->assertContains('reveal-delay-2', $classes);
    }

    public function testTextOnlyDivContainerConvertsDirectlyToTextElement(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="hero__watermark">84%</div>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Text', $result['element']['data']['type']);
        $this->assertSame('84%', trim((string) ($result['element']['data']['properties']['content']['content']['text'] ?? '')));
        $this->assertSame([], $result['element']['children'] ?? []);
    }
}
