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
        $GLOBALS['__wp_localized_scripts'] = [];
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
}
