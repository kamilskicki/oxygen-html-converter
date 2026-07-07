<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\ElementTypes;
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

        $items = $result['stats']['unsupportedItems'] ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame('removed_inline_behavior', $items[0]['fallbackCategory']);
        $this->assertSame('button[onclick]', $items[0]['selector']);
        $this->assertStringContainsString('behavior attribute', $items[0]['reason']);
    }

    public function testDefaultTreeBuilderDoesNotEmitExecutableCodeWithoutOptIn(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<script>console.log("x")</script><div>Hello</div>');

        $this->assertTrue($result['success']);

        $types = [];
        $this->collectElementTypes($result['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);
        $this->assertNotEmpty($result['stats']['unsupportedItems'] ?? []);
    }

    public function testUnsafeModeWithoutExecutableOptInStillStripsScripts(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $result = $builder->convert('<script>console.log("x")</script><div>Hello</div>');

        $this->assertTrue($result['success']);

        $types = [];
        $this->collectElementTypes($result['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);
        $this->assertSame('removed_executable', $result['stats']['unsupportedItems'][0]['fallbackCategory'] ?? null);
    }

    public function testExplicitExecutableCodeOptInAllowsUnsafeJavaScriptFallback(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert('<script>console.log("x")</script><div>Hello</div>');

        $this->assertTrue($result['success']);

        $types = [];
        $this->collectElementTypes($result['element'], $types);
        $this->assertContains('OxygenElements\\JavaScriptCode', $types);
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
        $this->assertStringNotContainsString('<form', $payload);
        $this->assertStringNotContainsString('action=', $payload);
    }

    public function testUnsupportedHtmlCodeFallbackReportsActionableTaxonomy(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert(
            '<form id="lead" class="signup" action="javascript:alert(1)"><input name="email"><button>Join</button></form>'
        );

        $this->assertTrue($result['success']);

        $items = $result['stats']['unsupportedItems'] ?? [];

        $this->assertNotEmpty($items);
        $this->assertSame('form#lead.signup', $items[0]['selector']);
        $this->assertSame('unsupported_form', $items[0]['fallbackCategory']);
        $this->assertSame('blocking', $items[0]['severity']);
        $this->assertStringContainsString('form#lead', $items[0]['location']);
        $this->assertStringContainsString('<form', $items[0]['sourceSnippet']);
        $this->assertStringContainsString('Safe Mode', $items[0]['safeModeImpact']);
        $this->assertNotSame('', $items[0]['remediation']);
    }

    public function testCoreReportsFormsAsUnsupportedAndSanitizesUnsafeFieldFallbackByDefault(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert(
            '<form id="lead" action="javascript:alert(1)" method="post" target="_blank" onsubmit="evil()">'
            . '<input type="email" name="email" required minlength="3" pattern=".+@.+" formaction="javascript:alert(2)" formtarget="_blank">'
            . '<textarea name="message" maxlength="200"></textarea>'
            . '<select name="topic"><option>Sales</option></select>'
            . '<button type="submit" formaction="javascript:alert(3)">Send</button>'
            . '</form>'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(ElementTypes::HTML_CODE, $result['element']['data']['type']);

        $payload = strtolower((string) ($result['element']['data']['properties']['content']['content']['html_code'] ?? ''));
        foreach ([
            '<form',
            '<input',
            '<textarea',
            '<select',
            'action=',
            'method=',
            'target=',
            'onsubmit=',
            'formaction=',
            'formtarget=',
            'required',
            'minlength',
            'pattern=',
            'javascript:',
        ] as $blocked) {
            $this->assertStringNotContainsString($blocked, $payload);
        }

        $item = $result['stats']['unsupportedItems'][0] ?? [];
        $this->assertSame('unsupported_form', $item['fallbackCategory'] ?? null);
        $this->assertStringContainsString('Forms are not imported as editable native controls', $item['reason'] ?? '');
        $this->assertStringContainsString('action/formaction/target', $item['safeModeImpact'] ?? '');
        $this->assertStringContainsString('approved WordPress/form integration', $item['remediation'] ?? '');
        $this->assertStringContainsString('[unsafe-url]:', $item['sourceSnippet'] ?? '');
    }

    public function testUnsafeFormHtmlCodeFallbackRequiresExplicitOptIn(): void
    {
        $html = '<form id="lead" action="https://example.test/lead" method="post" target="_blank" onsubmit="evil()">'
            . '<input type="email" name="email" required minlength="3">'
            . '<button type="submit" formaction="https://example.test/alt" formtarget="_blank">Send</button>'
            . '</form>';

        $defaultBuilder = new TreeBuilder();
        $defaultBuilder->setSafeMode(false);
        $defaultResult = $defaultBuilder->convert($html);
        $this->assertTrue($defaultResult['success']);
        $defaultPayload = strtolower((string) ($defaultResult['element']['data']['properties']['content']['content']['html_code'] ?? ''));
        $this->assertStringNotContainsString('<form', $defaultPayload);
        $this->assertStringContainsString('explicit unsafe code-fallback opt-in', $defaultResult['stats']['unsupportedItems'][0]['reason'] ?? '');

        $unsafeBuilder = new TreeBuilder();
        $unsafeBuilder->setSafeMode(false);
        $unsafeBuilder->setAllowExecutableCode(true);
        $unsafeResult = $unsafeBuilder->convert($html);
        $this->assertTrue($unsafeResult['success']);
        $unsafePayload = strtolower((string) ($unsafeResult['element']['data']['properties']['content']['content']['html_code'] ?? ''));

        $this->assertStringContainsString('<form', $unsafePayload);
        $this->assertStringContainsString('action="https://example.test/lead"', $unsafePayload);
        $this->assertStringContainsString('method="post"', $unsafePayload);
        $this->assertStringContainsString('target="_blank"', $unsafePayload);
        $this->assertStringContainsString('onsubmit="evil()"', $unsafePayload);
        $this->assertStringContainsString('<input', $unsafePayload);
        $this->assertStringContainsString('required', $unsafePayload);
        $this->assertStringContainsString('formaction="https://example.test/alt"', $unsafePayload);
        $this->assertSame('unsupported_form', $unsafeResult['stats']['unsupportedItems'][0]['fallbackCategory'] ?? null);
        $this->assertStringContainsString('unsafe visible HtmlCode fallback', $unsafeResult['stats']['unsupportedItems'][0]['reason'] ?? '');
        $this->assertStringContainsString('explicitly approved', $unsafeResult['stats']['unsupportedItems'][0]['safeModeImpact'] ?? '');
    }

    public function testStandaloneFormAssociatedButtonAttributesAreReportedWhenStripped(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert(
            '<button formaction="javascript:alert(1)" formtarget="_blank" formmethod="post">Send</button>'
        );

        $this->assertTrue($result['success']);

        $attributes = $result['element']['data']['properties']['settings']['advanced']['attributes'] ?? [];
        $this->assertSame([], $attributes);

        $items = array_values(array_filter(
            $result['stats']['unsupportedItems'] ?? [],
            static fn (array $item): bool => ($item['fallbackCategory'] ?? '') === 'unsupported_form'
        ));

        $this->assertCount(3, $items);
        $selectors = array_column($items, 'selector');
        $this->assertContains('button[formaction]', $selectors);
        $this->assertContains('button[formtarget]', $selectors);
        $this->assertContains('button[formmethod]', $selectors);
        $this->assertStringContainsString('Form-associated submission attribute', $items[0]['reason']);
        $this->assertStringContainsString('approved WordPress/form integration', $items[0]['remediation']);
    }

    public function testSafeModeReportsObjectAndEmbedAsUnsupportedExecutableContainers(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert(
            '<section><object data="https://example.test/app.swf"></object><embed src="https://example.test/app.swf"></section>'
        );

        $this->assertTrue($result['success']);

        $items = $result['stats']['unsupportedItems'] ?? [];
        $selectors = array_column($items, 'selector');
        $categories = array_column($items, 'fallbackCategory');

        $this->assertContains('object', $selectors);
        $this->assertContains('embed', $selectors);
        $this->assertContains('unsupported_embed', $categories);
    }

    public function testUnsupportedOnlyInputCarriesReportStats(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert('<script>alert(1)</script><iframe srcdoc="<script>alert(1)</script>"></iframe>');

        $this->assertFalse($result['success']);
        $this->assertSame('No convertible content found in HTML', $result['error']);
        $this->assertNotEmpty($result['stats']['unsupportedItems'] ?? []);
        $this->assertContains(
            'removed_executable',
            array_column($result['stats']['unsupportedItems'], 'fallbackCategory')
        );
        $this->assertContains(
            'unsupported_embed',
            array_column($result['stats']['unsupportedItems'], 'fallbackCategory')
        );
    }

    public function testHeadAssetsReportActionableUnsupportedItemsInSafeMode(): void
    {
        $html = '<!doctype html><html><head>'
            . '<link rel="stylesheet" href="https://cdn.example.test/app.css">'
            . '<script src="https://cdn.example.test/app.js"></script>'
            . '<script>window.tailwind.config = { theme: {} };</script>'
            . '</head><body><i data-lucide="star"></i><main>Body</main></body></html>';

        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $items = $result['stats']['unsupportedItems'] ?? [];
        $categories = array_column($items, 'fallbackCategory');

        $this->assertContains('removed_head_stylesheet', $categories);
        $this->assertContains('removed_head_script', $categories);
        $this->assertContains('removed_icon_asset', $categories);
        $this->assertSame([], $result['headLinkElements']);
        $this->assertSame([], $result['headScriptElements']);
        $this->assertSame([], $result['iconScriptElements']);

        foreach ($items as $item) {
            $this->assertNotSame('', trim((string) ($item['location'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['selector'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['sourceSnippet'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['reason'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['safeModeImpact'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['remediation'] ?? '')));
        }
    }

    public function testHeadAssetsReportActionableUnsupportedItemsInUnsafeMode(): void
    {
        $html = '<!doctype html><html><head>'
            . '<link rel="stylesheet" href="https://cdn.example.test/app.css">'
            . '<script src="https://cdn.example.test/app.js"></script>'
            . '<script>window.tailwind.config = { theme: {} };</script>'
            . '</head><body><main>Body</main></body></html>';

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $items = $result['stats']['unsupportedItems'] ?? [];
        $categories = array_column($items, 'fallbackCategory');

        $this->assertContains('unsafe_head_stylesheet_fallback', $categories);
        $this->assertContains('unsafe_head_script_fallback', $categories);
        $this->assertNotEmpty($result['headLinkElements']);
        $this->assertNotEmpty($result['headScriptElements']);
    }

    public function testUnsafeModeWithoutExecutableOptInDoesNotPreserveHeadAssets(): void
    {
        $html = '<!doctype html><html><head>'
            . '<link rel="stylesheet" href="https://cdn.example.test/app.css">'
            . '<script src="https://cdn.example.test/app.js"></script>'
            . '</head><body><main>Body</main></body></html>';

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['headLinkElements']);
        $this->assertSame([], $result['headScriptElements']);

        $categories = array_column($result['stats']['unsupportedItems'] ?? [], 'fallbackCategory');
        $this->assertContains('removed_head_stylesheet', $categories);
        $this->assertContains('removed_head_script', $categories);
    }

    public function testHeadExternalScriptSnippetsPreserveSafeSourceIdentity(): void
    {
        $html = '<!doctype html><html><head>'
            . '<script src="https://cdn.example.test/one.js"></script>'
            . '<script src="https://cdn.example.test/two.js"></script>'
            . '</head><body><main>Body</main></body></html>';

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $scriptItems = array_values(array_filter(
            $result['stats']['unsupportedItems'] ?? [],
            static fn (array $item): bool => ($item['fallbackCategory'] ?? '') === 'unsafe_head_script_fallback'
        ));

        $this->assertCount(2, $scriptItems);
        $this->assertStringContainsString('one.js', $scriptItems[0]['sourceSnippet']);
        $this->assertStringContainsString('two.js', $scriptItems[1]['sourceSnippet']);
        $this->assertStringContainsString('[removed script]', $scriptItems[0]['sourceSnippet']);
    }

    public function testUnsupportedItemCountsPreserveRepeatedIdenticalOccurrences(): void
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert(
            '<section>'
            . '<form class="lead"><input name="email"></form>'
            . '<form class="lead"><input name="email"></form>'
            . '</section>'
        );

        $this->assertTrue($result['success']);

        $items = array_values(array_filter(
            $result['stats']['unsupportedItems'] ?? [],
            static fn (array $item): bool => ($item['selector'] ?? '') === 'form.lead'
        ));

        $this->assertCount(2, $items);
    }

    public function testSafeModeSanitizesTextRichTextLinkImageButtonAndAttributeSinks(): void
    {
        $textResult = $this->convertInSafeMode(
            '<p>Hello <span onclick="alert(1)">x</span><a href="javascript:alert(1)">link</a><script>alert(1)</script></p>'
        );
        $textPayload = (string) ($textResult['element']['data']['properties']['content']['content']['text'] ?? '');

        $this->assertSafeMarkupString($textPayload);
        $this->assertStringContainsString('href="#"', $textPayload);

        $richResult = $this->convertInSafeMode(
            '<table><tr><td><img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" onerror="alert(1)"><iframe srcdoc="<script>alert(1)</script>"></iframe></td></tr></table>'
        );
        $richPayload = (string) ($richResult['element']['data']['properties']['content']['content']['text'] ?? '');

        $this->assertSafeMarkupString($richPayload);
        $this->assertStringNotContainsString('<iframe', strtolower($richPayload));
        $this->assertStringNotContainsString('srcdoc', strtolower($richPayload));
        $this->assertStringNotContainsString('data:image/svg+xml', strtolower($richPayload));

        $linkResult = $this->convertInSafeMode(
            '<a href="jav&#x61;script:alert(1)" target="_blank"><span onclick="alert(1)">Open</span><script>alert(1)</script></a>'
        );
        $linkContent = $linkResult['element']['data']['properties']['content']['content'] ?? [];

        $this->assertSame('#', $linkContent['url'] ?? null);
        $this->assertSafeMarkupString((string) ($linkContent['text'] ?? ''));

        $imageResult = $this->convertInSafeMode(
            '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" alt="<b>x</b>' . chr(1) . '" onerror="alert(1)">'
        );
        $image = $imageResult['element']['data']['properties']['content']['image'] ?? [];

        $this->assertSame('#', $image['url'] ?? null);
        $this->assertSame('x', $image['custom_alt_when_from_url'] ?? null);

        $buttonResult = $this->convertInSafeMode(
            '<button formaction="javascript:alert(1)" ping="/track"><span onclick="alert(1)">Pay</span></button>'
        );
        $buttonPayload = (string) ($buttonResult['element']['data']['properties']['content']['content']['text'] ?? '');
        $buttonAttributes = $buttonResult['element']['data']['properties']['settings']['advanced']['attributes'] ?? [];

        $this->assertSafeMarkupString($buttonPayload);
        $this->assertSame([], $buttonAttributes);
    }

    public function testSafeModeBlocksObfuscatedJavaScriptUrlsInNativeLinks(): void
    {
        $entityResult = $this->convertInSafeMode('<a href="jav&#x61;script:alert(1)">Open</a>');
        $this->assertSame(
            '#',
            $entityResult['element']['data']['properties']['content']['content']['url'] ?? null
        );

        $controlResult = $this->convertInSafeMode('<a href="java&#10;script:alert(1)">Open</a>');
        $this->assertSame(
            '#',
            $controlResult['element']['data']['properties']['content']['content']['url'] ?? null
        );

        $schemeRelativeResult = $this->convertInSafeMode('<a href="//attacker.test/path">Open</a>');
        $this->assertSame(
            '#',
            $schemeRelativeResult['element']['data']['properties']['content']['content']['url'] ?? null
        );

        $mailtoHeaderResult = $this->convertInSafeMode('<a href="mailto:user@example.com?bcc=attacker@example.com">Open</a>');
        $this->assertSame(
            '#',
            $mailtoHeaderResult['element']['data']['properties']['content']['content']['url'] ?? null
        );
    }

    public function testSafeModeEscapesDomTextThatLooksLikeMarkup(): void
    {
        $result = $this->convertInSafeMode('<section>Before &lt;img src=x onerror=alert(1)&gt;<strong>after</strong></section>');

        $textPayloads = [];
        $this->collectTextPayloads($result['element'], $textPayloads);
        $combinedPayload = implode("\n", $textPayloads);

        $this->assertStringContainsString('Before &lt;img src=x onerror=alert(1)&gt;', $combinedPayload);
        foreach ($textPayloads as $payload) {
            $this->assertStringNotContainsString('<img', strtolower($payload));
            $this->assertStringNotContainsString('<script', strtolower($payload));
        }
    }

    public function testSafeModeDoesNotAddNullContentPropertyToContainers(): void
    {
        $result = $this->convertInSafeMode('<section><div><span>Hi</span></div></section>');

        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertArrayNotHasKey('content', $result['element']['data']['properties']);
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

    private function collectTextPayloads(array $element, array &$payloads): void
    {
        $payload = $element['data']['properties']['content']['content']['text'] ?? null;
        if (is_string($payload)) {
            $payloads[] = $payload;
        }

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectTextPayloads($child, $payloads);
            }
        }
    }

    private function convertInSafeMode(string $html): array
    {
        $builder = new TreeBuilder();
        $builder->setSafeMode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        return $result;
    }

    private function assertSafeMarkupString(string $payload): void
    {
        $lowerPayload = strtolower($payload);

        $this->assertStringNotContainsString('<script', $lowerPayload);
        $this->assertStringNotContainsString('onclick', $lowerPayload);
        $this->assertStringNotContainsString('onerror', $lowerPayload);
        $this->assertStringNotContainsString('javascript:', $lowerPayload);
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
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
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

    public function testTailwindHeadConfigScriptIsGuardedForBuilderRuntime(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { theme: { extend: { colors: { brand: '#ff0084' } } } };</script>
</head>
<body><div class="text-brand">Hello</div></body>
</html>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $headScripts = $result['headScriptElements'] ?? [];
        $payloads = array_map(
            static fn (array $element): string => (string) ($element['data']['properties']['content']['content']['html_code'] ?? ''),
            $headScripts
        );
        $combinedPayload = implode("\n", $payloads);

        $this->assertStringContainsString('window.tailwind = window.tailwind || {};', $combinedPayload);
        $this->assertStringContainsString('window.tailwind.config =', $combinedPayload);
        $this->assertDoesNotMatchRegularExpression('/(?<![\\w$.])tailwind\\s*\\.\\s*config\\s*=/', $combinedPayload);
    }

    public function testHeadAssetExtractionSkipsPreconnectHints(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap">
</head>
<body><div class="font-body">Hello</div></body>
</html>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $headLinks = $result['headLinkElements'] ?? [];
        $this->assertCount(1, $headLinks);

        $payload = (string) ($headLinks[0]['data']['properties']['content']['content']['html_code'] ?? '');
        $this->assertStringContainsString('rel="stylesheet"', $payload);
        $this->assertStringNotContainsString('rel="preconnect"', $payload);
    }

    public function testWindowTailwindHeadConfigScriptIsGuardedForBuilderRuntime(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <script>window.tailwind.config = { theme: { extend: { colors: { brand: '#ff0084' } } } };</script>
  <script>globalThis.tailwind.config = { darkMode: 'class' };</script>
</head>
<body><div class="text-brand">Hello</div></body>
</html>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $headScripts = $result['headScriptElements'] ?? [];
        $payloads = array_map(
            static fn (array $element): string => (string) ($element['data']['properties']['content']['content']['html_code'] ?? ''),
            $headScripts
        );
        $combinedPayload = implode("\n", $payloads);

        $this->assertStringContainsString('window.tailwind = window.tailwind || {};', $combinedPayload);
        $this->assertStringContainsString('window.tailwind.config =', $combinedPayload);
        $this->assertStringContainsString('globalThis.tailwind = globalThis.tailwind || {};', $combinedPayload);
        $this->assertStringContainsString('globalThis.tailwind.config =', $combinedPayload);
    }

    public function testAppendsTailwindFallbackCssForTypographyUtilities(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<h1 class="text-6xl md:text-8xl text-white leading-[0.9] tracking-tight uppercase">Hello</h1>');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['cssElement']);

        $css = (string) ($result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '');
        $this->assertStringContainsString('.leading-\\[0\\.9\\] { line-height: 0.9 !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('.md\\:text-8xl { font-size: 6rem !important; line-height: 1 !important; color: inherit !important; }', $css);

        $typography = $result['element']['data']['properties']['design']['typography'] ?? [];
        $this->assertSame('#FFFFFFFF', $typography['color'] ?? null);
        $this->assertSame('uppercase', $typography['text_transform'] ?? null);
        $this->assertSame('3.75rem', $typography['font_size']['style'] ?? null);
        $this->assertSame('1', $typography['line_height']['style'] ?? null);
        $this->assertSame('-0.025em', $typography['letter_spacing']['style'] ?? null);
    }

    public function testRejectsUnsafeArbitraryGridFallbackCss(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="grid-cols-[1fr;}body{color:red] text-[#ff0084]">Hello</div>');

        $this->assertTrue($result['success']);

        $css = (string) ($result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '');
        $this->assertStringNotContainsString('body{color:red', $css);
        $this->assertStringNotContainsString('grid-template-columns: 1fr', $css);
        $this->assertStringNotContainsString('body{color:red', $result['extractedCss']);
        $this->assertStringNotContainsString('grid-template-columns: 1fr', $result['extractedCss']);
        $this->assertStringNotContainsString('body{color:red', $result['pageScopedCss']);
        $this->assertStringNotContainsString('grid-template-columns: 1fr', $result['pageScopedCss']);
        $this->assertSame('#FF0084FF', $result['element']['data']['properties']['design']['typography']['color'] ?? null);
        $this->assertContains(
            'grid-cols-[1fr;}body{color:red]',
            $result['element']['data']['properties']['settings']['advanced']['classes'] ?? []
        );
    }

    public function testScrollRevealClassesAreRemovedWhenEntranceAnimationIsAdded(): void
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
        $designEffects = $result['element']['data']['properties']['design']['effects'] ?? [];

        $this->assertNotNull($animation);
        $this->assertNotContains('reveal', $classes);
        $this->assertNotContains('reveal-delay-2', $classes);
        $this->assertArrayNotHasKey('opacity', is_array($designEffects) ? $designEffects : []);
        $this->assertStringNotContainsString('.reveal', (string) ($result['extractedCss'] ?? ''));
        $this->assertStringNotContainsString('opacity: 0', (string) ($result['extractedCss'] ?? ''));
    }

    public function testJavascriptVisibleStateCssIsPromotedWithoutRemovingSemanticClasses(): void
    {
        $html = <<<HTML
<style>
.hero__descriptor {
    color: #00ffcc;
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.hero__descriptor.visible {
    opacity: 1;
    transform: translateY(0);
}
.hero__line {
    width: 0;
    height: 3px;
    background: red;
    transition: width 1.2s ease;
}
.hero__line.visible {
    width: min(500px, 60%);
}
.loader {
    position: fixed;
    transition: transform 0.8s ease;
}
.loader.done {
    transform: translateY(-100%);
}
</style>
<section>
  <div class="loader">Loading</div>
  <div class="hero__line"></div>
  <p class="hero__descriptor">Visible content</p>
</section>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $descriptor = $this->findFirstElementWithClass($result['element'], 'hero__descriptor');
        $this->assertNotNull($descriptor);
        $this->assertContains(
            'hero__descriptor',
            $descriptor['data']['properties']['settings']['advanced']['classes'] ?? []
        );

        $designEffects = $descriptor['data']['properties']['design']['effects'] ?? [];
        $this->assertArrayNotHasKey('opacity', is_array($designEffects) ? $designEffects : []);

        $css = (string) ($result['extractedCss'] ?? '');
        $this->assertStringContainsString('JS-dependent final states promoted', $css);
        $this->assertStringContainsString('.hero__descriptor { opacity: 1 !important; transform: translateY(0) !important; }', $css);
        $this->assertStringContainsString('.hero__line { width: min(500px, 60%) !important; }', $css);
        $this->assertStringContainsString(
            '.loader { transform: translateY(-100%) !important; pointer-events: none !important; }',
            $css
        );
    }

    public function testJavascriptVisibleStateCssIsNotPromotedWhenExecutableJavascriptIsPreserved(): void
    {
        $html = <<<HTML
<style>
.hero {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.hero.visible {
    opacity: 1;
    transform: translateY(0);
}
</style>
<div class="hero">Animated content</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('.hero').classList.add('visible');
});
</script>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $javascriptPayloads = [];
        $this->collectJavascriptPayloads($result['element'], $javascriptPayloads);
        $this->assertNotSame([], $javascriptPayloads);
        $this->assertStringNotContainsString(
            'JS-dependent final states promoted',
            (string) ($result['extractedCss'] ?? '')
        );
    }

    public function testNonHiddenVisibleStateCssIsNotPromotedToDefaultState(): void
    {
        $html = <<<HTML
<style>
.card {
    transition: transform 0.2s ease;
}
.card.visible {
    transform: scale(1.08);
}
</style>
<div class="card">Card</div>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $css = (string) ($result['extractedCss'] ?? '');
        $this->assertStringNotContainsString('JS-dependent final states promoted', $css);
        $this->assertStringNotContainsString('.card { transform: scale(1.08)', $css);
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

    public function testUniversalResetCssGetsBodyBlockResetCompatibilityShim(): void
    {
        $html = <<<HTML
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
.hero-center h1 { margin-bottom: 1.5rem; }
</style>
<div class="hero-center"><h1>Hello</h1><p>World</p></div>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['cssElement']);

        $css = (string) ($result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '');
        $this->assertStringContainsString('body h1, body h2, body h3, body h4, body h5, body h6,', $css);
        $this->assertStringContainsString('body p, body ul, body ol, body li, body blockquote, body figure {', $css);
        $this->assertStringContainsString('margin: 0;', $css);
    }

    public function testCompatibilityShimIsNotAddedWithoutUniversalReset(): void
    {
        $html = <<<HTML
<style>
.hero-center h1 { margin-bottom: 1.5rem; }
</style>
<div class="hero-center"><h1>Hello</h1></div>
HTML;

        $builder = new TreeBuilder();
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $css = (string) ($result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '');
        $this->assertStringNotContainsString('body h1, body h2, body h3, body h4, body h5, body h6,', $css);
    }

    public function testToggleHandlersStayInJavascriptCodeForFrontendParity(): void
    {
        $html = <<<HTML
<nav>
  <ul id="navLinks"><li><a href="#contact">Contact</a></li></ul>
  <button id="navToggle">Menu</button>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var navToggle = document.getElementById('navToggle');
    var navLinks = document.getElementById('navLinks');

    navToggle.addEventListener('click', function () {
        navToggle.classList.toggle('active');
        navLinks.classList.toggle('open');
    });
});
</script>
HTML;

        $builder = new TreeBuilder();
        $builder->setSafeMode(false);
        $builder->setAllowExecutableCode(true);
        $result = $builder->convert($html);

        $this->assertTrue($result['success']);

        $toggleElement = $this->findElementByAdvancedId($result['element'], 'navToggle');
        $this->assertNotNull($toggleElement);

        $interactions = $toggleElement['data']['properties']['settings']['interactions']['interactions'] ?? [];
        $this->assertSame([], $interactions);

        $javascriptPayloads = [];
        $this->collectJavascriptPayloads($result['element'], $javascriptPayloads);
        $combinedPayload = implode("\n", $javascriptPayloads);

        $this->assertStringContainsString("navToggle.addEventListener('click'", $combinedPayload);
        $this->assertStringContainsString("navLinks.classList.toggle('open')", $combinedPayload);
    }

    private function findElementByAdvancedId(array $element, string $advancedId): ?array
    {
        $currentId = $element['data']['properties']['settings']['advanced']['id'] ?? null;
        if ($currentId === $advancedId) {
            return $element;
        }

        foreach (($element['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $match = $this->findElementByAdvancedId($child, $advancedId);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function findFirstElementWithClass(array $element, string $className): ?array
    {
        $classes = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (is_array($classes) && in_array($className, $classes, true)) {
            return $element;
        }

        foreach (($element['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $match = $this->findFirstElementWithClass($child, $className);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function collectJavascriptPayloads(array $element, array &$payloads): void
    {
        if (($element['data']['type'] ?? '') === 'OxygenElements\\JavaScriptCode') {
            $payloads[] = (string) ($element['data']['properties']['content']['content']['javascript_code'] ?? '');
        }

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectJavascriptPayloads($child, $payloads);
            }
        }
    }
}
