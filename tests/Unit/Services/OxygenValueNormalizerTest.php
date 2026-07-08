<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenValueNormalizer;
use PHPUnit\Framework\TestCase;

class OxygenValueNormalizerTest extends TestCase
{
    private OxygenValueNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new OxygenValueNormalizer();
    }

    public function testNormalizesUnitValuesToOxygenMeasurementShape(): void
    {
        $this->assertSame(
            ['number' => 24, 'unit' => 'px', 'style' => '24px'],
            $this->normalizer->normalizeMeasurement('24px')
        );

        $this->assertSame(
            ['number' => 1.5, 'unit' => 'rem', 'style' => '1.5rem'],
            $this->normalizer->normalizeMeasurement('1.5rem')
        );

        $this->assertSame(
            ['number' => 0, 'unit' => 'px', 'style' => '0px'],
            $this->normalizer->normalizeMeasurement('0')
        );
    }

    public function testNormalizesKeywordAndCustomMeasurementValues(): void
    {
        $this->assertSame(
            ['number' => null, 'unit' => 'auto', 'style' => 'auto'],
            $this->normalizer->normalizeMeasurement('auto')
        );

        $this->assertSame(
            ['number' => null, 'unit' => 'custom', 'style' => 'calc(100% - var(--gap, 2rem))'],
            $this->normalizer->normalizeMeasurement('calc(100% - var(--gap, 2rem))')
        );

        $this->assertSame(
            ['number' => null, 'unit' => 'custom', 'style' => 'var(--section-gap)'],
            $this->normalizer->normalizeMeasurement('var(--section-gap)')
        );

        $this->assertSame(
            ['number' => null, 'unit' => 'custom', 'style' => 'repeat(3, minmax(0, 1fr))'],
            $this->normalizer->normalizeMeasurement('repeat(3, minmax(0, 1fr))')
        );

        $this->assertSame(
            ['number' => null, 'unit' => 'custom', 'style' => 'calc(100% - 2rem) 1fr'],
            $this->normalizer->normalizeMeasurement('calc(100% - 2rem) 1fr')
        );

        $this->assertSame(
            ['number' => null, 'unit' => 'custom', 'style' => 'var(--track) 1fr'],
            $this->normalizer->normalizeMeasurement('var(--track) 1fr')
        );
    }

    public function testRejectsInvalidMeasurementValues(): void
    {
        $this->assertNull($this->normalizer->normalizeMeasurement('url(javascript:alert(1))'));
        $this->assertNull($this->normalizer->normalizeMeasurement('calc(100% - 1rem); color:red'));
        $this->assertNull($this->normalizer->normalizeMeasurement('1px bananas'));
        $this->assertNull($this->normalizer->normalizeMeasurement('1px cats'));
        $this->assertNull($this->normalizer->normalizeMeasurement('minmax(nonsense,1fr)'));
        $this->assertNull($this->normalizer->normalizeMeasurement('repeat(3, minmax(nonsense, 1fr))'));
    }

    public function testNormalizesColorsForOxygenControls(): void
    {
        $this->assertSame('#FF0000FF', $this->normalizer->normalizeColor('#ff0000'));
        $this->assertSame('#FF0000FF', $this->normalizer->normalizeColor('#f00'));
        $this->assertSame('rgba(255,0,0,0.5)', $this->normalizer->normalizeColor('rgba(255, 0, 0, 0.5)'));
        $this->assertSame('var(--brand-color)', $this->normalizer->normalizeColor('var(--brand-color)'));
        $this->assertNull($this->normalizer->normalizeColor('notacolor'));
        $this->assertNull($this->normalizer->normalizeColor('rgb(999,999,999)'));
    }

    public function testNormalizesAssignmentValueByOxygenPath(): void
    {
        $this->assertSame(
            ['number' => 18, 'unit' => 'px', 'style' => '18px'],
            $this->normalizer->normalizeForPath(['typography', 'font_size'], '18px', 'font-size')
        );

        $this->assertSame(
            '#123456FF',
            $this->normalizer->normalizeForPath(['typography', 'color'], '#123456', 'color')
        );

        $this->assertSame(
            75,
            $this->normalizer->normalizeForPath(['effects', 'opacity'], '0.75', 'opacity')
        );

        $this->assertSame(
            700,
            $this->normalizer->normalizeForPath(['typography', 'font_weight'], 'bold', 'font-weight')
        );
    }

    public function testNormalizesStableRawPercentageNumberPaths(): void
    {
        $this->assertSame(25, $this->normalizer->normalizeForPath(['size', 'object_position', 'x'], '25%', 'object-position'));
        $this->assertSame(75, $this->normalizer->normalizeForPath(['size', 'object_position', 'y'], '75%', 'object-position'));
        $this->assertSame(50, $this->normalizer->normalizeForPath(['effects', 'transform_origin', 'x'], '50%', 'transform-origin'));
        $this->assertSame(20, $this->normalizer->normalizeForPath(['effects', 'transform_origin', 'y'], '20%', 'transform-origin'));
        $this->assertSame(85, $this->normalizer->normalizeForPath(['typography', 'font_width'], '85%', 'font-stretch'));

        $this->assertNull($this->normalizer->normalizeForPath(['size', 'object_position', 'x'], 'left', 'object-position'));
        $this->assertNull($this->normalizer->normalizeForPath(['typography', 'font_width'], 'condensed', 'font-stretch'));
    }

    public function testRejectsInvalidKeywordValuesForEnumeratedPaths(): void
    {
        $this->assertNull($this->normalizer->normalizeForPath(['layout', 'display'], 'definitelybogus', 'display'));
        $this->assertNull($this->normalizer->normalizeForPath(['position', 'position'], 'middle', 'position'));
        $this->assertNull($this->normalizer->normalizeForPath(['typography', 'text_align'], 'diagonal', 'text-align'));
        $this->assertNull($this->normalizer->normalizeForPath(['size', 'object_fit'], 'stretchy', 'object-fit'));
        $this->assertNull($this->normalizer->normalizeForPath(['effects', 'blend_mode'], 'magic', 'mix-blend-mode'));
        $this->assertNull($this->normalizer->normalizeForPath(['typography', 'font_weight'], 'definitelybogus', 'font-weight'));
        $this->assertNull($this->normalizer->normalizeForPath(['position', 'z_index'], 'definitelybogus', 'z-index'));
        $this->assertNull($this->normalizer->normalizeForPath(['size', 'aspect_ratio'], 'bogus', 'aspect-ratio'));
        $this->assertNull($this->normalizer->normalizeForPath(['flex_child', 'align_self'], 'definitelybogus', 'align-self'));
        $this->assertNull($this->normalizer->normalizeForPath(['grid_child', 'align_self'], 'definitelybogus', 'align-self'));
        $this->assertNull($this->normalizer->normalizeForPath(['grid_child', 'justify_self'], 'definitelybogus', 'justify-self'));
        $this->assertNull($this->normalizer->normalizeForPath(['background', 'backgrounds', '0', 'background_size'], 'definitelybogus', 'background-size'));
        $this->assertNull($this->normalizer->normalizeForPath(['background', 'backgrounds', '0', 'background_repeat'], 'definitelybogus', 'background-repeat'));
        $this->assertNull($this->normalizer->normalizeForPath(['background', 'backgrounds', '0', 'background_attachment'], 'definitelybogus', 'background-attachment'));
        $this->assertNull($this->normalizer->normalizeForPath(['background', 'backgrounds', '0', 'background_blend_mode'], 'definitelybogus', 'background-blend-mode'));
        $this->assertNull($this->normalizer->normalizeForPath(['effects', 'filter', '0', 'type'], 'definitelybogus', 'filter'));
        $this->assertNull($this->normalizer->normalizeForPath(['effects', 'backdrop_filter', '0', 'type'], 'definitelybogus', 'backdrop-filter'));
    }

    public function testRejectsInvalidNumericAndGridChildValues(): void
    {
        $this->assertNull($this->normalizer->normalizeForPath(['flex_child', 'flex_grow'], 'definitelybogus', 'flex-grow'));
        $this->assertNull($this->normalizer->normalizeForPath(['flex_child', 'flex_shrink'], 'definitelybogus', 'flex-shrink'));
        $this->assertNull($this->normalizer->normalizeForPath(['flex_child', 'flex_grow'], '-1', 'flex-grow'));
        $this->assertNull($this->normalizer->normalizeForPath(['flex_child', 'order_custom'], 'definitelybogus', 'order'));
        $this->assertNull($this->normalizer->normalizeForPath(['grid_child', 'order_custom'], 'definitelybogus', 'order'));
        $this->assertNull($this->normalizer->normalizeForPath(['grid_child', 'column_start'], 'definitelybogus', 'grid-column'));
        $this->assertNull($this->normalizer->normalizeForPath(['grid_child', 'row_end'], 'span definitelybogus', 'grid-row'));
    }

    public function testNormalizesBreakpointPathsUsingNestedValueShape(): void
    {
        $this->assertSame(
            ['number' => 16, 'unit' => 'px', 'style' => '16px'],
            $this->normalizer->normalizeForPath(
                ['typography', 'breakpoint_phone_landscape', 'font_size'],
                '16px',
                'font-size'
            )
        );

        $this->assertSame(
            ['number' => 12, 'unit' => 'px', 'style' => '12px'],
            $this->normalizer->normalizeForPath(
                ['breakpoint_phone_landscape', 'layout', 'gap', 'row'],
                '12px',
                'row-gap'
            )
        );
    }
}
