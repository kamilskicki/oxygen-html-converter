<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\CssParser;
use PHPUnit\Framework\TestCase;

class CssParserTest extends TestCase
{
    private CssParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CssParser();
    }

    public function testParseBasicRules(): void
    {
        $rules = $this->parser->parse('#hero { color: red; margin: 10px; } .card { background: blue; }');

        $this->assertCount(2, $rules);
        $this->assertSame('#hero', $rules[0]['selector']);
        $this->assertSame('red', $rules[0]['declarations']['color']);
        $this->assertSame('10px', $rules[0]['declarations']['margin']);
        $this->assertSame('.card', $rules[1]['selector']);
        $this->assertSame('blue', $rules[1]['declarations']['background']);
    }

    public function testParseDeclarationsWithSemicolonsInsideValues(): void
    {
        $css = '.x { background-image: url("data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>"); content: "a;b"; color: red; }';

        $rules = $this->parser->parse($css);

        $this->assertCount(1, $rules);
        $this->assertStringContainsString('data:image/svg+xml;utf8', $rules[0]['declarations']['background-image']);
        $this->assertSame('"a;b"', $rules[0]['declarations']['content']);
        $this->assertSame('red', $rules[0]['declarations']['color']);
    }

    public function testParseDeclarationsPreservesCustomPropertiesFunctionsAndComments(): void
    {
        $css = <<<'CSS'
.x {
  --label: "a;b";
  font-family: 'A;B', sans-serif;
  width: calc(100% - var(--gap, 2rem));
  /* ignored: color: red; */
  background: linear-gradient(90deg, rgba(0,0,0,.1), rgba(255,255,255,.2));
  transform: translate(calc(100% - var(--gap, 1rem)));
  mask-image: image-set(url("a;b.png") 1x);
  content: "/* keep; not comment */";
  color: blue !important;
}
CSS;

        $rules = $this->parser->parse($css);

        $this->assertCount(1, $rules);
        $this->assertSame('"a;b"', $rules[0]['declarations']['--label']);
        $this->assertSame("'A;B', sans-serif", $rules[0]['declarations']['font-family']);
        $this->assertSame('calc(100% - var(--gap, 2rem))', $rules[0]['declarations']['width']);
        $this->assertSame('linear-gradient(90deg, rgba(0,0,0,.1), rgba(255,255,255,.2))', $rules[0]['declarations']['background']);
        $this->assertSame('translate(calc(100% - var(--gap, 1rem)))', $rules[0]['declarations']['transform']);
        $this->assertSame('image-set(url("a;b.png") 1x)', $rules[0]['declarations']['mask-image']);
        $this->assertSame('"/* keep; not comment */"', $rules[0]['declarations']['content']);
        $this->assertSame('blue', $rules[0]['declarations']['color']);
        $this->assertTrue($rules[0]['importantDeclarations']['color']);
        $this->assertArrayNotHasKey('ignored', $rules[0]['declarations']);
    }

    public function testImportantDeclarationSurvivesLaterNormalDuplicate(): void
    {
        $rules = $this->parser->parse('.x { color: red !important; color: blue; }');

        $this->assertSame('red', $rules[0]['declarations']['color']);
        $this->assertTrue($rules[0]['importantDeclarations']['color']);
        $this->assertTrue($this->parser->parseImportantDeclarations('color: red !important;')['color']);
    }

    public function testParseDeclarationsRecoversMalformedTrailingDeclaration(): void
    {
        $declarations = $this->parser->parseDeclarations('color: red; broken; empty: ; background: url("unterminated; padding: 1rem');

        $this->assertSame('red', $declarations['color']);
        $this->assertSame('url("unterminated; padding: 1rem', $declarations['background']);
        $this->assertArrayNotHasKey('broken', $declarations);
        $this->assertArrayNotHasKey('empty', $declarations);
    }

    public function testParseDeclarationsSkipsNestedRuleBlocksInsideDeclarationBlocks(): void
    {
        $rules = $this->parser->parse('.x { color:red; & .child { color:blue; content:"a;b"; } background:white; }');

        $this->assertCount(1, $rules);
        $this->assertSame('red', $rules[0]['declarations']['color']);
        $this->assertSame('white', $rules[0]['declarations']['background']);
        $this->assertArrayNotHasKey('& .child { color', $rules[0]['declarations']);
    }

    public function testParseMediaRulesWithContext(): void
    {
        $rules = $this->parser->parse(
            '.card { padding: 32px; } @media (max-width: 767px) { .card { padding: 12px; } .card:hover { color: #2563eb; } }'
        );

        $this->assertCount(3, $rules);
        $this->assertSame('.card', $rules[0]['selector']);
        $this->assertArrayNotHasKey('media', $rules[0]);
        $this->assertSame('.card', $rules[1]['selector']);
        $this->assertSame('(max-width: 767px)', $rules[1]['media']);
        $this->assertSame('12px', $rules[1]['declarations']['padding']);
        $this->assertSame('.card:hover', $rules[2]['selector']);
        $this->assertSame('(max-width: 767px)', $rules[2]['media']);
    }

    public function testStatementAtRulesDoNotSwallowTheFollowingRule(): void
    {
        $rules = $this->parser->parse('@charset "UTF-8"; @import url("theme;v2.css"); @layer reset, base; .card { color: red; }');

        $this->assertCount(1, $rules);
        $this->assertSame('.card', $rules[0]['selector']);
        $this->assertSame('red', $rules[0]['declarations']['color']);
    }

    public function testSelectorListsSplitOnlyAtTopLevelCommas(): void
    {
        $rules = $this->parser->parse(':is(.card, .panel), [data-label="a,b"], .escaped\\,comma { color: red; }');

        $this->assertSame([':is(.card, .panel)', '[data-label="a,b"]', '.escaped\\,comma'], array_column($rules, 'selector'));
    }

    public function testSelectorQuotedCommentAndBraceCharactersArePreserved(): void
    {
        $rules = $this->parser->parse('/* ignore */ [data-label="/*literal*/{x}"] { color: red; }');

        $this->assertSame('[data-label="/*literal*/{x}"]', $rules[0]['selector']);
        $this->assertSame('red', $rules[0]['declarations']['color']);
    }

    public function testCustomPropertyNamesRemainCaseSensitive(): void
    {
        $declarations = $this->parser->parseDeclarations('--Brand: red !important; --brand: blue; COLOR: var(--Brand);');

        $this->assertSame(['--Brand' => 'red', '--brand' => 'blue', 'color' => 'var(--Brand)'], $declarations);
        $this->assertSame(['--Brand' => true], $this->parser->parseImportantDeclarations('--Brand: red !important; --brand: blue;'));
    }

    public function testNestedPseudoRulesCannotContaminateParentDeclarations(): void
    {
        $declarations = $this->parser->parseDeclarations('color: red; &:hover { color: blue; } a:focus { color: green; } background: white; --Theme: { color: pink; };');

        $this->assertSame(['color' => 'red', 'background' => 'white', '--Theme' => '{ color: pink; }'], $declarations);
    }

    public function testNestedMediaListsCombineEveryAlternative(): void
    {
        $rules = $this->parser->parse('@media (min-width: 600px), (orientation: landscape) { @media (hover: hover), (pointer: fine) { .card { color: red; } } }');

        $this->assertSame('(min-width: 600px) and (hover: hover), (min-width: 600px) and (pointer: fine), (orientation: landscape) and (hover: hover), (orientation: landscape) and (pointer: fine)', $rules[0]['media']);
    }

    public function testNestedMediaExpansionIsBoundedAndLaterRulesStillParse(): void
    {
        $css = str_repeat('@media (hover: hover), (pointer: fine) { ', 20)
            . '.card { color: red; }' . str_repeat('}', 20) . '.after { color: blue; }';

        $rules = $this->parser->parse($css);

        $this->assertCount(1, $rules);
        $this->assertSame('.after', $rules[0]['selector']);
    }
}
