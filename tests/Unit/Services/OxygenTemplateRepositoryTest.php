<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\OxygenTemplateRepository;
use PHPUnit\Framework\TestCase;

class OxygenTemplateRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];
    }

    public function testSupportedTemplatePostTypesAndRequiredMetaKeys(): void
    {
        $repository = new OxygenTemplateRepository();

        $this->assertSame([
            'oxygen_template',
            'oxygen_header',
            'oxygen_footer',
            'oxygen_part',
        ], $repository->supportedPostTypes());
        $this->assertSame([
            '_oxygen_data',
            '_oxygen_template_settings',
        ], $repository->requiredMetaKeys());
    }

    public function testValidateTemplateSpecAcceptsEveryTemplatePostType(): void
    {
        $repository = new OxygenTemplateRepository();

        foreach ($repository->supportedPostTypes() as $postType) {
            $result = $repository->validateTemplateSpec($this->templateSpec($postType));

            $this->assertTrue($result['valid'], $postType . ': ' . implode(' ', $result['errors']));
            $this->assertSame($postType, $result['postType']);
            $this->assertSame($repository->requiredMetaKeys(), $result['metaKeys']);
        }
    }

    public function testTemplateSpecAcceptsAndPersistsNativeComponentInstanceTree(): void
    {
        $repository = new OxygenTemplateRepository();
        $spec = $this->templateSpec('oxygen_template');
        $spec['_oxygen_data']['tree_json_string'] = wp_json_encode($this->componentInstanceTree(42));

        $validation = $repository->validateTemplateSpec($spec);
        $this->assertTrue($validation['valid'], implode(' ', $validation['errors']));

        $result = $repository->createOrUpdateTemplate($spec);
        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));

        $oxygenData = $this->decodeStoredMetaObject((string) get_post_meta((int) $result['postId'], '_oxygen_data', true));
        $tree = json_decode((string) $oxygenData['tree_json_string'], true);
        $this->assertSame(ElementTypes::COMPONENT, $tree['root']['children'][0]['data']['type']);
        $this->assertSame(42, $tree['root']['children'][0]['data']['properties']['content']['content']['block']['componentId']);
    }

    public function testValidateTemplateSpecRejectsUnsupportedPostTypeAndInvalidSettings(): void
    {
        $spec = $this->templateSpec('page');
        $spec['_oxygen_template_settings'] = wp_json_encode([
            'type' => '',
            'ruleGroups' => 'bad',
            'priority' => 'high',
            'fallback' => 'no',
        ]);

        $result = (new OxygenTemplateRepository())->validateTemplateSpec($spec);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $result['errors']));
        $this->assertStringContainsString('$.type expected non-empty string', implode(' ', $result['errors']));
        $this->assertStringContainsString('$.ruleGroups expected array', implode(' ', $result['errors']));
        $this->assertStringContainsString('$.priority expected integer', implode(' ', $result['errors']));
        $this->assertStringContainsString('$.fallback expected boolean', implode(' ', $result['errors']));
    }

    public function testValidateTemplateSpecRequiresOxygenDataEnvelopeAndTemplateSettingsJsonString(): void
    {
        $result = (new OxygenTemplateRepository())->validateTemplateSpec([
            'post_type' => 'oxygen_template',
            '_oxygen_data' => ['tree_json_string' => '{"root":{"id":"bad"}}'],
            '_oxygen_template_settings' => ['type' => 'everywhere'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings must be a JSON string', implode(' ', $result['errors']));
    }

    public function testCreateOrUpdateTemplateCreatesEveryTemplatePostTypeWithOxygenMeta(): void
    {
        $repository = new OxygenTemplateRepository();

        foreach ($repository->supportedPostTypes() as $postType) {
            $result = $repository->createOrUpdateTemplate($this->templateSpec($postType));

            $this->assertTrue($result['success'], $postType . ': ' . implode(' ', $result['errors'] ?? []));
            $this->assertSame(200, $result['status']);
            $this->assertSame('created', $result['action']);
            $this->assertSame($postType, $result['postType']);
            $this->assertSame($repository->requiredMetaKeys(), $result['metaKeys']);

            $postId = (int) $result['postId'];
            $this->assertGreaterThan(0, $postId);
            $this->assertArrayHasKey($postId, $GLOBALS['__wp_posts']);
            $this->assertSame($postType, $GLOBALS['__wp_posts'][$postId]->post_type);

            $oxygenData = $this->decodeStoredMetaObject((string) get_post_meta($postId, '_oxygen_data', true));
            $this->assertArrayHasKey('tree_json_string', $oxygenData);
            $this->assertIsArray(json_decode((string) $oxygenData['tree_json_string'], true));

            $settingsJson = $this->decodeStoredTemplateSettings((string) get_post_meta($postId, '_oxygen_template_settings', true));
            $settings = json_decode($settingsJson, true);
            $this->assertIsArray($settings);
            $this->assertSame('everywhere', $settings['type']);
        }
    }

    public function testCreateOrUpdateTemplateUpdatesExistingPostAndMeta(): void
    {
        $repository = new OxygenTemplateRepository();
        $created = $repository->createOrUpdateTemplate($this->templateSpec('oxygen_template'));
        $postId = (int) $created['postId'];

        $updatedSpec = $this->templateSpec('oxygen_template');
        $updatedSpec['ID'] = $postId;
        $updatedSpec['post_title'] = 'Updated imported template';
        $updatedSpec['_oxygen_template_settings'] = wp_json_encode([
            'type' => 'all-singles',
            'ruleGroups' => [],
            'triggers' => [],
            'priority' => 20,
            'fallback' => true,
        ]);

        $updated = $repository->createOrUpdateTemplate($updatedSpec);

        $this->assertTrue($updated['success'], implode(' ', $updated['errors'] ?? []));
        $this->assertSame('updated', $updated['action']);
        $this->assertSame($postId, $updated['postId']);
        $this->assertSame('Updated imported template', $GLOBALS['__wp_posts'][$postId]->post_title);

        $settingsJson = $this->decodeStoredTemplateSettings((string) get_post_meta($postId, '_oxygen_template_settings', true));
        $settings = json_decode($settingsJson, true);
        $this->assertSame('all-singles', $settings['type']);
        $this->assertSame(20, $settings['priority']);
        $this->assertTrue($settings['fallback']);
    }

    public function testCreateOrUpdateTemplateRejectsInvalidSpecBeforeWrite(): void
    {
        $repository = new OxygenTemplateRepository();
        $spec = $this->templateSpec('page');

        $result = $repository->createOrUpdateTemplate($spec);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $result['errors']));
    }

    public function testCreateOrUpdateTemplatePersistsMilestoneFiveTemplateSettings(): void
    {
        $repository = new OxygenTemplateRepository();
        $spec = $this->templateSpec('oxygen_header');
        $spec['post_title'] = 'Imported site header';
        $spec['_oxygen_template_settings'] = wp_json_encode([
            'parentId' => 42,
            'type' => 'post-type-archive',
            'ruleGroups' => [[[
                'operand' => 'is',
                'ruleCategorySlug' => 'archive',
                'ruleSlug' => 'post-type-archive',
                'ruleDynamic' => '',
                'value' => [['text' => 'Posts', 'value' => 'post']],
            ]]],
            'triggers' => [[
                'slug' => 'click',
                'options' => [
                    'selector' => '.site-menu-toggle',
                    'limit' => 1,
                ],
            ]],
            'priority' => 30,
            'fallback' => true,
            'disabled' => false,
        ]);

        $result = $repository->createOrUpdateTemplate($spec);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame('oxygen_header', $result['postType']);

        $postId = (int) $result['postId'];
        $settingsJson = $this->decodeStoredTemplateSettings((string) get_post_meta($postId, '_oxygen_template_settings', true));
        $settings = json_decode($settingsJson, true);
        $this->assertIsArray($settings);
        $this->assertSame(42, $settings['parentId']);
        $this->assertSame('post-type-archive', $settings['type']);
        $this->assertSame('archive', $settings['ruleGroups'][0][0]['ruleCategorySlug']);
        $this->assertSame('post', $settings['ruleGroups'][0][0]['value'][0]['value']);
        $this->assertSame('click', $settings['triggers'][0]['slug']);
        $this->assertSame('.site-menu-toggle', $settings['triggers'][0]['options']['selector']);
        $this->assertSame(30, $settings['priority']);
        $this->assertTrue($settings['fallback']);
        $this->assertFalse($settings['disabled']);
    }

    public function testClassifiesManifestTemplateOperationScope(): void
    {
        $repository = new OxygenTemplateRepository();
        $sections = $repository->normalizeManifestSections([
            'templates' => [[
                'id' => 'single-post',
                'title' => 'Single Post',
                'documentTree' => $this->minimalTree('main'),
                'templateSettings' => [
                    'type' => 'all-singles',
                    'ruleGroups' => [[[
                        'ruleCategorySlug' => 'singular',
                    ]]],
                ],
            ], [
                'id' => 'post-archive',
                'title' => 'Posts Archive',
                'documentTree' => $this->minimalTree('main'),
                'templateSettings' => [
                    'type' => 'post-type-archive',
                    'ruleGroups' => [[[
                        'ruleCategorySlug' => 'archive',
                    ]]],
                ],
            ]],
        ]);

        $this->assertSame('single_template', $sections['templates'][0]['operationScope']);
        $this->assertSame('archive_template', $sections['templates'][1]['operationScope']);
        $this->assertSame('single_template', $repository->classifyManifestTemplateOperation($sections['templates'][0]));
        $this->assertSame('archive_template', $repository->classifyManifestTemplateOperation($sections['templates'][1]));
    }

    public function testCreateOrUpdateTemplateRejectsInvalidConditionPayloadBeforeWrite(): void
    {
        $repository = new OxygenTemplateRepository();
        $spec = $this->templateSpec('oxygen_template');
        $spec['_oxygen_template_settings'] = wp_json_encode([
            'type' => 'bad type',
            'ruleGroups' => [[[
                'operand' => 'is one of',
                'ruleSlug' => 'post-type',
            ]]],
            'triggers' => [[
                'slug' => 'launch',
            ]],
            'priority' => 10,
            'fallback' => false,
        ]);

        $result = $repository->createOrUpdateTemplate($spec);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertStringContainsString('registered template type slug', implode(' ', $result['errors']));
        $this->assertStringContainsString('field required', implode(' ', $result['errors']));
        $this->assertStringContainsString('registered template trigger slug', implode(' ', $result['errors']));
    }

    public function testCreateOrUpdateTemplateRejectsUnknownConditionSlugBeforeWrite(): void
    {
        $repository = new OxygenTemplateRepository();
        $spec = $this->templateSpec('oxygen_template');
        $spec['_oxygen_template_settings'] = wp_json_encode([
            'type' => 'all-singles',
            'ruleGroups' => [[[
                'operand' => 'is',
                'ruleSlug' => 'not-a-real-condition',
                'value' => 'post',
            ]]],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ]);

        $result = $repository->createOrUpdateTemplate($spec);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
        $this->assertStringContainsString('registered template condition slug', implode(' ', $result['errors']));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateSpec(string $postType): array
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
            'post_type' => $postType,
            'post_status' => 'publish',
            '_oxygen_data' => [
                'tree_json_string' => wp_json_encode($tree),
            ],
            '_oxygen_template_settings' => wp_json_encode([
                'type' => 'everywhere',
                'ruleGroups' => [],
                'triggers' => [],
                'priority' => 1,
                'fallback' => false,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentInstanceTree(int $componentId): array
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
                        'type' => ElementTypes::COMPONENT,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'block' => [
                                        'componentId' => $componentId,
                                        'targets' => [],
                                        'properties' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ],
            '_nextNodeId' => 2,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTree(string $tag): array
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
                                    'tag' => $tag,
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ]],
            ],
            '_nextNodeId' => 2,
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredMetaObject(string $raw): array
    {
        foreach ([$raw, stripslashes($raw)] as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function decodeStoredTemplateSettings(string $raw): string
    {
        foreach ([$raw, stripslashes($raw)] as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return '';
    }
}
