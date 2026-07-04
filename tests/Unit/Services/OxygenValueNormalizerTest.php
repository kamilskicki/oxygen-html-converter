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
    }

    public function testRejectsInvalidMeasurementValues(): void
    {
        $this->assertNull($this->normalizer->normalizeMeasurement('url(javascript:alert(1))'));
        $this->assertNull($this->normalizer->normalizeMeasurement('calc(100% - 1rem); color:red'));
    }

    public function testNormalizesColorsForOxygenControls(): void
    {
        $this->assertSame('#FF0000FF', $this->normalizer->normalizeColor('#ff0000'));
        $this->assertSame('#FF0000FF', $this->normalizer->normalizeColor('#f00'));
        $this->assertSame('rgba(255,0,0,0.5)', $this->normalizer->normalizeColor('rgba(255, 0, 0, 0.5)'));
        $this->assertSame('var(--brand-color)', $this->normalizer->normalizeColor('var(--brand-color)'));
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
}
