<?php

namespace OxyHtmlConverter;

/**
 * Main plugin class
 */
class Plugin
{
    private static ?Plugin $instance = null;

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

        wp_localize_script('oxy-html-converter', 'oxyHtmlConverter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
        ]);
    }

    private function isOxygenBuilder(): bool
    {
        // Broad detection for Oxygen 6 / Breakdance environment
        return isset($_GET['oxygen']) ||
               isset($_GET['ct_builder']) ||
               isset($_GET['breakdance_iframe']) ||
               isset($_GET['oxygen_iframe']) ||
               (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME) ||
               (defined('BREAKDANCE_MODE') && BREAKDANCE_MODE === 'oxygen' && !is_admin());
    }
}
