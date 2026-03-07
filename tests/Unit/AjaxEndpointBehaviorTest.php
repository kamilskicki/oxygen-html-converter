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
        unset($GLOBALS['__wp_current_user_can_last_capability']);
    }

    public function testPreviewSafeModeRemovesScriptElementsFromStats(): void
    {
        $ajax = new Ajax();

        $_POST = [
            'nonce' => 'n',
            'html' => '<script>console.log("x")</script><div>Hello</div>',
            'safeMode' => 'false',
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

        $documentJson = json_decode((string) $firstResult['documentJson'], true);
        $this->assertIsArray($documentJson);
        $this->assertArrayHasKey('tree_json_string', $documentJson);
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
}
