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
        remove_all_filters();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        remove_all_filters();
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

    public function testNativeModeDoesNotEmitFallbackForNativeMappedUtilities(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $doc = new \DOMDocument();
        $doc->loadHTML('<div class="grid grid-cols-3 gap-4 p-4 text-6xl font-bold opacity-50">Hello</div>');

        $extractor = new DocumentCssExtractor(
            new HeuristicsService(),
            new TailwindDetector(),
            new TailwindPropertyMapper(),
            new TailwindCssFallbackGenerator()
        );

        $css = $extractor->extract($doc);

        $this->assertStringNotContainsString('Tailwind utility fallback', $css);
        $this->assertStringNotContainsString('.p-4', $css);
        $this->assertStringNotContainsString('.text-6xl', $css);
        $this->assertStringNotContainsString('.grid-cols-3', $css);
    }

    public function testNativeModeKeepsFallbackForResponsiveAndStateUtilities(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $doc = new \DOMDocument();
        $doc->loadHTML('<div class="p-4 md:grid-cols-3 hover:bg-[#ff0084]">Hello</div>');

        $extractor = new DocumentCssExtractor(
            new HeuristicsService(),
            new TailwindDetector(),
            new TailwindPropertyMapper(),
            new TailwindCssFallbackGenerator()
        );

        $css = $extractor->extract($doc);

        $this->assertStringContainsString('Tailwind utility fallback', $css);
        $this->assertStringContainsString('.md\\:grid-cols-3', $css);
        $this->assertStringContainsString('.hover\\:bg-\\[\\#ff0084\\]:hover', $css);
        $this->assertStringNotContainsString('.p-4 {', $css);
    }

    public function testWindPressModeStillExtractsTailwindFallbackForStyleRouter(): void
    {
        $this->enableWindPressIntegration();
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
        $this->assertStringContainsString('.p-4', $css);
    }

    private function enableWindPressIntegration(): void
    {
        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_integration'] = true;
            $flags['windpress_class_mode'] = true;
            return $flags;
        });
    }
}
