<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\UiConfigProvider;

/**
 * Main plugin class
 */
class Plugin
{
    private static ?Plugin $instance = null;
    private UiConfigProvider $uiConfigProvider;

    /**
     * @var array<string, bool>
     */
    private array $featureFlags = [
        'core' => true,
        'pro' => false,
        'batch_convert' => true,
        'preview' => true,
    ];

    public static function getInstance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct(?UiConfigProvider $uiConfigProvider = null)
    {
        $this->uiConfigProvider = $uiConfigProvider ?: new UiConfigProvider();
        $this->init();
    }

    private function init(): void
    {
        new Ajax();
        new AdminPage();

        /**
         * Fired after core services are registered.
         *
         * Pro add-ons should hook here to register integrations.
         */
        do_action('oxy_html_converter_core_init', $this);

        add_action('wp_enqueue_scripts', [$this, 'enqueueBuilderScripts'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueBuilderScripts'], 9999);
    }

    /**
     * @param string $hook
     */
    public function enqueueBuilderScripts(string $hook = ''): void
    {
        if (!$this->shouldEnqueueBuilderScripts($hook) || wp_script_is('oxy-html-converter', 'enqueued')) {
            return;
        }

        wp_enqueue_script(
            'oxy-html-converter-options',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/converter-options.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-clipboard-utils',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/clipboard-utils.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-client',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-client.js',
            ['oxy-html-converter-options'],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-paste',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-paste.js',
            ['oxy-html-converter-clipboard-utils'],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-toast',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-toast.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-modal',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-modal.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_enqueue_script(
            'oxy-html-converter',
            OXY_HTML_CONVERTER_URL . 'assets/js/converter.js',
            [
                'oxy-html-converter-options',
                'oxy-html-converter-clipboard-utils',
                'oxy-html-converter-builder-client',
                'oxy-html-converter-builder-paste',
                'oxy-html-converter-builder-toast',
                'oxy-html-converter-builder-modal',
            ],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        $scriptData = apply_filters('oxy_html_converter_builder_script_data', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
            'features' => apply_filters('oxy_html_converter_feature_flags', $this->featureFlags),
            'apiVersion' => OXY_HTML_CONVERTER_API_VERSION,
            'ui' => $this->getUiConfig(),
            'strings' => [
                'converting' => __('Converting HTML…', 'oxygen-html-converter'),
                'convertedAndPasted' => __('HTML converted and pasted.', 'oxygen-html-converter'),
                'convertedReady' => __('HTML converted. Review the result and paste it into Oxygen.', 'oxygen-html-converter'),
                'convertFailed' => __('Conversion failed.', 'oxygen-html-converter'),
                'emptyHtml' => __('Paste HTML before importing.', 'oxygen-html-converter'),
                'modalTitle' => __('Import HTML', 'oxygen-html-converter'),
                'importButton' => __('Import into Builder', 'oxygen-html-converter'),
                'cancelButton' => __('Cancel', 'oxygen-html-converter'),
                'safeModeLabel' => __('Safe mode: strip scripts, event handlers, and external head assets', 'oxygen-html-converter'),
                'fallbackClipboard' => __('Direct insertion is unavailable. Converted JSON was copied to the clipboard.', 'oxygen-html-converter'),
                'modalErrorPrefix' => __('Import error:', 'oxygen-html-converter'),
            ],
        ]);

        wp_localize_script('oxy-html-converter', 'oxyHtmlConverter', $scriptData);

        do_action('oxy_html_converter_after_enqueue_builder_scripts', $scriptData);
    }

    /**
     * @param string $hook
     */
    public function shouldEnqueueBuilderScripts(string $hook = ''): bool
    {
        if ($this->isToolPage($hook)) {
            return true;
        }

        return $this->isOxygenBuilderRequest();
    }

    /**
     * @param string $hook
     */
    public function isToolPage(string $hook = ''): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : '';

        if ($page === 'oxy-html-converter-tool' || $page === 'oxy-html-converter') {
            return true;
        }

        return in_array($hook, [
            'tools_page_oxy-html-converter-tool',
            'oxygen_page_oxy-html-converter',
            'oxygen_admin_page_oxy-html-converter',
        ], true);
    }

    public function isOxygenBuilderRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
        $oxygenParam = isset($_GET['oxygen']) ? sanitize_text_field(wp_unslash((string) $_GET['oxygen'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
        $breakdanceParam = isset($_GET['breakdance']) ? sanitize_text_field(wp_unslash((string) $_GET['breakdance'])) : '';

        $isBuilderLoader = $oxygenParam === 'builder'
            || $breakdanceParam === 'builder'
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
            || isset($_GET['ct_builder']);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
        $isBuilderIframe = !empty($_GET['breakdance_iframe'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
            || !empty($_GET['oxygen_iframe'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for asset bootstrapping.
            || !empty($_GET['breakdance_gutenberg_iframe'])
            || (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME);

        return $isBuilderLoader || $isBuilderIframe;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUiConfig(): array
    {
        return $this->uiConfigProvider->getConfig();
    }
}
