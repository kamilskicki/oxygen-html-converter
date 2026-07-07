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
        $candidates = $this->detector->candidates();

        $this->assertCount(1, $stats['warnings']);
        $this->assertStringContainsString('Detected 3 repeated <div> structures', $stats['warnings'][0]);
        $this->assertCount(1, $candidates);
        $this->assertSame('div[h3,p]', $candidates[0]['signature']);
        $this->assertSame('card', $candidates[0]['suggestedName']);
        $this->assertSame(3, $candidates[0]['occurrences']);
        $this->assertSame(1.0, $candidates[0]['confidence']);
        $this->assertTrue($candidates[0]['eligible']);
        $this->assertSame(3, $candidates[0]['threshold']['minOccurrences']);
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
        $this->assertSame([], $this->detector->candidates());
    }

    public function testCandidatesReportEditableFieldTypes(): void
    {
        $html = <<<'HTML'
<section>
    <div class="feature-card"><h3>One</h3><a href="/one">Open</a><img src="/one.jpg" alt="One"><svg viewBox="0 0 10 10"></svg></div>
    <div class="feature-card"><h3>Two</h3><a href="/two">Open</a><img src="/two.jpg" alt="Two"><svg viewBox="0 0 10 10"></svg></div>
    <div class="feature-card"><h3>Three</h3><a href="/three">Open</a><img src="/three.jpg" alt="Three"><svg viewBox="0 0 10 10"></svg></div>
</section>
HTML;

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->detector->analyze($dom->documentElement);
        $candidates = $this->detector->candidates();

        $this->assertSame(['text', 'link_url', 'image_src', 'image_alt', 'icon'], $candidates[0]['editableFieldTypes']);
    }

    public function testCandidatesMarkRepeatedStructuresWithoutEditableFieldsAsIneligible(): void
    {
        $html = <<<'HTML'
<section>
    <div class="decorative"><span></span></div>
    <div class="decorative"><span></span></div>
    <div class="decorative"><span></span></div>
</section>
HTML;

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->detector->analyze($dom->documentElement);
        $this->detector->reportFindings();
        $candidates = $this->detector->candidates();
        $stats = $this->report->toArray();

        $this->assertCount(1, $candidates);
        $this->assertFalse($candidates[0]['eligible']);
        $this->assertSame(0, $candidates[0]['editablePropertyCount']);
        $this->assertFalse($candidates[0]['editablePropertiesSufficient']);
        $this->assertSame(1, $candidates[0]['threshold']['minEditableProperties']);
        $this->assertContains('insufficient_editable_properties', $candidates[0]['reasons']);
        $this->assertSame([], $stats['warnings']);
    }

    public function testCandidatesReportAdvancedPatternTypes(): void
    {
        $html = <<<'HTML'
<section>
    <div class="feature-card variant-primary" data-repeat="items"><h3>{{ post.title }}</h3><ul><li>One</li></ul><form><input name="email"></form><style>.feature-card{color:red}</style></div>
    <div class="feature-card variant-primary" data-repeat="items"><h3>{{ post.title }}</h3><ul><li>Two</li></ul><form><input name="email"></form><style>.feature-card{color:blue}</style></div>
    <div class="feature-card variant-primary" data-repeat="items"><h3>{{ post.title }}</h3><ul><li>Three</li></ul><form><input name="email"></form><style>.feature-card{color:green}</style></div>
</section>
HTML;

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->detector->analyze($dom->documentElement);
        $candidates = $this->detector->candidates();

        $this->assertSame(
            ['variants', 'repeated_regions', 'dynamic_data', 'lists', 'forms', 'component_scoped_css'],
            $candidates[0]['advancedPatternTypes']
        );
    }
}
