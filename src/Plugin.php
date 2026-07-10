<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\GlobalStyleRepository;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\PageStyleRepository;
use OxyHtmlConverter\Services\UiConfigProvider;

/**
 * Main plugin class
 */
class Plugin
{
    private static ?Plugin $instance = null;
    private UiConfigProvider $uiConfigProvider;
    private GlobalStyleRepository $globalStyleRepository;
    private PageStyleRepository $pageStyleRepository;

    /**
     * @var array<string, bool>
     */
    private array $featureFlags = [
        'core' => true,
        'pro' => false,
        'batch_convert' => true,
        'preview' => true,
        'tailwind_native_mapping' => true,
        'tailwind_fallback_css' => true,
        'tailwind_runtime_integration' => false,
        'windpress_integration' => false,
        'windpress_class_mode' => false,
        'windpress_cache_reset' => false,
    ];

    public static function getInstance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct(
        ?UiConfigProvider $uiConfigProvider = null,
        ?GlobalStyleRepository $globalStyleRepository = null,
        ?PageStyleRepository $pageStyleRepository = null
    ) {
        $this->uiConfigProvider = $uiConfigProvider ?: new UiConfigProvider();
        $this->globalStyleRepository = $globalStyleRepository ?: new GlobalStyleRepository();
        $this->pageStyleRepository = $pageStyleRepository ?: new PageStyleRepository();
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
        add_action('wp_enqueue_scripts', [$this, 'enqueueGlobalStyles'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueGlobalStyles'], 20);
        add_action('wp_footer', [$this, 'printPageScopedStyles'], 9999);
        add_action('admin_footer', [$this, 'printPageScopedStyles'], 9999);
        add_action('admin_notices', [$this, 'printCacheRefreshNotice']);
    }

    public function enqueueGlobalStyles(string $hook = ''): void
    {
        if (!$this->shouldEnqueueGlobalStyles($hook)) {
            return;
        }

        $css = trim($this->globalStyleRepository->getCombinedCss());

        if ($css === '') {
            return;
        }

        if (function_exists('wp_register_style')) {
            wp_register_style(
                'oxy-html-converter-global-styles',
                false,
                [],
                OXY_HTML_CONVERTER_VERSION
            );
        }

        wp_enqueue_style(
            'oxy-html-converter-global-styles',
            false,
            [],
            OXY_HTML_CONVERTER_VERSION
        );

        if (function_exists('wp_add_inline_style')) {
            wp_add_inline_style('oxy-html-converter-global-styles', $css);
        }
    }

    public function printPageScopedStyles(): void
    {
        if (!$this->shouldRenderImportedStylesInCurrentRequest()) {
            return;
        }

        $postId = $this->resolveCurrentPostId();
        $css = $postId > 0 ? trim($this->pageStyleRepository->getCssForPost($postId)) : '';

        if ($css === '') {
            return;
        }

        $css = str_replace('</style', '<\/style', $css);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated CSS is not HTML; closing style tags are neutralized above.
        echo "\n<style id=\"oxy-html-converter-page-styles-inline-css\">\n" . $css . "\n</style>\n";
    }

    public function printCacheRefreshNotice(): void
    {
        if (!function_exists('get_option')) {
            return;
        }

        $notice = get_option(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION, []);
        if (!is_array($notice) || !is_string($notice['message'] ?? null) || trim($notice['message']) === '') {
            return;
        }

        if (function_exists('delete_option')) {
            delete_option(OxygenPageImporter::CACHE_REFRESH_NOTICE_OPTION);
        }

        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html($notice['message'])
            . '</p></div>';
    }

    public function shouldEnqueueGlobalStyles(string $hook = ''): bool
    {
        unset($hook);

        return $this->shouldRenderImportedStylesInCurrentRequest();
    }

    private function shouldRenderImportedStylesInCurrentRequest(): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return $this->isOxygenBuilderRequest();
        }

        return true;
    }

    private function resolveCurrentPostId(): int
    {
        if (function_exists('get_queried_object_id')) {
            $postId = (int) get_queried_object_id();
            if ($postId > 0) {
                return $postId;
            }
        }

        foreach (['post', 'post_id', 'id', 'page_id'] as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for scoped CSS.
            if (!isset($_GET[$key])) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for scoped CSS.
            $postId = absint(wp_unslash((string) $_GET[$key]));
            if ($postId > 0) {
                return $postId;
            }
        }

        return 0;
    }

    /**
     * @param string $hook
     */
    public function enqueueBuilderScripts(string $hook = ''): void
    {
        if (!$this->shouldEnqueueBuilderScripts($hook) || wp_script_is('oxy-html-converter', 'enqueued')) {
            return;
        }

        $loadInFooter = !$this->isOxygenBuilderRequest();

        wp_enqueue_script(
            'oxy-html-converter-options',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/converter-options.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-clipboard-utils',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/clipboard-utils.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-client',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-client.js',
            ['oxy-html-converter-options'],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-paste',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-paste.js',
            ['oxy-html-converter-clipboard-utils'],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-toast',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-toast.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-modal',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-modal.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        wp_enqueue_script(
            'oxy-html-converter-builder-editability',
            OXY_HTML_CONVERTER_URL . 'assets/js/lib/builder-editability.js',
            [],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
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
                'oxy-html-converter-builder-editability',
            ],
            OXY_HTML_CONVERTER_VERSION,
            $loadInFooter
        );

        $scriptData = apply_filters('oxy_html_converter_builder_script_data', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
            'features' => $this->uiConfigProvider->getFeatureFlags($this->featureFlags),
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
