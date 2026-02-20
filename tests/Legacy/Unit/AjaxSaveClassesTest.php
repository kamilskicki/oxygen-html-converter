<?php

namespace OxyHtmlConverter {
    if (!function_exists('OxyHtmlConverter\add_action')) {
        function add_action($hook, $callback): void
        {
            // No-op in tests.
        }
    }

    if (!function_exists('OxyHtmlConverter\check_ajax_referer')) {
        function check_ajax_referer($action, $queryArg, $stop)
        {
            return true;
        }
    }

    if (!function_exists('OxyHtmlConverter\current_user_can')) {
        function current_user_can($capability)
        {
            return true;
        }
    }

    if (!function_exists('OxyHtmlConverter\wp_unslash')) {
        function wp_unslash($value)
        {
            return $value;
        }
    }

    if (!function_exists('OxyHtmlConverter\get_option')) {
        function get_option($name, $default = false)
        {
            return $GLOBALS['oxy_ajax_test_options'][$name] ?? $default;
        }
    }

    if (!function_exists('OxyHtmlConverter\update_option')) {
        function update_option($name, $value)
        {
            $GLOBALS['oxy_ajax_test_options'][$name] = $value;
            return true;
        }
    }

    if (!function_exists('OxyHtmlConverter\wp_json_encode')) {
        function wp_json_encode($value)
        {
            return json_encode($value);
        }
    }

    if (!function_exists('OxyHtmlConverter\wp_send_json_success')) {
        function wp_send_json_success($data = [], $statusCode = 200): void
        {
            $GLOBALS['oxy_ajax_test_response'] = [
                'success' => true,
                'data' => is_array($data) ? $data : [],
                'statusCode' => (int) $statusCode,
            ];
        }
    }

    if (!function_exists('OxyHtmlConverter\wp_send_json_error')) {
        function wp_send_json_error($data = [], $statusCode = 400): void
        {
            $GLOBALS['oxy_ajax_test_response'] = [
                'success' => false,
                'data' => is_array($data) ? $data : [],
                'statusCode' => (int) $statusCode,
            ];
        }
    }
}

namespace Breakdance\BreakdanceOxygen\Selectors {
    if (!function_exists('Breakdance\BreakdanceOxygen\Selectors\getOxySelectors')) {
        function getOxySelectors()
        {
            return $GLOBALS['oxy_ajax_test_existing_selectors'] ?? [];
        }
    }

    if (!function_exists('Breakdance\BreakdanceOxygen\Selectors\getOxySelectorsCollections')) {
        function getOxySelectorsCollections()
        {
            return $GLOBALS['oxy_ajax_test_existing_collections'] ?? [];
        }
    }

    if (!function_exists('Breakdance\BreakdanceOxygen\Selectors\saveSelectors')) {
        function saveSelectors($data): void
        {
            $GLOBALS['oxy_ajax_test_saved_payload'] = $data;
            $decoded = json_decode((string) $data, true);
            $GLOBALS['oxy_ajax_test_saved_decoded'] = is_array($decoded) ? $decoded : [];
        }
    }
}

namespace Breakdance\Render {
    if (!function_exists('Breakdance\Render\generateCacheForGlobalSettings')) {
        function generateCacheForGlobalSettings(): void
        {
            $GLOBALS['oxy_ajax_test_cache_regenerated'] = true;
        }
    }
}

namespace OxyHtmlConverter\Tests\Unit {
    use OxyHtmlConverter\Ajax;
    use OxyHtmlConverter\Tests\TestCase;

    class AjaxSaveClassesTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['oxy_ajax_test_options'] = [];
            $GLOBALS['oxy_ajax_test_existing_selectors'] = [];
            $GLOBALS['oxy_ajax_test_existing_collections'] = ['Default'];
            $GLOBALS['oxy_ajax_test_saved_payload'] = null;
            $GLOBALS['oxy_ajax_test_saved_decoded'] = [];
            $GLOBALS['oxy_ajax_test_cache_regenerated'] = false;
            $GLOBALS['oxy_ajax_test_response'] = null;

            $_POST = [];
        }

        protected function tearDown(): void
        {
            $_POST = [];
            parent::tearDown();
        }

        public function testHandleSaveClassesUsesSaveSelectorsFlowAndReturnsSyncPayload(): void
        {
            $GLOBALS['oxy_ajax_test_existing_selectors'] = [[
                'id' => 'existing-uuid',
                'name' => 'hero',
                'type' => 'class',
                'collection' => 'Default',
                'children' => [],
                'properties' => [
                    'breakpoint_base' => [
                        'typography' => ['color' => '#FF0000FF'],
                    ],
                ],
            ]];

            $_POST['classDefinitions'] = json_encode([[
                'id' => 'incoming-uuid',
                'name' => 'hero-fresh',
                'type' => 'class',
                'collection' => 'Default',
                'children' => [],
                'properties' => [
                    'breakpoint_base' => [
                        'typography' => ['color' => '#FF0000FF'],
                    ],
                ],
            ]]);

            $ajax = new Ajax();
            $ajax->handleSaveClasses();

            $response = $GLOBALS['oxy_ajax_test_response'];
            $this->assertIsArray($response);
            $this->assertTrue($response['success']);
            $this->assertEquals(200, $response['statusCode']);
            $this->assertEquals(0, $response['data']['saved']);
            $this->assertEquals(1, $response['data']['reused']);
            $this->assertEquals('existing-uuid', $response['data']['idMap']['incoming-uuid']);
            $this->assertArrayHasKey('selectors', $response['data']);
            $this->assertArrayHasKey('locked', $response['data']['selectors'][0]);

            $this->assertIsString($GLOBALS['oxy_ajax_test_saved_payload']);
            $this->assertArrayHasKey('selectors', $GLOBALS['oxy_ajax_test_saved_decoded']);
            $this->assertArrayHasKey('collections', $GLOBALS['oxy_ajax_test_saved_decoded']);
            $this->assertTrue($GLOBALS['oxy_ajax_test_cache_regenerated']);
        }
    }
}
