<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\DesignDocumentBuilder;
use OxyHtmlConverter\Validation\OxygenSchemaValidator;
use PHPUnit\Framework\TestCase;

class DesignDocumentBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters();
    }

    public function testBuildDetectsSectionsTokensAndComponentCandidates(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
<head>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">
    <style>
        :root { --ohc-radius: 12px; --ohc-hero-gradient: linear-gradient(135deg, #731b19 0%, #14b8a6 100%); }
        body { font-family: "Inter", sans-serif; font-size: 16px; }
        .hero { background: #731b19 url("https://example.test/assets/hero.jpg") center/cover; color: rgba(255, 255, 255, .92); padding: 48px 24px; font-family: "Inter", sans-serif; font-size: 18px; max-width: 1120px; opacity: .92; z-index: 2; transition-duration: 180ms; }
        .feature-card { border-color: #731B19; margin-bottom: 24px; border-radius: 16px; }
    </style>
</head>
<body>
    <section id="top" class="hero">
        <h1>Launch Page</h1>
        <a class="btn btn-primary" href="#">Start</a>
        <img src="https://example.test/assets/product.png" alt="Product">
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
            'element' => $this->convertedFeatureCardsElement([
                ['title' => 'One', 'copy' => 'Alpha', 'url' => '#', 'label' => 'Open'],
                ['title' => 'Two', 'copy' => 'Beta', 'url' => '#', 'label' => 'Open'],
                ['title' => 'Three', 'copy' => 'Gamma', 'url' => '#', 'label' => 'Open'],
            ]),
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
        $this->assertGreaterThanOrEqual(2, $document['summary']['imageTokenCount']);
        $this->assertGreaterThanOrEqual(3, $document['summary']['measurementTokenCount']);
        $this->assertGreaterThanOrEqual(2, $document['summary']['numberTokenCount']);
        $this->assertSame('hero', $document['sections'][0]['role']);
        $this->assertSame('Launch Page', $document['sections'][0]['heading']);
        $this->assertSame('native', $document['classStrategy']['recommendation']);

        $this->assertContains('#731B19', array_column($document['tokens']['colors'], 'value'));
        $this->assertContains('Inter', array_column($document['tokens']['fonts'], 'value'));
        $this->assertContains('24px', array_column($document['tokens']['spacing'], 'value'));
        $this->assertContains('https://example.test/assets/hero.jpg', array_column($document['tokens']['images'], 'value'));
        $this->assertContains('https://example.test/assets/product.png', array_column($document['tokens']['images'], 'value'));
        $this->assertContains('18px', array_column($document['tokens']['measurements'], 'value'));
        $this->assertContains('1120px', array_column($document['tokens']['measurements'], 'value'));
        $this->assertContains('16px', array_column($document['tokens']['measurements'], 'value'));
        $this->assertContains('0.92', array_column($document['tokens']['numbers'], 'value'));
        $this->assertContains('2', array_column($document['tokens']['numbers'], 'value'));
        $this->assertContains('card', array_column($document['componentCandidates'], 'suggestedName'));
        $this->assertIsArray($document['componentCandidates'][0]['documentTree']);
        $this->assertSame('root', $document['componentCandidates'][0]['documentTree']['root']['data']['type']);

        $this->assertIsArray($document['oxygenGlobalSettings']);
        $globalSettingsValidation = (new OxygenSchemaValidator())->validateGlobalSettings($document['oxygenGlobalSettings']);
        $this->assertTrue($globalSettingsValidation['valid'], wp_json_encode($globalSettingsValidation['errors']));

        $settings = $document['oxygenGlobalSettings']['settings'];
        $this->assertContains('ohc-color-731b19', array_column($settings['colors']['palette']['colors'], 'cssVariableName'));
        $this->assertContains('ohc-hero-gradient', array_column($settings['colors']['palette']['gradients'], 'cssVariableName'));
        $this->assertSame('Inter', $settings['typography']['body_font']);
        $this->assertSame('16px', $settings['typography']['base_size']['style']);
        $this->assertNotEmpty($settings['typography']['global_typography']['typography_presets']);
        $this->assertSame('ohc-body', $settings['typography']['global_typography']['typography_presets'][0]['preset']['id']);
        $this->assertSame('1120px', $settings['containers']['sections']['container_width']['style']);
        $this->assertSame('180ms', $settings['other']['transition_duration']['style']);
        $this->assertSame([], $settings['code']['scripts']);
        $this->assertStringContainsString('--ohc-radius: 12px', $settings['code']['stylesheets'][0]['code']);
    }

    public function testBuildDoesNotRecommendWindPressForTailwindHeavyMarkupWhenIntegrationIsDisabled(): void
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

        $this->assertSame('hybrid', $document['classStrategy']['recommendation']);
        $this->assertNotContains(
            'Keep WindPress as the fast path for Tailwind-heavy drafts, then promote repeated patterns into native selectors.',
            $document['followUp']
        );
    }

    public function testBuildCanRecommendWindPressForTailwindHeavyMarkupWhenIntegrationIsEnabled(): void
    {
        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_integration'] = true;
            $flags['windpress_class_mode'] = true;
            return $flags;
        });

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

    public function testBuildAddsEditableComponentPropertiesToCandidates(): void
    {
        $html = <<<'HTML'
<section>
    <div class="feature-card"><h3>One</h3><p>Alpha</p><a href="https://example.test/one">Open</a><img src="https://example.test/one.jpg" alt="One image"></div>
    <div class="feature-card"><h3>Two</h3><p>Beta</p><a href="https://example.test/two">Open</a><img src="https://example.test/two.jpg" alt="Two image"></div>
    <div class="feature-card"><h3>Three</h3><p>Gamma</p><a href="https://example.test/three">Open</a><img src="https://example.test/three.jpg" alt="Three image"></div>
</section>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'customClasses' => ['feature-card'],
            'element' => $this->convertedFeatureCardsElement([
                [
                    'title' => 'One',
                    'copy' => 'Alpha',
                    'url' => 'https://example.test/one',
                    'label' => 'Open',
                    'image' => 'https://example.test/one.jpg',
                    'alt' => 'One image',
                ],
                [
                    'title' => 'Two',
                    'copy' => 'Beta',
                    'url' => 'https://example.test/two',
                    'label' => 'Open',
                    'image' => 'https://example.test/two.jpg',
                    'alt' => 'Two image',
                ],
                [
                    'title' => 'Three',
                    'copy' => 'Gamma',
                    'url' => 'https://example.test/three',
                    'label' => 'Open',
                    'image' => 'https://example.test/three.jpg',
                    'alt' => 'Three image',
                ],
            ]),
        ]));

        $candidate = $document['componentCandidates'][0];
        $schema = $candidate['componentProperties'];
        $targetsByKey = [];
        foreach ($schema['targets'] as $target) {
            $targetsByKey[$target['propertyKey']] = $target;
        }

        $this->assertSame('card', $candidate['suggestedName']);
        $this->assertSame('content.content.text', $targetsByKey['card_text']['controlPath']);
        $this->assertSame('content.content.text', $targetsByKey['card_text_2']['controlPath']);
        $this->assertSame('content.content.text', $targetsByKey['card_link_label']['controlPath']);
        $this->assertSame('content.content.url', $targetsByKey['card_link_url']['controlPath']);
        $this->assertSame('content.image.url', $targetsByKey['card_image_url']['controlPath']);
        $this->assertSame('content.image.custom_alt_when_from_url', $targetsByKey['card_image_alt']['controlPath']);
        $this->assertSame('One', $schema['properties']['card_text']);
        $this->assertSame('Alpha', $schema['properties']['card_text_2']);
        $this->assertSame('https://example.test/one', $schema['properties']['card_link_url']);
        $this->assertSame('https://example.test/one.jpg', $schema['properties']['card_image_url']);
        $this->assertSame('One image', $schema['properties']['card_image_alt']);
    }

    public function testBuildUsesConvertedTreeSignatureForSvgComponentCandidates(): void
    {
        $html = <<<'HTML'
<section>
    <div class="feature-card"><h3>One</h3><a href="/one">Open</a><img src="/one.jpg" alt="One"><svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg></div>
    <div class="feature-card"><h3>Two</h3><a href="/two">Open</a><img src="/two.jpg" alt="Two"><svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg></div>
    <div class="feature-card"><h3>Three</h3><a href="/three">Open</a><img src="/three.jpg" alt="Three"><svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg></div>
</section>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'customClasses' => ['feature-card'],
            'element' => $this->convertedFeatureCardsElement([
                [
                    'title' => 'One',
                    'url' => '/one',
                    'label' => 'Open',
                    'image' => '/one.jpg',
                    'alt' => 'One',
                    'html' => '<svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg>',
                ],
                [
                    'title' => 'Two',
                    'url' => '/two',
                    'label' => 'Open',
                    'image' => '/two.jpg',
                    'alt' => 'Two',
                    'html' => '<svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg>',
                ],
                [
                    'title' => 'Three',
                    'url' => '/three',
                    'label' => 'Open',
                    'image' => '/three.jpg',
                    'alt' => 'Three',
                    'html' => '<svg viewBox="0 0 10 10"><path d="M0 0h1v1z"></path></svg>',
                ],
            ]),
        ]));

        $candidate = $document['componentCandidates'][0];
        $targetsByKey = [];
        foreach ($candidate['componentProperties']['targets'] as $target) {
            $targetsByKey[$target['propertyKey']] = $target;
        }

        $this->assertSame('div[h3,a,img,html]', $candidate['signature']);
        $this->assertSame('div', $candidate['tag']);
        $this->assertSame('content.content.url', $targetsByKey['card_link_url']['controlPath']);
        $this->assertSame('content.image.url', $targetsByKey['card_image_url']['controlPath']);
    }

    public function testBuildDetectsRepeatedNavItemsWithEditableInstances(): void
    {
        $html = <<<'HTML'
<nav>
    <ul>
        <li><a href="/one">One</a></li>
        <li><a href="/two">Two</a></li>
        <li><a href="/three">Three</a></li>
    </ul>
</nav>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'element' => $this->convertedNavItemsElement([
                ['label' => 'One', 'url' => '/one'],
                ['label' => 'Two', 'url' => '/two'],
                ['label' => 'Three', 'url' => '/three'],
            ]),
        ]));

        $candidate = $document['componentCandidates'][0];

        $this->assertSame('li[a]', $candidate['signature']);
        $this->assertSame('nav-item', $candidate['suggestedName']);
        $this->assertTrue($candidate['editablePropertiesSufficient']);
        $this->assertSame(2, $candidate['editablePropertyCount']);
        $this->assertSame(1, $candidate['threshold']['minEditableProperties']);
        $this->assertSame([1, 3, 5], array_column($candidate['instances'], 'nodeId'));
    }

    public function testBuildMarksDecorativeRepeatedStructuresAsIneligible(): void
    {
        $html = <<<'HTML'
<section>
    <div class="decorative"><span></span></div>
    <div class="decorative"><span></span></div>
    <div class="decorative"><span></span></div>
</section>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'element' => $this->convertedDecorativeItemsElement(),
        ]));

        $candidate = $document['componentCandidates'][0];

        $this->assertSame('div[span]', $candidate['signature']);
        $this->assertFalse($candidate['eligible']);
        $this->assertSame(0, $candidate['editablePropertyCount']);
        $this->assertFalse($candidate['editablePropertiesSufficient']);
        $this->assertSame('insufficient_editable_properties', $candidate['reason']);
        $this->assertSame([1, 3, 5], array_column($candidate['instances'], 'nodeId'));
    }

    public function testBuildDoesNotMergeStructurallySimilarNodesWithDifferentSemanticClasses(): void
    {
        $html = <<<'HTML'
<section>
    <div class="account-summary"><h3>Account</h3><p>Balance</p><a href="#">Open</a></div>
    <div class="legal-notice"><h3>Legal</h3><p>Terms</p><a href="#">Read</a></div>
    <div class="hero-copy"><h3>Welcome</h3><p>Intro</p><a href="#">Start</a></div>
</section>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'element' => $this->convertedFeatureCardsElement([
                ['class' => 'account-summary', 'title' => 'Account', 'copy' => 'Balance', 'label' => 'Open'],
                ['class' => 'legal-notice', 'title' => 'Legal', 'copy' => 'Terms', 'label' => 'Read'],
                ['class' => 'hero-copy', 'title' => 'Welcome', 'copy' => 'Intro', 'label' => 'Start'],
            ]),
        ]));

        $this->assertSame([], $document['componentCandidates']);
    }

    public function testBuildReportsSemanticClassMapAndApplications(): void
    {
        $html = <<<'HTML'
<html>
<head>
    <style>
        .pricing-card { color:#123456; padding:24px; }
        .feature-card { padding:24px; color:#123456; }
    </style>
</head>
<body>
    <article class="pricing-card">One</article>
    <article class="feature-card">Two</article>
</body>
</html>
HTML;

        $document = (new DesignDocumentBuilder())->build($html, $this->resultFixture([
            'customClasses' => ['pricing-card', 'feature-card'],
            'selectorPayload' => [
                'selectors' => [['selector' => '.ohc-card']],
                'collections' => [],
            ],
        ]));

        $this->assertSame('ohc-card', $document['classStrategy']['classMap'][0]['semanticClass']);
        $this->assertSame('ohc-card', $document['classStrategy']['aliases']['pricing-card']);
        $this->assertSame(['feature-card', 'pricing-card'], $document['classStrategy']['duplicateStylePatterns'][0]['sourceClasses']);
        $this->assertSame(1, $document['classStrategy']['selectorCountReduction']);
        $this->assertSame('ohc-card', $document['designProfile']['semanticClasses'][0]['semanticClass']);
        $this->assertSame(['pricing-card'], $document['designProfile']['elementApplications'][0]['sourceClasses']);
        $this->assertSame(['ohc-card'], $document['designProfile']['elementApplications'][0]['appliedClasses']);
        $this->assertSame(2, $document['summary']['classApplicationCount']);
    }

    public function testBuildCountsRealOxygenSurfaceShape(): void
    {
        $document = (new DesignDocumentBuilder())->build('<main><h1>Surface</h1></main>', $this->resultFixture([
            ...$this->oxygenSurfaceResultOverrides(),
            'selectorPayload' => [
                'selectors' => [['selector' => '.hero']],
                'collections' => [],
            ],
            'stats' => [
                'elements' => 99,
                'tailwindClasses' => 0,
                'customClasses' => 0,
                'warnings' => [],
                'errors' => [],
                'info' => [],
                'unsupportedItems' => [[
                    'location' => 'body > iframe',
                    'reason' => 'Unsupported embed.',
                ]],
            ],
        ]));

        $this->assertSame(11, $document['summary']['totalNodes']);
        $this->assertSame(6, $document['summary']['codeBlocksTotal']);
        $this->assertSame(4, $document['summary']['htmlCodeBlocks']);
        $this->assertSame(1, $document['summary']['cssCodeBlocks']);
        $this->assertSame(1, $document['summary']['javascriptCodeBlocks']);
        $this->assertSame(1, $document['summary']['componentNodes']);
        $this->assertSame(2, $document['summary']['assetNodes']);
        $this->assertSame(1, $document['summary']['imageNodes']);
        $this->assertSame(1, $document['summary']['videoNodes']);
        $this->assertSame(4, $document['summary']['classAssignments']);
        $this->assertSame(1, $document['summary']['selectorCount']);
        $this->assertSame(1, $document['summary']['unsupportedCount']);
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

    /**
     * @param list<array<string, string>> $cards
     * @return array<string, mixed>
     */
    private function convertedFeatureCardsElement(array $cards): array
    {
        $nextId = 1;
        $children = [];
        foreach ($cards as $card) {
            $children[] = $this->convertedFeatureCardNode($nextId, $card);
        }

        return [
            'id' => 0,
            'data' => [
                'type' => ElementTypes::CONTAINER,
                'properties' => [],
            ],
            'children' => $children,
        ];
    }

    /**
     * @param array<string, string> $card
     * @return array<string, mixed>
     */
    private function convertedFeatureCardNode(int &$nextId, array $card): array
    {
        $nodeId = $nextId++;
        $children = [
            [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::TEXT,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'h3',
                            ],
                        ],
                        'content' => [
                            'content' => [
                                'text' => $card['title'] ?? '',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
        ];

        if (($card['copy'] ?? '') !== '') {
            $children[] = [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::TEXT,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'p',
                            ],
                        ],
                        'content' => [
                            'content' => [
                                'text' => $card['copy'],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
        }

        $children[] = [
            'id' => $nextId++,
            'data' => [
                'type' => ElementTypes::TEXT_LINK,
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => $card['label'] ?? '',
                            'url' => $card['url'] ?? '#',
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];

        if (($card['image'] ?? '') !== '') {
            $children[] = [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::IMAGE,
                    'properties' => [
                        'content' => [
                            'image' => [
                                'from' => 'url',
                                'url' => $card['image'],
                                'alt_when_from_url' => 'custom',
                                'custom_alt_when_from_url' => $card['alt'] ?? '',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
        }

        if (($card['html'] ?? '') !== '') {
            $children[] = [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::HTML_CODE,
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => $card['html'],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
        }

        return [
            'id' => $nodeId,
            'data' => [
                'type' => ElementTypes::CONTAINER,
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'tag' => 'div',
                            'classes' => [$card['class'] ?? 'feature-card'],
                        ],
                    ],
                ],
            ],
            'children' => $children,
        ];
    }

    /**
     * @param list<array{label:string,url:string}> $items
     * @return array<string, mixed>
     */
    private function convertedNavItemsElement(array $items): array
    {
        $nextId = 1;
        $children = [];
        foreach ($items as $item) {
            $liId = $nextId++;
            $children[] = [
                'id' => $liId,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'li',
                                'classes' => ['nav-item'],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'id' => $nextId++,
                    'data' => [
                        'type' => ElementTypes::TEXT_LINK,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'text' => $item['label'],
                                    'url' => $item['url'],
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ];
        }

        return [
            'id' => 0,
            'data' => [
                'type' => ElementTypes::CONTAINER,
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'tag' => 'nav',
                        ],
                    ],
                ],
            ],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function convertedDecorativeItemsElement(): array
    {
        $nextId = 1;
        $children = [];
        for ($index = 0; $index < 3; $index++) {
            $children[] = [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'div',
                                'classes' => ['decorative'],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'id' => $nextId++,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'span',
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ];
        }

        return [
            'id' => 0,
            'data' => [
                'type' => ElementTypes::CONTAINER,
                'properties' => [],
            ],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function oxygenSurfaceResultOverrides(): array
    {
        return [
            'element' => [
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'classes' => ['layout', 'hero'],
                            ],
                        ],
                    ],
                ],
                'children' => [[
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'classes' => ['nested'],
                                ],
                            ],
                        ],
                    ],
                    'children' => [
                        ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
                        ['data' => ['type' => ElementTypes::JAVASCRIPT_CODE], 'children' => []],
                        ['data' => ['type' => ElementTypes::COMPONENT], 'children' => []],
                        [
                            'data' => [
                                'type' => ElementTypes::IMAGE,
                                'properties' => [
                                    'settings' => [
                                        'advanced' => [
                                            'classes' => ['media'],
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        ['data' => ['type' => ElementTypes::HTML5_VIDEO], 'children' => []],
                    ],
                ]],
            ],
            'cssElement' => ['data' => ['type' => ElementTypes::CSS_CODE], 'children' => []],
            'headLinkElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
            'headScriptElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
            'iconScriptElements' => [
                ['data' => ['type' => ElementTypes::HTML_CODE], 'children' => []],
            ],
        ];
    }
}
