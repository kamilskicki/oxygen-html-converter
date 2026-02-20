<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\TreeBuilder;

class TreeBuilderTest extends TestCase
{
    private TreeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TreeBuilder();
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Find a class definition by UUID from the classDefinitions array.
     */
    private function findClassDef(array $classDefinitions, string $uuid): ?array
    {
        foreach ($classDefinitions as $def) {
            if ($def['id'] === $uuid) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Get the first class definition for an element (via meta.classes[0]).
     */
    private function getElementClassDef(array $element, array $classDefinitions): ?array
    {
        $uuids = $element['data']['properties']['meta']['classes'] ?? [];
        if (empty($uuids)) {
            return null;
        }
        return $this->findClassDef($classDefinitions, $uuids[0]);
    }

    /**
     * Assert an element has meta.classes set.
     */
    private function assertHasClassUuids(array $element, string $message = ''): void
    {
        $classes = $element['data']['properties']['meta']['classes'] ?? [];
        $this->assertNotEmpty($classes, $message ?: 'Element should have meta.classes UUIDs');
    }

    /**
     * Helper to recursively find elements in the tree.
     */
    private function findElement(array $element, callable $predicate): void
    {
        $predicate($element);
        foreach ($element['children'] ?? [] as $child) {
            $this->findElement($child, $predicate);
        }
    }

    // ─── Basic Structure Tests ──────────────────────────────────────

    /**
     * Test that conversion result includes classDefinitions.
     */
    public function testResultIncludesClassDefinitions(): void
    {
        $html = '<div style="font-size: 16px;">Hello</div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('classDefinitions', $result);
        $this->assertNotEmpty($result['classDefinitions']);
    }

    /**
     * Test preservation of HTML IDs.
     */
    public function testIdPreservation(): void
    {
        $html = '<nav id="navbar" class="test-class">Content</nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);

        $element = $result['element'];

        $this->assertEquals('OxygenElements\\Container', $element['data']['type']);
        $this->assertEquals('navbar', $element['data']['properties']['settings']['advanced']['id']);
    }

    /**
     * Test preservation of complex Tailwind classes.
     */
    public function testTailwindComplexity(): void
    {
        $complexClass = 'hover:border-[#ff0084]/50';
        $html = '<div class="' . $complexClass . '">Content</div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $classes = $element['data']['properties']['settings']['advanced']['classes'];
        $this->assertContains($complexClass, $classes);
    }

    // ─── Class-Based CSS Application ────────────────────────────────

    /**
     * Test CSS from style tags creates class definitions with correct properties.
     */
    public function testCssExtractionCreatesClassDefinitions(): void
    {
        $html = '<body><style>.glass-panel { color: red; }</style><div class="glass-panel">Content</div></body>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        // Class rule is consumed
        $this->assertStringNotContainsString('.glass-panel', $result['extractedCss']);

        // Element should have a class UUID
        $this->assertHasClassUuids($result['element']);

        // Class definition should have the color
        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);
        $typography = $classDef['properties']['breakpoint_base']['typography'];
        $this->assertEquals('red', $typography['color']);
    }

    /**
     * Test class-based CSS rules create Oxygen class definitions.
     */
    public function testClassBasedCssCreatesOxygenClasses(): void
    {
        $html = <<<HTML
<style>
.hero-badge { font-size: 2rem; color: gold; }
</style>
<div class="hero-badge">Badge Text</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $element = $result['element'];
        $this->assertHasClassUuids($element);

        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $typography = $classDef['properties']['breakpoint_base']['typography'];
        $this->assertEquals(2, $typography['font_size']['number']);
        $this->assertEquals('rem', $typography['font_size']['unit']);
        $this->assertEquals('gold', $typography['color']);
    }

    /**
     * Test consumed class CSS rules are removed from extractedCss.
     */
    public function testConsumedClassCssRemovedFromOutput(): void
    {
        $html = <<<HTML
<style>
.hero-badge { font-size: 2rem; color: gold; }
.other-style { margin: 10px; }
</style>
<div class="hero-badge">Badge</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $this->assertStringNotContainsString('.hero-badge', $result['extractedCss']);
        $this->assertStringContainsString('.other-style', $result['extractedCss']);
    }

    /**
     * Test pseudo-class selectors are NOT consumed.
     */
    public function testPseudoClassSelectorsNotConsumed(): void
    {
        $html = <<<HTML
<style>
.btn:hover { color: blue; }
.btn { color: red; }
</style>
<div class="btn">Button</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $this->assertStringContainsString('.btn:hover', $result['extractedCss']);
        $this->assertStringNotContainsString("\n.btn {", $result['extractedCss']);
    }

    /**
     * Test compound selectors are NOT consumed.
     */
    public function testCompoundSelectorsNotConsumed(): void
    {
        $html = <<<HTML
<style>
.parent .child { color: red; }
</style>
<div class="parent"><div class="child">Content</div></div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $this->assertStringContainsString('.parent .child', $result['extractedCss']);
    }

    /**
     * Test multi-class compound selector (.class1.class2) is consumed natively.
     */
    public function testMultiClassCompoundConsumed(): void
    {
        $html = <<<HTML
<style>
.btn.primary { color: blue; }
</style>
<div class="btn primary">Button</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);
        $this->assertEquals('blue', $classDef['properties']['breakpoint_base']['typography']['color']);
        $this->assertStringNotContainsString('.btn.primary', $result['extractedCss']);
    }

