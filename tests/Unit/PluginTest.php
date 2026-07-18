<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Plugin;
use OxyHtmlConverter\Services\OxygenPageImporter;
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
        $GLOBALS['__wp_current_user_can'] = true;
        remove_all_filters();
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

    public function testPrintCacheRefreshNoticeEscapesAndConsumesStoredWarning(): void
    {
        update_option(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION, [
            'postId' => 42,
            'message' => 'Cache failed at uploads/oxygen <script>alert(1)</script>',
        ]);

        $plugin = Plugin::getInstance();
        ob_start();
        $plugin->printCacheRefreshNotice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString('is-dismissible', $output);
        $this->assertStringContainsString('uploads/oxygen', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertArrayNotHasKey(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION, $GLOBALS['__wp_options']);
    }

    public function testPrintCacheRefreshNoticeHidesDetailsAndPreservesNoticeForNonAdministrators(): void
    {
        update_option(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION, [
            'postId' => 42,
            'message' => 'Sensitive path: wp-content/uploads/oxygen/private',
        ]);
        $GLOBALS['__wp_current_user_can'] = static fn (string $capability): bool => $capability !== 'manage_options';

        $plugin = Plugin::getInstance();
        ob_start();
        $plugin->printCacheRefreshNotice();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
        $this->assertArrayHasKey(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION, $GLOBALS['__wp_options']);
        $this->assertSame('manage_options', $GLOBALS['__wp_current_user_can_last_capability']);
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
            "__('Oxygen HTML Converter requires Oxygen Builder 6.0 or newer to be active.', 'oxygen-html-converter')",
            $bootstrap
        );
        $this->assertStringContainsString('esc_html($message)', $bootstrap);
        $this->assertStringNotContainsString(
            '<p>Oxygen HTML Converter requires Oxygen Builder 6 to be active.</p>',
            $bootstrap
        );
    }

    public function testBootstrapRejectsLegacyOxygenAndEscapesDetectedVersionNotice(): void
    {
        $result = $this->runBootstrapVersionHarness([
            'CT_VERSION' => '4.9<script>alert(1)</script>',
        ]);

        $this->assertFalse($result['booted']);
        $this->assertSame(1, $result['noticeCount']);
        $this->assertStringContainsString('6.0', $result['notice']);
        $this->assertStringNotContainsString('<script>', $result['notice']);
        $this->assertStringContainsString('&lt;script&gt;', $result['notice']);
    }

    public function testBootstrapAcceptsModernOxygenSixVersionConstant(): void
    {
        $result = $this->runBootstrapVersionHarness([
            '__BREAKDANCE_PLUGIN_FILE__' => 'oxygen/plugin.php',
            'BREAKDANCE_MODE' => 'oxygen',
            '__BREAKDANCE_VERSION' => '6.1.0',
        ]);

        $this->assertTrue($result['booted']);
        $this->assertSame(0, $result['noticeCount']);
        $this->assertSame('', $result['notice']);
    }

    /**
     * @param array<string, string> $constants
     * @return array{booted: bool, noticeCount: int, notice: string}
     */
    private function runBootstrapVersionHarness(array $constants): array
    {
        $constantDefinitions = '';
        foreach ($constants as $name => $value) {
            $constantDefinitions .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ');';
        }

        $bootstrap = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'oxygen-html-converter.php';
        $code = <<<'PHP'
namespace OxyHtmlConverter {
    final class Plugin {
        public static function getInstance(): self {
            $GLOBALS['__bootstrap_harness_booted'] = true;
            return new self();
        }
    }
}
namespace {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    %s
    $GLOBALS['__bootstrap_harness_booted'] = false;
    $GLOBALS['__bootstrap_harness_hooks'] = [];
    function plugin_dir_path($file) { return dirname((string) $file) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url($file) { return 'http://example.test/plugin/'; }
    function plugin_basename($file) { return basename((string) $file); }
    function load_plugin_textdomain(...$args) { return true; }
    function add_action($tag, $callback, $priority = 10, $acceptedArgs = 1) {
        $GLOBALS['__bootstrap_harness_hooks'][$tag][] = $callback;
        return true;
    }
    function do_action($tag, ...$args) {
        foreach ($GLOBALS['__bootstrap_harness_hooks'][$tag] ?? [] as $callback) {
            $callback(...$args);
        }
    }
    function __($text, $domain = null) { return (string) $text; }
    function esc_html__($text, $domain = null) { return esc_html($text); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function current_user_can($capability) { return $capability === 'activate_plugins'; }
    require %s;
    do_action('plugins_loaded');
    ob_start();
    do_action('admin_notices');
    $notice = (string) ob_get_clean();
    echo json_encode([
        'booted' => (bool) $GLOBALS['__bootstrap_harness_booted'],
        'noticeCount' => count($GLOBALS['__bootstrap_harness_hooks']['admin_notices'] ?? []),
        'notice' => $notice,
    ]);
}
PHP;
        $code = sprintf($code, $constantDefinitions, var_export($bootstrap, true));
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);
        $decoded = json_decode((string) $stdout, true);
        $this->assertIsArray($decoded, (string) $stdout);

        return $decoded;
    }
}
