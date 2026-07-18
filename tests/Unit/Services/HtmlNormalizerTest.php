<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use DOMElement;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\Services\DocumentCssExtractor;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Services\HeadAssetExtractor;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Services\HtmlNormalizer;
use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use PHPUnit\Framework\TestCase;

class HtmlNormalizerTest extends TestCase
{
    public function testNormalizesGeneratedSourceFixtureDeterministically(): void
    {
        $fixture = self::loadFixture();
        $html = file_get_contents($fixture['path']);
        $this->assertIsString($html);

        $first = $this->createNormalizer()->normalize($html, $fixture['manifest']);
        $second = $this->createNormalizer()->normalize($html, $fixture['manifest']);
        $report = $first->normalizationReport();

        $this->assertTrue($first->isSuccess());
        $this->assertSame($first->normalizedHtml(), $second->normalizedHtml());
        $this->assertSame($first->normalizedHash(), $second->normalizedHash());
        $this->assertSame(2, $report['summary']['placeholderLinks']);
        $this->assertSame(2, $report['summary']['temporaryMedia']);
        $this->assertSame(2, $report['summary']['sourceArtifactsRemoved']);
        $this->assertCount(2, array_filter(
            $first->decisions(),
            static fn (array $decision): bool => ($decision['type'] ?? '') === 'duplicate_root_wrapper'
        ));

        $bodyNodes = array_values(array_filter(
            $first->bodyNodes(),
            static fn ($node): bool => $node instanceof DOMElement
        ));
        $this->assertCount(2, $bodyNodes);
        $this->assertSame('header', strtolower($bodyNodes[0]->tagName));
        $this->assertSame('main', strtolower($bodyNodes[1]->tagName));
        $this->assertSame('', $first->document()->getElementById('site-header')->getAttribute('data-ai-wrapper'));
        $this->assertSame('', $first->document()->getElementById('hero-header')->getAttribute('data-figma-id'));
    }

    public function testGenericSourceHintAttributesAreReportedButPreserved(): void
    {
        $normalized = $this->createNormalizer()->normalize(
            '<style>[data-source="hero"] { color:#123456; }</style>'
            . '<section id="hero" data-source="hero" data-layer="Hero">Copy</section>'
        );
        $hero = $normalized->document()->getElementById('hero');
        $this->assertInstanceOf(DOMElement::class, $hero);

        $reported = array_values(array_filter(
            $normalized->issues(),
            static fn (array $issue): bool => ($issue['type'] ?? '') === 'source_artifact_attribute'
                && ($issue['action'] ?? '') === 'reported'
        ));

        $this->assertSame('hero', $hero->getAttribute('data-source'));
        $this->assertSame('Hero', $hero->getAttribute('data-layer'));
        $this->assertCount(2, $reported);
    }

    public function testHeaderRoleClassificationIsDeterministicAndManifestOverrideable(): void
    {
        $html = '<header id="site-header"><nav><a href="/">Home</a><a href="/about">About</a></nav></header>'
            . '<main><header id="hero-header"><h1>Hero</h1></header></main>';

        $default = $this->createNormalizer()->normalize($html);
        $defaultRoles = $this->rolesBySelector($default->headerDecisions());

        $this->assertSame('site_header', $defaultRoles['header#site-header'] ?? null);
        $this->assertSame('content_header', $defaultRoles['header#hero-header'] ?? null);

        $overridden = $this->createNormalizer()->normalize($html, [
            'normalization' => [
                'headerRoles' => [
                    '#site-header' => 'content_header',
                ],
            ],
        ]);
        $overrideRoles = $this->rolesBySelector($overridden->headerDecisions());
        $overrideSources = $this->sourcesBySelector($overridden->headerDecisions());

        $this->assertSame('content_header', $overrideRoles['header#site-header'] ?? null);
        $this->assertSame('override', $overrideSources['header#site-header'] ?? null);
    }

    public function testSiteHeaderSignalsWinInsideGeneratedSectionWrapper(): void
    {
        $html = '<section class="generated-page"><header id="site-header" class="site-header">'
            . '<nav><a href="/">Home</a><a href="/about">About</a></nav>'
            . '</header><main><p>Copy</p></main></section>';

        $normalized = $this->createNormalizer()->normalize($html);
        $roles = $this->rolesBySelector($normalized->headerDecisions());

        $this->assertSame('site_header', $roles['header#site-header'] ?? null);
    }

    public function testNormalizationReportIncludesHeadAssetsAndFrameworks(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
            . '</head><body><div x-data="{ open: false }">Panel</div></body></html>';

        $normalized = $this->createNormalizer()->normalize($html);

        $this->assertSame(['Alpine.js'], $normalized->frameworks());
        $this->assertSame('head_stylesheet', $normalized->headAssets()[0]['type'] ?? null);
        $this->assertSame(['Alpine.js'], $normalized->normalizationReport()['frameworks']);
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @return array<string, string>
     */
    private function rolesBySelector(array $decisions): array
    {
        $roles = [];
        foreach ($decisions as $decision) {
            $roles[(string) ($decision['selector'] ?? '')] = (string) ($decision['role'] ?? '');
        }

        return $roles;
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @return array<string, string>
     */
    private function sourcesBySelector(array $decisions): array
    {
        $sources = [];
        foreach ($decisions as $decision) {
            $sources[(string) ($decision['selector'] ?? '')] = (string) ($decision['source'] ?? '');
        }

        return $sources;
    }

    private function createNormalizer(): HtmlNormalizer
    {
        $report = new ConversionReport();
        $heuristics = new HeuristicsService();
        $headAssetExtractor = new HeadAssetExtractor(static fn (): int => 0);

        return new HtmlNormalizer(
            new HtmlParser(),
            new DocumentCssExtractor(
                $heuristics,
                new TailwindDetector(),
                new TailwindPropertyMapper(),
                new TailwindCssFallbackGenerator()
            ),
            new CssParser(),
            $headAssetExtractor,
            new FrameworkDetector($report),
            $heuristics
        );
    }

    /**
     * @return array{path:string, manifest:array<string, mixed>}
     */
    private static function loadFixture(): array
    {
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'html'
            . DIRECTORY_SEPARATOR . 'source-normalization';
        $manifestJson = file_get_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json');
        if (!is_string($manifestJson)) {
            throw new \RuntimeException('Source normalization manifest could not be read.');
        }

        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest) || !is_array($manifest['fixtures'] ?? null)) {
            throw new \RuntimeException('Source normalization manifest is invalid.');
        }

        $fixture = $manifest['fixtures'][0];
        if (!is_array($fixture)) {
            throw new \RuntimeException('Source normalization fixture entry is invalid.');
        }

        return [
            'path' => $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $fixture['file']),
            'manifest' => is_array($fixture['normalization'] ?? null)
                ? ['normalization' => $fixture['normalization']]
                : [],
        ];
    }
}
