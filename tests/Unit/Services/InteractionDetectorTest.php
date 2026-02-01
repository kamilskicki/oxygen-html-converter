<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\InteractionDetector;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Tests\TestCase;
use DOMDocument;

class InteractionDetectorTest extends TestCase
{
    private InteractionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $report = new ConversionReport();
        $frameworkDetector = new FrameworkDetector($report);
        $this->detector = new InteractionDetector($frameworkDetector);
    }

    public function test_it_detects_expanded_event_types()
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<div ondblclick="test()" onchange="test2()" onsubmit="test3()"></div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $interactions = $element['data']['properties']['settings']['interactions']['interactions'];
        $triggers = array_column($interactions, 'trigger');

        $this->assertContains('dblclick', $triggers);
        $this->assertContains('change', $triggers);
        $this->assertContains('submit', $triggers);
    }

    public function test_it_handles_multiple_function_calls()
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<div onclick="func1(); func2(123);"></div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $interactions = $element['data']['properties']['settings']['interactions']['interactions'];
        $this->assertCount(1, $interactions);
        $this->assertCount(2, $interactions[0]['actions']);

        $this->assertEquals('func1', $interactions[0]['actions'][0]['js_function_name']);
        $this->assertEquals('func2', $interactions[0]['actions'][1]['js_function_name']);

        // Check data attributes for func2 arguments
        $attributes = $element['data']['properties']['settings']['advanced']['attributes'];
        $this->assertEquals('data-arg-func2', $attributes[0]['name']);
        $this->assertEquals('123', $attributes[0]['value']);
    }

    public function test_it_handles_complex_arguments()
    {
        $doc = new DOMDocument();
        // Object literal, multiple args, strings with quotes
        $doc->loadHTML('<div onclick="complexFunc(\'hello\', {key: \'value\'}, 1 + 1)"></div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $attributes = $element['data']['properties']['settings']['advanced']['attributes'];
        $this->assertEquals('data-arg-complexfunc', $attributes[0]['name']);
        $this->assertEquals("'hello', {key: 'value'}, 1 + 1", $attributes[0]['value']);
    }

    public function test_it_handles_this_reference()
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<div onclick="handleThis(this)"></div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $attributes = $element['data']['properties']['settings']['advanced']['attributes'];
        $this->assertEquals('data-arg-handlethis', $attributes[0]['name']);
        $this->assertEquals('this', $attributes[0]['value']);
    }

    public function test_it_preserves_other_attributes()
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<div data-test="value" role="button" aria-label="Label" title="Title"></div>');
        $node = $doc->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $attributes = $element['data']['properties']['settings']['advanced']['attributes'];
        $names = array_column($attributes, 'name');

        $this->assertContains('data-test', $names);
        $this->assertContains('role', $names);
        $this->assertContains('aria-label', $names);
        $this->assertContains('title', $names);
    }

    public function test_it_handles_alpine_attributes()
    {
        $parser = new HtmlParser();
        $node = $parser->parse('<div @click="toggle()" x-show="isOpen"></div>')->getElementsByTagName('div')->item(0);

        $element = ['data' => ['properties' => ['settings' => []]]];
        $this->detector->processCustomAttributes($node, $element);

        $attributes = $element['data']['properties']['settings']['advanced']['attributes'];
        $names = array_column($attributes, 'name');

        $this->assertContains('@click', $names);
        $this->assertContains('x-show', $names);

        // Check if @click was also converted to an interaction
        $interactions = $element['data']['properties']['settings']['interactions']['interactions'];
        $this->assertCount(1, $interactions);
        $this->assertEquals('click', $interactions[0]['trigger']);
        $this->assertEquals('toggle', $interactions[0]['actions'][0]['js_function_name']);
    }
}
