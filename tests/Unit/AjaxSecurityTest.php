<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Ajax;
use PHPUnit\Framework\TestCase;

class AjaxSecurityTest extends TestCase
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
        unset($GLOBALS['__wp_current_user_can_last_args']);
    }

    public function testHandleConvertUsesCapabilityFilter(): void
    {
        add_filter('oxy_html_converter_required_capability', static function (string $capability): string {
            return 'custom_cap';
        });

        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            return $capability === 'custom_cap';
        };

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<div>Hello</div>',
        ];

        $ajax = new Ajax();
        $ajax->handleConvert();

        $response = $GLOBALS['__wp_send_json_last'];
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame('custom_cap', $GLOBALS['__wp_current_user_can_last_capability']);
    }

    public function testHandleConvertRedactsExceptionByDefault(): void
    {
        add_filter('oxy_html_converter_tree_builder', static function () {
            throw new \RuntimeException('Secret conversion details');
        }, 10, 3);

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<div>Hello</div>',
        ];

        $ajax = new Ajax();
        $ajax->handleConvert();

        $response = $GLOBALS['__wp_send_json_last'];
        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertSame(500, $response['status_code']);
        $this->assertSame('Conversion failed', $response['data']['message']);
    }

    public function testHandleConvertCanExposeDetailedErrorMessageViaFilter(): void
    {
        add_filter('oxy_html_converter_expose_error_details', static function (): bool {
            return true;
        });

        add_filter('oxy_html_converter_tree_builder', static function () {
            throw new \RuntimeException('Secret conversion details');
        }, 10, 3);

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<div>Hello</div>',
        ];

        $ajax = new Ajax();
        $ajax->handleConvert();

        $response = $GLOBALS['__wp_send_json_last'];
        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertSame(500, $response['status_code']);
        $this->assertStringContainsString('Secret conversion details', $response['data']['message']);
    }

    public function testOmittedSafeModeDefaultsToSafeForAjaxConversionFlows(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
        ];
        $ajax->handleConvert();
        $convertResponse = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($convertResponse['success']);
        $this->assertTrue($convertResponse['data']['audit']['transformed']['safeMode'] ?? false);
        $convertTypes = [];
        $this->collectElementTypes($convertResponse['data']['element'], $convertTypes);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $convertTypes);

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
        ];
        $ajax->handlePreview();
        $previewResponse = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($previewResponse['success']);
        $this->assertTrue($previewResponse['data']['audit']['transformed']['safeMode'] ?? false);

        $_POST = [
            'nonce' => 'test-nonce',
            'batch' => [
                '<script>console.log("x")</script><div>Hello</div>',
            ],
        ];
        $ajax->handleBatchConvert();
        $batchResponse = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($batchResponse['success']);
        $batchTypes = [];
        $this->collectElementTypes($batchResponse['data']['results'][0]['element'], $batchTypes);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $batchTypes);
        $this->assertTrue($batchResponse['data']['results'][0]['audit']['transformed']['safeMode'] ?? false);
    }

    public function testExplicitUnsafeModeWithoutExecutableOptInDoesNotPreserveScripts(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
        ];
        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertFalse($response['data']['audit']['transformed']['safeMode'] ?? true);

        $types = [];
        $this->collectElementTypes($response['data']['element'], $types);
        $this->assertNotContains('OxygenElements\\JavaScriptCode', $types);

        $warnings = $response['data']['audit']['diagnostics']['warnings'] ?? [];
        $this->assertContains(
            'Unsafe mode was requested without executable-code approval; scripts, event handlers, and unsafe HtmlCode remain stripped or report-only.',
            $warnings
        );
    }

    public function testExplicitExecutableOptInPreservesScriptsAndReturnsAuditWarning(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
        ];
        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertFalse($response['data']['audit']['transformed']['safeMode'] ?? true);

        $types = [];
        $this->collectElementTypes($response['data']['element'], $types);
        $this->assertContains('OxygenElements\\JavaScriptCode', $types);

        $warnings = $response['data']['audit']['diagnostics']['warnings'] ?? [];
        $this->assertContains(
            'Executable-code fallback was explicitly approved; scripts, event handlers, and unsafe HtmlCode may be preserved.',
            $warnings
        );
    }

    public function testExecutableOptInRequiresUnfilteredHtmlCapability(): void
    {
        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            return $capability !== 'unfiltered_html';
        };

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
        ];

        $ajax->handleConvert();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('unfiltered HTML', $response['data']['message']);

        $_POST = [
            'nonce' => 'test-nonce',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
        ];
        $ajax->handlePreview();
        $previewResponse = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($previewResponse['success']);
        $this->assertSame(403, $previewResponse['status_code']);

        $_POST = [
            'nonce' => 'test-nonce',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
            'batch' => [
                '<script>console.log("x")</script><div>Hello</div>',
            ],
        ];
        $ajax->handleBatchConvert();
        $batchResponse = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($batchResponse['success']);
        $this->assertSame(403, $batchResponse['status_code']);
    }

    public function testHandleImportPageRejectsExecutablePayloadWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload()),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsExecutableStandaloneSiteKitManifestWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'siteKitManifest' => [
                    'version' => 1,
                    'id' => 'unsafe-site-kit',
                    'pages' => [[
                        'id' => 'home',
                        'title' => 'Home',
                        'documentTree' => [
                            'root' => [
                                'id' => 1,
                                'data' => [
                                    'type' => 'OxygenElements\\JavaScriptCode',
                                    'properties' => [
                                        'content' => [
                                            'content' => [
                                                'javascript_code' => 'console.log("x");',
                                            ],
                                        ],
                                    ],
                                ],
                                'children' => [],
                            ],
                            '_nextNodeId' => 2,
                        ],
                    ]],
                ],
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public function testHandleImportPageRejectsExecutableStandaloneSiteKitTokenAliasesWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'siteKitManifest' => array_merge($this->minimalStandaloneSiteKitManifest(), [
                    'tokens' => [
                        'spacing' => [[
                            'value' => 'calc(1px + url(javascript:alert(1)))',
                            'uses' => 1,
                            'suggestedName' => 'unsafe-space',
                        ]],
                    ],
                ]),
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public function testHandleImportPageRejectsStandaloneSiteKitSelectorAliasesOverLimit(): void
    {
        $manifest = $this->minimalStandaloneSiteKitManifest();
        $manifest['selectors'] = array_fill(0, 1001, [
            'id' => 'selector-over-limit',
            'name' => 'selector-over-limit',
            'collection' => 'Site Kit',
            'properties' => [],
        ]);

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'siteKitManifest' => $manifest,
            ]),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('Site-kit selector payload contains too many selectors', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertSame([], $GLOBALS['__wp_post_meta']);
    }

    public function testHandleImportPageRejectsExecutablePayloadWithoutUnfilteredHtml(): void
    {
        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            return $capability !== 'unfiltered_html';
        };

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'data' => [
                    'type' => 'OxygenElements\\HtmlCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => '<iframe src="https://example.test"></iframe>',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageAllowsExecutablePayloadWithOptInAndUnfilteredHtml(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
            'importPayload' => wp_json_encode($this->executableImportPayload()),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['postId']);
    }

    public function testHandleImportPageRejectsNativeExecutableAttributesWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'attributes' => [[
                                    'name' => 'onclick',
                                    'value' => 'alert(1)',
                                ]],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsEntityEncodedJavascriptUrlsInHtmlCodeWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\HtmlCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => '<a href="jav&#x61;script:alert(1)">x</a>',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsSvgDataUriInHtmlCodeWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\HtmlCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsSvgDataUriInNativeAttributesWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'attributes' => [[
                                    'name' => 'src',
                                    'value' => 'data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+',
                                ]],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnsafeCssCodeWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\CssCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'css_code' => 'body{background:url(javascript:alert(1))}',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnsafePageScopedCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->executableImportPayload([
                'id' => 1,
                'data' => ['type' => 'OxygenElements\\Container', 'properties' => []],
                'children' => [],
            ]), [
                'pageScopedCss' => 'body{background:url(javascript:alert(1))}',
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnsafeGlobalCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->executableImportPayload([
                'id' => 1,
                'data' => ['type' => 'OxygenElements\\Container', 'properties' => []],
                'children' => [],
            ]), [
                'globalCss' => '@font-face{src:url(data:text/html,<script>alert(1)</script>)}',
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnsafeStyleRoutingCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->executableImportPayload([
                'id' => 1,
                'data' => ['type' => 'OxygenElements\\Container', 'properties' => []],
                'children' => [],
            ]), [
                'styleRouting' => [
                    'pageScopedCss' => 'body{background:url(javascript:alert(1))}',
                    'routes' => [[
                        'type' => 'page_fallback',
                        'destination' => 'page_scoped_styles',
                        'css' => '.probe{background:url(javascript:alert(1))}',
                    ]],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnsafeSelectorCustomCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->selectorImportPayload([
                'custom_css' => [
                    'custom_css' => '.x{background:url(javascript:alert(1))}',
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsCssEscapedUnsafeSelectorCustomCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->selectorImportPayload([
                'custom_css' => [
                    'custom_css' => '.x{background:url(\\6a avascript:alert(1))}',
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsDataUriSelectorCustomCssWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->selectorImportPayload([
                'custom_css' => [
                    'custom_css' => '.x{background:url(data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+)}',
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsSelectorBackgroundJavascriptUrlWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->selectorImportPayload([
                'breakpoint_base' => [
                    'background' => [
                        'backgrounds' => [[
                            'type' => 'image',
                            'image' => [
                                'url' => 'javascript:alert(1)',
                            ],
                        ]],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsGlobalSettingsScriptCodeWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'oxygenGlobalSettings' => [
                    'settings' => [
                        'code' => [
                            'stylesheets' => [],
                            'scripts' => [[
                                'name' => 'Unsafe script',
                                'code' => 'alert(1);',
                            ]],
                        ],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_global_settings_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsGlobalSettingsUnsafeStylesheetWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'globalSettings' => [
                    'settings' => [
                        'code' => [
                            'stylesheets' => [[
                                'name' => 'Unsafe stylesheet',
                                'code' => 'body{background:url(javascript:alert(1))}',
                            ]],
                            'scripts' => [],
                        ],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_global_settings_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsBrandImageTokenExecutableUrlWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'designDocument' => [
                    'tokens' => [
                        'images' => [[
                            'value' => 'javascript:alert(1)',
                            'suggestedName' => 'Unsafe Image',
                            'uses' => 1,
                        ]],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsGlobalPaletteGradientSvgWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'oxygenGlobalSettings' => [
                    'settings' => [
                        'colors' => [
                            'palette' => [
                                'gradients' => [[
                                    'label' => 'Unsafe gradient',
                                    'cssVariableName' => 'unsafe-gradient',
                                    'value' => [
                                        'svgValue' => '<svg onload="alert(1)"></svg>',
                                        'value' => 'linear-gradient(90deg,#000,#fff)',
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_global_settings_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsGlobalPaletteUnsafeColorWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'globalSettings' => [
                    'settings' => [
                        'colors' => [
                            'palette' => [
                                'colors' => [[
                                    'label' => 'Unsafe color',
                                    'cssVariableName' => 'unsafe-color',
                                    'value' => 'url(javascript:alert(1))',
                                ]],
                            ],
                        ],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_global_settings_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsBrandColorTokenExecutableValueWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'designDocument' => [
                    'tokens' => [
                        'colors' => [[
                            'value' => 'url(javascript:alert(1))',
                            'suggestedName' => 'Unsafe color token',
                            'uses' => 1,
                        ]],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsBrandComponentSignatureExecutableMarkupWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'designDocument' => [
                    'componentCandidates' => [[
                        'suggestedName' => 'Unsafe Component',
                        'signature' => '<svg onload="alert(1)"></svg>',
                        'classes' => ['safe-class'],
                        'occurrences' => 1,
                    ]],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsBrandDesignProfileExecutableStyleSignatureWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode(array_merge($this->cleanContainerImportPayload(), [
                'designDocument' => [
                    'tokens' => [
                        'numbers' => [[
                            'value' => '1',
                            'suggestedName' => 'safe-number',
                            'uses' => 1,
                        ]],
                    ],
                    'designProfile' => [
                        'semanticClasses' => [[
                            'semanticClass' => 'unsafe',
                            'styleSignature' => 'url(javascript:alert(1))',
                            'sourceClass' => 'unsafe',
                        ]],
                    ],
                ],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsImportPlanDesignProfileExecutableStyleSignatureWithoutExplicitOptIn(): void
    {
        $payload = $this->cleanContainerImportPayload();
        $payload['importPlan']['tokens'] = [
            'numbers' => [[
                'value' => '1',
                'suggestedName' => 'safe-number',
                'uses' => 1,
            ]],
        ];
        $payload['importPlan']['designProfile'] = [
            'semanticClasses' => [[
                'semanticClass' => 'unsafe',
                'styleSignature' => 'url(javascript:alert(1))',
                'sourceClass' => 'unsafe',
            ]],
        ];

        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($payload),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsNativeJavascriptInteractionsWithoutExplicitOptIn(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode($this->executableImportPayload([
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\Container',
                    'properties' => [
                        'settings' => [
                            'interactions' => [
                                'interactions' => [[
                                    'trigger' => 'click',
                                    'target' => 'this_element',
                                    'actions' => [[
                                        'name' => 'javascript_function',
                                        'target' => 'this_element',
                                        'js_function_name' => 'run',
                                    ]],
                                ]],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ])),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsExecutablePayloadWhenSafeModeRemainsEnabled(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'safeMode' => 'true',
            'allowExecutableCode' => 'true',
            'importPayload' => wp_json_encode($this->executableImportPayload()),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsExecutablePayloadWhenStrictNativeRemainsEnabled(): void
    {
        $ajax = new Ajax();
        $_POST = [
            'nonce' => 'test-nonce',
            'safeMode' => 'false',
            'strictNative' => 'true',
            'allowExecutableCode' => 'true',
            'importPayload' => wp_json_encode($this->executableImportPayload()),
        ];

        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('Executable code import', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsUnauthorizedExistingPostUpdate(): void
    {
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Existing',
            'post_name' => 'existing-page',
            'post_content' => '',
        ], true);

        $GLOBALS['__wp_current_user_can'] = static function (string $capability, ...$args) use ($postId): bool {
            if ($capability === 'manage_options') {
                return true;
            }

            if ($capability === 'edit_post' && (int) ($args[0] ?? 0) === (int) $postId) {
                return false;
            }

            return true;
        };

        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Existing Updated',
                'slug' => 'existing-page',
                'replaceExisting' => true,
                'element' => [
                    'id' => 1,
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [],
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                    'tokens' => [
                        'colors' => [],
                        'fonts' => [],
                        'spacing' => [],
                    ],
                ],
                'designDocument' => [
                    'tokens' => [
                        'colors' => [],
                        'fonts' => [],
                        'spacing' => [],
                    ],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame('Existing', $GLOBALS['__wp_posts'][(int) $postId]->post_title);
    }

    public function testHandleImportPageRejectsGlobalDesignMutationWithoutThemeOptionsCapability(): void
    {
        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            if ($capability === 'edit_theme_options') {
                return false;
            }

            return in_array($capability, ['manage_options', 'edit_pages'], true);
        };

        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Global Mutation',
                'element' => [
                    'id' => 1,
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [],
                ],
                'selectorPayload' => [
                    'selectors' => [[
                        'id' => 'selector-1',
                        'name' => 'card',
                        'properties' => ['breakpoint_base' => ['typography' => ['color' => 'red']]],
                    ]],
                    'collections' => ['Imported HTML'],
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertStringContainsString('global design', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageAllowsPageOnlyImportWithoutThemeOptionsCapability(): void
    {
        $GLOBALS['__wp_current_user_can'] = static function (string $capability): bool {
            if ($capability === 'edit_theme_options') {
                return false;
            }

            return in_array($capability, ['manage_options', 'edit_pages'], true);
        };

        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Page Only',
                'element' => [
                    'id' => 1,
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [],
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['postId']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleRollbackRejectsUnauthorizedPostId(): void
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

        $GLOBALS['__wp_current_user_can'] = static function (string $capability, ...$args) use ($postId): bool {
            if ($capability === 'manage_options') {
                return true;
            }

            if ($capability === 'edit_post' && (int) ($args[0] ?? 0) === (int) $postId) {
                return false;
            }

            return true;
        };

        $_POST = [
            'nonce' => 'test-nonce',
            'postId' => (string) $postId,
        ];

        $ajax = new Ajax();
        $ajax->handleRollbackImport();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame('current-oxygen-payload', $GLOBALS['__wp_post_meta'][(int) $postId]['_oxygen_data']);
    }

    public function testHandleSaveSelectorsRejectsOversizedJsonBeforeDecode(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'selectorPayload' => str_repeat('{', 524289),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('too large', $response['data']['message']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveSelectorsRejectsTooManySelectorRecords(): void
    {
        $selectors = [];
        for ($i = 0; $i < 1001; $i++) {
            $selectors[] = [
                'id' => 'selector-' . $i,
                'name' => 'selector-' . $i,
                'properties' => [],
            ];
        }

        $_POST = [
            'nonce' => 'test-nonce',
            'selectorPayload' => wp_json_encode([
                'selectors' => $selectors,
                'collections' => ['Imported HTML'],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('selectors', $response['data']['message']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveSelectorsRejectsPayloadBeyondDecodeDepth(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'selectorPayload' => $this->nestedJson(33),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('depth', $response['data']['message']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveSelectorsRejectsTooManyCollections(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'selectorPayload' => wp_json_encode([
                'selectors' => [[
                    'id' => 'selector-1',
                    'name' => 'selector-1',
                    'properties' => [],
                ]],
                'collections' => array_map(static fn (int $index): string => 'collection-' . $index, range(1, 101)),
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveSelectors();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('collections', $response['data']['message']);
        $this->assertArrayNotHasKey('oxygen_oxy_selectors_json_string', $GLOBALS['__wp_options']);
    }

    public function testHandleImportPageRejectsPayloadBeyondDecodeDepth(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => $this->nestedJson(65),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('depth', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsTooManyDocumentTreeNodes(): void
    {
        $children = [];
        for ($i = 0; $i < 5001; $i++) {
            $children[] = [
                'id' => $i + 2,
                'data' => ['type' => 'OxygenElements\\Text'],
                'children' => [],
            ];
        }

        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Oversized Tree',
                'documentTree' => [
                    'root' => [
                        'id' => 1,
                        'data' => ['type' => 'OxygenElements\\Container'],
                        'children' => $children,
                    ],
                    '_nextNodeId' => 5003,
                    'status' => 'exported',
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('document tree', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsTooDeepDocumentTree(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Too Deep Tree',
                'documentTree' => [
                    'root' => $this->documentTreeChain(81),
                    '_nextNodeId' => 100,
                    'status' => 'exported',
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('depth', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsTooManyChildrenOnOneNode(): void
    {
        $children = [];
        for ($i = 0; $i < 501; $i++) {
            $children[] = [
                'id' => $i + 2,
                'data' => ['type' => 'OxygenElements\\Text'],
                'children' => [],
            ];
        }

        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Too Many Children',
                'documentTree' => [
                    'root' => [
                        'id' => 1,
                        'data' => ['type' => 'OxygenElements\\Container'],
                        'children' => $children,
                    ],
                    '_nextNodeId' => 504,
                    'status' => 'exported',
                ],
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('children', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleImportPageRejectsOversizedCssSections(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'importPayload' => wp_json_encode([
                'title' => 'Oversized CSS',
                'element' => [
                    'id' => 1,
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [],
                ],
                'pageScopedCss' => str_repeat('a', 262145),
                'importPlan' => [
                    'status' => 'ready',
                    'canImport' => true,
                    'nativeCoverage' => ['percent' => 100],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleImportPage();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('CSS', $response['data']['message']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testHandleSaveBrandLibraryRejectsTooManyColorTokens(): void
    {
        $colors = [];
        for ($i = 0; $i < 513; $i++) {
            $colors[] = [
                'value' => sprintf('#%06X', $i),
                'suggestedName' => 'color-' . $i,
            ];
        }

        $_POST = [
            'nonce' => 'test-nonce',
            'brandPayload' => wp_json_encode([
                'designDocument' => [
                    'tokens' => [
                        'colors' => $colors,
                    ],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveBrandLibrary();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('color', $response['data']['message']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveBrandLibraryRejectsPayloadBeyondDecodeDepth(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'brandPayload' => $this->nestedJson(33),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveBrandLibrary();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('depth', $response['data']['message']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveBrandLibraryRejectsHugeTokenNames(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'brandPayload' => wp_json_encode([
                'designDocument' => [
                    'tokens' => [
                        'colors' => [[
                            'value' => '#123456',
                            'suggestedName' => str_repeat('a', 4097),
                        ]],
                    ],
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveBrandLibrary();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('string', $response['data']['message']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
    }

    public function testHandleSaveBrandLibraryRejectsTooManyNestedItems(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'brandPayload' => wp_json_encode([
                'designDocument' => [
                    'metadata' => range(1, 10001),
                ],
            ]),
        ];

        $ajax = new Ajax();
        $ajax->handleSaveBrandLibrary();
        $response = $GLOBALS['__wp_send_json_last'];

        $this->assertFalse($response['success']);
        $this->assertSame(413, $response['status_code']);
        $this->assertStringContainsString('nested items', $response['data']['message']);
        $this->assertArrayNotHasKey('oxy_html_converter_brand_library', $GLOBALS['__wp_options']);
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

    private function nestedJson(int $depth): string
    {
        $json = '"leaf"';
        for ($i = 0; $i < $depth; $i++) {
            $json = '{"level":' . $json . '}';
        }

        return $json;
    }

    /**
     * @param array<string, mixed>|null $element
     * @return array<string, mixed>
     */
    private function executableImportPayload(?array $element = null): array
    {
        return [
            'title' => 'Executable Import',
            'element' => $element ?? [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\JavaScriptCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'javascript_code' => 'console.log("x");',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanContainerImportPayload(): array
    {
        return $this->executableImportPayload([
            'id' => 1,
            'data' => ['type' => 'OxygenElements\\Container', 'properties' => []],
            'children' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalStandaloneSiteKitManifest(): array
    {
        return [
            'version' => 1,
            'id' => 'safe-site-kit',
            'pages' => [[
                'id' => 'home',
                'title' => 'Home',
                'documentTree' => [
                    'root' => [
                        'id' => 1,
                        'data' => [
                            'type' => 'OxygenElements\\Container',
                            'properties' => [],
                        ],
                        'children' => [],
                    ],
                    '_nextNodeId' => 2,
                ],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function selectorImportPayload(array $properties): array
    {
        return array_merge($this->cleanContainerImportPayload(), [
            'title' => 'Selector Probe',
            'selectorPayload' => [
                'selectors' => [[
                    'id' => 'unsafe-selector',
                    'name' => 'unsafe-selector',
                    'collection' => 'Imported HTML',
                    'properties' => $properties,
                ]],
                'collections' => ['Imported HTML'],
            ],
        ]);
    }

    private function documentTreeChain(int $depth): array
    {
        $node = [
            'id' => $depth,
            'data' => ['type' => 'OxygenElements\\Container'],
            'children' => [],
        ];

        for ($i = $depth - 1; $i >= 1; $i--) {
            $node = [
                'id' => $i,
                'data' => ['type' => 'OxygenElements\\Container'],
                'children' => [$node],
            ];
        }

        return $node;
    }
}
