<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Ajax;
use PHPUnit\Framework\TestCase;

class AjaxEndpointBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        remove_all_filters();

        $GLOBALS['__wp_send_json_last'] = null;
        $GLOBALS['__wp_check_ajax_referer'] = true;
        $GLOBALS['__wp_current_user_can'] = true;
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_cleaned_post_cache'] = [];
        unset($GLOBALS['__wp_current_user_can_last_capability']);
    }

    public function testPreviewSafeModeRemovesScriptElementsFromStats(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'n',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
        ];
        $ajax->handlePreview();
        $responseWithoutSafeMode = $GLOBALS['__wp_send_json_last'];

        $_POST = [
            'nonce' => 'n',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'true',
        ];
        $ajax->handlePreview();
        $responseWithSafeMode = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($responseWithoutSafeMode['success']);
        $this->assertTrue($responseWithSafeMode['success']);

        $countWithout = (int) $responseWithoutSafeMode['data']['elementCount'];
        $countWith = (int) $responseWithSafeMode['data']['elementCount'];

        $this->assertGreaterThan($countWith, $countWithout);
    }

    public function testBatchSafeModeStripsJavaScriptCodeElements(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'n',
            'safeMode' => 'true',
            'batch' => [
                '<script>console.log("x")</script><div>Hello</div>',
            ],
        ];

        $ajax->handleBatchConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['data']['results']);

        $types = [];
        $this->collectElementTypes($response['data']['results'][0]['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);
    }

    public function testBatchOptionsFilterCanForceSafeMode(): void
    {
        add_filter('oxy_html_converter_batch_options', static function (array $options): array {
            $options['safeMode'] = true;
            return $options;
        });

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'batch' => [
                '<script>console.log("x")</script><div>Hello</div>',
            ],
        ];

        $ajax->handleBatchConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['data']['results']);

        $types = [];
        $this->collectElementTypes($response['data']['results'][0]['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);
    }

    public function testConvertOptionsAreNormalizedAfterFilters(): void
    {
        add_filter('oxy_html_converter_convert_options', static function (array $options): array {
            $options['safeMode'] = 'false';
            $options['inlineStyles'] = 'true';
            $options['wrapInContainer'] = '1';
            $options['includeCssElement'] = '0';
            $options['startingNodeId'] = -5;
            return $options;
        });

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<div>Hello</div>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['element']['id']);
        $this->assertSame('OxygenElements\\Container', $response['data']['element']['data']['type']);
    }

    public function testConvertDefaultNativeProfileRoutesCssWithoutVisibleCssCodeBlock(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.hero{clip-path:circle(50%);}</style><section class="hero">Hello</section>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertNull($response['data']['cssElement']);

        $types = [];
        $this->collectElementTypes($response['data']['element'], $types);

        $this->assertNotContains('OxygenElements\\CssCode', $types);
        $this->assertStringContainsString('clip-path', $response['data']['extractedCss']);
        $this->assertSame(0, $response['data']['designDocument']['summary']['cssCodeBlocks']);

        $fallbackTypes = array_map(
            static fn (array $fallback): string => (string) ($fallback['type'] ?? ''),
            $response['data']['importPlan']['fallbacks'] ?? []
        );

        $this->assertContains('extracted_css', $fallbackTypes);
        $this->assertNotContains('css_code', $fallbackTypes);
    }

    public function testConvertResponseIncludesBuilderSafeDocumentTree(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<div><span>Hello</span></div>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']['documentTree']);
        $this->assertArrayHasKey('root', $response['data']['documentTree']);
        $this->assertArrayHasKey('_nextNodeId', $response['data']['documentTree']);
        $this->assertArrayHasKey('status', $response['data']['documentTree']);
        $this->assertSame('exported', $response['data']['documentTree']['status']);

        $documentJson = json_decode((string) $response['data']['documentJson'], true);
        $this->assertIsArray($documentJson);
        $this->assertArrayHasKey('tree_json_string', $documentJson);

        $encodedTree = json_decode((string) $documentJson['tree_json_string'], true);
        $this->assertIsArray($encodedTree);
        $this->assertSame($response['data']['documentTree'], $encodedTree);
    }

    public function testConvertResponseIncludesNativeSelectorPayload(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.card{color:red;}</style><div class="card">Card</div>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('selectorPayload', $response['data']);
        $this->assertNotEmpty($response['data']['selectorPayload']['selectors']);
        $this->assertSame([
            'requiresTreeJsonString' => true,
            'requiresOxygenSelectorPersistence' => true,
            'requiresBreakdanceClassesJsonString' => false,
            'persistsBreakdanceClassesJsonString' => true,
            'oxygenSelectorsOptionName' => 'oxygen_oxy_selectors_json_string',
            'oxygenSelectorCollectionsOptionName' => 'oxygen_oxy_selectors_collections_json_string',
            'breakdanceClassesOptionName' => 'breakdance_classes_json_string',
        ], $response['data']['selectorPayload']['persistence']);
        $this->assertTrue($this->treeContainsNativeClassReference($response['data']['element']));

        $selectorJson = json_decode((string) $response['data']['selectorJson'], true);
        $this->assertIsArray($selectorJson);
        $this->assertSame('.card', $selectorJson['selectors'][0]['selector']);
    }

    public function testSaveSelectorsEndpointPersistsGeneratedSelectorRecords(): void
    {
        unset($GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string']);
        unset($GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string']);
        unset($GLOBALS['__wp_options']['oxygen_breakdance_classes_json_string']);

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'selectorPayload' => wp_json_encode([
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
            ]),
        ];

        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['saved']);
        $this->assertSame(['Imported HTML'], $response['data']['collections']);

        $selectors = json_decode($GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'], true);
        $this->assertIsArray($selectors);
        $this->assertSame('selector-1', $selectors[0]['id']);
        $this->assertSame('card', $selectors[0]['name']);

        $collections = json_decode($GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string'], true);
        $this->assertSame(['Imported HTML'], $collections);

        $breakdanceClasses = json_decode($GLOBALS['__wp_options']['oxygen_breakdance_classes_json_string'], true);
        $this->assertIsArray($breakdanceClasses);
        $this->assertSame('.card', $breakdanceClasses[0]['name']);
        $this->assertSame('class', $breakdanceClasses[0]['type']);
    }

    public function testSaveSelectorsEndpointMergesCollectionsAndRefreshesBreakdanceClassPayload(): void
    {
        $GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'] = wp_json_encode([
            [
                'id' => 'selector-1',
                'name' => 'card',
                'type' => 'class',
                'collection' => 'Imported HTML',
                'locked' => false,
                'children' => [],
                'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
            ],
        ]);
        $GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string'] = wp_json_encode(['Imported HTML']);
        $GLOBALS['__wp_options']['oxygen_breakdance_classes_json_string'] = wp_json_encode([
            [
                'name' => '.card',
                'type' => 'class',
                'properties' => new \stdClass(),
            ],
        ]);

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'selectorPayload' => wp_json_encode([
                'selectors' => [[
                    'id' => 'selector-2',
                    'name' => 'badge',
                    'type' => 'class',
                    'collection' => 'Marketing Imports',
                    'locked' => false,
                    'children' => [],
                    'properties' => ['breakpoint_base' => ['typography' => ['color' => 'blue']]],
                ]],
                'collections' => ['Marketing Imports'],
            ]),
        ];

        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['saved']);
        $this->assertSame(2, $response['data']['total']);
        $this->assertSame(['Marketing Imports', 'Imported HTML'], $response['data']['collections']);

        $selectors = json_decode($GLOBALS['__wp_options']['oxygen_oxy_selectors_json_string'], true);
        $this->assertIsArray($selectors);
        $this->assertCount(2, $selectors);
        $this->assertSame(['selector-1', 'selector-2'], array_column($selectors, 'id'));

        $collections = json_decode($GLOBALS['__wp_options']['oxygen_oxy_selectors_collections_json_string'], true);
        $this->assertSame(['Marketing Imports', 'Imported HTML'], $collections);

        $breakdanceClasses = json_decode($GLOBALS['__wp_options']['oxygen_breakdance_classes_json_string'], true);
        $this->assertIsArray($breakdanceClasses);
        $this->assertSame(['.card', '.badge'], array_column($breakdanceClasses, 'name'));
    }

    public function testImportPageEndpointCreatesDraftPageWithOxygenDocumentTree(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'importPayload' => wp_json_encode([
                'title' => 'Imported Draft',
                'slug' => 'imported-draft',
                'documentTree' => [
                    'root' => [
                        'id' => 1,
                        'data' => ['type' => 'OxygenElements\\Container'],
                        'children' => [],
                    ],
                    '_nextNodeId' => 2,
                    'status' => 'exported',
                ],
                'selectorPayload' => [
                    'selectors' => [[
                        'id' => 'selector-1',
                        'name' => 'hero',
                        'type' => 'class',
                        'collection' => 'Imported HTML',
                        'locked' => false,
                        'children' => [],
                        'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                    ]],
                    'collections' => ['Imported HTML'],
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
                'sourceHash' => 'source-1',
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['postId']);
        $this->assertSame('created', $response['data']['postAction']);
        $this->assertSame('draft', $response['data']['postStatus']);
        $this->assertArrayHasKey('_oxygen_data', $GLOBALS['__wp_post_meta'][1]);
        $this->assertArrayHasKey('_oxy_html_converter_import_manifest', $GLOBALS['__wp_post_meta'][1]);
        $this->assertSame(1, $response['data']['selectorPersistence']['saved']);
    }

    public function testImportPageEndpointImportsStandaloneSiteKitManifest(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'importPayload' => wp_json_encode([
                'siteKitManifest' => [
                    'version' => 1,
                    'id' => 'ajax-site-kit',
                    'pages' => [[
                        'id' => 'home',
                        'title' => 'Home',
                        'slug' => 'home',
                        'documentTree' => [
                            'root' => [
                                'id' => 0,
                                'data' => [
                                    'type' => 'root',
                                    'properties' => [],
                                ],
                                'children' => [[
                                    'id' => 1,
                                    'data' => [
                                        'type' => 'OxygenElements\\Container',
                                        'properties' => [],
                                    ],
                                    'children' => [],
                                ]],
                            ],
                            '_nextNodeId' => 2,
                        ],
                    ]],
                    'assets' => [[
                        'id' => 'hero-image',
                        'type' => 'image',
                    ]],
                    'unsupportedItems' => [[
                        'id' => 'external-form',
                        'type' => 'form',
                    ]],
                ],
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['data']['rollbackId']);
        $this->assertSame('page', $response['data']['objects']['pages'][0]['postType']);
        $this->assertSame('hero-image', $response['data']['assets'][0]['id']);
        $this->assertSame('external-form', $response['data']['unsupportedItems'][0]['id']);
        $this->assertArrayHasKey('_oxygen_data', $GLOBALS['__wp_post_meta'][1]);

        $manifest = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][1]['_oxy_html_converter_import_manifest']), true);
        $this->assertSame('site-kit', $manifest['kind']);
        $this->assertSame($response['data']['rollbackId'], $manifest['rollbackId']);
    }

    public function testImportPageEndpointRejectsBlockedImportPlan(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'importPayload' => wp_json_encode([
                'element' => [
                    'id' => 1,
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [],
                ],
                'importPlan' => [
                    'status' => 'blocked',
                    'canImport' => false,
                    'blockers' => ['Strict native mode blocks CSS code fallback block(s).'],
                ],
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(422, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame(['Strict native mode blocks CSS code fallback block(s).'], $response['data']['errors']);
    }

    public function testRollbackImportEndpointRestoresPreviousOxygenPayload(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);
        update_post_meta((int) $postId, '_oxygen_data', 'current-oxygen-payload');
        update_post_meta((int) $postId, '_oxy_html_converter_previous_oxygen_data', 'previous-oxygen-payload');

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'postId' => (string) $postId,
        ];

        $ajax->handleRollbackImport();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame((int) $postId, $response['data']['postId']);
        $this->assertTrue($response['data']['rollbackRestored']);
        $this->assertSame('previous-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId]['_oxygen_data']);
        $this->assertArrayNotHasKey('_oxy_html_converter_previous_oxygen_data', $GLOBALS['__wp_post_meta'][(int) $postId]);
    }

    public function testSaveBrandLibraryEndpointPersistsTokensAndComponents(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'brandPayload' => wp_json_encode([
                'designDocument' => [
                    'tokens' => [
                        'colors' => [[
                            'value' => '#731B19',
                            'uses' => 2,
                            'suggestedName' => 'color-731b19',
                        ]],
                    ],
                    'componentCandidates' => [[
                        'suggestedName' => 'card',
                        'signature' => 'div[h3,p]',
                        'count' => 3,
                        'classes' => ['card'],
                    ]],
                ],
            ]),
        ];

        $ajax->handleSaveBrandLibrary();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['tokenChanges']);
        $this->assertSame(1, $response['data']['componentChanges']);

        $library = json_decode($GLOBALS['__wp_options']['oxy_html_converter_brand_library'], true);
        $this->assertSame('#731B19', $library['tokens']['colors'][0]['value']);
        $this->assertSame('card', $library['components'][0]['suggestedName']);
    }

    public function testConvertHonorsPositiveStartingNodeIdForEveryGeneratedNode(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.hero{clip-path:circle(50%);color:red;}</style><section class="hero"><h1>Hello</h1><p>World</p></section>',
            'startingNodeId' => 50,
            'wrapInContainer' => 'true',
            'includeCssElement' => 'true',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);

        $ids = [];
        $this->collectElementIds($response['data']['element'], $ids);

        $this->assertNotEmpty($ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            $this->assertGreaterThanOrEqual(50, $id);
        }

        $this->assertSame(max($ids) + 1, $response['data']['documentTree']['_nextNodeId']);
        $this->assertIsArray($response['data']['cssElement']);
        $this->assertContains($response['data']['cssElement']['id'], $ids);

        $fallbackTypes = array_map(
            static fn (array $fallback): string => (string) ($fallback['type'] ?? ''),
            $response['data']['importPlan']['fallbacks'] ?? []
        );

        $this->assertContains('css_code', $fallbackTypes);
    }

    public function testBatchResultsIncludeBuilderSafeDocumentTree(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'n',
            'batch' => [
                '<div><span>Hello</span></div>',
            ],
        ];

        $ajax->handleBatchConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['data']['results']);

        $firstResult = $response['data']['results'][0];
        $this->assertIsArray($firstResult['documentTree']);
        $this->assertArrayHasKey('root', $firstResult['documentTree']);
        $this->assertArrayHasKey('_nextNodeId', $firstResult['documentTree']);
        $this->assertArrayHasKey('status', $firstResult['documentTree']);
        $this->assertArrayHasKey('designDocument', $firstResult);

        $documentJson = json_decode((string) $firstResult['documentJson'], true);
        $this->assertIsArray($documentJson);
        $this->assertArrayHasKey('tree_json_string', $documentJson);
    }

    public function testConvertResponseIncludesStructuredAuditPayload(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<div class="hero"><span>Hello</span></div>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('audit', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']['audit']);
        $this->assertArrayHasKey('preserved', $response['data']['audit']);
        $this->assertArrayHasKey('followUp', $response['data']['audit']);
    }

    public function testConvertResponseIncludesDesignDocumentPayload(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.hero{color:#731b19;padding:24px;font-family:Inter,sans-serif;}</style><section class="hero"><h1>Hello</h1></section>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('designDocument', $response['data']);
        $this->assertSame(1, $response['data']['designDocument']['version']);
        $this->assertGreaterThanOrEqual(1, $response['data']['designDocument']['summary']['sectionCount']);
        $this->assertArrayHasKey('designSections', $response['data']['audit']['summary']);
        $this->assertArrayHasKey('designDocument', $response['data']['audit']['transformed']);
    }

    public function testPreviewResponseIncludesDesignDocumentPayload(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<section class="hero bg-[#731b19] p-8"><h1>Preview</h1><a class="btn">Start</a></section>',
        ];

        $ajax->handlePreview();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('designDocument', $response['data']);
        $this->assertSame('hero', $response['data']['designDocument']['sections'][0]['role']);
        $this->assertArrayHasKey('classStrategy', $response['data']['designDocument']);
    }

    public function testStrictNativeConvertRejectsFallbackPlanBeforeReturningSuccess(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.hero{clip-path:circle(50%);color:red;}</style><div class="hero"><custom-widget>Unsupported</custom-widget></div>',
            'strictNative' => 'true',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(422, $response['status_code']);
        $this->assertArrayHasKey('importPlan', $response['data']);
        $this->assertSame('blocked', $response['data']['importPlan']['status']);
        $this->assertFalse($response['data']['importPlan']['canImport']);
        $this->assertArrayHasKey('audit', $response['data']);
    }

    public function testStrictNativePreviewReturnsBlockedImportPlanWithoutFailingPreview(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<style>.hero{clip-path:circle(50%);color:red;}</style><section class="hero"><h1>Preview</h1></section>',
            'strictNative' => 'true',
        ];

        $ajax->handlePreview();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('importPlan', $response['data']);
        $this->assertSame('blocked', $response['data']['importPlan']['status']);
    }

    public function testConvertRejectsInvalidBuilderPayloadsBeforeReturningSuccess(): void
    {
        add_filter('oxy_html_converter_tree_builder', static function () {
            return new class extends \OxyHtmlConverter\TreeBuilder {
                public function convert(string $html): array
                {
                    return [
                        'success' => true,
                        'element' => [
                            'data' => [
                                'type' => 'OxygenElements\\Container',
                                'properties' => [],
                            ],
                            'children' => 'invalid',
                        ],
                        'cssElement' => null,
                        'headLinkElements' => [],
                        'headScriptElements' => [],
                        'iconScriptElements' => [],
                        'detectedIconLibraries' => [],
                        'extractedCss' => '',
                        'customClasses' => [],
                        'stats' => [
                            'elements' => 1,
                            'tailwindClasses' => 0,
                            'customClasses' => 0,
                            'warnings' => [],
                            'errors' => [],
                            'info' => [],
                        ],
                    ];
                }
            };
        });

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'n',
            'html' => '<div>Hello</div>',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(422, $response['status_code']);
        $this->assertArrayHasKey('audit', $response['data']);
    }

    public function testTreeBuilderFilterRunsForConvertPreviewAndBatchFlows(): void
    {
        $contexts = [];
        add_filter('oxy_html_converter_tree_builder', static function ($builder, array $options, string $html, string $context) use (&$contexts) {
            $contexts[] = $context;
            return $builder;
        }, 10, 4);

        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'n',
            'html' => '<div>Convert</div>',
        ];
        $ajax->handleConvert();

        $_POST = [
            'nonce' => 'n',
            'html' => '<div>Preview</div>',
        ];
        $ajax->handlePreview();

        $_POST = [
            'nonce' => 'n',
            'batch' => [
                '<div>Batch</div>',
            ],
        ];
        $ajax->handleBatchConvert();

        $this->assertContains('convert', $contexts);
        $this->assertContains('preview', $contexts);
        $this->assertContains('batch', $contexts);
    }

    private function collectElementTypes(array $element, array &$types): void
    {
        $types[] = $element['data']['type'] ?? '';

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectElementTypes($child, $types);
            }
        }
    }

    private function collectElementIds(array $element, array &$ids): void
    {
        $ids[] = $element['id'] ?? null;

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectElementIds($child, $ids);
            }
        }
    }

    private function treeContainsNativeClassReference(array $element): bool
    {
        $refs = $element['data']['properties']['meta']['classes'] ?? [];
        if (is_array($refs) && $refs !== []) {
            return true;
        }

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child) && $this->treeContainsNativeClassReference($child)) {
                return true;
            }
        }

        return false;
    }
}
