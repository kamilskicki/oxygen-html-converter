<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use Mockery;
use OxyHtmlConverter\Services\OxygenSelectorImporter;
use OxyHtmlConverter\Services\OxygenSelectorRepository;
use OxyHtmlConverter\Services\OxygenStorageAdapter;
use OxyHtmlConverter\Tests\TestCase;

class OxygenSelectorImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
    }

    public function testBuildPayloadMarksOxygenSelectorPersistence(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.card',
            'declarations' => [
                'color' => '#123456',
            ],
        ]]);
        $importer->syncElementClasses(['card'], $element);

        $payload = $importer->buildPayload();

        $this->assertSame([
            'requiresTreeJsonString' => true,
            'requiresOxygenSelectorPersistence' => true,
            'requiresBreakdanceClassesJsonString' => false,
            'persistsBreakdanceClassesJsonString' => true,
            'oxygenSelectorsOptionName' => 'oxygen_oxy_selectors_json_string',
            'oxygenSelectorCollectionsOptionName' => 'oxygen_oxy_selectors_collections_json_string',
            'breakdanceClassesOptionName' => 'breakdance_classes_json_string',
        ], $payload['persistence']);
        $this->assertSame(['Imported HTML'], $payload['collections']);
        $this->assertSame('card', $payload['selectors'][0]['name']);
        $this->assertSame($payload['selectors'][0]['id'], $element['data']['properties']['meta']['classes'][0]);
    }

    public function testBuildPayloadUsesSemanticClassAliasesForRepeatedStylePatterns(): void
    {
        $importer = new OxygenSelectorImporter();
        $importer->setClassAliases([
            'pricing-card' => 'ohc-card',
            'feature-card' => 'ohc-card',
        ]);
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['feature-card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([
            [
                'selector' => '.pricing-card',
                'declarations' => [
                    'color' => '#123456',
                    'padding' => '24px',
                ],
            ],
            [
                'selector' => '.feature-card',
                'declarations' => [
                    'padding' => '24px',
                    'color' => '#123456',
                ],
            ],
        ]);
        $importer->syncElementClasses(['feature-card'], $element);
        $payload = $importer->buildPayload();

        $this->assertCount(1, $payload['selectors']);
        $this->assertSame('ohc-card', $payload['selectors'][0]['name']);
        $this->assertSame('.ohc-card', $payload['selectors'][0]['selector']);
        $this->assertSame($payload['selectors'][0]['id'], $element['data']['properties']['meta']['classes'][0]);
    }

    public function testBuildPayloadUsesOxygenReadableNativeControlPaths(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.card',
            'declarations' => [
                'display' => 'flex',
                'justify-content' => 'center',
                'align-items' => 'flex-start',
                'gap' => '24px',
                'row-gap' => '8px',
                'column-gap' => '12px',
                'margin' => '5px 6px 7px 8px',
                'padding' => '10px 20px',
                'font-style' => 'italic',
                'text-decoration' => 'underline',
            ],
        ]]);
        $importer->syncElementClasses(['card'], $element);

        $base = $importer->buildPayload()['selectors'][0]['properties']['breakpoint_base'];

        $this->assertSame('flex', $base['layout']['display']);
        $this->assertSame('center', $base['layout']['flex_align']['primary_axis']);
        $this->assertSame('flex-start', $base['layout']['flex_align']['cross_axis']);
        $this->assertSame('8px', $base['layout']['gap']['row']['style']);
        $this->assertSame('12px', $base['layout']['gap']['column']['style']);
        $this->assertSame('5px', $base['spacing']['spacing']['margin']['top']['style']);
        $this->assertSame('6px', $base['spacing']['spacing']['margin']['right']['style']);
        $this->assertSame('7px', $base['spacing']['spacing']['margin']['bottom']['style']);
        $this->assertSame('8px', $base['spacing']['spacing']['margin']['left']['style']);
        $this->assertSame('10px', $base['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('20px', $base['spacing']['spacing']['padding']['right']['style']);
        $this->assertSame('10px', $base['spacing']['spacing']['padding']['bottom']['style']);
        $this->assertSame('20px', $base['spacing']['spacing']['padding']['left']['style']);
        $this->assertSame('italic', $base['typography']['style']['font_style']);
        $this->assertSame('underline', $base['typography']['style']['text_decoration']);
        $this->assertArrayNotHasKey('justify_content', $base['layout']);
        $this->assertArrayNotHasKey('align_items', $base['layout']);
        $this->assertArrayNotHasKey('row_gap', $base['layout']);
        $this->assertArrayNotHasKey('column_gap', $base['layout']);
        $this->assertArrayNotHasKey('font_style', $base['typography']);
        $this->assertArrayNotHasKey('margin', $base['spacing']);
        $this->assertArrayNotHasKey('padding', $base['spacing']);
    }

    public function testBuildPayloadRoutesUnsupportedSelectorCssToCustomCssFallback(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['fallback-card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.fallback-card',
            'declarations' => [
                'display' => 'flex',
                'scroll-margin-top' => '80px',
                'align-content' => 'space-between',
            ],
        ]]);
        $importer->syncElementClasses(['fallback-card'], $element);

        $base = $importer->buildPayload()['selectors'][0]['properties']['breakpoint_base'];

        $this->assertSame('flex', $base['layout']['display']);
        $this->assertStringContainsString(':selector {', $base['custom_css']['custom_css']);
        $this->assertStringContainsString('scroll-margin-top: 80px;', $base['custom_css']['custom_css']);
        $this->assertStringContainsString('align-content: space-between;', $base['custom_css']['custom_css']);
        $this->assertArrayNotHasKey('scroll-margin-top', $base);
        $this->assertArrayNotHasKey('content_axis', $base['layout']['flex_align'] ?? []);
    }

    public function testBuildPayloadBindsSupportedTokensToSelectorVariableReferences(): void
    {
        $references = (new \OxyHtmlConverter\Services\OxygenVariableRepository())->buildTokenReferencesFromPayload([
            'designDocument' => [
                'tokens' => [
                    'colors' => [[
                        'value' => '#731B19',
                        'uses' => 1,
                        'suggestedName' => 'color-primary',
                    ]],
                    'spacing' => [[
                        'value' => '24px',
                        'uses' => 1,
                        'suggestedName' => 'space-card',
                    ]],
                    'fonts' => [[
                        'value' => 'Inter',
                        'uses' => 1,
                        'suggestedName' => 'font-body',
                    ]],
                    'images' => [[
                        'value' => 'https://example.test/assets/hero.jpg',
                        'uses' => 1,
                        'suggestedName' => 'image-hero',
                    ]],
                ],
            ],
        ]);

        $referenceByGroup = [];
        foreach ($references['items'] as $reference) {
            $referenceByGroup[$reference['group']] = $reference;
        }

        $importer = new OxygenSelectorImporter();
        $importer->setTokenReferences($references['items']);
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.card',
            'declarations' => [
                'color' => '#731B19',
                'padding' => '24px',
                'font-family' => 'Inter',
                'background-image' => 'url("https://example.test/assets/hero.jpg")',
            ],
        ]]);
        $importer->syncElementClasses(['card'], $element);

        $base = $importer->buildPayload()['selectors'][0]['properties']['breakpoint_base'];

        $this->assertSame($referenceByGroup['colors']['selectorReference'], $base['typography']['color']);
        $this->assertSame($referenceByGroup['spacing']['selectorReference'], $base['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('custom', $base['spacing']['spacing']['padding']['top']['unit']);
        $this->assertNull($base['spacing']['spacing']['padding']['top']['number']);
        $this->assertSame($referenceByGroup['fonts']['selectorReference'], $base['typography']['font_family']);
        $this->assertSame($referenceByGroup['images']['selectorReference'], $base['background']['backgrounds'][0]['image']['url']);
    }

    public function testBuildPayloadConvertsContractPathsWithoutMeasurementOverreach(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['media-card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.media-card',
            'declarations' => [
                'position' => 'relative',
                'top' => '0',
                'width' => '100%',
                'object-fit' => 'cover',
                'mix-blend-mode' => 'multiply',
                'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
                'border' => '2px solid #ff0000',
                'outline' => '1px solid #000',
                'background-image' => 'linear-gradient(red, blue)',
            ],
        ]]);
        $importer->syncElementClasses(['media-card'], $element);

        $base = $importer->buildPayload()['selectors'][0]['properties']['breakpoint_base'];

        $this->assertSame('relative', $base['position']['position']);
        $this->assertSame('0px', $base['position']['top']['style']);
        $this->assertSame('100%', $base['size']['width']['style']);
        $this->assertSame('cover', $base['size']['object_fit']);
        $this->assertSame('multiply', $base['effects']['blend_mode']);
        $this->assertSame('3', $base['layout']['grid']['simple_grid_template_columns']);
        $this->assertSame('2px', $base['borders']['borders']['top']['width']['style']);
        $this->assertSame('solid', $base['borders']['borders']['top']['style']);
        $this->assertSame('#FF0000FF', $base['borders']['borders']['top']['color']);
        $this->assertSame('1px', $base['effects']['outline_width']['style']);
        $this->assertSame('solid', $base['effects']['outline_style']);
        $this->assertSame('#000000FF', $base['effects']['outline_color']);
        $this->assertSame('gradient', $base['background']['backgrounds'][0]['type']);
        $this->assertSame('linear-gradient(red, blue)', $base['background']['backgrounds'][0]['gradient']['value']);
    }

    public function testBuildPayloadImportsResponsiveStateAndNestedSelectorChildren(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([
            [
                'selector' => '.card',
                'declarations' => [
                    'padding' => '32px',
                    'display' => 'flex',
                ],
            ],
            [
                'selector' => '.card',
                'media' => '(max-width: 1023px)',
                'declarations' => [
                    'padding' => '24px',
                ],
            ],
            [
                'selector' => '.card',
                'media' => '(max-width: 767px)',
                'declarations' => [
                    'padding' => '12px',
                ],
            ],
            [
                'selector' => '.card',
                'media' => '(orientation: landscape)',
                'declarations' => [
                    'margin' => '99px',
                ],
            ],
            [
                'selector' => '.card',
                'media' => '(max-width: 767px) and (orientation: landscape)',
                'declarations' => [
                    'margin' => '88px',
                ],
            ],
            [
                'selector' => '.card:hover',
                'declarations' => [
                    'color' => '#2563eb',
                ],
            ],
            [
                'selector' => '.card:focus',
                'declarations' => [
                    'outline' => '2px solid #000',
                ],
            ],
            [
                'selector' => '.card .title',
                'declarations' => [
                    'font-weight' => '700',
                ],
            ],
            [
                'selector' => '.card > .eyebrow',
                'declarations' => [
                    'font-style' => 'italic',
                ],
            ],
            [
                'selector' => '.card + .sibling',
                'declarations' => [
                    'color' => 'red',
                ],
            ],
        ]);
        $importer->syncElementClasses(['card'], $element);

        $selector = $importer->buildPayload()['selectors'][0];
        $properties = $selector['properties'];

        $this->assertSame('32px', $properties['breakpoint_base']['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('24px', $properties['breakpoint_tablet_portrait']['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('12px', $properties['breakpoint_phone_landscape']['spacing']['spacing']['padding']['top']['style']);
        $this->assertArrayNotHasKey('(orientation: landscape)', $properties);
        $this->assertArrayNotHasKey('(max-width: 767px) and (orientation: landscape)', $properties);
        $this->assertArrayNotHasKey('margin', $properties['breakpoint_base']['spacing']['spacing'] ?? []);
        $this->assertArrayNotHasKey('margin', $properties['breakpoint_phone_landscape']['spacing']['spacing'] ?? []);

        $childrenByName = [];
        foreach ($selector['children'] as $child) {
            $childrenByName[$child['name']] = $child;
        }

        $this->assertArrayHasKey('&:hover', $childrenByName);
        $this->assertTrue($childrenByName['&:hover']['pseudo']);
        $this->assertFalse($childrenByName['&:hover']['locked']);
        $this->assertSame('#2563EBFF', $childrenByName['&:hover']['properties']['breakpoint_base']['typography']['color']);

        $this->assertArrayHasKey('&:focus', $childrenByName);
        $this->assertTrue($childrenByName['&:focus']['pseudo']);
        $this->assertFalse($childrenByName['&:focus']['locked']);
        $this->assertSame('2px', $childrenByName['&:focus']['properties']['breakpoint_base']['effects']['outline_width']['style']);

        $this->assertArrayHasKey('& .title', $childrenByName);
        $this->assertArrayNotHasKey('pseudo', $childrenByName['& .title']);
        $this->assertFalse($childrenByName['& .title']['locked']);
        $this->assertSame(700, $childrenByName['& .title']['properties']['breakpoint_base']['typography']['font_weight']);

        $this->assertArrayHasKey('& > .eyebrow', $childrenByName);
        $this->assertSame('italic', $childrenByName['& > .eyebrow']['properties']['breakpoint_base']['typography']['style']['font_style']);
        $this->assertArrayNotHasKey('& + .sibling', $childrenByName);
    }

    public function testBuildPayloadImportsResponsivePseudoChildProperties(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['card'],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.card:hover',
            'media' => '(max-width: 767px)',
            'declarations' => [
                'color' => '#2563eb',
            ],
        ]]);
        $importer->syncElementClasses(['card'], $element);

        $child = $importer->buildPayload()['selectors'][0]['children'][0];

        $this->assertSame('&:hover', $child['name']);
        $this->assertTrue($child['pseudo']);
        $this->assertSame('#2563EBFF', $child['properties']['breakpoint_phone_landscape']['typography']['color']);
        $this->assertArrayNotHasKey('@media (max-width: 767px)', $child['properties']);
    }

    public function testSyncElementClassesRemapsConditionalClassApplicationsToSelectorIds(): void
    {
        $importer = new OxygenSelectorImporter();
        $element = [
            'data' => [
                'properties' => [
                    'settings' => [
                        'advanced' => [
                            'classes' => ['featured-card'],
                        ],
                    ],
                    'meta' => [
                        'classes_conditions' => [
                            'featured-card' => [
                                'ruleGroups' => [[[
                                    'operand' => 'is',
                                    'ruleCategorySlug' => 'post',
                                    'ruleSlug' => 'post_type',
                                    'value' => 'post',
                                ]]],
                                'builderPreview' => true,
                            ],
                            'unrelated-card' => [
                                'ruleGroups' => [[[
                                    'operand' => 'is',
                                    'ruleCategorySlug' => 'post',
                                    'ruleSlug' => 'post_type',
                                    'value' => 'page',
                                ]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $importer->setCssRules([[
            'selector' => '.featured-card',
            'declarations' => [
                'color' => 'red',
            ],
        ]]);
        $importer->syncElementClasses(['featured-card'], $element);

        $selectorId = $element['data']['properties']['meta']['classes'][0];
        $conditions = $element['data']['properties']['meta']['classes_conditions'];

        $this->assertArrayHasKey($selectorId, $conditions);
        $this->assertSame('post_type', $conditions[$selectorId]['ruleGroups'][0][0]['ruleSlug']);
        $this->assertTrue($conditions[$selectorId]['builderPreview']);
        $this->assertArrayNotHasKey('featured-card', $conditions);
        $this->assertArrayNotHasKey('unrelated-card', $conditions);
    }

    public function testRepositoryDelegatesSelectorWriteToStorageAdapter(): void
    {
        $adapter = Mockery::mock(OxygenStorageAdapter::class);
        $adapter->shouldReceive('writeSelectors')
            ->once()
            ->withArgs(function (array $selectors, array $collections): bool {
                $this->assertSame(['selector-1'], array_column($selectors, 'id'));
                $this->assertSame(['Imported HTML'], $collections);
                $this->assertSame('card', $selectors[0]['name']);
                $this->assertSame('class', $selectors[0]['type']);
                $this->assertSame([[
                    'id' => 'selector-1-hover',
                    'name' => '&:hover',
                    'locked' => false,
                    'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                    'pseudo' => true,
                ]], $selectors[0]['children']);

                return true;
            })
            ->andReturn([
                'success' => true,
                'cacheRegenerated' => true,
            ]);

        $result = (new OxygenSelectorRepository($adapter))->savePayload([
            'selectors' => [[
                'id' => 'selector-1',
                'name' => 'card',
                'type' => 'class',
                'collection' => 'Imported HTML',
                'locked' => false,
                'children' => [[
                    'id' => 'selector-1-hover',
                    'name' => '&:hover',
                    'pseudo' => true,
                    'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                    'children' => [],
                ]],
                'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                'selector' => '.card',
                'persistence' => ['importerOnly' => true],
            ]],
            'collections' => ['Imported HTML'],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['total']);
    }

    public function testRepositoryRejectsUnknownNativeSelectorPropertyPathsBeforePersisting(): void
    {
        $adapter = Mockery::mock(OxygenStorageAdapter::class);
        $adapter->shouldNotReceive('writeSelectors');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Selector payload failed contract validation');

        (new OxygenSelectorRepository($adapter))->savePayload([
            'selectors' => [[
                'id' => 'selector-1',
                'name' => 'card',
                'type' => 'class',
                'collection' => 'Imported HTML',
                'locked' => false,
                'children' => [],
                'properties' => [
                    'breakpoint_base' => [
                        'layout' => [
                            'unsupported_justify' => 'center',
                        ],
                    ],
                ],
            ]],
            'collections' => ['Imported HTML'],
        ]);
    }

    public function testRepositoryRejectsInvalidMergedSelectorPayloadBeforePersisting(): void
    {
        $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'] = wp_json_encode([[
            'id' => 'stale-selector',
            'name' => 'stale-card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'locked' => false,
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                        'layout' => [
                            'unsupported_justify' => 'center',
                        ],
                ],
            ],
        ]]);
        $GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string'] = wp_json_encode(['Imported HTML']);

        $adapter = Mockery::mock(OxygenStorageAdapter::class);
        $adapter->shouldNotReceive('writeSelectors');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Selector payload failed contract validation');

        (new OxygenSelectorRepository($adapter))->savePayload([
            'selectors' => [[
                'id' => 'selector-1',
                'name' => 'card',
                'type' => 'class',
                'collection' => 'Imported HTML',
                'locked' => false,
                'children' => [],
                'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
            ]],
            'collections' => ['Imported HTML'],
        ]);
    }

    public function testRepositoryPersistsSelectorOptionEnvelopesThroughDefaultAdapter(): void
    {
        $result = (new OxygenSelectorRepository())->savePayload([
            'selectors' => [[
                'id' => 'selector-1',
                'name' => 'card',
                'type' => 'class',
                'collection' => 'Imported HTML',
                'locked' => false,
                'children' => [],
                'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
            ]],
            'collections' => ['Imported HTML'],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertArrayHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
        $this->assertArrayHasKey('oxygen_oxy_selectors_collections_json_string', $GLOBALS['__wp_options']);
        $this->assertArrayHasKey('breakdance_classes_json_string', $GLOBALS['__wp_options']);

        $selectors = json_decode((string) $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'], true);
        $collections = json_decode((string) $GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string'], true);
        $breakdanceClasses = json_decode((string) $GLOBALS['__wp_options']['breakdance_classes_json_string'], true);

        $this->assertSame([[
            'id' => 'selector-1',
            'name' => 'card',
            'type' => 'class',
            'collection' => 'Imported HTML',
            'locked' => false,
            'children' => [],
            'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
        ]], $selectors);
        $this->assertSame(['Imported HTML'], $collections);
        $this->assertSame([[
            'name' => '.card',
            'type' => 'class',
            'properties' => [],
        ]], $breakdanceClasses);
    }
}
