<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\DocumentCssExtractor;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use PHPUnit\Framework\TestCase;

class DocumentCssExtractorTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        parent::tearDown();
    }

    public function testAddsCompatibilityShimForUniversalReset(): void
    {
        $doc = new \DOMDocument();
        $doc->loadHTML('<style>*, *::before, *::after { margin:0; padding:0; }</style><div>Hello</div>');

        $extractor = new DocumentCssExtractor(
            new HeuristicsService(),
            new TailwindDetector(),
            new TailwindPropertyMapper(),
            new TailwindCssFallbackGenerator()
        );

        $css = $extractor->extract($doc);

        $this->assertStringContainsString('body h1, body h2, body h3, body h4, body h5, body h6,', $css);
    }

    public function testAppendsTailwindFallbackCss(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $doc = new \DOMDocument();
        $doc->loadHTML('<div class="md:text-8xl leading-[0.9]">Hello</div>');

        $extractor = new DocumentCssExtractor(
            new HeuristicsService(),
            new TailwindDetector(),
            new TailwindPropertyMapper(),
            new TailwindCssFallbackGenerator()
        );

        $css = $extractor->extract($doc);

        $this->assertStringContainsString('Tailwind utility fallback', $css);
        $this->assertStringContainsString('.leading-\\[0\\.9\\]', $css);
    }

    public function testWindPressModeStillExtractsTailwindFallbackForStyleRouter(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'windpress';

        $doc = new \DOMDocument();
        $doc->loadHTML('<div class="text-6xl leading-[0.9] md:flex p-4">Hello</div>');

        $extractor = new DocumentCssExtractor(
            new HeuristicsService(),
            new TailwindDetector(),
            new TailwindPropertyMapper(),
            new TailwindCssFallbackGenerator()
        );

        $css = $extractor->extract($doc);

        $this->assertStringContainsString('Tailwind utility fallback', $css);
        $this->assertStringContainsString('.text-6xl', $css);
        $this->assertStringContainsString('.leading-\\[0\\.9\\]', $css);
    }
}
