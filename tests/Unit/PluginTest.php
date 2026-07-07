<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_enqueued_scripts'] = [];
        $GLOBALS['__wp_enqueued_styles'] = [];
        $GLOBALS['__wp_registered_styles'] = [];
        $GLOBALS['__wp_inline_styles'] = [];
        $GLOBALS['__wp_localized_scripts'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_is_admin'] = false;
        $_GET = [];

        $reflection = new \ReflectionProperty(Plugin::class, 'instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    public function testShouldEnqueueBuilderScriptsForBuilderRequests(): void
    {
        $_GET['oxygen'] = 'builder';
        $plugin = Plugin::getInstance();

        $this->assertTrue($plugin->shouldEnqueueBuilderScripts());
    }

    public function testShouldEnqueueBuilderScriptsForToolPage(): void
    {
        $_GET['page'] = 'oxy-html-converter-tool';
        $plugin = Plugin::getInstance();

        $this->assertTrue($plugin->shouldEnqueueBuilderScripts('tools_page_oxy-html-converter-tool'));
    }

    public function testEnqueueBuilderScriptsLoadsClipboardAndUiConfig(): void
    {
        $_GET['oxygen'] = 'builder';
        $plugin = Plugin::getInstance();
        $plugin->enqueueBuilderScripts();

        $this->assertArrayHasKey('oxy-html-converter-clipboard-utils', $GLOBALS['__wp_enqueued_scripts']);
        $this->assertArrayHasKey('oxy-html-converter', $GLOBALS['__wp_localized_scripts']);
        $this->assertArrayHasKey('ui', $GLOBALS['__wp_localized_scripts']['oxy-html-converter']['l10n']);
    }

    public function testEnqueueBuilderScriptsLoadsInHeadForOxygenBuilderRequests(): void
    {
        $_GET['oxygen'] = 'builder';
        $plugin = Plugin::getInstance();
        $plugin->enqueueBuilderScripts();

        $this->assertFalse($GLOBALS['__wp_enqueued_scripts']['oxy-html-converter-builder-editability']['in_footer']);
        $this->assertFalse($GLOBALS['__wp_enqueued_scripts']['oxy-html-converter']['in_footer']);
    }

    public function testEnqueueBuilderScriptsKeepsFooterLoadingForToolPage(): void
    {
        $_GET['page'] = 'oxy-html-converter-tool';
        $plugin = Plugin::getInstance();
        $plugin->enqueueBuilderScripts('tools_page_oxy-html-converter-tool');

        $this->assertTrue($GLOBALS['__wp_enqueued_scripts']['oxy-html-converter-builder-editability']['in_footer']);
        $this->assertTrue($GLOBALS['__wp_enqueued_scripts']['oxy-html-converter']['in_footer']);
    }

    public function testEnqueueGlobalStylesRegistersPersistedImportedCss(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_global_styles'] = wp_json_encode([
            'version' => 1,
            'styles' => [
                [
                    'id' => 'style-1',
                    'css' => '.material-symbols-outlined { font-variation-settings: "FILL" 0; }',
                ],
            ],
        ]);

        $plugin = Plugin::getInstance();
        $plugin->enqueueGlobalStyles();

        $this->assertArrayHasKey('oxy-html-converter-global-styles', $GLOBALS['__wp_registered_styles']);
        $this->assertArrayHasKey('oxy-html-converter-global-styles', $GLOBALS['__wp_enqueued_styles']);
        $this->assertStringContainsString(
            '.material-symbols-outlined',
            implode("\n", $GLOBALS['__wp_inline_styles']['oxy-html-converter-global-styles'])
        );
    }

    public function testEnqueueGlobalStylesSkipsGenericAdminScreens(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $GLOBALS['__wp_options']['oxy_html_converter_global_styles'] = wp_json_encode([
            'version' => 1,
            'styles' => [
                [
                    'id' => 'style-1',
                    'css' => '.fixed { position: fixed !important; }',
                ],
            ],
        ]);

        $plugin = Plugin::getInstance();
        $plugin->enqueueGlobalStyles('edit.php');

        $this->assertArrayNotHasKey('oxy-html-converter-global-styles', $GLOBALS['__wp_enqueued_styles']);
        $this->assertArrayNotHasKey('oxy-html-converter-global-styles', $GLOBALS['__wp_inline_styles']);
    }

    public function testEnqueueGlobalStylesAllowsOxygenBuilderAdminRequest(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $_GET['oxygen'] = 'builder';
        $GLOBALS['__wp_options']['oxy_html_converter_global_styles'] = wp_json_encode([
            'version' => 1,
            'styles' => [
                [
                    'id' => 'style-1',
                    'css' => '.fixed { position: fixed !important; }',
                ],
            ],
        ]);

        $plugin = Plugin::getInstance();
        $plugin->enqueueGlobalStyles('oxygen_page_builder');

        $this->assertArrayHasKey('oxy-html-converter-global-styles', $GLOBALS['__wp_enqueued_styles']);
        $this->assertStringContainsString(
            '.fixed',
            implode("\n", $GLOBALS['__wp_inline_styles']['oxy-html-converter-global-styles'])
        );
    }

    public function testPrintPageScopedStylesOutputsCurrentPostCss(): void
    {
        $_GET['post'] = '42';
        update_post_meta(42, '_oxy_html_converter_page_styles', wp_slash(wp_json_encode([
            'version' => 1,
            'css' => '.text-6xl { font-size: 3.75rem !important; }',
        ])));

        $plugin = Plugin::getInstance();
        ob_start();
        $plugin->printPageScopedStyles();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('oxy-html-converter-page-styles-inline-css', $output);
        $this->assertStringContainsString('.text-6xl', $output);
    }

    public function testUiConfigDocsUrlsTargetCurrentRepositoryPaths(): void
    {
        $plugin = Plugin::getInstance();
        $method = new \ReflectionMethod(Plugin::class, 'getUiConfig');
        $method->setAccessible(true);

        $ui = $method->invoke($plugin);

        $this->assertSame('https://github.com/kamilskicki/oxygen-html-converter#readme', $ui['docs']['readme']);
        $this->assertSame(
            'https://github.com/kamilskicki/oxygen-html-converter/blob/master/docs/SUPPORTED_SCOPE.md',
            $ui['docs']['supportedScope']
        );
        $this->assertSame(
            'https://github.com/kamilskicki/oxygen-html-converter/blob/master/docs/RELEASE_CHECKLIST.md',
            $ui['docs']['releaseChecklist']
        );
    }

    public function testBootstrapHeaderVersionMatchesDefinedVersion(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/oxygen-html-converter.php');

        $this->assertIsString($bootstrap);
        $this->assertSame(1, preg_match('/^\s*\*\s+Version:\s+(.+)$/m', $bootstrap, $headerMatches));
        $this->assertSame(
            1,
            preg_match("/define\\('OXY_HTML_CONVERTER_VERSION',\\s*'([^']+)'\\);/", $bootstrap, $constantMatches)
        );
        $this->assertSame(trim($constantMatches[1]), trim($headerMatches[1]));
    }

    public function testMissingOxygenAdminNoticeIsEscapedAndTranslated(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/oxygen-html-converter.php');

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString(
            "esc_html__('Oxygen HTML Converter requires Oxygen Builder 6 to be active.', 'oxygen-html-converter')",
            $bootstrap
        );
        $this->assertStringNotContainsString(
            '<p>Oxygen HTML Converter requires Oxygen Builder 6 to be active.</p>',
            $bootstrap
        );
    }
}
