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
        unset($GLOBALS['__wp_current_user_can_last_capability']);
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
}
