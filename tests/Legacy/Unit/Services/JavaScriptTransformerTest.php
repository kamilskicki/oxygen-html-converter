<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\JavaScriptTransformer;
use OxyHtmlConverter\Tests\TestCase;

class JavaScriptTransformerTest extends TestCase
{
    private JavaScriptTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new JavaScriptTransformer();
    }

    /**
     * @dataProvider provideJsScenarios
     */
    public function testTransformJavaScriptForOxygen(string $input, string $expectedPattern, string $message)
    {
        $output = $this->transformer->transformJavaScriptForOxygen($input);
        
        $this->assertMatchesRegularExpression($expectedPattern, $output, $message);
    }

    public static function provideJsScenarios(): array
    {
        return [
            'Simple function declaration' => [
                'function foo() { console.log("bar"); }',
                '/window\.foo = function\(event, target, action\) \{ console\.log\("bar"\); \}/',
                'Should transform simple function declaration to window assignment'
            ],
            'Function with parameters' => [
                'function foo(a, b) { console.log(a, b); }',
                '/window\.foo = function\(event, target, action\) \{.*var a = _rawArgs;.*var b = _argParts\[1\]/s',
                'Should handle parameters by extracting them from data attributes'
            ],
            'Arrow functions' => [
                'const foo = () => { console.log("bar"); }',
                '/window\.foo = \(\) => \{ console\.log\("bar"\); \}/',
                'Should transform arrow function assignment to window assignment'
            ],
            'Arrow function with implicit return' => [
                'const foo = x => x * 2',
                '/window\.foo = x => x \* 2/',
                'Should transform arrow function with implicit return to window assignment'
            ],
            'Function expression' => [
                'const foo = function() { console.log("bar"); }',
                '/window\.foo = function\(\) \{ console\.log\("bar"\); \}/',
                'Should transform function expression assignment to window assignment'
            ],
            'Async functions' => [
                'async function foo() { await bar(); }',
                '/window\.foo = (async\s+)?function\(event, target, action\) \{ await bar\(\); \}/',
                'Should handle async function declarations'
            ],
            'Nested braces in strings' => [
                "function foo() { return '}'; }",
                "/window\.foo = function\(event, target, action\) \{ return '\}'; \}/",
                'Should correctly handle nested braces inside string literals'
            ],
            'Comments with braces' => [
                "function foo() { // } comment\n return 1; }",
                "/window\.foo = function\(event, target, action\) \{ \/\/ \} comment\n return 1; \}/",
                'Should correctly handle braces inside comments'
            ],
            'Template literals' => [
                'function foo() { return `}`; }',
                '/window\.foo = function\(event, target, action\) \{ return `\}`; \}/',
                'Should correctly handle braces inside template literals'
            ],
            'Multiple functions' => [
                "function foo() { }\nfunction bar() { }",
                '/window\.foo.*window\.bar/s',
                'Should handle multiple functions in one script block'
            ],
            'DOMContentLoaded already wrapped' => [
                "document.addEventListener('DOMContentLoaded', function() { function foo() {} });",
                "/window\.foo = function\(event, target, action\)?/",
                'Should transform functions even if already wrapped in DOMContentLoaded'
            ],
            'jQuery ready pattern' => [
                "$(function() { function foo() {} });",
                "/window\.foo = function\(event, target, action\)?/",
                'Should transform functions even if already wrapped in jQuery ready'
            ],
            'Init code not wrapped (Oxygen wraps automatically)' => [
                "console.log('init');\ndocument.querySelectorAll('.btn');",
                "/console\.log\('init'\);/",
                'Should NOT wrap init code in DOMContentLoaded (Oxygen does it automatically)'
            ],
            // ES6 Class Methods are intentionally NOT extracted to avoid false positives with
            // arrow function callbacks like entries.forEach(entry => {...})
            'ES6 Class Methods preserved as-is' => [
                'class MyComponent { setup() { console.log("setup"); } static init() { console.log("init"); } }',
                '/class MyComponent/',
                'Should preserve ES6 classes as-is (method extraction disabled to avoid false positives)'
            ],
            'IIFE pattern' => [
                '(function() { function internal() { console.log("iife"); } internal(); })();',
                '/window\.internal = function\(event, target, action\) \{ console\.log\("iife"\); \}/',
                'Should extract functions from inside IIFEs'
            ],
            'addEventListener preservation' => [
                'document.querySelector(".btn").addEventListener("click", function(e) { console.log("clicked"); });',
                '/\.querySelector\("\.btn"\)\.addEventListener\("click", function\(e\)/s',
                'Should preserve addEventListener without extra wrapping (Oxygen wraps automatically)'
            ]
        ];
    }

    public function testStripIntersectionObserverWithSemicolons(): void
    {
        $js = <<<'JS'
const observerOptions = { threshold: 0.1 };
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    });
}, observerOptions);
document.querySelectorAll('.animate-on-scroll').forEach(el => { observer.observe(el); });
function keepMe() { return true; }
JS;

        $output = $this->transformer->stripConvertedPatterns($js, true, false, []);
        $this->assertStringNotContainsString('IntersectionObserver', $output);
        $this->assertStringNotContainsString('observerOptions', $output);
        $this->assertStringNotContainsString('animate-on-scroll', $output);
        $this->assertStringContainsString('keepMe', $output);
    }

    public function testStripSmoothScrollBlock(): void
    {
        $js = <<<'JS'
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({ behavior: 'smooth' });
    });
});
function keepMe() { return true; }
JS;

        $output = $this->transformer->stripConvertedPatterns($js, false, true, []);
        $this->assertStringNotContainsString('scrollIntoView', $output);
        $this->assertStringNotContainsString('smooth', $output);
        $this->assertStringContainsString('keepMe', $output);
    }
}