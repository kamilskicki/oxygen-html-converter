<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\EnvironmentService;

/**
 * Admin page for HTML conversion.
 */
class AdminPage
{
    private function getRequiredCapability(): string
    {
        $capability = 'manage_options';

        return (string) apply_filters('oxy_html_converter_required_capability', $capability);
    }

    public function __construct()
    {
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
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/js/admin.js',
            ['jquery', 'oxy-html-converter-presets', 'oxy-html-converter-options'],
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
        $environment = new EnvironmentService();
        $ui = $this->getUiConfig();

        $isEssentialPluginActive = $environment->isBreakdanceElementsForOxygenActive();
        $isEssentialContractCompatible = $environment->isEssentialButtonContractCompatible();
        $effectiveButtonMapping = $environment->shouldPreferEssentialElements() ? 'essential' : 'oxygen';
        $contractIssues = $isEssentialPluginActive ? $environment->getEssentialButtonContractIssues() : [];

        $contractStatusText = __('Not checked', 'oxygen-html-converter');
        $contractStatusClass = 'is-neutral';

        if ($isEssentialPluginActive) {
            if ($isEssentialContractCompatible) {
                $contractStatusText = __('Compatible', 'oxygen-html-converter');
                $contractStatusClass = 'is-success';
            } else {
                $contractStatusText = __('Incompatible', 'oxygen-html-converter');
                $contractStatusClass = 'is-danger';
            }
        }
        ?>
        <div class="wrap oxy-html-converter-wrap">
            <section class="oxy-hero-card">
                <div class="oxy-hero-copy">
                    <p class="oxy-eyebrow"><?php echo esc_html__('v1.0 hardening workflow', 'oxygen-html-converter'); ?></p>
                    <h1><?php echo esc_html__('Oxygen HTML Converter', 'oxygen-html-converter'); ?></h1>
                    <p class="oxy-hero-description">
                        <?php echo esc_html__('Paste supported HTML, inspect the conversion audit, and import builder-safe Oxygen output without manual JSON surgery.', 'oxygen-html-converter'); ?>
                    </p>
                </div>
                <div class="oxy-hero-links">
                    <a class="button button-secondary" href="<?php echo esc_url($ui['docs']['supportedScope']); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html__('Supported scope', 'oxygen-html-converter'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($ui['docs']['readme']); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html__('Readme', 'oxygen-html-converter'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($ui['docs']['releaseChecklist']); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html__('Release checklist', 'oxygen-html-converter'); ?></a>
                </div>
            </section>

            <section class="oxy-banner-card">
                <div>
                    <strong><?php echo esc_html__('First import?', 'oxygen-html-converter'); ?></strong>
                    <p><?php echo esc_html__('Start with the sample hero snippet, run Preview, then Convert. Use Safe Mode when you want the most defensive import path.', 'oxygen-html-converter'); ?></p>
                </div>
                <div class="oxy-banner-actions">
                    <button type="button" id="oxy-load-example-btn" class="button button-primary"><?php echo esc_html__('Load sample HTML', 'oxygen-html-converter'); ?></button>
                    <span class="oxy-shortcut-pill"><?php echo esc_html__('Shortcut: Ctrl/Cmd + Enter to convert', 'oxygen-html-converter'); ?></span>
                </div>
            </section>

            <div class="oxy-workspace">
                <div class="oxy-main-column">
                    <section class="oxy-card oxy-step-card">
                        <div class="oxy-section-header">
                            <span class="oxy-step-number">1</span>
                            <div>
                                <h2><?php echo esc_html__('Input', 'oxygen-html-converter'); ?></h2>
                                <p><?php echo esc_html__('Paste full sections, landing pages, or builder-safe fragments. Preview first if the source includes scripts, utility classes, or extracted CSS.', 'oxygen-html-converter'); ?></p>
                            </div>
                        </div>

                        <label class="screen-reader-text" for="oxy-html-input"><?php echo esc_html__('HTML input', 'oxygen-html-converter'); ?></label>
                        <textarea id="oxy-html-input" placeholder="<?php echo esc_attr__('Paste your HTML here…', 'oxygen-html-converter'); ?>"></textarea>

                        <div class="oxy-options-grid">
                            <div class="oxy-option-row oxy-option-row-preset">
                                <label for="oxy-convert-preset"><?php echo esc_html__('Conversion preset', 'oxygen-html-converter'); ?></label>
                                <select id="oxy-convert-preset">
                                    <option value="balanced" selected><?php echo esc_html__('Balanced (recommended)', 'oxygen-html-converter'); ?></option>
                                    <option value="safe"><?php echo esc_html__('Safe import', 'oxygen-html-converter'); ?></option>
                                    <option value="fidelity"><?php echo esc_html__('Max fidelity', 'oxygen-html-converter'); ?></option>
                                    <option value="custom"><?php echo esc_html__('Custom', 'oxygen-html-converter'); ?></option>
                                </select>
                            </div>
                            <label class="oxy-toggle">
                                <input type="checkbox" id="oxy-wrap-container" checked>
                                <span><?php echo esc_html__('Wrap output in a container element', 'oxygen-html-converter'); ?></span>
                            </label>
                            <label class="oxy-toggle">
                                <input type="checkbox" id="oxy-include-css" checked>
                                <span><?php echo esc_html__('Include extracted CSS as a code element', 'oxygen-html-converter'); ?></span>
                            </label>
                            <label class="oxy-toggle">
                                <input type="checkbox" id="oxy-inline-styles" checked>
                                <span><?php echo esc_html__('Map inline and supported class styles into Oxygen design properties', 'oxygen-html-converter'); ?></span>
                            </label>
                            <label class="oxy-toggle">
                                <input type="checkbox" id="oxy-safe-mode">
                                <span><?php echo esc_html__('Safe mode: remove scripts, event handlers, and external head assets', 'oxygen-html-converter'); ?></span>
                            </label>
                        </div>

                        <div class="oxy-action-bar">
                            <button type="button" id="oxy-preview-btn" class="button"><?php echo esc_html__('Preview', 'oxygen-html-converter'); ?></button>
                            <button type="button" id="oxy-convert-btn" class="button button-primary"><?php echo esc_html__('Convert', 'oxygen-html-converter'); ?></button>
                            <button type="button" id="oxy-copy-btn" class="button" disabled><?php echo esc_html__('Copy JSON', 'oxygen-html-converter'); ?></button>
                        </div>
                    </section>

                    <section class="oxy-card oxy-step-card">
                        <div class="oxy-section-header">
                            <span class="oxy-step-number">2</span>
                            <div>
                                <h2><?php echo esc_html__('Analysis', 'oxygen-html-converter'); ?></h2>
                                <p><?php echo esc_html__('Use Preview and Convert to inspect the builder impact before importing. The audit below highlights preserved assets, transformations, stripped constructs, and follow-up work.', 'oxygen-html-converter'); ?></p>
                            </div>
                        </div>

                        <div id="oxy-preview-result" class="oxy-preview-box" hidden>
                            <h3><?php echo esc_html__('Preview summary', 'oxygen-html-converter'); ?></h3>
                            <div id="oxy-preview-content"></div>
                        </div>

                        <div id="oxy-audit-summary" class="oxy-audit-box">
                            <div class="oxy-audit-header">
                                <h3><?php echo esc_html__('Conversion audit', 'oxygen-html-converter'); ?></h3>
                                <p><?php echo esc_html__('Run preview or convert to populate the audit.', 'oxygen-html-converter'); ?></p>
                            </div>
                            <div class="oxy-audit-grid">
                                <section class="oxy-audit-section">
                                    <h4><?php echo esc_html__('Preserved', 'oxygen-html-converter'); ?></h4>
                                    <ul id="oxy-audit-preserved" class="oxy-audit-list"><li><?php echo esc_html__('No audit data yet.', 'oxygen-html-converter'); ?></li></ul>
                                </section>
                                <section class="oxy-audit-section">
                                    <h4><?php echo esc_html__('Transformed', 'oxygen-html-converter'); ?></h4>
                                    <ul id="oxy-audit-transformed" class="oxy-audit-list"><li><?php echo esc_html__('No audit data yet.', 'oxygen-html-converter'); ?></li></ul>
                                </section>
                                <section class="oxy-audit-section">
                                    <h4><?php echo esc_html__('Stripped', 'oxygen-html-converter'); ?></h4>
                                    <ul id="oxy-audit-stripped" class="oxy-audit-list"><li><?php echo esc_html__('No audit data yet.', 'oxygen-html-converter'); ?></li></ul>
                                </section>
                                <section class="oxy-audit-section">
                                    <h4><?php echo esc_html__('Manual follow-up', 'oxygen-html-converter'); ?></h4>
                                    <ul id="oxy-audit-follow-up" class="oxy-audit-list"><li><?php echo esc_html__('No audit data yet.', 'oxygen-html-converter'); ?></li></ul>
                                </section>
                            </div>
                        </div>
                    </section>

                    <section class="oxy-card oxy-step-card">
                        <div class="oxy-section-header">
                            <span class="oxy-step-number">3</span>
                            <div>
                                <h2><?php echo esc_html__('Import output', 'oxygen-html-converter'); ?></h2>
                                <p><?php echo esc_html__('Copy the builder-safe JSON into Oxygen, or use the builder import flow directly from inside the editor with Ctrl+Shift+H.', 'oxygen-html-converter'); ?></p>
                            </div>
                        </div>

                        <div id="oxy-json-result" class="oxy-json-box" hidden>
                            <h3><?php echo esc_html__('Oxygen JSON', 'oxygen-html-converter'); ?> <span class="oxy-json-status"></span></h3>
                            <div id="oxy-report-summary" class="oxy-report-summary" hidden>
                                <div class="oxy-stat-grid">
                                    <div class="oxy-stat-item"><strong><?php echo esc_html__('Elements', 'oxygen-html-converter'); ?></strong><span id="report-elements">0</span></div>
                                    <div class="oxy-stat-item"><strong><?php echo esc_html__('Tailwind', 'oxygen-html-converter'); ?></strong><span id="report-tailwind">0</span></div>
                                    <div class="oxy-stat-item"><strong><?php echo esc_html__('Custom classes', 'oxygen-html-converter'); ?></strong><span id="report-custom">0</span></div>
                                </div>
                                <div id="report-warnings" class="oxy-report-panel" hidden>
                                    <strong><?php echo esc_html__('Warnings', 'oxygen-html-converter'); ?></strong>
                                    <ul></ul>
                                </div>
                                <div id="report-info" class="oxy-report-panel" hidden>
                                    <strong><?php echo esc_html__('Optimizations', 'oxygen-html-converter'); ?></strong>
                                    <ul></ul>
                                </div>
                            </div>
                            <label class="screen-reader-text" for="oxy-json-output"><?php echo esc_html__('Converted Oxygen JSON', 'oxygen-html-converter'); ?></label>
                            <textarea id="oxy-json-output" readonly></textarea>
                        </div>

                        <div id="oxy-error-result" class="oxy-error-box" hidden>
                            <h3><?php echo esc_html__('Action required', 'oxygen-html-converter'); ?></h3>
                            <div id="oxy-error-content"></div>
                        </div>
                    </section>
                </div>

                <aside class="oxy-sidebar-column">
                    <section class="oxy-card">
                        <h2><?php echo esc_html__('Import settings', 'oxygen-html-converter'); ?></h2>
                        <form method="post" action="options.php" class="oxy-settings-form">
                            <?php settings_fields('oxy_html_converter_options'); ?>
                            <label for="oxy_html_converter_class_mode"><?php echo esc_html__('Class handling mode', 'oxygen-html-converter'); ?></label>
                            <select name="oxy_html_converter_class_mode" id="oxy_html_converter_class_mode">
                                <option value="auto" <?php selected($classMode, 'auto'); ?>><?php echo esc_html__('Auto-detect (WindPress if available)', 'oxygen-html-converter'); ?></option>
                                <option value="windpress" <?php selected($classMode, 'windpress'); ?>><?php echo esc_html__('Force WindPress mode', 'oxygen-html-converter'); ?></option>
                                <option value="native" <?php selected($classMode, 'native'); ?>><?php echo esc_html__('Force native Oxygen mode', 'oxygen-html-converter'); ?></option>
                            </select>

                            <label for="oxy_html_converter_element_mapping_mode"><?php echo esc_html__('Button mapping mode', 'oxygen-html-converter'); ?></label>
                            <select name="oxy_html_converter_element_mapping_mode" id="oxy_html_converter_element_mapping_mode">
                                <option value="auto" <?php selected($elementMappingMode, 'auto'); ?>><?php echo esc_html__('Auto-detect', 'oxygen-html-converter'); ?></option>
                                <option value="oxygen" <?php selected($elementMappingMode, 'oxygen'); ?>><?php echo esc_html__('Force Oxygen button', 'oxygen-html-converter'); ?></option>
                                <option value="essential" <?php selected($elementMappingMode, 'essential'); ?>><?php echo esc_html__('Force Essential button', 'oxygen-html-converter'); ?></option>
                            </select>

                            <?php submit_button(__('Save settings', 'oxygen-html-converter'), 'secondary', 'submit', false); ?>
                        </form>
                    </section>

                    <section class="oxy-card">
                        <h2><?php echo esc_html__('Contract health', 'oxygen-html-converter'); ?></h2>
                        <dl class="oxy-health-list">
                            <div><dt><?php echo esc_html__('Configured button mode', 'oxygen-html-converter'); ?></dt><dd><code><?php echo esc_html($elementMappingMode); ?></code></dd></div>
                            <div><dt><?php echo esc_html__('Effective button mapping', 'oxygen-html-converter'); ?></dt><dd><code><?php echo esc_html($effectiveButtonMapping); ?></code></dd></div>
                            <div><dt><?php echo esc_html__('Breakdance Elements for Oxygen', 'oxygen-html-converter'); ?></dt><dd class="<?php echo $isEssentialPluginActive ? 'is-success' : 'is-danger'; ?>"><?php echo esc_html($isEssentialPluginActive ? __('Detected', 'oxygen-html-converter') : __('Not detected', 'oxygen-html-converter')); ?></dd></div>
                            <div><dt><?php echo esc_html__('Essential button contract', 'oxygen-html-converter'); ?></dt><dd class="<?php echo esc_attr($contractStatusClass); ?>"><?php echo esc_html($contractStatusText); ?></dd></div>
                        </dl>

                        <?php if (!empty($contractIssues)): ?>
                            <div class="oxy-callout is-danger">
                                <strong><?php echo esc_html__('Contract issues', 'oxygen-html-converter'); ?></strong>
                                <ul>
                                    <?php foreach ($contractIssues as $issue): ?>
                                        <li><?php echo esc_html($issue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php elseif ($isEssentialPluginActive && $isEssentialContractCompatible): ?>
                            <div class="oxy-callout is-success">
                                <strong><?php echo esc_html__('All required Essential button contract checks passed.', 'oxygen-html-converter'); ?></strong>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="oxy-card">
                        <h2><?php echo esc_html__('Supported scope', 'oxygen-html-converter'); ?></h2>
                        <ul class="oxy-plain-list">
                            <li><?php echo esc_html__('Single-page marketing and landing-page HTML', 'oxygen-html-converter'); ?></li>
                            <li><?php echo esc_html__('Inline styles and utility-first CSS markup', 'oxygen-html-converter'); ?></li>
                            <li><?php echo esc_html__('Common interactions such as nav toggles, anchor scroll, reveal-on-scroll, and counters', 'oxygen-html-converter'); ?></li>
                            <li><?php echo esc_html__('Builder-safe editability and save/reopen integrity are the release bar', 'oxygen-html-converter'); ?></li>
                        </ul>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string, mixed>
     */
    private function getUiConfig(): array
    {
        $baseDocsUrl = 'https://github.com/kamilskicki/oxygen-html-converter';

        $config = [
            'docs' => [
                'readme' => $baseDocsUrl . '#readme',
                'supportedScope' => $baseDocsUrl . '/blob/master/docs/SUPPORTED_SCOPE.md',
                'releaseChecklist' => $baseDocsUrl . '/blob/master/docs/RELEASE_CHECKLIST.md',
            ],
            'examples' => [
                'hero' => "<section class=\"hero\">\n  <h1>Build native Oxygen pages from HTML</h1>\n  <p>Paste full landing-page sections and keep them editable.</p>\n  <a href=\"#cta\" class=\"btn btn-primary\">Start importing</a>\n</section>",
            ],
        ];

        return (array) apply_filters('oxy_html_converter_ui_config', $config);
    }
}
