<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Tests\TestCase;
use OxyHtmlConverter\Services\CssParser;

class CssParserTest extends TestCase
{
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CssParser();
    }

    public function testParseBasicRules()
    {
        $css = "
            #hero { color: red; margin: 10px; }
            .card { background: blue; }
        ";

        $rules = $this->parser->parse($css);

        $this->assertCount(2, $rules);
        $this->assertEquals('#hero', $rules[0]['selector']);
        $this->assertEquals('red', $rules[0]['declarations']['color']);
        $this->assertEquals('10px', $rules[0]['declarations']['margin']);
        
        $this->assertEquals('.card', $rules[1]['selector']);
        $this->assertEquals('blue', $rules[1]['declarations']['background']);
    }

    public function testParseMultipleSelectors()
    {
        $css = "h1, h2 { font-weight: bold; }";
        $rules = $this->parser->parse($css);

        $this->assertCount(2, $rules);
        $this->assertEquals('h1', $rules[0]['selector']);
        $this->assertEquals('h2', $rules[1]['selector']);
        $this->assertEquals('bold', $rules[0]['declarations']['font-weight']);
    }

    public function testParseStripsCommentsAndImportant()
    {
        $css = "
            /* This is a comment */
            #main { 
                padding: 20px !important; 
            }
        ";

        $rules = $this->parser->parse($css);

        $this->assertCount(1, $rules);
        $this->assertEquals('20px', $rules[0]['declarations']['padding']);
    }
}
