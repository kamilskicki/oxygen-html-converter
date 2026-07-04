<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\DesignDocumentBuilder;
use PHPUnit\Framework\TestCase;

class DesignDocumentBuilderTest extends TestCase
{
    public function testBuildDetectsSectionsTokensAndComponentCandidates(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
<head>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">
    <style>
        .hero { background: #731b19; color: rgba(255, 255, 255, .92); padding: 48px 24px; font-family: "Inter", sans-serif; }
        .feature-card { border-color: #731B19; margin-bottom: 24px; }
    </style>
</head>
<body>
    <section id="top" class="hero">
        <h1>Launch Page</h1>
        <a class="btn btn-primary" href="#">Start</a>
    </section>
    <section class="features">
        <div class="feature-card"><h3>One</h3><p>Alpha</p><a href="#">Open</a></div>
        <div class="feature-card"><h3>Two</h3><p>Beta</p><a href="#">Open</a></div>
        <div class="feature-card"><h3>Three</h3><p>Gamma</p><a href="#">Open</a></div>
    </section>
</body>
</html>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'element' => [
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [
                    [
                        'data' => ['type' => ElementTypes::CSS_CODE],
                        'children' => [],
                    ],
                ],
            ],
            'extractedCss' => '.hero{}',
            'customClasses' => ['hero', 'feature-card'],
            'selectorPayload' => [
                'selectors' => [['selector' => '.hero']],
                'collections' => [],
            ],
        ]));

        $this->assertSame(1, $document['version']);
        $this->assertTrue($document['source']['hasFullDocument']);
        $this->assertGreaterThanOrEqual(2, $document['summary']['sectionCount']);
        $this->assertGreaterThanOrEqual(1, $document['summary']['componentCandidatesCount']);
        $this->assertTrue($document['summary']['fallbackCss']);
        $this->assertSame('hero', $document['sections'][0]['role']);
        $this->assertSame('Launch Page', $document['sections'][0]['heading']);
        $this->assertSame('native', $document['classStrategy']['recommendation']);

        $this->assertContains('#731B19', array_column($document['tokens']['colors'], 'value'));
        $this->assertContains('Inter', array_column($document['tokens']['fonts'], 'value'));
        $this->assertContains('24px', array_column($document['tokens']['spacing'], 'value'));
        $this->assertContains('card', array_column($document['componentCandidates'], 'suggestedName'));
    }

    public function testBuildRecommendsWindPressForTailwindHeavyMarkup(): void
    {
        $html = '<section class="flex items-center justify-between gap-8 p-8 text-white bg-[#111827] rounded-2xl shadow-xl"><h1 class="text-5xl font-bold tracking-tight">Hello</h1></section>';
        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'stats' => [
                'elements' => 4,
                'tailwindClasses' => 22,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
            'customClasses' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
        ]));

        $this->assertSame('windpress', $document['classStrategy']['recommendation']);
        $this->assertContains(
            'Keep WindPress as the fast path for Tailwind-heavy drafts, then promote repeated patterns into native selectors.',
            $document['followUp']
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function resultFixture(array $overrides = []): array
    {
        return array_replace_recursive([
            'success' => true,
            'element' => [
                'data' => ['type' => ElementTypes::CONTAINER],
                'children' => [],
            ],
            'cssElement' => null,
            'headLinkElements' => [],
            'headScriptElements' => [],
            'iconScriptElements' => [],
            'detectedIconLibraries' => [],
            'extractedCss' => '',
            'customClasses' => [],
            'selectorPayload' => [
                'selectors' => [],
                'collections' => [],
            ],
            'stats' => [
                'elements' => 1,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ], $overrides);
    }
}
