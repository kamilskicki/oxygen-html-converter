<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use DOMDocument;
use OxyHtmlConverter\Services\HeadAssetExtractor;
use PHPUnit\Framework\TestCase;

class HeadAssetExtractorTest extends TestCase
{
    public function testExtractAssetReferencesListsHeadStylesheetsAndScriptsOnce(): void
    {
        $document = new DOMDocument();
        @$document->loadHTML(
            '<html><head>'
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com">'
            . '<link rel="preload" as="font" href="https://fonts.gstatic.com/s/inter/v20/inter.woff2">'
            . '<script src="https://cdn.example.test/runtime.js"></script>'
            . '<script src="https://cdn.example.test/runtime.js"></script>'
            . '</head><body><main>Content</main></body></html>'
            ,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $assets = (new HeadAssetExtractor(fn (): int => 1))->extractAssetReferences($document);

        $this->assertCount(3, $assets);
        $this->assertSame('head_stylesheet', $assets[0]['type']);
        $this->assertSame('https://fonts.googleapis.com/css2?family=Inter', $assets[0]['source']);
        $this->assertSame('head_font_preload', $assets[1]['type']);
        $this->assertSame('https://fonts.gstatic.com/s/inter/v20/inter.woff2', $assets[1]['source']);
        $this->assertSame('head_script', $assets[2]['type']);
        $this->assertSame('https://cdn.example.test/runtime.js', $assets[2]['source']);
    }

    public function testExtractTemporaryMediaReferencesFindsMediaAndSrcsetUrls(): void
    {
        $document = new DOMDocument();
        @$document->loadHTML(
            '<html><body>'
            . '<img src="https://aida-public.example/tmp/hero.png?Expires=60" '
            . 'srcset="https://aida-public.example/tmp/hero-small.png?Expires=60 640w, /uploads/hero.png 1280w">'
            . '<video poster="https://cdn.example.test/poster.jpg"><source src="/uploads/movie.mp4"></video>'
            . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $assets = (new HeadAssetExtractor(fn (): int => 1))->extractTemporaryMediaReferences($document);

        $this->assertCount(2, $assets);
        $this->assertSame('src', $assets[0]['attribute']);
        $this->assertSame('srcset', $assets[1]['attribute']);
        $this->assertSame('https://aida-public.example/tmp/hero.png?Expires=60', $assets[0]['source']);
        $this->assertSame('https://aida-public.example/tmp/hero-small.png?Expires=60', $assets[1]['source']);
    }
}
