<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\JavaScriptTransformer;
use PHPUnit\Framework\TestCase;

class JavaScriptTransformerWindowExportTest extends TestCase
{
    public function testSingleParameterFunctionKeepsDirectCallFallback(): void
    {
        $transformer = new JavaScriptTransformer();
        $output = $transformer->transformJavaScriptForOxygen(
            'function easeOutExpo(t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); }'
        );

        $this->assertStringContainsString('var _fallbackArgs = _hasOxygenTarget ? [target, action, event] : _args;', $output);
        $this->assertStringContainsString('var t = _fallbackArgs[0];', $output);
        $this->assertStringContainsString("if (_rawArgs !== '') { t = _coerceArg(_rawArgs); }", $output);
    }

    public function testMultiParameterFunctionKeepsDirectCallFallback(): void
    {
        $transformer = new JavaScriptTransformer();
        $output = $transformer->transformJavaScriptForOxygen(
            'function setPair(a, b) { return [a, b]; }'
        );

        $this->assertStringContainsString("var _argParts = _rawArgs !== '' ? _rawArgs.split(',') : [];", $output);
        $this->assertStringContainsString('var a = _fallbackArgs[0];', $output);
        $this->assertStringContainsString('var b = _fallbackArgs[1];', $output);
        $this->assertStringContainsString("if (_argParts[1] !== undefined && _argParts[1] !== '') { b = _coerceArg(_argParts[1].trim()); }", $output);
    }

    public function testNestedFunctionsInsideIifeStayLocal(): void
    {
        $transformer = new JavaScriptTransformer();
        $js = <<<'JS'
(function () {
    var counterAnimated = new Set();

    function easeOutExpo(t) {
        return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
    }

    function animateCounter(el) {
        counterAnimated.add(el);
        return easeOutExpo(0.5);
    }

    animateCounter(document.body);
})();
JS;

        $output = $transformer->transformJavaScriptForOxygen($js);

        $this->assertStringNotContainsString('window.easeOutExpo =', $output);
        $this->assertStringNotContainsString('window.animateCounter =', $output);
        $this->assertStringNotContainsString('var easeOutExpo = window.easeOutExpo;', $output);
        $this->assertStringContainsString('var counterAnimated = new Set();', $output);
        $this->assertStringContainsString('function animateCounter(el) {', $output);
        $this->assertStringContainsString('counterAnimated.add(el);', $output);
        $this->assertStringContainsString('return easeOutExpo(0.5);', $output);
    }

    public function testTopLevelNamedIifeStillTransformsWithoutLeavingInvalidRemnants(): void
    {
        $transformer = new JavaScriptTransformer();
        $js = <<<'JS'
(function initLoader() {
    console.log('loader');
})();
JS;

        $output = $transformer->transformJavaScriptForOxygen($js);

        $this->assertStringContainsString('window.initLoader = function(event, target, action) {', $output);
        $this->assertStringContainsString('window.initLoader();', $output);
        $this->assertStringNotContainsString('()();', $output);
    }
}
