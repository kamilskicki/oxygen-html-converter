<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\HtmlParser;
use DOMDocument;

class FrameworkDetectorTest extends TestCase
{
    private FrameworkDetector $detector;
    private ConversionReport $report;
    private HtmlParser $parser;

    protected function setUp(): void
    {
        $this->report = new ConversionReport();
        $this->detector = new FrameworkDetector($this->report);
        $this->parser = new HtmlParser();
    }

    public function testDetectAlpine()
    {
        $div = $this->parser->parse('<div x-data="{ open: false }"></div>')->getElementsByTagName('div')->item(0);
        
        $detected = $this->detector->detect($div);
        
        $this->assertContains('Alpine.js', $detected);
        $this->assertContains('Alpine.js detected. Ensure Alpine.js script is included in your WordPress site.', $this->report->toArray()['warnings']);
    }

    public function testDetectHtmx()
    {
        $button = $this->parser->parse('<button hx-post="/clicked"></button>')->getElementsByTagName('button')->item(0);
        
        $detected = $this->detector->detect($button);
        
        $this->assertContains('HTMX', $detected);
        $this->assertContains('HTMX detected. Ensure HTMX script is included in your WordPress site.', $this->report->toArray()['warnings']);
    }

    public function testDetectStimulus()
    {
        $div = $this->parser->parse('<div data-controller="hello"></div>')->getElementsByTagName('div')->item(0);
        
        $detected = $this->detector->detect($div);
        
        $this->assertContains('Stimulus.js', $detected);
        $this->assertContains('Stimulus.js detected. Ensure Stimulus.js is properly initialized in your project.', $this->report->toArray()['warnings']);
    }

    public function testIsFrameworkAttribute()
    {
        $this->assertTrue($this->detector->isFrameworkAttribute('x-data'));
        $this->assertTrue($this->detector->isFrameworkAttribute('@click'));
        $this->assertTrue($this->detector->isFrameworkAttribute(':class'));
        $this->assertTrue($this->detector->isFrameworkAttribute('hx-get'));
        $this->assertTrue($this->detector->isFrameworkAttribute('data-controller'));
        
        $this->assertFalse($this->detector->isFrameworkAttribute('class'));
        $this->assertFalse($this->detector->isFrameworkAttribute('id'));
        $this->assertFalse($this->detector->isFrameworkAttribute('data-other'));
    }

    public function testHasAlpineAttributes()
    {
        $parsed1 = $this->parser->parse('<div x-show="true"></div>');
        $div = $parsed1->getElementsByTagName('div')->item(0);
        if (!$div) {
            // Maybe it's the root itself if no body was created?
            $div = ($parsed1->tagName === 'div') ? $parsed1 : null;
        }
        $this->assertNotNull($div, 'Div 1 should not be null');
        $this->assertTrue($this->detector->hasAlpineAttributes($div));

        $parsed2 = $this->parser->parse('<div @click="doSomething"></div>');
        $div2 = $parsed2->getElementsByTagName('div')->item(0);
        if (!$div2) {
            $div2 = ($parsed2->tagName === 'div') ? $parsed2 : null;
        }
        $this->assertNotNull($div2, 'Div 2 should not be null');
        
        $this->assertTrue($this->detector->hasAlpineAttributes($div2), 'Div 2 should have Alpine attributes');

        $parsed3 = $this->parser->parse('<div :class="active"></div>');
        $div3 = $parsed3->getElementsByTagName('div')->item(0);
        if (!$div3) {
            $div3 = ($parsed3->tagName === 'div') ? $parsed3 : null;
        }
        $this->assertNotNull($div3, 'Div 3 should not be null');
        $this->assertTrue($this->detector->hasAlpineAttributes($div3));

        $parsed4 = $this->parser->parse('<div></div>');
        $div4 = $parsed4->getElementsByTagName('div')->item(0);
        if (!$div4) {
            $div4 = ($parsed4->tagName === 'div') ? $parsed4 : null;
        }
        $this->assertNotNull($div4, 'Div 4 should not be null');
        $this->assertFalse($this->detector->hasAlpineAttributes($div4));
    }
}
