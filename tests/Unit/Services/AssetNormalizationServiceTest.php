<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use DOMDocument;
use OxyHtmlConverter\Services\AssetNormalizationService;
use OxyHtmlConverter\Services\HeadAssetExtractor;
use OxyHtmlConverter\Services\IconDetector;
use PHPUnit\Framework\TestCase;

class AssetNormalizationServiceTest extends TestCase
{
    public function testBuildReportClassifiesStableManualAndRejectedAssets(): void
    {
        $temporaryUrl = 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/hero.png?Expires=60';
        $singleQuotedTemporaryUrl = 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/single.png?Expires=60';
        $unquotedTemporaryUrl = 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/unquoted.png?Expires=60';
        $temporaryImportUrl = 'https://oaidalleapiprodscus.blob.core.windows.net/tmp/font.css?Expires=60';
        $importedFontUrl = 'https://fonts.googleapis.com/css2?family=Inter';
        $css = '.hero { background-image: url("' . $temporaryUrl . '"); }'
            . '.single { background-image: url(\'' . $singleQuotedTemporaryUrl . '\'); }'
            . '.unquoted { background-image: url(' . $unquotedTemporaryUrl . '); }'
            . '@import "' . $temporaryImportUrl . '";'
            . '@import "' . $importedFontUrl . '";'
            . '.local { background-image: url("/wp-content/uploads/bg.jpg"); }';
        $document = new DOMDocument();
        @$document->loadHTML(
            '<html><head>'
            . '<link rel="stylesheet" href="' . $importedFontUrl . '">'
            . '<script src="https://cdn.example.test/runtime.js"></script>'
            . '</head><body>'
            . '<img src="/wp-content/uploads/hero.jpg" alt="Hero">'
            . '<video poster="https://example.test/poster.jpg"><source src="video.mp4"></video>'
            . '<i data-lucide="menu"></i>'
            . '</body></html>'
            ,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $headAssets = (new HeadAssetExtractor(fn (): int => 1))->extractAssetReferences($document);
        $iconLibraries = (new IconDetector())->detectIconLibraries($document);
        $service = new AssetNormalizationService();
        $report = $service->buildReport($document, $css, $headAssets, $iconLibraries);

        $this->assertSame(4, $report['summary']['rejected']);
        $this->assertGreaterThanOrEqual(3, $report['summary']['manualFollowUp']);
        $this->assertGreaterThanOrEqual(3, $report['summary']['stable']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['fonts']);
        $this->assertSame(1, $report['summary']['icons']);
        $this->assertContains($temporaryUrl, $service->rejectedSources($report));
        $this->assertContains($singleQuotedTemporaryUrl, $service->rejectedSources($report));
        $this->assertContains($unquotedTemporaryUrl, $service->rejectedSources($report));
        $this->assertContains($temporaryImportUrl, $service->rejectedSources($report));
        $this->assertTrue(
            count(array_filter(
                $report['assets'],
                static fn (array $asset): bool => ($asset['source'] ?? '') === $importedFontUrl
            )) >= 1
        );
        $this->assertStringNotContainsString($temporaryUrl, $service->sanitizeCss($css, $report));
        $this->assertStringNotContainsString($singleQuotedTemporaryUrl, $service->sanitizeCss($css, $report));
        $this->assertStringNotContainsString($unquotedTemporaryUrl, $service->sanitizeCss($css, $report));
        $this->assertStringNotContainsString($temporaryImportUrl, $service->sanitizeCss($css, $report));
        $this->assertStringContainsString($importedFontUrl, $service->sanitizeCss($css, $report));
        $this->assertStringContainsString('url("")', $service->sanitizeCss($css, $report));
    }
}
