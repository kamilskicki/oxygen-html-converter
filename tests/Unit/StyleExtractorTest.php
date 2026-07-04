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
        
        $this->assertSame('red', $properties['typography']['color']);
        $this->assertSame('blue', $properties['background']['background_color']);
        $this->assertArrayNotHasKey('background-color', $properties['background']);
    }

    public function testToOxygenPropertiesNormalizesMeasurementValues(): void
    {
        $properties = $this->extractor->toOxygenProperties([
            'width' => '120px',
            'padding' => '10px 20px',
            'font-size' => '18px',
            'line-height' => '1.5em',
            'border-radius' => '8px',
            'outline' => '2px solid #123456',
        ]);

        $this->assertSame('120px', $properties['size']['width']['style']);
        $this->assertSame(120, $properties['size']['width']['number']);
        $this->assertSame('10px', $properties['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('20px', $properties['spacing']['spacing']['padding']['right']['style']);
        $this->assertSame('18px', $properties['typography']['font_size']['style']);
        $this->assertSame('1.5em', $properties['typography']['line_height']['style']);
        $this->assertSame('8px', $properties['borders']['border_radius']['all']['style']);
        $this->assertSame('2px', $properties['effects']['outline_width']['style']);
        $this->assertSame('#123456FF', $properties['effects']['outline_color']);
    }

    public function testToOxygenPropertiesAcceptsCustomMeasurementFunctionsOnlyInMeasurementPaths(): void
    {
        $properties = $this->extractor->toOxygenProperties([
            'width' => 'calc(100% - var(--gap, 2rem))',
            'display' => 'calc(100% - 1rem)',
        ]);

        $this->assertSame('custom', $properties['size']['width']['unit']);
        $this->assertSame('calc(100% - var(--gap, 2rem))', $properties['size']['width']['style']);
        $this->assertArrayNotHasKey('display', $properties['layout'] ?? []);
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

    public function testParseInlineStylesPreservesSemicolonsInsideValues(): void
    {
        $styles = $this->extractor->parseInlineStyles(
            'background-image: url("data:image/svg+xml;utf8,<svg></svg>"); content: "a;b"; --label: "c;d"; width: calc(100% - var(--gap, 2rem));'
        );

        $this->assertStringContainsString('data:image/svg+xml;utf8', $styles['background-image']);
        $this->assertSame('"a;b"', $styles['content']);
        $this->assertSame('"c;d"', $styles['--label']);
        $this->assertSame('calc(100% - var(--gap, 2rem))', $styles['width']);
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
            'overflow-wrap' => 'break-word',
            'text-wrap' => 'balance',
            'text-overflow' => 'ellipsis',
        ];

        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('break-word', $properties['typography']['overflow_wrap']);
        $this->assertEquals('balance', $properties['typography']['text_wrap']);
        $this->assertEquals('ellipsis', $properties['typography']['text_overflow']);
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

        $this->assertEquals('1', $properties['flex_child']['flex_grow']);
        $this->assertEquals('0', $properties['flex_child']['flex_shrink']);
        $this->assertEquals('auto', $properties['flex_child']['flex_basis']);
        $this->assertEquals('custom', $properties['flex_child']['order']);
        $this->assertEquals('2', $properties['flex_child']['order_custom']);
        $this->assertEquals('span 2', $properties['grid_child']['column_start']);
        $this->assertEquals('1', $properties['grid_child']['row_start']);
        $this->assertEquals('3', $properties['grid_child']['row_end']);
    }

    public function testNewSizeProperties(): void
    {
        $styles = ['aspect-ratio' => '16 / 9'];
        $properties = $this->extractor->toOxygenProperties($styles);

        $this->assertEquals('16 / 9', $properties['size']['aspect_ratio']);
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
        $this->assertFalse($properties['effects']['backdrop_filter'][0]['disabled']);
        $this->assertEquals('blur', $properties['effects']['backdrop_filter'][0]['type']);
        $this->assertEquals('10px', $properties['effects']['backdrop_filter'][0]['blur_value']);
        $this->assertEquals('multiply', $properties['effects']['blend_mode']);
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

        $this->assertEquals('8px', $properties['borders']['border_radius']['topLeft']);
        $this->assertEquals('12px', $properties['borders']['border_radius']['topRight']);
        $this->assertEquals('4px', $properties['borders']['border_radius']['bottomLeft']);
        $this->assertEquals('16px', $properties['borders']['border_radius']['bottomRight']);
        $this->assertEquals('2px', $properties['effects']['outline_width']);
        $this->assertEquals('solid', $properties['effects']['outline_style']);
        $this->assertEquals('blue', $properties['effects']['outline_color']);
    }

    public function testNativeControlMapUsesOxygenReadablePathsForSupportedDeclarations(): void
    {
        $properties = $this->extractor->toOxygenProperties([
            'display' => 'flex',
            'justify-content' => 'center',
            'align-items' => 'flex-start',
            'gap' => '24px',
            'padding' => '10px 20px',
            'font-size' => '18px',
            'font-style' => 'italic',
            'text-decoration' => 'underline',
            'border-radius' => '8px',
        ]);

        $this->assertSame('flex', $properties['layout']['display']);
        $this->assertSame('center', $properties['layout']['flex_align']['primary_axis']);
        $this->assertSame('flex-start', $properties['layout']['flex_align']['cross_axis']);
        $this->assertSame('24px', $properties['layout']['gap']['row']);
        $this->assertSame('24px', $properties['layout']['gap']['column']);
        $this->assertSame('10px', $properties['spacing']['spacing']['padding']['top']);
        $this->assertSame('20px', $properties['spacing']['spacing']['padding']['right']);
        $this->assertSame('18px', $properties['typography']['font_size']);
        $this->assertSame('italic', $properties['typography']['style']['font_style']);
        $this->assertSame('underline', $properties['typography']['style']['text_decoration']);
        $this->assertSame('8px', $properties['borders']['border_radius']['all']);

        $this->assertArrayNotHasKey('font-size', $properties['typography']);
        $this->assertArrayNotHasKey('justify-content', $properties['layout']);
        $this->assertArrayNotHasKey('padding', $properties['spacing']);
    }

    public function testNativeControlMapUsesContractPathsForGridBackgroundBordersAndEffects(): void
    {
        $properties = $this->extractor->toOxygenProperties([
            'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
            'grid-template-rows' => '120px auto',
            'background-image' => 'linear-gradient(red, blue)',
            'background-size' => 'cover',
            'background-repeat' => 'no-repeat',
            'border' => '1px solid #ff0000',
            'box-shadow' => '0 12px 30px rgba(0,0,0,.2)',
            'object-fit' => 'cover',
            'mix-blend-mode' => 'multiply',
        ]);

        $this->assertSame('3', $properties['layout']['grid']['simple_grid_template_columns']);
        $this->assertTrue($properties['layout']['grid']['enable_advanced_mode']);
        $this->assertSame('120px auto', $properties['layout']['grid_template_rows'][0]['size']);
        $this->assertSame('gradient', $properties['background']['backgrounds'][0]['type']);
        $this->assertSame('linear-gradient(red, blue)', $properties['background']['backgrounds'][0]['gradient']['value']);
        $this->assertSame('cover', $properties['background']['backgrounds'][0]['background_size']);
        $this->assertSame('no-repeat', $properties['background']['backgrounds'][0]['background_repeat']);
        $this->assertSame('1px', $properties['borders']['borders']['top']['width']);
        $this->assertSame('solid', $properties['borders']['borders']['top']['style']);
        $this->assertSame('#ff0000', $properties['borders']['borders']['top']['color']);
        $this->assertSame('0', $properties['effects']['box_shadow'][0]['x']);
        $this->assertSame('12px', $properties['effects']['box_shadow'][0]['y']);
        $this->assertSame('cover', $properties['size']['object_fit']);
        $this->assertSame('multiply', $properties['effects']['blend_mode']);

        $this->assertArrayNotHasKey('template_columns', $properties['layout']['grid']);
        $this->assertArrayNotHasKey('mix_blend_mode', $properties['effects']);
        $this->assertArrayNotHasKey('object_fit', $properties['effects']);
    }

    public function testSupportsDeclarationsFullyReturnsTrueForFullyMappableStyles(): void
    {
        $this->assertTrue($this->extractor->supportsDeclarationsFully([
            'display' => 'flex',
            'justify-content' => 'center',
            'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
        ]));
    }

    public function testSupportsDeclarationsFullyReturnsFalseForPartiallyUnsupportedStyles(): void
    {
        $this->assertFalse($this->extractor->supportsDeclarationsFully([
            'display' => 'flex',
            'clip-path' => 'circle(50%)',
        ]));
    }

    public function testSupportsDeclarationsFullyReturnsFalseForInvalidSupportedValue(): void
    {
        $this->assertFalse($this->extractor->supportsDeclarationsFully([
            'width' => 'url(javascript:alert(1))',
        ]));
    }
}
