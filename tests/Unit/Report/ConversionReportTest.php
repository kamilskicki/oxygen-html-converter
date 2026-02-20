<?php

namespace OxyHtmlConverter\Tests\Unit\Report;

use OxyHtmlConverter\Report\ConversionReport;
use PHPUnit\Framework\TestCase;

class ConversionReportTest extends TestCase
{
    private ConversionReport $report;

    protected function setUp(): void
    {
        $this->report = new ConversionReport();
    }

    public function testInitialState()
    {
        $data = $this->report->toArray();
        $this->assertEquals(0, $data['elements']);
        $this->assertEquals(0, $data['tailwindClasses']);
        $this->assertEquals(0, $data['customClasses']);
        $this->assertEmpty($data['warnings']);
        $this->assertEmpty($data['errors']);
        $this->assertEmpty($data['info']);
    }

    public function testIncrements()
    {
        $this->report->incrementElementCount(2);
        $this->report->incrementTailwindClassCount(3);
        $this->report->incrementCustomClassCount(4);

        $data = $this->report->toArray();
        $this->assertEquals(2, $data['elements']);
        $this->assertEquals(3, $data['tailwindClasses']);
        $this->assertEquals(4, $data['customClasses']);
    }

    public function testWarningsAndErrors()
    {
        $this->report->addWarning('Warning 1');
        $this->report->addWarning('Warning 1'); // Duplicate
        $this->report->addError('Error 1');
        $this->report->addInfo('Info 1');

        $data = $this->report->toArray();
        $this->assertCount(1, $data['warnings']);
        $this->assertEquals('Warning 1', $data['warnings'][0]);
        $this->assertCount(1, $data['errors']);
        $this->assertEquals('Error 1', $data['errors'][0]);
        $this->assertCount(1, $data['info']);
        $this->assertEquals('Info 1', $data['info'][0]);
    }

    public function testReset()
    {
        $this->report->incrementElementCount(1);
        $this->report->addWarning('Warning');
        $this->report->addInfo('Info');
        $this->report->reset();

        $data = $this->report->toArray();
        $this->assertEquals(0, $data['elements']);
        $this->assertEmpty($data['warnings']);
        $this->assertEmpty($data['info']);
    }
}