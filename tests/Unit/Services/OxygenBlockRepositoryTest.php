<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\DesignDocumentBuilder;
use OxyHtmlConverter\Services\OxygenBlockRepository;
use OxyHtmlConverter\Services\OxygenStorageAdapterFactory;
use PHPUnit\Framework\TestCase;

class OxygenBlockRepositoryTest extends TestCase
{
    public function testSupportedBlockPostTypeAndRequiredMetaKeys(): void
    {
        $repository = new OxygenBlockRepository();

        $this->assertSame('oxygen_block', $repository->postType());
        $this->assertSame([
            '_oxygen_data',
            '_breakdance_block_settings',
        ], $repository->requiredMetaKeys());
    }

    public function testValidateBlockSpecAcceptsMinimumPublishBlockShape(): void
    {
        $repository = new OxygenBlockRepository();
        $result = $repository->validateBlockSpec($this->fixturePayload('block.json'));

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame('oxygen_block', $result['postType']);
        $this->assertSame($repository->requiredMetaKeys(), $result['metaKeys']);
    }

    public function testValidateComponentInstanceTargetsResolveAgainstBlockFixture(): void
    {
        $repository = new OxygenBlockRepository();
        $component = $this->fixturePayload('component-instance.json')['componentNode'];
        $this->assertIsArray($component);

        $result = $repository->validateComponentInstanceAgainstBlockSpec(
            $component,
            $this->fixturePayload('block.json')
        );

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
    }

    public function testValidateComponentInstanceRejectsUnresolvedTargetAndMissingOverride(): void
    {
        $repository = new OxygenBlockRepository();
        $component = $this->fixturePayload('component-instance.json')['componentNode'];
        $this->assertIsArray($component);

        $blockPath = ['data', 'properties', 'content', 'content', 'block'];
        $block =& $component;
        foreach ($blockPath as $key) {
            $block =& $block[$key];
        }

        $block['targets'][0]['nodeId'] = 999;
        $block['targets'][1]['controlPath'] = 'content.content.url';
        unset($block['properties']['cta_button_url']);

        $result = $repository->validateComponentInstanceAgainstBlockSpec(
            $component,
            $this->fixturePayload('block.json')
        );

        $this->assertFalse($result['valid']);
        $errors = implode(' ', $result['errors']);
        $this->assertStringContainsString('nodeId 999 must reference a node inside the oxygen_block tree', $errors);
        $this->assertStringContainsString('controlPath must match the editable property controlPath for cta_button_label', $errors);
        $this->assertStringContainsString('propertyKey cta_button_url must have an override value', $errors);
    }

    public function testValidateComponentInstanceRejectsSharedControlPathTypo(): void
    {
        $repository = new OxygenBlockRepository();
        $component = $this->fixturePayload('component-instance.json')['componentNode'];
        $spec = $this->fixturePayload('block.json');
        $this->assertIsArray($component);

        $component['data']['properties']['content']['content']['block']['targets'][0]['controlPath'] = 'content.content.missing_text';

        $tree = json_decode((string) $spec['_oxygen_data']['tree_json_string'], true);
        $this->assertIsArray($tree);
        $tree['root']['children'][0]['children'][0]['data']['properties']['meta']['component']['editableProperties'][0]['controlPath'] = 'content.content.missing_text';
        $spec['_oxygen_data']['tree_json_string'] = wp_json_encode($tree);

        $result = $repository->validateComponentInstanceAgainstBlockSpec($component, $spec);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(
            'controlPath content.content.missing_text must resolve inside node 2 properties',
            implode(' ', $result['errors'])
        );
    }

    public function testValidateBlockSpecRejectsWrongPostTypeDraftStatusAndInvalidSettings(): void
    {
        $spec = $this->blockSpec();
        $spec['post_type'] = 'oxygen_template';
        $spec['post_status'] = 'draft';
        $spec['_breakdance_block_settings'] = [
            'preview' => 'bad',
        ];

        $result = (new OxygenBlockRepository())->validateBlockSpec($spec);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('post_type must be oxygen_block', implode(' ', $result['errors']));
        $this->assertStringContainsString('oxygen_block post_status must be publish', implode(' ', $result['errors']));
        $this->assertStringContainsString('_breakdance_block_settings.preview must be an object', implode(' ', $result['errors']));
    }

    public function testValidateBlockSpecRequiresOxygenDataEnvelope(): void
    {
        $result = (new OxygenBlockRepository())->validateBlockSpec([
            'post_type' => 'oxygen_block',
            'post_status' => 'publish',
            '_oxygen_data' => ['tree_json_string' => '{"root":{"id":"bad"}}'],
            '_breakdance_block_settings' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $result['errors']));
    }

    public function testBuildBlockSpecFromCandidateCreatesPublishBlockContract(): void
    {
        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );

