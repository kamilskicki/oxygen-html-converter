<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\AnimationDetector;
use OxyHtmlConverter\Tests\TestCase;
use DOMDocument;

class AnimationDetectorTest extends TestCase
{
    private AnimationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AnimationDetector();
    }

    public function test_detects_scroll_reveal_slideUp(): void
    {
        $cssRules = [
            ['selector' => '.animate-on-scroll', 'declarations' => [
                'opacity' => '0',
                'transform' => 'translateY(30px)',
                'transition' => 'opacity 0.6s ease, transform 0.6s ease',
            ]],
            ['selector' => '.animate-on-scroll.visible', 'declarations' => [
                'opacity' => '1',
                'transform' => 'translateY(0)',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="animate-on-scroll">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['animate-on-scroll'], []);

        $this->assertNotNull($result);
        $this->assertEquals('slideUp', $result['type']);
        $this->assertEquals(600, $result['duration']);
        $this->assertEquals(0, $result['delay']);
        $this->assertEquals('ease', $result['easing']);
        $this->assertEquals(30, $result['distance']);
        $this->assertTrue($result['once']);
    }

    public function test_detects_stagger_delay(): void
    {
        $cssRules = [
            ['selector' => '.animate-on-scroll', 'declarations' => [
                'opacity' => '0',
                'transform' => 'translateY(30px)',
                'transition' => 'opacity 0.6s ease, transform 0.6s ease',
            ]],
            ['selector' => '.stagger-3', 'declarations' => [
                'transition-delay' => '0.3s',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="animate-on-scroll stagger-3">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['animate-on-scroll', 'stagger-3'], []);

        $this->assertNotNull($result);
        $this->assertEquals(300, $result['delay']);
        $this->assertContains('stagger-3', $this->detector->getConsumedClasses());
        $this->assertContains('animate-on-scroll', $this->detector->getConsumedClasses());
    }

    public function test_detects_keyframe_fadeInUp(): void
    {
        $cssRules = [
            ['selector' => '.hero-badge', 'declarations' => [
                'animation' => 'fadeInUp 0.8s ease forwards',
                'animation-delay' => '0.2s',
                'opacity' => '0',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="hero-badge">Badge</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['hero-badge'], []);

        $this->assertNotNull($result);
        $this->assertEquals('slideUp', $result['type']);
        $this->assertEquals(800, $result['duration']);
        $this->assertEquals(200, $result['delay']);
        $this->assertEquals('ease', $result['easing']);
        $this->assertEquals(40, $result['distance']);
    }

    public function test_detects_hero_headline_delay(): void
    {
        $cssRules = [
            ['selector' => '.hero-headline', 'declarations' => [
                'animation' => 'fadeInUp 0.8s ease forwards',
                'animation-delay' => '0.4s',
                'opacity' => '0',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<h1 class="hero-headline">Title</h1>');
        $node = $doc->getElementsByTagName('h1')->item(0);

        $result = $this->detector->detectAnimations($node, ['hero-headline'], []);

        $this->assertNotNull($result);
        $this->assertEquals(400, $result['delay']);
    }

    public function test_returns_null_for_non_animated_element(): void
    {
        $this->detector->analyzeCssRules([], '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="container">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['container'], []);
        $this->assertNull($result);
    }

    public function test_detects_fade_only(): void
    {
        $cssRules = [
            ['selector' => '.fade-in', 'declarations' => [
                'opacity' => '0',
                'transition' => 'opacity 0.5s ease',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="fade-in">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['fade-in'], []);

        $this->assertNotNull($result);
        $this->assertEquals('fade', $result['type']);
        $this->assertEquals(500, $result['duration']);
        $this->assertEquals(0, $result['distance']);
    }

    public function test_detects_scale_zoomIn(): void
    {
        $cssRules = [
            ['selector' => '.reveal', 'declarations' => [
                'opacity' => '0',
                'transform' => 'scale(0.8)',
                'transition' => 'opacity 0.4s ease, transform 0.4s ease',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="reveal">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $result = $this->detector->detectAnimations($node, ['reveal'], []);

        $this->assertNotNull($result);
        $this->assertEquals('zoomIn', $result['type']);
    }

    public function test_cleanup_removes_consumed_css(): void
    {
        $css = <<<CSS
.animate-on-scroll {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}

.animate-on-scroll.visible {
    opacity: 1;
    transform: translateY(0);
}

.stagger-1 { transition-delay: 0.1s; }
.stagger-2 { transition-delay: 0.2s; }

.other-class { color: red; }
CSS;

        $cssRules = [
            ['selector' => '.animate-on-scroll', 'declarations' => [
                'opacity' => '0',
                'transform' => 'translateY(30px)',
                'transition' => 'opacity 0.6s ease, transform 0.6s ease',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, $css);

        // Trigger detection to mark selectors as consumed
        $doc = new DOMDocument();
        $doc->loadHTML('<div class="animate-on-scroll stagger-1">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);
        $this->detector->detectAnimations($node, ['animate-on-scroll', 'stagger-1'], []);

        $doc->loadHTML('<div class="animate-on-scroll stagger-2">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);
        $this->detector->detectAnimations($node, ['animate-on-scroll', 'stagger-2'], []);

        $cleaned = $this->detector->cleanupConvertedCss($css);

        $this->assertStringNotContainsString('.animate-on-scroll', $cleaned);
        $this->assertStringNotContainsString('.stagger-1', $cleaned);
        $this->assertStringNotContainsString('.stagger-2', $cleaned);
        $this->assertStringContainsString('.other-class', $cleaned);
    }

    public function test_consumed_selectors_tracked(): void
    {
        $cssRules = [
            ['selector' => '.animate-on-scroll', 'declarations' => [
                'opacity' => '0',
                'transform' => 'translateY(30px)',
                'transition' => 'opacity 0.6s ease',
            ]],
        ];

        $this->detector->analyzeCssRules($cssRules, '');

        $doc = new DOMDocument();
        $doc->loadHTML('<div class="animate-on-scroll stagger-2">Content</div>');
        $node = $doc->getElementsByTagName('div')->item(0);
        $this->detector->detectAnimations($node, ['animate-on-scroll', 'stagger-2'], []);

        $selectors = $this->detector->getConsumedCssSelectors();
        $this->assertContains('.animate-on-scroll', $selectors);
        $this->assertContains('.animate-on-scroll.visible', $selectors);
        $this->assertContains('.stagger-2', $selectors);
    }
}
