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
}
