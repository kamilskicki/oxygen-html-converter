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
        $this->assertSame('24px', $base['layout']['gap']['row']['style']);
        $this->assertSame('24px', $base['layout']['gap']['column']['style']);
        $this->assertSame('10px', $base['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('20px', $base['spacing']['spacing']['padding']['right']['style']);
        $this->assertSame('italic', $base['typography']['style']['font_style']);
        $this->assertSame('underline', $base['typography']['style']['text_decoration']);
        $this->assertArrayNotHasKey('justify_content', $base['layout']);
        $this->assertArrayNotHasKey('align_items', $base['layout']);
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
                'children' => [],
                'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                'selector' => '.card',
                'persistence' => ['importerOnly' => true],
            ]],
            'collections' => ['Imported HTML'],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['total']);
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