        $spec = $repository->buildBlockSpecFromCandidate([
            'suggestedName' => 'feature-card',
            'signature' => 'div[h3,p,a]',
            'count' => 3,
            'confidence' => 0.9,
            'documentTree' => $this->fixtureTree(),
        ]);

        $this->assertSame('oxygen_block', $spec['post_type']);
        $this->assertSame('publish', $spec['post_status']);
        $this->assertSame('Feature Card', $spec['post_title']);
        $this->assertSame('feature-card', $spec['post_name']);
        $this->assertIsArray($spec['_breakdance_block_settings']);

        $validation = $repository->validateBlockSpec($spec);
        $this->assertTrue($validation['valid'], implode(' ', $validation['errors']));
    }

    public function testBuildBlockSpecAddsEditablePropertiesAndResolvableTargets(): void
    {
        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );

        $spec = $repository->buildBlockSpecFromCandidate([
            'suggestedName' => 'cta',
            'signature' => 'section[h2,a,img,svg,a]',
            'count' => 3,
            'confidence' => 1.0,
            'documentTree' => $this->editableComponentTree(),
        ]);

        $componentProperties = $spec['sourceCandidate']['componentProperties'];
        $this->assertSame('Default title', $componentProperties['properties']['cta_text']);
        $this->assertSame('Read more', $componentProperties['properties']['cta_link_label']);
        $this->assertSame('https://example.test/read', $componentProperties['properties']['cta_link_url']);
        $this->assertSame('https://example.test/cta.jpg', $componentProperties['properties']['cta_image_url']);
        $this->assertSame('CTA image', $componentProperties['properties']['cta_image_alt']);
        $this->assertSame(['svgCode' => '<svg viewBox="0 0 24 24"></svg>'], $componentProperties['properties']['cta_icon']);
        $this->assertSame('https://example.test/wrapped-card', $componentProperties['properties']['cta_link_url_2']);

        $tree = json_decode((string) $spec['_oxygen_data']['tree_json_string'], true);
        $this->assertIsArray($tree);
        $nodes = $this->indexNodesById($tree['root']);
        $this->assertSame('cta_text', $nodes[2]['data']['properties']['meta']['component']['editableProperties'][0]['propertyKey']);
        $this->assertSame('content.content.url', $nodes[3]['data']['properties']['meta']['component']['editableProperties'][1]['controlPath']);
        $this->assertSame('cta_image_alt', $nodes[4]['data']['properties']['meta']['component']['editableProperties'][1]['propertyKey']);
        $this->assertSame('content.content.icon', $nodes[5]['data']['properties']['meta']['component']['editableProperties'][0]['controlPath']);
        $this->assertSame('cta_link_url_2', $nodes[6]['data']['properties']['meta']['component']['editableProperties'][0]['propertyKey']);

        $componentNode = [
            'id' => 9,
            'data' => [
                'type' => ElementTypes::COMPONENT,
                'properties' => [
                    'content' => [
                        'content' => [
                            'block' => [
                                'componentId' => 123,
                                'targets' => $componentProperties['targets'],
                                'properties' => $componentProperties['properties'],
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
        $validation = $repository->validateComponentInstanceAgainstBlockSpec($componentNode, $spec);
        $this->assertTrue($validation['valid'], implode(' ', $validation['errors']));
    }

    public function testBuildBlockSpecRecordsComponentCssOwnershipMetadata(): void
    {
        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );

        $spec = $repository->buildBlockSpecFromCandidate([
            'suggestedName' => 'feature-card',
            'signature' => 'div[h3,style]',
            'count' => 3,
            'confidence' => 1.0,
            'documentTree' => $this->componentCssTree(),
        ]);

        $componentCss = $spec['sourceCandidate']['componentCss'];
        $this->assertCount(1, $componentCss);
        $this->assertSame('component', $componentCss[0]['owner']);
        $this->assertSame('component_block', $componentCss[0]['destination']);
        $this->assertSame('component_css', $componentCss[0]['type']);
        $this->assertSame('feature-card', $componentCss[0]['componentName']);
        $this->assertSame('div[h3,style]', $componentCss[0]['signature']);
        $this->assertSame('.feature-card { padding: 32px; }', $componentCss[0]['css']);
        $this->assertSame(3, $componentCss[0]['nodeId']);
        $this->assertNotSame('', $componentCss[0]['hash']);

        $this->assertSame(
            $componentCss,
            $spec['_breakdance_block_settings']['oxyHtmlConverter']['componentCss']
        );
    }

    public function testPersistComponentCandidatesCreatesUpdatesAndSkipsWithReasons(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];

        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );

        $tree = $this->fixtureTree();
        $result = $repository->persistComponentCandidates([
            [
                'suggestedName' => 'feature-card',
                'signature' => 'div[h3,p,a]',
                'count' => 3,
                'confidence' => 0.9,
                'documentTree' => $tree,
            ],
            [
                'suggestedName' => 'single-card',
                'signature' => 'div[h3,p]',
                'count' => 1,
                'confidence' => 0.3,
                'documentTree' => $tree,
            ],
            [
                'suggestedName' => 'missing-tree',
                'signature' => 'section[h2,p]',
                'count' => 3,
                'confidence' => 0.9,
            ],
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors']));
        $this->assertSame(3, $result['candidates']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame('oxygen_block', $result['postType']);
        $this->assertSame(['_oxygen_data', '_breakdance_block_settings'], $result['metaKeys']);

        $postId = (int) $result['createdBlocks'][0]['postId'];
        $this->assertGreaterThan(0, $postId);
        $this->assertSame('oxygen_block', $GLOBALS['__wp_posts'][$postId]->post_type);
        $this->assertNotSame('', get_post_meta($postId, '_oxygen_data', true));
        $this->assertNotSame('', get_post_meta($postId, '_breakdance_block_settings', true));
        $this->assertContains('below_occurrence_threshold', $result['skippedCandidates'][0]['reasons']);
        $this->assertContains('missing_component_tree', $result['skippedCandidates'][1]['reasons']);

        $update = $repository->persistComponentCandidates([[
            'suggestedName' => 'feature-card',
            'signature' => 'div[h3,p,a]',
            'count' => 3,
            'confidence' => 0.9,
            'postId' => $postId,
            'documentTree' => $tree,
        ]]);

        $this->assertTrue($update['success'], implode(' ', $update['errors']));
        $this->assertSame(0, $update['created']);
        $this->assertSame(1, $update['updated']);
        $this->assertTrue($update['updatedBlocks'][0]['rollback']['post']);
        $this->assertTrue($update['updatedBlocks'][0]['rollback']['oxygenData']);
        $this->assertTrue($update['updatedBlocks'][0]['rollback']['blockSettings']);
    }

    public function testPersistComponentCandidatesReturnsComponentCssForHostMerge(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;

        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );

        $result = $repository->persistComponentCandidates([[
            'suggestedName' => 'feature-card',
            'signature' => 'div[h3,style]',
            'count' => 3,
            'confidence' => 1.0,
            'documentTree' => $this->componentCssTree(),
        ]]);

        $this->assertTrue($result['success'], implode(' ', $result['errors']));
        $this->assertSame(1, $result['created']);
        $this->assertSame('.feature-card { padding: 32px; }', $result['createdBlocks'][0]['componentCss'][0]['css']);
        $this->assertSame('component', $result['createdBlocks'][0]['componentCss'][0]['owner']);
    }

    public function testPersistComponentCandidatesAcceptsRealDesignDocumentDetectedTrees(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;

        $html = <<<'HTML'
<section>
    <div class="feature-card"><h3>One</h3><p>Alpha</p><a href="/one">Open</a></div>
    <div class="feature-card"><h3>Two</h3><p>Beta</p><a href="/two">Open</a></div>
    <div class="feature-card"><h3>Three</h3><p>Gamma</p><a href="/three">Open</a></div>
</section>
HTML;
        $designDocument = (new DesignDocumentBuilder())->build($html, [
            'success' => true,
            'element' => $this->featureCardsElementForDesignDocument(),
            'stats' => [
                'elements' => 12,
                'warnings' => [],
                'errors' => [],
                'info' => [],
            ],
        ]);

        $this->assertNotEmpty($designDocument['componentCandidates']);
        $this->assertIsArray($designDocument['componentCandidates'][0]['documentTree']);

        $repository = new OxygenBlockRepository(
            (new OxygenStorageAdapterFactory(null, $this->fixtureDir()))->create()
        );
        $result = $repository->persistComponentCandidates($designDocument['componentCandidates']);

        $this->assertTrue($result['success'], implode(' ', $result['errors']));
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $postId = (int) $result['createdBlocks'][0]['postId'];
        $this->assertSame('oxygen_block', $GLOBALS['__wp_posts'][$postId]->post_type);
        $this->assertNotSame('', get_post_meta($postId, '_oxygen_data', true));
        $this->assertNotSame('', get_post_meta($postId, '_breakdance_block_settings', true));
    }

    /**
     * @return array<string, mixed>
     */
    private function blockSpec(): array
    {
        $tree = [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [],
            ],
            '_nextNodeId' => 1,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];

        return [
            'post_type' => 'oxygen_block',
            'post_status' => 'publish',
            '_oxygen_data' => [
                'tree_json_string' => wp_json_encode($tree),
            ],
            '_breakdance_block_settings' => [
                'preview' => [
                    'acfFlexibleField' => '',
                    'acfFlexibleFieldRow' => '',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixturePayload(string $fileName): array
    {
        $file = $this->fixtureDir() . DIRECTORY_SEPARATOR . $fileName;

        $content = file_get_contents($file);
        $this->assertIsString($content);

        $fixture = json_decode($content, true);
        $this->assertIsArray($fixture);
        $this->assertIsArray($fixture['payload'] ?? null);

        return $fixture['payload'];
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . 'tests'
            . DIRECTORY_SEPARATOR
            . 'fixtures'
            . DIRECTORY_SEPARATOR
            . 'oxygen6-contracts';
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureTree(): array
    {
        $payload = $this->fixturePayload('block.json');
        $treeJson = $payload['_oxygen_data']['tree_json_string'] ?? null;
        $this->assertIsString($treeJson);
        $tree = json_decode($treeJson, true);
        $this->assertIsArray($tree);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function editableComponentTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'section',
                                ],
                            ],
                        ],
                    ],
                    'children' => [
                        [
                            'id' => 2,
                            'data' => [
                                'type' => ElementTypes::TEXT,
                                'properties' => [
                                    'content' => [
                                        'content' => [
                                            'text' => 'Default title',
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'id' => 3,
                            'data' => [
                                'type' => ElementTypes::TEXT_LINK,
                                'properties' => [
                                    'content' => [
                                        'content' => [
                                            'text' => 'Read more',
                                            'url' => 'https://example.test/read',
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'id' => 4,
                            'data' => [
                                'type' => ElementTypes::IMAGE,
                                'properties' => [
                                    'content' => [
                                        'image' => [
                                            'from' => 'url',
                                            'url' => 'https://example.test/cta.jpg',
                                            'alt_when_from_url' => 'custom',
                                            'custom_alt_when_from_url' => 'CTA image',
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'id' => 5,
                            'data' => [
                                'type' => ElementTypes::SVG_ICON,
                                'properties' => [
                                    'content' => [
                                        'content' => [
                                            'icon' => [
                                                'svgCode' => '<svg viewBox="0 0 24 24"></svg>',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'id' => 6,
                            'data' => [
                                'type' => ElementTypes::CONTAINER_LINK,
                                'properties' => [
                                    'content' => [
                                        'content' => [
                                            'url' => 'https://example.test/wrapped-card',
                                        ],
                                    ],
                                ],
                            ],
                            'children' => [[
                                'id' => 7,
                                'data' => [
                                    'type' => ElementTypes::IMAGE,
                                    'properties' => [
                                        'content' => [
                                            'image' => [
                                                'from' => 'url',
                                                'url' => 'https://example.test/wrapped-card.jpg',
                                            ],
                                        ],
                                    ],
                                ],
                                'children' => [],
                            ]],
                        ],
                    ],
                ]],
            ],
            '_nextNodeId' => 8,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentCssTree(): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [[
                    'id' => 1,
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [
                            'settings' => [
                                'advanced' => [
                                    'tag' => 'div',
                                    'classes' => ['feature-card'],
                                ],
                            ],
                        ],
                    ],
                    'children' => [[
                        'id' => 2,
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
                                        'text' => 'Feature',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ], [
                        'id' => 3,
                        'data' => [
                            'type' => ElementTypes::CSS_CODE,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'css_code' => '.feature-card { padding: 32px; }',
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ]],
                ]],
            ],
            '_nextNodeId' => 4,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureCardsElementForDesignDocument(): array
    {
        $nextId = 1;
        $cards = [];
        foreach ([
            ['title' => 'One', 'copy' => 'Alpha', 'url' => '/one'],
            ['title' => 'Two', 'copy' => 'Beta', 'url' => '/two'],
            ['title' => 'Three', 'copy' => 'Gamma', 'url' => '/three'],
        ] as $card) {
            $cards[] = [
                'id' => $nextId++,
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'tag' => 'div',
                                'classes' => ['feature-card'],
                            ],
                        ],
                    ],
                ],
                'children' => [
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
                                        'text' => $card['title'],
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                    [
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
                    ],
                    [
                        'id' => $nextId++,
                        'data' => [
                            'type' => ElementTypes::TEXT_LINK,
                            'properties' => [
                                'content' => [
                                    'content' => [
                                        'text' => 'Open',
                                        'url' => $card['url'],
                                    ],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                ],
            ];
        }

        return [
            'id' => 0,
            'data' => [
                'type' => ElementTypes::CONTAINER,
                'properties' => [],
            ],
            'children' => $cards,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function indexNodesById(array $node): array
    {
        $nodes = [];
        if (is_int($node['id'] ?? null)) {
            $nodes[(int) $node['id']] = $node;
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $nodes += $this->indexNodesById($child);
            }
        }

        return $nodes;
    }
}