    /**
     * Test button early-return path still applies simple style-tag CSS rules.
     */
    public function testButtonEarlyReturnStillAppliesStyleTagRules(): void
    {
        $html = <<<HTML
<style>
.cta-btn { color: blue; padding: 12px; }
</style>
<button class="cta-btn">Click me</button>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $element = $result['element'];
        $this->assertHasClassUuids($element);

        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $base = $classDef['properties']['breakpoint_base'];
        $this->assertEquals('blue', $base['typography']['color']);
        $this->assertEquals(12, $base['spacing']['spacing']['padding']['top']['number']);
        $this->assertStringNotContainsString('.cta-btn', $result['extractedCss']);
    }

    /**
     * Test same-element tag+class selectors are converted natively.
     */
    public function testSimpleTagClassSelectorConvertedNatively(): void
    {
        $html = <<<HTML
<style>
a.cta-link { color: #123456; }
</style>
<a class="cta-link" href="#">CTA</a>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);
        $this->assertEquals('#123456FF', $classDef['properties']['breakpoint_base']['typography']['color']);
        $this->assertStringNotContainsString('a.cta-link', $result['extractedCss']);
    }

    /**
     * Test same-element compound class selectors are converted natively.
     */
    public function testCompoundClassSelectorConvertedNatively(): void
    {
        $html = <<<HTML
<style>
.btn-glass.primary { border: 2px solid rgb(255, 0, 0); }
</style>
<a class="btn-glass primary" href="#">CTA</a>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $borders = $classDef['properties']['breakpoint_base']['borders']['borders'];
        $this->assertEquals(2, $borders['top']['width']['number']);
        $this->assertEquals('solid', $borders['top']['style']);
        $this->assertStringNotContainsString('.btn-glass.primary', $result['extractedCss']);
    }

    /**
     * Test unsupported complex selectors are reported as a conversion limitation.
     */
    public function testUnsupportedComplexSelectorLimitationReported(): void
    {
        $html = <<<HTML
<style>
.parent .child { color: red; }
</style>
<div class="parent"><div class="child">Content</div></div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $info = $result['stats']['info'] ?? [];
        $limitationFound = false;
        foreach ($info as $message) {
            if (strpos($message, 'supports #id, .class, simple tag+class selectors') !== false) {
                $limitationFound = true;
                break;
            }
        }

        $this->assertTrue($limitationFound, 'Expected complex-selector limitation info message.');
    }

    // ─── Shorthand Expansion ────────────────────────────────────────

    /**
     * Test shorthand margin expansion into class definitions.
     */
    public function testShorthandMarginExpansion(): void
    {
        $html = <<<HTML
<style>
.spaced { margin: 10px 20px; }
</style>
<div class="spaced">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $margin = $classDef['properties']['breakpoint_base']['spacing']['spacing']['margin'];
        $this->assertEquals(10, $margin['top']['number']);
        $this->assertEquals(20, $margin['right']['number']);
        $this->assertEquals(10, $margin['bottom']['number']);
        $this->assertEquals(20, $margin['left']['number']);
    }

    /**
     * Test shorthand padding expansion.
     */
    public function testShorthandPaddingExpansion(): void
    {
        $html = <<<HTML
<style>
.padded { padding: 1rem; }
</style>
<div class="padded">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $padding = $classDef['properties']['breakpoint_base']['spacing']['spacing']['padding'];
        $this->assertEquals(1, $padding['top']['number']);
        $this->assertEquals('rem', $padding['top']['unit']);
    }

