<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementMapper;
use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\TreeBuilder;
use OxyHtmlConverter\Services\AnimationDetector;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\ConversionFallbackReporter;
use OxyHtmlConverter\Services\ConversionIrBuilder;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\Services\DocumentCssExtractor;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Services\HtmlCodeSanitizer;
use OxyHtmlConverter\Services\HtmlNormalizer;
use OxyHtmlConverter\Services\IconDetector;
use OxyHtmlConverter\Services\InteractionDetector;
use OxyHtmlConverter\Services\JavaScriptTransformer;
use OxyHtmlConverter\Services\NativeCssMaterializer;
use OxyHtmlConverter\Services\NativeElementMapper;
use OxyHtmlConverter\Services\NativeNodeMapper;
use OxyHtmlConverter\Services\OxygenSelectorImporter;
use OxyHtmlConverter\Services\SelectorMatcher;
use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use OxyHtmlConverter\StyleExtractor;
use PHPUnit\Framework\TestCase;

class ConversionIrBuilderTest extends TestCase
{
    public function testHtmlNormalizerReturnsExplicitDomCssAndBodyOutput(): void
    {
        $normalizer = $this->createNormalizer();

        $normalized = $normalizer->normalize(
            '<!DOCTYPE html><html><head><style>.card { color: red; }</style></head>'
            . '<body><section class="card">Copy</section></body></html>'
        );

        $this->assertTrue($normalized->isSuccess());
        $this->assertSame('body', strtolower($normalized->root()->tagName));
        $this->assertStringContainsString('.card', $normalized->extractedCss());
        $this->assertNotEmpty($normalized->cssRules());
        $this->assertCount(1, $normalized->bodyNodes());
        $this->assertIsArray($normalized->errors());
    }

    public function testConversionIrPreservesPreMappingFactsForLaterPipelineStages(): void
    {
        $normalized = $this->createNormalizer()->normalize(
            '<!DOCTYPE html><html><head><style>'
            . '.pricing-card { color:#123456; padding:24px; }'
            . '.feature-card { padding:24px; color:#123456; }'
            . '.service-card { color:#123456; padding:24px; }'
            . '</style></head><body>'
            . '<section>'
            . '<article class="pricing-card"><h2>One</h2></article>'
            . '<article class="feature-card"><h2>Two</h2></article>'
            . '<article class="service-card"><h2>Three</h2></article>'
            . '<a href="#details">Details</a><i data-lucide="menu"></i>'
            . '</section>'
            . '<script>document.querySelectorAll(\'a[href^="#"]\').forEach(link => { link.addEventListener("click", function(e) { e.preventDefault(); document.querySelector(this.getAttribute("href")).scrollIntoView({ behavior: "smooth" }); }); });</script>'
            . '</body></html>'
        );
        $report = new ConversionReport();
        $builder = new ConversionIrBuilder(
            new InteractionDetector(),
            new IconDetector(),
            $report
        );

        $ir = $builder->build($normalized);

        $this->assertGreaterThanOrEqual(2, count($ir->bodyNodes()));
        $this->assertNotEmpty($ir->cssRules());
        $this->assertContains('pricing-card', $ir->sourceClassTokens());
        $this->assertSame('ohc-card', $ir->classAliases()['pricing-card'] ?? null);
        $this->assertTrue($ir->javaScriptPatterns()['smoothScroll']);
        $this->assertArrayHasKey('lucide', $ir->detectedIconLibraries());
        $this->assertNotEmpty($ir->componentCandidates());
    }

    public function testNativeElementMapperBuildsRootContainerForMultipleMappedNodes(): void
    {
        $normalized = $this->createNormalizer()->normalize('<div>One</div><div>Two</div>');
        $ir = (new ConversionIrBuilder(
            new InteractionDetector(),
            new IconDetector(),
            new ConversionReport()
        ))->build($normalized);
        $nodeMapper = $this->createNativeNodeMapper($ir->cssRules(), $ir->javaScriptPatterns());
        $mapper = new NativeElementMapper($nodeMapper);

        $result = $mapper->map(
            $ir,
            static fn(): int => 99
        );

        $this->assertTrue($result->hasRootElement());
        $this->assertSame(99, $result->rootElement()['id'] ?? null);
        $this->assertSame(ElementTypes::CONTAINER, $result->rootElement()['data']['type'] ?? null);
        $this->assertCount(2, $result->children());
        $this->assertSame('One', $result->children()[0]['data']['properties']['content']['content']['text']);
    }

    public function testTreeBuilderDoesNotOwnNativeMappingStyleOrFallbackInternals(): void
    {
        $reflection = new \ReflectionClass(TreeBuilder::class);

        foreach ([
            'convertNode',
            'applyCssRules',
            'cleanupConsumedCssRules',
            'reportUnsupportedNode',
            'reportExecutableAttributes',
            'sanitizeSafeModeElementSinks',
        ] as $method) {
            $this->assertFalse(
                $reflection->hasMethod($method),
                'TreeBuilder should delegate ' . $method . ' to an explicit pipeline service.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $cssRules
     * @param array<string, mixed> $javaScriptPatterns
     */
    private function createNativeNodeMapper(array $cssRules, array $javaScriptPatterns): NativeNodeMapper
    {
        $report = new ConversionReport();
        $environment = new EnvironmentService();
        $tailwindDetector = new TailwindDetector();
        $tailwindPropertyMapper = new TailwindPropertyMapper();
        $styleExtractor = new StyleExtractor();
        $cssParser = new CssParser();
        $selectorImporter = new OxygenSelectorImporter();
        $htmlCodeSanitizer = new HtmlCodeSanitizer();
        $cssMaterializer = new NativeCssMaterializer(
            $styleExtractor,
            $cssParser,
            new SelectorMatcher(),
            $selectorImporter,
            $environment
        );
        $fallbackReporter = new ConversionFallbackReporter($report, $htmlCodeSanitizer);
        $fallbackReporter->configure(true, false);
        $cssMaterializer->configure([], true);

        $mapper = new NativeNodeMapper(
            new HtmlParser(),
            new ElementMapper(),
            $styleExtractor,
            new JavaScriptTransformer(),
            $environment,
            new ClassStrategyService($environment, $report, $tailwindDetector, $tailwindPropertyMapper),
            $tailwindDetector,
            new InteractionDetector(new FrameworkDetector($report)),
            new FrameworkDetector($report),
            new AnimationDetector(),
            new HeuristicsService(),
            $htmlCodeSanitizer,
            $selectorImporter,
            $cssMaterializer,
            $fallbackReporter,
            $report
        );
        $mapper->configure($cssRules, $javaScriptPatterns, true, true, false, static function (): int {
            static $id = 10;
            return ++$id;
        });

        return $mapper;
    }

    private function createNormalizer(): HtmlNormalizer
    {
        return new HtmlNormalizer(
            new HtmlParser(),
            new DocumentCssExtractor(
                new HeuristicsService(),
                new TailwindDetector(),
                new TailwindPropertyMapper(),
                new TailwindCssFallbackGenerator()
            ),
            new CssParser()
        );
    }
}
