<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Tests\TestCase;
use OxyHtmlConverter\Services\ComponentDetector;
use OxyHtmlConverter\Report\ConversionReport;
use DOMDocument;

class ComponentDetectorTest extends TestCase
{
    private $detector;
    private $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new ConversionReport();
        $this->detector = new ComponentDetector($this->report);
    }

    public function testDetectRepeatedStructures()
    {
        $html = "
            <div>
                <div class='card'><h3>Title 1</h3><p>Text 1</p></div>
                <div class='card'><h3>Title 2</h3><p>Text 2</p></div>
                <div class='card'><h3>Title 3</h3><p>Text 3</p></div>
            </div>
        ";

        $dom = new DOMDocument();
        $dom->loadHTML($html);

        $this->detector->analyze($dom->documentElement);
        $this->detector->reportFindings();

        $stats = $this->report->toArray();
        $this->assertCount(1, $stats['warnings']);
        $this->assertStringContainsString('Detected 3 repeated <div> structures', $stats['warnings'][0]);
    }

    public function testIgnoreNonRepeatedStructures()
    {
        $html = "
            <div>
                <div class='card'><h3>Title 1</h3><p>Text 1</p></div>
                <section><h2>Only one</h2></section>
            </div>
        ";

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);

        $this->detector->analyze($dom->documentElement);
        $this->detector->reportFindings();

        $stats = $this->report->toArray();
        $this->assertCount(0, $stats['warnings']);
    }
}
