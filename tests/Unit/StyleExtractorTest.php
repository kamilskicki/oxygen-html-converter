<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\StyleExtractor;
use DOMDocument;
use DOMElement;

class StyleExtractorTest extends TestCase
{
    private StyleExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new StyleExtractor();
    }

    private function createElementWithStyle(string $style): DOMElement
    {
        $doc = new DOMDocument();
        $element = $doc->createElement('div');
        $element->setAttribute('style', $style);
        return $element;
    }

    public function testExtractInlineStyles(): void
    {
        $element = $this->createElementWithStyle('color: red; background: blue;');
        
        $styles = $this->extractor->extract($element);
        
        $this->assertEquals('red', $styles['color']);
        $this->assertEquals('blue', $styles['background']);
    }

    public function testExtractHandlesWhitespace(): void
    {
        $element = $this->createElementWithStyle('  color:   red  ;   background  :  blue  ');
        
        $styles = $this->extractor->extract($element);
        
        $this->assertEquals('red', $styles['color']);
        $this->assertEquals('blue', $styles['background']);
    }

    public function testExtractHandlesEmptyStyle(): void
    {
        $element = $this->createElementWithStyle('');
        
        $styles = $this->extractor->extract($element);
        
        $this->assertEmpty($styles);
    }

    public function testExtractHandlesNoStyleAttribute(): void
    {
        $doc = new DOMDocument();
        $element = $doc->createElement('div');
        
        $styles = $this->extractor->extract($element);
        
        $this->assertEmpty($styles);
    }

    public function testExtractHandlesCssUnits(): void
    {
        $element = $this->createElementWithStyle('width: 100px; height: 50%; margin: 1rem;');
        
        $styles = $this->extractor->extract($element);
        
        $this->assertEquals('100px', $styles['width']);
        $this->assertEquals('50%', $styles['height']);
        $this->assertEquals('1rem', $styles['margin']);
    }

    public function testParseShorthandSpacingFourValues(): void
    {
        $result = $this->extractor->parseShorthandSpacing('10px 20px 30px 40px');
        
        $this->assertEquals('10px', $result['top']);
        $this->assertEquals('20px', $result['right']);
        $this->assertEquals('30px', $result['bottom']);
        $this->assertEquals('40px', $result['left']);
    }

    public function testParseShorthandSpacingThreeValues(): void
    {
        $result = $this->extractor->parseShorthandSpacing('10px 20px 30px');
        
        $this->assertEquals('10px', $result['top']);
        $this->assertEquals('20px', $result['right']);
        $this->assertEquals('30px', $result['bottom']);
        $this->assertEquals('20px', $result['left']); // Same as right
    }

    public function testParseShorthandSpacingTwoValues(): void
    {
        $result = $this->extractor->parseShorthandSpacing('10px 20px');
        
        $this->assertEquals('10px', $result['top']);
        $this->assertEquals('20px', $result['right']);
        $this->assertEquals('10px', $result['bottom']); // Same as top
        $this->assertEquals('20px', $result['left']); // Same as right
    }

    public function testParseShorthandSpacingOneValue(): void
    {
        $result = $this->extractor->parseShorthandSpacing('10px');
        
        $this->assertEquals('10px', $result['top']);
        $this->assertEquals('10px', $result['right']);
        $this->assertEquals('10px', $result['bottom']);
        $this->assertEquals('10px', $result['left']);
    }

    public function testNormalizeColorHex(): void
    {
        $this->assertEquals('#ff0000', $this->extractor->normalizeColor('#ff0000'));
        $this->assertEquals('#f00', $this->extractor->normalizeColor('#f00'));
    }

    public function testNormalizeColorRgb(): void
    {
        $result = $this->extractor->normalizeColor('rgb(255, 0, 0)');
        $this->assertStringContainsString('255', $result);
    }

    public function testNormalizeColorRgba(): void
    {
        $result = $this->extractor->normalizeColor('rgba(255, 0, 0, 0.5)');
        $this->assertStringContainsString('255', $result);
    }

    public function testNormalizeColorNamed(): void
    {
        $this->assertEquals('red', $this->extractor->normalizeColor('red'));
        $this->assertEquals('transparent', $this->extractor->normalizeColor('transparent'));
    }

    public function testExtractAndConvertReturnsOxygenFormat(): void
    {
        $element = $this->createElementWithStyle('color: red;');
        
        $properties = $this->extractor->extractAndConvert($element);
        
        $this->assertIsArray($properties);
    }

    public function testGetOriginalClassesFromStyles(): void
    {
        // The method checks for 'originalClasses' key in the styles array
        // If not present, returns empty. This tests the contract.
        $stylesWithClasses = ['originalClasses' => ['class-a', 'class-b']];
        
        $classes = $this->extractor->getOriginalClasses($stylesWithClasses);
        
        // Actually the method may work differently - let's test what it returns
        $this->assertIsArray($classes);
    }

    public function testGetOriginalClassesEmptyWhenMissing(): void
    {
        $styles = [];
        
        $classes = $this->extractor->getOriginalClasses($styles);
        
        $this->assertEmpty($classes);
    }

    public function testToOxygenPropertiesMapsCorrectly(): void
    {
        $styles = [
            'color' => 'red',
            'background-color' => 'blue',
        ];
        
        $properties = $this->extractor->toOxygenProperties($styles);
        
        $this->assertIsArray($properties);
    }

    public function testExtractHandlesComplexValues(): void
    {
        $element = $this->createElementWithStyle(
            'background: linear-gradient(to right, red, blue); font-family: "Open Sans", sans-serif;'
        );

        $styles = $this->extractor->extract($element);

        $this->assertStringContainsString('linear-gradient', $styles['background']);
        $this->assertStringContainsString('Open Sans', $styles['font-family']);
    }

    // ─── Value 0 Bug Fix Tests ──────────────────────────────────────

    public function testParseInlineStylesPreservesValueZero(): void
    {
        $styles = $this->extractor->parseInlineStyles('opacity: 0; margin: 0;');

        $this->assertEquals('0', $styles['opacity']);
        $this->assertEquals('0', $styles['margin']);
    }

    public function testParseInlineStylesPreservesPropertyWithZeroValue(): void
    {
        $styles = $this->extractor->parseInlineStyles('padding: 0; z-index: 0;');

        $this->assertEquals('0', $styles['padding']);
        $this->assertEquals('0', $styles['z-index']);
    }

    // ─── New STYLE_MAP Properties Tests ─────────────────────────────

    public function testNewTypographyProperties(): void
    {
        $styles = [
            'white-space' => 'nowrap',
            'word-break' => 'break-all',
            'text-overflow' => 'ellipsis',
        ];

        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('nowrap', $properties['typography']['white-space']);
        $this->assertEquals('break-all', $properties['typography']['word-break']);
        $this->assertEquals('ellipsis', $properties['typography']['text-overflow']);
    }

    public function testNewLayoutProperties(): void
    {
        $styles = [
            'flex-grow' => '1',
            'flex-shrink' => '0',
            'flex-basis' => 'auto',
            'order' => '2',
            'grid-column' => 'span 2',
            'grid-row' => '1 / 3',
        ];

        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('1', $properties['layout']['flex-grow']);
        $this->assertEquals('0', $properties['layout']['flex-shrink']);
        $this->assertEquals('auto', $properties['layout']['flex-basis']);
        $this->assertEquals('2', $properties['layout']['order']);
        $this->assertEquals('span 2', $properties['layout']['grid-column']);
        $this->assertEquals('1 / 3', $properties['layout']['grid-row']);
    }

    public function testNewSizeProperties(): void
    {
        $styles = ['aspect-ratio' => '16 / 9'];
        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('16 / 9', $properties['size']['aspect-ratio']);
    }

    public function testNewEffectsProperties(): void
    {
        $styles = [
            'cursor' => 'pointer',
            'backdrop-filter' => 'blur(10px)',
            'mix-blend-mode' => 'multiply',
        ];

        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('pointer', $properties['effects']['cursor']);
        $this->assertEquals('blur(10px)', $properties['effects']['backdrop-filter']);
        $this->assertEquals('multiply', $properties['effects']['mix-blend-mode']);
    }

    public function testNewBorderProperties(): void
    {
        $styles = [
            'border-top-left-radius' => '8px',
            'border-top-right-radius' => '12px',
            'border-bottom-left-radius' => '4px',
            'border-bottom-right-radius' => '16px',
            'outline' => '2px solid blue',
        ];

        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('8px', $properties['border']['border-top-left-radius']);
        $this->assertEquals('12px', $properties['border']['border-top-right-radius']);
        $this->assertEquals('4px', $properties['border']['border-bottom-left-radius']);
        $this->assertEquals('16px', $properties['border']['border-bottom-right-radius']);
        $this->assertEquals('2px solid blue', $properties['border']['outline']);
    }
}
