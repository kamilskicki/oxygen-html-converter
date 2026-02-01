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

    /**
     * Test preservation of HTML IDs
     */
    public function testIdPreservation(): void
    {
        $html = '<nav id="navbar" class="test-class">Content</nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        
        $element = $result['element'];
        
        // Navbar is now a Container with sticky settings
        $this->assertEquals('OxygenElements\\Container', $element['data']['type']);
        $this->assertEquals('navbar', $element['data']['properties']['settings']['advanced']['id']);
    }

    /**
     * Test preservation of complex Tailwind classes
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

    /**
     * Test CSS extraction from body style tags
     */
    public function testCssExtractionFromBody(): void
    {
        $html = '<body><style>.glass-panel { color: red; }</style><div class="glass-panel">Content</div></body>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('.glass-panel', $result['extractedCss']);
        $this->assertStringContainsString('color: red', $result['extractedCss']);
    }

    /**
     * Test onclick to interaction conversion
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
     * Test preservation and wrapping of scroll listener
     */
    public function testScrollScriptTransformation(): void
    {
        $js = "window.addEventListener('scroll', () => { console.log('scroll'); });";
        $html = '<script>' . $js . '</script>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        
        // Find the JavaScript_Code element
        $element = $result['element'];
        $found = false;
        
        $checkNode = function($node) use (&$checkNode, &$found) {
            if ($node['data']['type'] === 'OxygenElements\\JavaScript_Code') {
                $code = $node['data']['properties']['content']['content']['javascript_code'];
                if (strpos($code, "window.addEventListener('scroll'") !== false) {
                    // Should be wrapped in DOMContentLoaded
                    if (strpos($code, "document.addEventListener('DOMContentLoaded'") !== false) {
                        $found = true;
                    }
                }
            }
            foreach ($node['children'] as $child) {
                $checkNode($child);
            }
        };

        $checkNode($element);
        $this->assertTrue($found, "Scroll listener script should be found and wrapped in DOMContentLoaded");
    }

    /**
     * Test integration of Wedding.html critical parts
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
        $this->assertStringContainsString('.glass-panel', $result['extractedCss']);
        
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
     * Test Tailwind grid mapping
     */
    public function testGridMapping(): void
    {
        $html = '<div class="grid grid-cols-3 gap-8"><div>1</div><div>2</div><div>3</div></div>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $layout = $element['data']['properties']['design']['layout'];
        $this->assertEquals('grid', $layout['display']);
        $this->assertEquals('true', $layout['grid']);
        $this->assertEquals('repeat(3, minmax(0, 1fr))', $layout['grid-template-columns']);
        $this->assertEquals('2rem', $layout['gap']);
    }

    /**
     * Test URL sanitization
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

    /**
     * Test button centering
     */
    public function testButtonCentering(): void
    {
        $html = '<button class="bg-blue-500">Click Me</button>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $layout = $element['data']['properties']['design']['layout'];
        $this->assertEquals('flex', $layout['display']);
        $this->assertEquals('center', $layout['justify-content']);
        $this->assertEquals('center', $layout['align-items']);

        $typography = $element['data']['properties']['design']['typography'];
        $this->assertEquals('center', $typography['text-align']);
    }

    /**
     * Test Header sticky settings (now on Container)
     */
    public function testHeaderStickySettings(): void
    {
        $html = '<nav id="navbar">Content</nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $element = $result['element'];

        $this->assertEquals('OxygenElements\\Container', $element['data']['type']);
        $sticky = $element['data']['properties']['design']['sticky'];
        $this->assertEquals('top', $sticky['position']);
        $this->assertEquals('viewport', $sticky['relative_to']);
        $this->assertEquals('0', $sticky['offset']);
    }

    /**
     * Test Nav Link styling
     */
    public function testNavLinkStyling(): void
    {
        $html = '<nav><a href="#">Link</a></nav>';
        $result = $this->builder->convert($html);

        $this->assertTrue($result['success']);
        $nav = $result['element'];
        $link = $nav['children'][0];

        $this->assertEquals('OxygenElements\\Text_Link', $link['data']['type']);
        $typography = $link['data']['properties']['design']['typography'];
        $this->assertEquals('none', $typography['text-decoration']);
        $this->assertEquals('#ffffff', $typography['color']);
    }
}
