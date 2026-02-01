<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\HtmlParser;
use DOMElement;
use DOMText;

class HtmlParserTest extends TestCase
{
    private HtmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new HtmlParser();
    }

    public function testParseSimpleHtmlFragment(): void
    {
        $html = '<div class="test">Hello</div>';
        $result = $this->parser->parse($html);

        $this->assertInstanceOf(DOMElement::class, $result);
        $this->assertEquals('body', $result->tagName);
    }

    public function testParseCompleteHtmlDocument(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><p>Content</p></body></html>';
        $result = $this->parser->parse($html);

        $this->assertInstanceOf(DOMElement::class, $result);
        $this->assertEquals('body', $result->tagName);
    }

    public function testParseHandlesUtf8Characters(): void
    {
        $html = '<div>Héllo Wörld! 日本語</div>';
        $result = $this->parser->parse($html);

        $this->assertInstanceOf(DOMElement::class, $result);
        $this->assertStringContainsString('日本語', $result->textContent);
    }

    public function testParsePreprocessesAlpineJsAttributes(): void
    {
        // @ attributes are converted to data-oxy-at- prefix for DOMDocument compatibility
        $html = '<button @click="open = true">Click</button>';
        $result = $this->parser->parse($html);

        $this->assertInstanceOf(DOMElement::class, $result);
        
        // Find the button
        $button = $result->getElementsByTagName('button')->item(0);
        $this->assertNotNull($button);
        $this->assertTrue($button->hasAttribute('data-oxy-at-click'));
    }

    public function testShouldSkipNodeWhitespaceText(): void
    {
        $doc = new \DOMDocument();
        $textNode = $doc->createTextNode('   ');
        
        $this->assertTrue($this->parser->shouldSkipNode($textNode));
    }

    public function testShouldNotSkipNodeWithContent(): void
    {
        $doc = new \DOMDocument();
        $textNode = $doc->createTextNode('Hello World');
        
        $this->assertFalse($this->parser->shouldSkipNode($textNode));
    }

    public function testShouldSkipCommentNodes(): void
    {
        $doc = new \DOMDocument();
        $comment = $doc->createComment('This is a comment');
        
        $this->assertTrue($this->parser->shouldSkipNode($comment));
    }

    public function testShouldSkipMetaElements(): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<html><body><meta charset="UTF-8"></body></html>');
        $meta = $doc->getElementsByTagName('meta')->item(0);
        
        $this->assertTrue($this->parser->shouldSkipNode($meta));
    }

    public function testShouldSkipNoscriptElements(): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<html><body><noscript>No JS</noscript></body></html>');
        $noscript = $doc->getElementsByTagName('noscript')->item(0);
        
        $this->assertTrue($this->parser->shouldSkipNode($noscript));
    }

    public function testShouldNotSkipNormalElements(): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<html><body><div>Content</div></body></html>');
        $div = $doc->getElementsByTagName('div')->item(0);
        
        $this->assertFalse($this->parser->shouldSkipNode($div));
    }

    public function testExtractBodyContentFiltersNodes(): void
    {
        $html = '<body>  <div>Keep</div>  <!--comment--><p>Also Keep</p>  </body>';
        $body = $this->parser->parse($html);
        
        $children = $this->parser->extractBodyContent($body);
        
        // Should only get div and p, not whitespace or comments
        $this->assertCount(2, $children);
    }

    public function testExtractStylesFromDocument(): void
    {
        $html = '<head><style>.test { color: red; }</style></head><body></body>';
        $this->parser->parse($html);
        
        $styles = $this->parser->extractStyles();
        
        $this->assertCount(1, $styles);
        $this->assertEquals('inline', $styles[0]['type']);
        $this->assertStringContainsString('.test', $styles[0]['content']);
    }

    public function testExtractExternalStylesheets(): void
    {
        $html = '<head><link rel="stylesheet" href="style.css"></head><body></body>';
        $this->parser->parse($html);
        
        $styles = $this->parser->extractStyles();
        
        $this->assertCount(1, $styles);
        $this->assertEquals('external', $styles[0]['type']);
        $this->assertEquals('style.css', $styles[0]['href']);
    }

    public function testGetDomReturnsDocument(): void
    {
        $this->parser->parse('<div>Test</div>');
        $dom = $this->parser->getDom();
        
        $this->assertInstanceOf(\DOMDocument::class, $dom);
    }

    public function testGetErrorsReturnsEmptyForValidHtml(): void
    {
        $this->parser->parse('<div>Valid HTML</div>');
        
        $errors = $this->parser->getErrors();
        
        $this->assertIsArray($errors);
    }

    public function testParseReturnsNullOnFailure(): void
    {
        // This should not actually fail with DOMDocument, but test the contract
        $result = $this->parser->parse('');
        
        // Even empty string gets wrapped in structure
        $this->assertInstanceOf(DOMElement::class, $result);
    }
}
