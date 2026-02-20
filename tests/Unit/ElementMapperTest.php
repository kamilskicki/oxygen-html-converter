<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\ElementMapper;
use DOMDocument;
use DOMElement;

class ElementMapperTest extends TestCase
{
    private ElementMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ElementMapper();
    }

    private function createElement(string $tag, array $attributes = [], string $content = ''): DOMElement
    {
        $doc = new DOMDocument();
        $element = $doc->createElement($tag);
        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
        if ($content) {
            $element->appendChild($doc->createTextNode($content));
        }
        $doc->appendChild($element);
        return $element;
    }

    /**
     * @dataProvider tagToTypeProvider
     */
    public function testGetElementType(string $tag, string $expectedType): void
    {
        $type = $this->mapper->getElementType($tag);
        
        $this->assertEquals($expectedType, $type, "Tag <{$tag}> should map to {$expectedType}");
    }

    public static function tagToTypeProvider(): array
    {
        return [
            // Container elements
            'div' => ['div', 'OxygenElements\\Container'],
            'section' => ['section', 'OxygenElements\\Container'],
            'article' => ['article', 'OxygenElements\\Container'],
            'header' => ['header', 'OxygenElements\\Container'],
            'footer' => ['footer', 'OxygenElements\\Container'],
            'nav' => ['nav', 'OxygenElements\\Container'],
            'aside' => ['aside', 'OxygenElements\\Container'],
            'main' => ['main', 'OxygenElements\\Container'],

            // Text elements
            'p' => ['p', 'OxygenElements\\Text'],
            'h1' => ['h1', 'OxygenElements\\Text'],
            'h2' => ['h2', 'OxygenElements\\Text'],
            'h3' => ['h3', 'OxygenElements\\Text'],
            'h4' => ['h4', 'OxygenElements\\Text'],
            'h5' => ['h5', 'OxygenElements\\Text'],
            'h6' => ['h6', 'OxygenElements\\Text'],
            'span' => ['span', 'OxygenElements\\Text'],
            'blockquote' => ['blockquote', 'OxygenElements\\Text'],

            // Link element
            'a' => ['a', 'OxygenElements\\TextLink'],

            // Image element
            'img' => ['img', 'OxygenElements\\Image'],

            // Rich text elements
            'ul' => ['ul', 'OxygenElements\\Container'],
            'ol' => ['ol', 'OxygenElements\\Container'],
            'table' => ['table', 'OxygenElements\\RichText'],

            // HTML Code elements
            'iframe' => ['iframe', 'OxygenElements\\HtmlCode'],
            'svg' => ['svg', 'OxygenElements\\HtmlCode'],
            'form' => ['form', 'OxygenElements\\HtmlCode'],
            'video' => ['video', 'OxygenElements\\Html5Video'],
        ];
    }

    public function testIsContainerReturnsTrueForContainerTags(): void
    {
        $containerTags = ['div', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main'];
        
        foreach ($containerTags as $tag) {
            $this->assertTrue(
                $this->mapper->isContainer($tag),
                "<{$tag}> should be a container"
            );
        }
    }

    public function testIsContainerReturnsFalseForNonContainerTags(): void
    {
        $nonContainerTags = ['p', 'span', 'img'];
        
        foreach ($nonContainerTags as $tag) {
            $this->assertFalse(
                $this->mapper->isContainer($tag),
                "<{$tag}> should not be a container"
            );
        }
    }

    public function testIsTextElementReturnsTrueForTextTags(): void
    {
        $textTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span'];
        
        foreach ($textTags as $tag) {
            $this->assertTrue(
                $this->mapper->isTextElement($tag),
                "<{$tag}> should be a text element"
            );
        }
    }

    public function testShouldKeepInnerHtmlForHtmlCodeTags(): void
    {
        $htmlCodeTags = ['iframe', 'svg', 'form', 'video'];
        
        foreach ($htmlCodeTags as $tag) {
            $this->assertTrue(
                $this->mapper->shouldKeepInnerHtml($tag),
                "<{$tag}> should keep inner HTML"
            );
        }
    }

    public function testGetTagOptionReturnsCorrectTag(): void
    {
        $this->assertEquals('h1', $this->mapper->getTagOption('h1'));
        $this->assertEquals('h2', $this->mapper->getTagOption('h2'));
        $this->assertEquals('p', $this->mapper->getTagOption('p'));
        $this->assertEquals('blockquote', $this->mapper->getTagOption('blockquote'));
    }

    public function testBuildPropertiesForImage(): void
    {
        $img = $this->createElement('img', [
            'src' => 'image.jpg',
            'alt' => 'Test image',
            'width' => '200',
            'height' => '100',
        ]);

        $properties = $this->mapper->buildProperties($img);

        $this->assertArrayHasKey('content', $properties);
    }

    public function testBuildPropertiesForLink(): void
    {
        $link = $this->createElement('a', [
            'href' => 'https://example.com',
            'target' => '_blank',
        ], 'Click me');

        $properties = $this->mapper->buildProperties($link);

        $this->assertArrayHasKey('content', $properties);
    }

    public function testGetInnerHtml(): void
    {
        $doc = new DOMDocument();
        $div = $doc->createElement('div');
        $p = $doc->createElement('p');
        $p->appendChild($doc->createTextNode('Hello'));
        $div->appendChild($p);
        $doc->appendChild($div);

        $innerHtml = $this->mapper->getInnerHtml($div);

        $this->assertStringContainsString('<p>', $innerHtml);
        $this->assertStringContainsString('Hello', $innerHtml);
    }

    public function testGetOuterHtml(): void
    {
        $div = $this->createElement('div', ['class' => 'test'], 'Content');

        $outerHtml = $this->mapper->getOuterHtml($div);

        $this->assertStringContainsString('<div', $outerHtml);
        $this->assertStringContainsString('class="test"', $outerHtml);
        $this->assertStringContainsString('Content', $outerHtml);
    }

    public function testHasOnlyTextContentTrue(): void
    {
        $p = $this->createElement('p', [], 'Just text content');

        $this->assertTrue($this->mapper->hasOnlyTextContent($p));
    }

    public function testHasOnlyTextContentFalseWithNonInlineChildren(): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<div><div>child div</div></div>');
        $div = $doc->getElementsByTagName('div')->item(0);

        $this->assertFalse($this->mapper->hasOnlyTextContent($div));
    }



    public function testIsButtonLikeLinkWithButtonClasses(): void
    {
        $link = $this->createElement('a', [
            'href' => '#',
            'class' => 'btn btn-primary',
        ], 'Button');

        $this->assertTrue($this->mapper->isButtonLikeLink($link));
    }

    public function testIsButtonLikeLinkReturnsFalseForNormalLink(): void
    {
        $link = $this->createElement('a', [
            'href' => 'https://example.com',
        ], 'Normal link');

        $this->assertFalse($this->mapper->isButtonLikeLink($link));
    }

    public function testBuildChildTextElement(): void
    {
        $button = $this->createElement('button', [], 'Click me');

        $textElement = $this->mapper->buildChildTextElement($button, 42);

        $this->assertEquals(42, $textElement['id']);
        $this->assertEquals('OxygenElements\Text', $textElement['data']['type']);
    }
}
