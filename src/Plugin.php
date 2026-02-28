<?php

namespace OxyHtmlConverter;

/**
 * Main plugin class
 */
class Plugin
{
    private static ?Plugin $instance = null;
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

    private function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Register AJAX handlers
        new Ajax();

        // Register admin page
        new AdminPage();

        /**
         * Fired after core services are registered.
         *
         * Pro add-ons should hook here to register integrations.
         */
        do_action('oxy_html_converter_core_init', $this);

        // Enqueue builder scripts on multiple hooks to ensure it catches the builder environment
        add_action('wp_enqueue_scripts', [$this, 'enqueueBuilderScripts'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueBuilderScripts'], 9999);
        add_action('wp_footer', [$this, 'enqueueBuilderScripts'], 9999);
    }

    public function enqueueBuilderScripts(): void
    {
        // If we've already enqueued, don't do it again
        if (wp_script_is('oxy-html-converter', 'enqueued')) {
            return;
        }

        // Detect Oxygen 6 / Breakdance Builder or our manual tool page
        $isBuilder = $this->isOxygenBuilder();
        $isToolPage = isset($_GET['page']) && $_GET['page'] === 'oxy-html-converter-tool';

        if (!$isBuilder && !$isToolPage) {
            return;
        }

        wp_enqueue_script(
            'oxy-html-converter',
            OXY_HTML_CONVERTER_URL . 'assets/js/converter.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        $scriptData = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
            'features' => apply_filters('oxy_html_converter_feature_flags', $this->featureFlags),
            'apiVersion' => OXY_HTML_CONVERTER_API_VERSION,
        ];

        $scriptData = apply_filters('oxy_html_converter_builder_script_data', $scriptData);
        wp_localize_script('oxy-html-converter', 'oxyHtmlConverter', $scriptData);

        do_action('oxy_html_converter_after_enqueue_builder_scripts', $scriptData);
    }

    private function isOxygenBuilder(): bool
    {
        $oxygenParam = isset($_GET['oxygen']) ? (string) $_GET['oxygen'] : '';
        $breakdanceParam = isset($_GET['breakdance']) ? (string) $_GET['breakdance'] : '';

        // Oxygen/Breakdance builder loader route: ?oxygen=builder&id=...
        $isBuilderLoader = $oxygenParam === 'builder' ||
                           $breakdanceParam === 'builder' ||
                           isset($_GET['ct_builder']);

        // Builder iframe requests include one of these flags.
        $isBuilderIframe = !empty($_GET['breakdance_iframe']) ||
                           !empty($_GET['oxygen_iframe']) ||
                           !empty($_GET['breakdance_gutenberg_iframe']) ||
                           (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME);

        return $isBuilderLoader || $isBuilderIframe;
    }
}
