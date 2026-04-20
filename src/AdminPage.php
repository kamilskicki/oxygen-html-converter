<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\AdminPageRenderer;
use OxyHtmlConverter\Services\AdminPageViewDataBuilder;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\UiConfigProvider;

/**
 * Admin page for HTML conversion.
 */
class AdminPage
{
    private UiConfigProvider $uiConfigProvider;
    private AdminPageViewDataBuilder $viewDataBuilder;
    private AdminPageRenderer $renderer;

    private function getRequiredCapability(): string
    {
        $capability = 'manage_options';

        return (string) apply_filters('oxy_html_converter_required_capability', $capability);
    }

    public function __construct(
        ?UiConfigProvider $uiConfigProvider = null,
        ?AdminPageViewDataBuilder $viewDataBuilder = null,
        ?AdminPageRenderer $renderer = null
    )
    {
        $this->uiConfigProvider = $uiConfigProvider ?: new UiConfigProvider();
        $this->viewDataBuilder = $viewDataBuilder ?: new AdminPageViewDataBuilder(new EnvironmentService());
        $this->renderer = $renderer ?: new AdminPageRenderer();

        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        register_setting('oxy_html_converter_options', 'oxy_html_converter_class_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeClassHandlingMode'],
            'default' => 'auto',
        ]);

        register_setting('oxy_html_converter_options', 'oxy_html_converter_element_mapping_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeElementMappingMode'],
            'default' => 'auto',
        ]);
    }

    /**
     * @param mixed $value
     */
    public function sanitizeClassHandlingMode($value): string
    {
        $value = sanitize_text_field((string) $value);
        if ($value === 'oxygen') {
            return 'native';
        }

        if (!in_array($value, ['auto', 'windpress', 'native'], true)) {
            return 'auto';
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public function sanitizeElementMappingMode($value): string
    {
        $value = sanitize_text_field((string) $value);
        if (!in_array($value, ['auto', 'oxygen', 'essential'], true)) {
            return 'auto';
        }

        return $value;
    }

    public function addMenuPage(): void
    {
        $capability = $this->getRequiredCapability();

        add_submenu_page(
            'oxygen',
            __('HTML Converter', 'oxygen-html-converter'),
            __('HTML Converter', 'oxygen-html-converter'),
            $capability,
            'oxy-html-converter',
            [$this, 'renderPage']
        );

        add_management_page(
            __('Oxygen HTML Converter', 'oxygen-html-converter'),
            __('Oxygen HTML Converter', 'oxygen-html-converter'),
            $capability,
            'oxy-html-converter-tool',
            [$this, 'renderPage']
        );
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, [
            'oxygen_page_oxy-html-converter',
            'oxygen_admin_page_oxy-html-converter',
            'tools_page_oxy-html-converter-tool',
        ], true)) {
            return;
        }

        wp_enqueue_style(
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/css/admin.css',
            [],
            OXY_HTML_CONVERTER_VERSION
        );

        wp_enqueue_script(
            'oxy-html-converter-presets',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/presets.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-options',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/converter-options.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-admin-client',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/admin-request-client.js',
            ['jquery', 'oxy-html-converter-options'],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-admin-renderers',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/admin-renderers.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-admin-state',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/admin-state.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/js/admin.js',
            [
                'jquery',
                'oxy-html-converter-presets',
                'oxy-html-converter-options',
                'oxy-html-converter-admin-client',
                'oxy-html-converter-admin-renderers',
                'oxy-html-converter-admin-state',
            ],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_localize_script('oxy-html-converter-admin', 'oxyHtmlConverterAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
            'ui' => $this->getUiConfig(),
            'strings' => [
                'previewCta' => __('Preview', 'oxygen-html-converter'),
                'convertCta' => __('Convert', 'oxygen-html-converter'),
                'copyCta' => __('Copy JSON', 'oxygen-html-converter'),
                'copied' => __('Copied', 'oxygen-html-converter'),
                'emptyPreview' => __('Paste HTML before previewing.', 'oxygen-html-converter'),
                'emptyConvert' => __('Paste HTML before converting.', 'oxygen-html-converter'),
                'requestFailed' => __('Request failed:', 'oxygen-html-converter'),
                'outdated' => __('Output is outdated', 'oxygen-html-converter'),
                'analysisTitle' => __('Conversion audit', 'oxygen-html-converter'),
                'analysisEmpty' => __('Run preview or convert to inspect the conversion audit.', 'oxygen-html-converter'),
                'sampleLoaded' => __('Sample HTML loaded.', 'oxygen-html-converter'),
                'stepsPreview' => __('Preview summary', 'oxygen-html-converter'),
                'stepsOutput' => __('Import output', 'oxygen-html-converter'),
            ],
        ]);
    }

    public function renderPage(): void
    {
        if (!current_user_can($this->getRequiredCapability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oxygen-html-converter'));
        }

        $classMode = $this->sanitizeClassHandlingMode(get_option('oxy_html_converter_class_mode', 'auto'));
        $elementMappingMode = $this->sanitizeElementMappingMode(get_option('oxy_html_converter_element_mapping_mode', 'auto'));
        $viewData = $this->viewDataBuilder->build($classMode, $elementMappingMode, $this->getUiConfig());
        $this->renderer->render($viewData);
    }

    /**
     * @return array<string, mixed>
     */
    private function getUiConfig(): array
    {
        return $this->uiConfigProvider->getConfig();
    }
}
