<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\AdminPage;
use PHPUnit\Framework\TestCase;

class AdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_registered_settings'] = [];
        $GLOBALS['__wp_enqueued_scripts'] = [];
        $GLOBALS['__wp_enqueued_styles'] = [];
        $GLOBALS['__wp_localized_scripts'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_current_user_can'] = true;
        remove_all_filters();
    }

    public function testClassHandlingModeSanitizerMapsLegacyAliasToNative(): void
    {
        $page = new AdminPage();

        $this->assertSame('native', $page->sanitizeClassHandlingMode('oxygen'));
        $this->assertSame('native', $page->sanitizeClassHandlingMode('invalid'));
    }

    public function testRegisterSettingsUsesDedicatedSanitizers(): void
    {
        $page = new AdminPage();
        $page->registerSettings();

        $this->assertArrayHasKey('oxy_html_converter_class_mode', $GLOBALS['__wp_registered_settings']);
        $this->assertArrayHasKey('oxy_html_converter_element_mapping_mode', $GLOBALS['__wp_registered_settings']);

        $classMode = $GLOBALS['__wp_registered_settings']['oxy_html_converter_class_mode'];
        $this->assertSame('native', $classMode['args']['default']);
        $this->assertIsArray($classMode['args']['sanitize_callback']);
    }

    public function testEnqueueAdminAssetsProvidesUiConfigAndStrings(): void
    {
        $page = new AdminPage();
        $page->enqueueAdminAssets('tools_page_oxy-html-converter-tool');

        $this->assertArrayHasKey('oxy-html-converter-admin', $GLOBALS['__wp_enqueued_scripts']);
        $this->assertArrayHasKey('oxy-html-converter-options', $GLOBALS['__wp_enqueued_scripts']);
        $this->assertArrayHasKey('oxy-html-converter-admin', $GLOBALS['__wp_localized_scripts']);
        $this->assertArrayHasKey('ui', $GLOBALS['__wp_localized_scripts']['oxy-html-converter-admin']['l10n']);
    }

    public function testRenderPageIncludesThreeStepWorkflowAndAudit(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->renderPage();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Conversion audit', $output);
        $this->assertStringContainsString('Load sample HTML', $output);
        $this->assertStringContainsString('Import output', $output);
        $this->assertStringContainsString('Strict native', $output);
    }

    public function testRenderPageHidesWindPressClassModesUntilIntegrationFlagIsEnabled(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->renderPage();
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString('Force WindPress mode', $output);

        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_integration'] = true;
            $flags['windpress_class_mode'] = true;
            return $flags;
        });

        ob_start();
        $page->renderPage();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Force WindPress mode', $output);
    }

    public function testUiConfigDocsUrlsTargetCurrentRepositoryPaths(): void
    {
        $page = new AdminPage();
        $method = new \ReflectionMethod(AdminPage::class, 'getUiConfig');
        $method->setAccessible(true);

        $ui = $method->invoke($page);

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

    public function testAdminViewEscapesDynamicContractStatusClasses(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/src/Views/admin-page.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('echo esc_attr($contractStatusClass);', $view);
        $this->assertStringContainsString("echo esc_attr(\$isEssentialPluginActive ? 'is-success' : 'is-danger');", $view);
    }
}