    /**
     * Test shorthand border expansion.
     */
    public function testShorthandBorderExpansion(): void
    {
        $html = <<<HTML
<style>
.bordered { border: 1px solid red; }
</style>
<div class="bordered">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $borders = $classDef['properties']['breakpoint_base']['borders']['borders'];
        $this->assertEquals(1, $borders['top']['width']['number']);
        $this->assertEquals('solid', $borders['top']['style']);
        $this->assertEquals('red', $borders['top']['color']);
    }

    /**
     * Test simple background shorthand to background-color.
     */
    public function testBackgroundShorthandToColor(): void
    {
        $html = <<<HTML
<style>
.bg { background: #fff; }
</style>
<div class="bg">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        // The element should have a class definition (background goes through)
        $this->assertHasClassUuids($result['element']);
    }

    /**
     * Test ID-based CSS rules create class definitions.
     */
    public function testIdBasedCssRulesCreateClasses(): void
    {
        $html = <<<HTML
<style>
#main { color: blue; font-size: 18px; }
</style>
<div id="main">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $this->assertHasClassUuids($result['element']);

        $classDef = $this->getElementClassDef($result['element'], $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $typography = $classDef['properties']['breakpoint_base']['typography'];
        $this->assertEquals('blue', $typography['color']);
        $this->assertEquals(18, $typography['font_size']['number']);

        // ID rule should be consumed
        $this->assertStringNotContainsString('#main', $result['extractedCss']);
    }

    // ─── Tag and Content ────────────────────────────────────────────

    /**
     * Test tag option is stored in the correct Oxygen property path.
     */
    public function testTagOptionOnElement(): void
    {
        $html = '<span>Inline</span><section>Body</section>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $span = $root['children'][0] ?? null;
        $section = $root['children'][1] ?? null;

        $this->assertNotNull($span);
        $this->assertNotNull($section);

        // Oxygen Text uses settings.tag
        $this->assertEquals('span', $span['data']['properties']['settings']['tag']);

        // Container keeps tag in settings.tag for renderer compatibility
        $this->assertEquals('section', $section['data']['properties']['settings']['tag']);
        // ...and mirrors to design.tag for backward compatibility
        $this->assertEquals('section', $section['data']['properties']['design']['tag']);
    }

    /**
     * Preserve list markup via HtmlCode to avoid renderer list flattening.
     */
    public function testListMarkupPreservedAsHtmlCode(): void
    {
        $html = '<ul class="menu"><li>One</li><li>Two</li></ul>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertEquals('OxygenElements\\HtmlCode', $result['element']['data']['type']);

        $htmlCode = $result['element']['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<ul class="menu">', $htmlCode);
        $this->assertStringContainsString('<li>One</li>', $htmlCode);
        $this->assertStringContainsString('<li>Two</li>', $htmlCode);
    }

    /**
     * Preserve heading/paragraph semantics via HtmlCode to avoid div-like text flattening.
     */
    public function testHeadingAndParagraphPreservedAsHtmlCode(): void
    {
        $html = '<h2 class="title">Heading</h2><p id="lead">Intro</p>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $h2 = $root['children'][0] ?? null;
        $p = $root['children'][1] ?? null;

        $this->assertNotNull($h2);
        $this->assertNotNull($p);
        $this->assertEquals('OxygenElements\\HtmlCode', $h2['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $p['data']['type']);

        $h2Html = $h2['data']['properties']['content']['content']['html_code'] ?? '';
        $pHtml = $p['data']['properties']['content']['content']['html_code'] ?? '';

        $this->assertStringContainsString('<h2 class="title">Heading</h2>', $h2Html);
        $this->assertStringContainsString('<p id="lead">Intro</p>', $pHtml);
    }

    /**
     * Preserve high-impact stat spans via HtmlCode while keeping generic spans on normal path.
     */
    public function testStatSpanPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<span class="stat-value">42%</span><span class="label">Label</span>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $stat = $root['children'][0] ?? null;
        $label = $root['children'][1] ?? null;

        $this->assertNotNull($stat);
        $this->assertNotNull($label);

        $this->assertEquals('OxygenElements\\HtmlCode', $stat['data']['type']);
        $statHtml = $stat['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<span class="stat-value">42%</span>', $statHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $label['data']['type']);
    }

    /**
     * Preserve targeted parity hotspot spans via HtmlCode while keeping generic spans on normal path.
     */
    public function testParityHotspotSpanPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<span class="gradient-text">Power</span><span class="feature-text">Fast</span><span class="label">Label</span>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $gradient = $root['children'][0] ?? null;
        $feature = $root['children'][1] ?? null;
        $label = $root['children'][2] ?? null;

        $this->assertNotNull($gradient);
        $this->assertNotNull($feature);
        $this->assertNotNull($label);

        $this->assertEquals('OxygenElements\\HtmlCode', $gradient['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $feature['data']['type']);

        $gradientHtml = $gradient['data']['properties']['content']['content']['html_code'] ?? '';
        $featureHtml = $feature['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<span class="gradient-text">Power</span>', $gradientHtml);
        $this->assertStringContainsString('<span class="feature-text">Fast</span>', $featureHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $label['data']['type']);
    }

    /**
     * Preserve key section wrappers via HtmlCode while keeping generic section mapping.
     */
    public function testParitySectionPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<section class="hero">Hero</section><section class="content-wrap">Content</section>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $hero = $root['children'][0] ?? null;
        $content = $root['children'][1] ?? null;

        $this->assertNotNull($hero);
        $this->assertNotNull($content);

        $this->assertEquals('OxygenElements\\HtmlCode', $hero['data']['type']);
        $heroHtml = $hero['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<section class="hero">Hero</section>', $heroHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $content['data']['type']);
    }

    /**
     * Preserve nav/footer semantic wrappers via HtmlCode while keeping generic mapping.
     */
    public function testParityNavFooterPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<nav class="nav-content">Nav</nav><footer class="footer-content">Foot</footer><footer class="footer-links">Links</footer><nav class="nav glass">Main</nav><nav class="nav-link">Item</nav><nav class="menu">Menu</nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $nav = $root['children'][0] ?? null;
        $footer = $root['children'][1] ?? null;
        $footerPrefixed = $root['children'][2] ?? null;
        $navGlass = $root['children'][3] ?? null;
        $navDenied = $root['children'][4] ?? null;
        $menu = $root['children'][5] ?? null;

        $this->assertNotNull($nav);
        $this->assertNotNull($footer);
        $this->assertNotNull($footerPrefixed);
        $this->assertNotNull($navGlass);
        $this->assertNotNull($navDenied);
        $this->assertNotNull($menu);

        $this->assertEquals('OxygenElements\\HtmlCode', $nav['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $footer['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $footerPrefixed['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $navGlass['data']['type']);

        $navHtml = $nav['data']['properties']['content']['content']['html_code'] ?? '';
        $footerHtml = $footer['data']['properties']['content']['content']['html_code'] ?? '';
        $footerPrefixedHtml = $footerPrefixed['data']['properties']['content']['content']['html_code'] ?? '';
        $navGlassHtml = $navGlass['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<nav class="nav-content">Nav</nav>', $navHtml);
        $this->assertStringContainsString('<footer class="footer-content">Foot</footer>', $footerHtml);
        $this->assertStringContainsString('<footer class="footer-links">Links</footer>', $footerPrefixedHtml);
        $this->assertStringContainsString('<nav class="nav glass">Main</nav>', $navGlassHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $navDenied['data']['type']);
        $this->assertNotEquals('OxygenElements\\HtmlCode', $menu['data']['type']);
    }

    /**
     * Preserve footer/nav hotspot wrapper divs via HtmlCode while keeping generic div mapping.
     */
    public function testParityFooterNavDivPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<div class="footer-content">Foot</div><div class="footer-col">Col</div><div class="bottom-bar">Bar</div><div class="nav-content">Nav</div><div class="nav-container">Wrap</div><div class="mobile-menu">Menu</div><div class="nav-toggle"><span></span><span></span><span></span></div><div class="stat-item">Stat</div><div class="content-wrap">Content</div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $footer = $root['children'][0] ?? null;
        $footerCol = $root['children'][1] ?? null;
        $bottomBar = $root['children'][2] ?? null;
        $navContent = $root['children'][3] ?? null;
        $navContainer = $root['children'][4] ?? null;
        $mobileMenu = $root['children'][5] ?? null;
        $navToggle = $root['children'][6] ?? null;
        $statItem = $root['children'][7] ?? null;
        $content = $root['children'][8] ?? null;

        $this->assertNotNull($footer);
        $this->assertNotNull($footerCol);
        $this->assertNotNull($bottomBar);
        $this->assertNotNull($navContent);
        $this->assertNotNull($navContainer);
        $this->assertNotNull($mobileMenu);
        $this->assertNotNull($navToggle);
        $this->assertNotNull($statItem);
        $this->assertNotNull($content);

        $this->assertEquals('OxygenElements\\HtmlCode', $footer['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $footerCol['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $bottomBar['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $navContent['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $navContainer['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $mobileMenu['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $navToggle['data']['type']);
        $this->assertEquals('OxygenElements\\HtmlCode', $statItem['data']['type']);

        $footerHtml = $footer['data']['properties']['content']['content']['html_code'] ?? '';
        $navContainerHtml = $navContainer['data']['properties']['content']['content']['html_code'] ?? '';
        $mobileMenuHtml = $mobileMenu['data']['properties']['content']['content']['html_code'] ?? '';
        $navToggleHtml = $navToggle['data']['properties']['content']['content']['html_code'] ?? '';
        $statItemHtml = $statItem['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<div class="footer-content">Foot</div>', $footerHtml);
        $this->assertStringContainsString('<div class="nav-container">Wrap</div>', $navContainerHtml);
        $this->assertStringContainsString('<div class="mobile-menu">Menu</div>', $mobileMenuHtml);
        $this->assertStringContainsString('<div class="nav-toggle"><span></span><span></span><span></span></div>', $navToggleHtml);
        $this->assertStringContainsString('<div class="stat-item">Stat</div>', $statItemHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $content['data']['type']);
    }

    /**
     * Preserve add-to-cart buttons via HtmlCode while keeping generic button mapping.
     */
    public function testAddToCartButtonPreservedAsHtmlCodeScopeIsNarrow(): void
    {
        $html = '<button class="add-to-cart">Buy</button><button class="cta-btn">Click</button>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $root = $result['element'];

        $buy = $root['children'][0] ?? null;
        $cta = $root['children'][1] ?? null;

        $this->assertNotNull($buy);
        $this->assertNotNull($cta);

        $this->assertEquals('OxygenElements\\HtmlCode', $buy['data']['type']);
        $buyHtml = $buy['data']['properties']['content']['content']['html_code'] ?? '';
        $this->assertStringContainsString('<button class="add-to-cart">Buy</button>', $buyHtml);

        $this->assertNotEquals('OxygenElements\\HtmlCode', $cta['data']['type']);
    }

    /**
     * Test onclick to interaction conversion.
     */
    public function testInteractionConversion(): void
    {
        $html = '<button onclick="toggleMenu()">Click Me</button>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $this->assertEquals('OxygenElements\\Container', $element['data']['type']);
        $interactions = $element['data']['properties']['settings']['interactions']['interactions'];
        $this->assertNotEmpty($interactions);
        $this->assertEquals('click', $interactions[0]['trigger']);
        $this->assertEquals('toggleMenu', $interactions[0]['actions'][0]['js_function_name']);
    }

    /**
     * Test preservation of scroll listener.
     */
    public function testScrollScriptTransformation(): void
    {
        $js = "window.addEventListener('scroll', () => { console.log('scroll'); });";
        $html = '<script>' . $js . '</script>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);

        $element = $result['element'];
        $found = false;

        $checkNode = function($node) use (&$checkNode, &$found) {
            if ($node['data']['type'] === 'OxygenElements\\JavaScriptCode') {
                $code = $node['data']['properties']['content']['content']['javascript_code'];
                if (strpos($code, "window.addEventListener('scroll'") !== false) {
                    $found = true;
                }
            }
            foreach ($node['children'] as $child) {
                $checkNode($child);
            }
        };

        $checkNode($element);
        $this->assertTrue($found, "Scroll listener script should be preserved in JavaScript Code element");
    }

    /**
     * Test integration of Wedding.html critical parts.
     */
    public function testWeddingHtmlIntegration(): void
    {
        $html = <<<HTML
<style>
    .glass-panel { background: rgba(0,0,0,0.1); }
</style>
<nav id="navbar" class="glass-panel">
    <button onclick="toggleMenu()">Menu</button>
</nav>
<script>
    function toggleMenu() { console.log('toggled'); }
    window.addEventListener('scroll', () => {});
</script>
HTML;

        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('.glass-panel', $result['extractedCss']);

        $root = $result['element'];

        // Verify nav
        $nav = null;
        foreach($root['children'] as $child) {
            if (isset($child['data']['properties']['settings']['advanced']['id']) && $child['data']['properties']['settings']['advanced']['id'] === 'navbar') {
                $nav = $child;
                break;
            }
        }

        $this->assertNotNull($nav);
        $this->assertContains('glass-panel', $nav['data']['properties']['settings']['advanced']['classes']);
    }

    /**
     * Test Tailwind grid mapping creates class definitions.
     */
    public function testGridMapping(): void
    {
        $html = '<div class="grid grid-cols-3 gap-8"><div>1</div><div>2</div><div>3</div></div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        // Grid properties should now be in a class definition
        $this->assertHasClassUuids($element);

        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        // The flattened design props from GridDetector go through flattenDesignToDeclarations
        // and then to OxygenClassBuilder. The exact routing depends on what GridDetector returns.
        $base = $classDef['properties']['breakpoint_base'];
        $this->assertNotEmpty($base);
    }

    /**
     * Test URL sanitization.
     */
    public function testUrlSanitization(): void
    {
        $html = '<img src="file:///D:/Images/wedding.jpg" alt="Wedding">';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $url = $element['data']['properties']['content']['image']['url'];
        $this->assertEquals('wedding.jpg', $url);
    }

    // ─── Heuristic Tests ────────────────────────────────────────────

    /**
     * Test button centering creates class definition.
     */
    public function testButtonCentering(): void
    {
        $this->builder->getHeuristics()->enableHeuristic('button_centering');

        $html = '<button class="bg-blue-500">Click Me</button>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $this->assertHasClassUuids($element);
        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $layout = $classDef['properties']['breakpoint_base']['layout'];
        $this->assertEquals('flex', $layout['display']);
        $this->assertEquals('center', $layout['justify_content']);
        $this->assertEquals('center', $layout['align_items']);
    }

    /**
     * Test Header sticky settings creates class definition.
     */
    public function testHeaderStickySettings(): void
    {
        $this->builder->getHeuristics()->enableHeuristic('sticky_navbar');

        $html = '<nav id="navbar">Content</nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $this->assertHasClassUuids($element);
        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $position = $classDef['properties']['breakpoint_base']['position'];
        $this->assertEquals('sticky', $position['position']);
        $this->assertEquals(0, $position['top']['number']);
        $this->assertEquals(999, $position['z_index']);
    }

    /**
     * Test Nav Link styling creates class definition.
     */
    public function testNavLinkStyling(): void
    {
        $this->builder->getHeuristics()->enableHeuristic('nav_link_white');

        $html = '<nav><a href="#">Link</a></nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $nav = $result['element'];
        $link = $nav['children'][0];

        $this->assertEquals('OxygenElements\\TextLink', $link['data']['type']);

        // Link should have class definition with typography
        $this->assertHasClassUuids($link);
        $classDef = $this->getElementClassDef($link, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $typography = $classDef['properties']['breakpoint_base']['typography'];
        $this->assertEquals('#FFFFFFFF', $typography['color']);
    }

    /**
     * Test that heuristics are disabled by default.
     */
    public function testHeuristicsDisabledByDefault(): void
    {
        $html = '<button class="bg-blue-500">Click Me</button>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        // Without heuristics, button should NOT have design classes for centering
        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        if ($classDef !== null) {
            $layout = $classDef['properties']['breakpoint_base']['layout'] ?? [];
            $this->assertArrayNotHasKey('display', $layout, 'Button should not have display:flex without heuristics');
        }
    }

    // ─── Native Animation Tests ─────────────────────────────────────

    /**
     * Test scroll-reveal elements get native entrance animation.
     */
    public function testScrollRevealEntranceAnimation(): void
    {
        $html = <<<HTML
<style>
.animate-on-scroll {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.animate-on-scroll.visible {
    opacity: 1;
    transform: translateY(0);
}
</style>
<div class="animate-on-scroll">Animated content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $element = $result['element'];
        $animation = $element['data']['properties']['settings']['animations']['entrance_animation'];

        $this->assertEquals('slideUp', $animation['type']);
        $this->assertEquals(600, $animation['duration']);
        $this->assertEquals(0, $animation['delay']);
        $this->assertEquals('ease', $animation['easing']);
        $this->assertEquals(30, $animation['distance']);
        $this->assertTrue($animation['once']);
    }

    /**
     * Test stagger classes produce correct delay.
     */
    public function testStaggerDelay(): void
    {
        $html = <<<HTML
<style>
.animate-on-scroll {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.stagger-3 { transition-delay: 0.3s; }
</style>
<div class="animate-on-scroll stagger-3">Staggered</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $animation = $result['element']['data']['properties']['settings']['animations']['entrance_animation'];
        $this->assertEquals(300, $animation['delay']);

        // Stagger class should be removed from element classes
        $classes = $result['element']['data']['properties']['settings']['advanced']['classes'] ?? [];
        $this->assertNotContains('animate-on-scroll', $classes);
        $this->assertNotContains('stagger-3', $classes);
    }

    /**
     * Test hero element fadeInUp animations.
     */
    public function testHeroFadeInUpAnimation(): void
    {
        $html = <<<HTML
<style>
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(40px); }
    to { opacity: 1; transform: translateY(0); }
}
.hero-badge {
    animation: fadeInUp 0.8s ease forwards;
    animation-delay: 0.2s;
    opacity: 0;
}
.hero-headline {
    animation: fadeInUp 0.8s ease forwards;
    animation-delay: 0.4s;
    opacity: 0;
}
</style>
<div>
    <span class="hero-badge">Badge</span>
    <h1 class="hero-headline">Headline</h1>
</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $badge = null;
        $headline = null;
        foreach ($result['element']['children'] as $child) {
            if (isset($child['data']['properties']['settings']['animations']['entrance_animation'])) {
                $anim = $child['data']['properties']['settings']['animations']['entrance_animation'];
                if ($anim['delay'] === 200) {
                    $badge = $child;
                } elseif ($anim['delay'] === 400) {
                    $headline = $child;
                }
            }
        }

        $this->assertNotNull($badge, 'Badge should have entrance animation with 200ms delay');
        $this->assertNotNull($headline, 'Headline should have entrance animation with 400ms delay');
        $this->assertEquals('slideUp', $badge['data']['properties']['settings']['animations']['entrance_animation']['type']);
        $this->assertEquals(800, $badge['data']['properties']['settings']['animations']['entrance_animation']['duration']);
    }

    /**
     * Test CSS cleanup removes converted animation rules.
     */
    public function testCssCleanupRemovesAnimationRules(): void
    {
        $html = <<<HTML
<style>
.animate-on-scroll {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.animate-on-scroll.visible {
    opacity: 1;
    transform: translateY(0);
}
.other-style { color: red; }
</style>
<div class="animate-on-scroll">Content</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $this->assertStringNotContainsString('.animate-on-scroll', $result['extractedCss']);
        $this->assertStringContainsString('.other-style', $result['extractedCss']);
    }

    // ─── Interaction Tests ──────────────────────────────────────────

    /**
     * Test smooth scroll anchor links get scroll_to interaction.
     */
    public function testSmoothScrollInteraction(): void
    {
        $html = <<<HTML
<a href="#services">Services</a>
<script>
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
</script>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $link = null;
        $this->findElement($result['element'], function ($el) use (&$link) {
            if (($el['data']['properties']['content']['content']['url'] ?? '') === '#services') {
                $link = $el;
            }
        });

        $this->assertNotNull($link, 'Link element should exist');
        $interactions = $link['data']['properties']['settings']['interactions']['interactions'] ?? [];
        $this->assertNotEmpty($interactions, 'Link should have interactions');

        $scrollAction = null;
        foreach ($interactions as $interaction) {
            foreach ($interaction['actions'] as $action) {
                if ($action['name'] === 'scroll_to') {
                    $scrollAction = $action;
                    break 2;
                }
            }
        }

        $this->assertNotNull($scrollAction, 'Should have scroll_to action');
        $this->assertEquals('#services', $scrollAction['target']);
        $this->assertEquals('smooth', $scrollAction['scroll_behavior']);
    }

    /**
     * Test toggle interactions from JS analysis.
     */
    public function testToggleInteractionFromJs(): void
    {
        $html = <<<HTML
<div id="navToggle">Toggle</div>
<div id="mobileMenu">Menu</div>
<script>
const navToggle = document.getElementById('navToggle');
const mobileMenu = document.getElementById('mobileMenu');
navToggle.addEventListener('click', () => {
    mobileMenu.classList.toggle('active');
});
</script>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $toggle = null;
        $this->findElement($result['element'], function ($el) use (&$toggle) {
            if (($el['data']['properties']['settings']['advanced']['id'] ?? '') === 'navToggle') {
                $toggle = $el;
            }
        });

        $this->assertNotNull($toggle, 'navToggle element should exist');
        $interactions = $toggle['data']['properties']['settings']['interactions']['interactions'] ?? [];
        $this->assertNotEmpty($interactions, 'navToggle should have interactions');
        $this->assertEquals('click', $interactions[0]['trigger']);
        $this->assertEquals('toggle_class', $interactions[0]['actions'][0]['name']);
        $this->assertEquals('#mobileMenu', $interactions[0]['actions'][0]['target']);
        $this->assertEquals('active', $interactions[0]['actions'][0]['class_name']);
    }

    // ─── Inline Style Tests ─────────────────────────────────────────

    /**
     * Test inline styles become class definitions.
     */
    public function testInlineStylesCreateClassDefinition(): void
    {
        $html = '<div style="font-size: 24px; color: blue; padding: 10px;">Content</div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $this->assertHasClassUuids($element);

        $classDef = $this->getElementClassDef($element, $result['classDefinitions']);
        $this->assertNotNull($classDef);

        $base = $classDef['properties']['breakpoint_base'];
        $this->assertEquals(24, $base['typography']['font_size']['number']);
        $this->assertEquals('blue', $base['typography']['color']);
    }

    /**
     * Test elements without styles don't get class UUIDs.
     */
    public function testElementWithoutStylesNoClassUuid(): void
    {
        $html = '<div>Plain content</div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $classes = $element['data']['properties']['meta']['classes'] ?? [];
        $this->assertEmpty($classes, 'Element without styles should not have meta.classes');
    }

    /**
     * Test deduplication: identical styles share one class definition.
     */
    public function testDeduplicationOfIdenticalStyles(): void
    {
        $html = <<<HTML
<style>
.item { color: red; font-size: 16px; }
</style>
<div>
    <div class="item">One</div>
    <div class="item">Two</div>
</div>
HTML;

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        // Both items should reference the same class definition
        $item1 = null;
        $item2 = null;
        $count = 0;
        foreach ($result['element']['children'] as $child) {
            $uuids = $child['data']['properties']['meta']['classes'] ?? [];
            if (!empty($uuids)) {
                if ($count === 0) $item1 = $uuids[0];
                else $item2 = $uuids[0];
                $count++;
            }
        }

        if ($item1 !== null && $item2 !== null) {
            $this->assertEquals($item1, $item2, 'Identical styles should share one class definition');
        }

        // Should have only 1 class definition for the shared style
        $this->assertCount(1, $result['classDefinitions']);
    }

    // ─── CSS Cleanup ─────────────────────────────────────────────

    public function testCssCleanupPreservesCommaSelectors(): void
    {
        // When only one selector in a comma group is consumed, the remaining must survive intact
        $html = '<style>.hero-left, .hero-right { flex: 1; display: flex; } .other { color: red; }</style>'
              . '<div class="hero-left"><p>Left</p></div>';

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $css = $result['extractedCss'];

        // .hero-left was consumed (matched the element), .hero-right should remain
        $this->assertStringContainsString('.hero-right', $css, 'Unconsumed selector from comma group must remain');
        $this->assertStringContainsString('flex: 1', $css, 'Declarations for remaining selector must survive');

        // .hero-left should be removed from the selector list
        $this->assertStringNotContainsString('.hero-left', $css, 'Consumed selector should be removed');
    }

    public function testCssCleanupRemovesFullyConsumedRule(): void
    {
        $html = '<style>.foo { color: red; }</style>'
              . '<div class="foo">Test</div>';

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $css = $result['extractedCss'];
        $this->assertStringNotContainsString('.foo', $css, 'Fully consumed rule should be removed');
        $this->assertStringNotContainsString('color: red', $css, 'Fully consumed declarations should be removed');
    }

    public function testCssCleanupPreservesAtRules(): void
    {
        $html = '<style>@keyframes fade { from { opacity: 0; } to { opacity: 1; } } .foo { color: red; }</style>'
              . '<div class="foo">Test</div>';

        $result = $this->builder->convert($html);
        $this->assertTrue($result['success']);

        $css = $result['extractedCss'];
        $this->assertStringContainsString('@keyframes fade', $css, '@keyframes must be preserved');
        $this->assertStringContainsString('opacity: 0', $css, '@keyframes body must be preserved');
    }
}
