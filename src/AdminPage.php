<?php

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\EnvironmentService;

/**
 * Admin page for HTML conversion
 */
class AdminPage
{
    /**
     * Capability required to access converter UI and actions.
     */
    private function getRequiredCapability(): string
    {
        $capability = 'manage_options';

        /**
         * Filter required capability for the converter.
         *
         * @param string $capability Default capability (`manage_options`).
         */
        return (string) apply_filters('oxy_html_converter_required_capability', $capability);
    }

    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Register plugin settings
     */
    public function registerSettings(): void
    {
        register_setting('oxy_html_converter_options', 'oxy_html_converter_class_mode', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'auto',
        ]);

        register_setting('oxy_html_converter_options', 'oxy_html_converter_element_mapping_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeElementMappingMode'],
            'default' => 'auto',
        ]);
    }

    /**
     * Sanitize element mapping mode option.
     */
    public function sanitizeElementMappingMode($value): string
    {
        $value = sanitize_text_field($value);
        if (!in_array($value, ['auto', 'oxygen', 'essential'], true)) {
            return 'auto';
        }

        return $value;
    }

    /**
     * Add admin menu page
     */
    public function addMenuPage(): void
    {
        $capability = $this->getRequiredCapability();

        add_submenu_page(
            'oxygen', // Oxygen 6 parent slug
            'HTML Converter',
            'HTML Converter',
            $capability,
            'oxy-html-converter',
            [$this, 'renderPage']
        );

        // Also add under Tools menu as fallback
        add_management_page(
            'Oxygen HTML Converter',
            'Oxygen HTML Converter',
            $capability,
            'oxy-html-converter-tool',
            [$this, 'renderPage']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, [
            'oxygen_page_oxy-html-converter',
            'oxygen_admin_page_oxy-html-converter', // legacy compatibility
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
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/js/admin.js',
            ['jquery', 'oxy-html-converter-presets'],
            OXY_HTML_CONVERTER_VERSION,
            true
        );

        wp_localize_script('oxy-html-converter-admin', 'oxyHtmlConverterAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oxy_html_converter'),
        ]);
    }

    /**
     * Render admin page
     */
    public function renderPage(): void
    {
        if (!current_user_can($this->getRequiredCapability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oxygen-html-converter'));
        }

        $classMode = get_option('oxy_html_converter_class_mode', 'auto');
        $elementMappingMode = get_option('oxy_html_converter_element_mapping_mode', 'auto');
        $environment = new EnvironmentService();

        $isEssentialPluginActive = $environment->isBreakdanceElementsForOxygenActive();
        $isEssentialContractCompatible = $environment->isEssentialButtonContractCompatible();
        $effectiveButtonMapping = $environment->shouldPreferEssentialElements() ? 'essential' : 'oxygen';
        $contractIssues = $isEssentialPluginActive ? $environment->getEssentialButtonContractIssues() : [];

        $contractStatusText = 'Not checked';
        $contractStatusColor = '#6c757d';
        if ($isEssentialPluginActive) {
            if ($isEssentialContractCompatible) {
                $contractStatusText = 'Compatible';
                $contractStatusColor = '#2e7d32';
            } else {
                $contractStatusText = 'Incompatible';
                $contractStatusColor = '#c62828';
            }
        }
        ?>
        <div class="wrap oxy-html-converter-wrap">
            <h1>Oxygen HTML Converter</h1>
            <p class="description">Convert HTML to native Oxygen Builder elements. Paste your HTML below and click Convert.</p>

            <div class="oxy-settings-bar" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <form method="post" action="options.php" style="display: flex; align-items: center; gap: 20px;">
                    <?php settings_fields('oxy_html_converter_options'); ?>
                    
                    <div class="setting-field">
                        <label for="oxy_html_converter_class_mode" style="font-weight: 600; margin-right: 10px;">Class Handling Mode:</label>
                        <select name="oxy_html_converter_class_mode" id="oxy_html_converter_class_mode">
                            <option value="auto" <?php selected($classMode, 'auto'); ?>>Auto-detect (WindPress if available, otherwise Native)</option>
                            <option value="windpress" <?php selected($classMode, 'windpress'); ?>>Force WindPress Mode (Keep all classes)</option>
                            <option value="native" <?php selected($classMode, 'native'); ?>>Force Oxygen Native Mode (Convert Tailwind to properties)</option>
                        </select>
                    </div>

                    <div class="setting-field">
                        <label for="oxy_html_converter_element_mapping_mode" style="font-weight: 600; margin-right: 10px;">Button Mapping Mode:</label>
                        <select name="oxy_html_converter_element_mapping_mode" id="oxy_html_converter_element_mapping_mode">
                            <option value="auto" <?php selected($elementMappingMode, 'auto'); ?>>Auto-detect (Use Essential button when available)</option>
                            <option value="oxygen" <?php selected($elementMappingMode, 'oxygen'); ?>>Force Oxygen Button Mapping</option>
                            <option value="essential" <?php selected($elementMappingMode, 'essential'); ?>>Force EssentialElements Button Mapping</option>
                        </select>
                    </div>

                    <?php submit_button('Save Settings', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="oxy-contract-health" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;">Contract Health</h2>
                <p class="description" style="margin-top: 0;">
                    Compatibility status for builder element contracts used by the converter.
                </p>

                <table class="widefat striped" style="max-width: 960px;">
                    <tbody>
                        <tr>
                            <td style="width: 280px;"><strong>Configured Button Mode</strong></td>
                            <td><code><?php echo esc_html($elementMappingMode); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Effective Button Mapping</strong></td>
                            <td>
                                <code><?php echo esc_html($effectiveButtonMapping); ?></code>
                                <?php if ($effectiveButtonMapping !== $elementMappingMode && $elementMappingMode !== 'auto'): ?>
                                    <span style="margin-left: 8px; color: #c62828;">(fallback applied)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Breakdance Elements for Oxygen</strong></td>
                            <td style="color: <?php echo $isEssentialPluginActive ? '#2e7d32' : '#c62828'; ?>;">
                                <?php echo $isEssentialPluginActive ? 'Detected' : 'Not detected'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Essential Button Contract</strong></td>
                            <td style="color: <?php echo esc_attr($contractStatusColor); ?>;">
                                <?php echo esc_html($contractStatusText); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!empty($contractIssues)): ?>
                    <div style="margin-top: 12px; padding: 12px; border: 1px solid #f3b3b3; background: #fff5f5; border-radius: 4px;">
                        <strong>Contract Issues</strong>
                        <ul style="margin: 8px 0 0 18px;">
                            <?php foreach ($contractIssues as $issue): ?>
                                <li><?php echo esc_html($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif ($isEssentialPluginActive && $isEssentialContractCompatible): ?>
                    <div style="margin-top: 12px; padding: 12px; border: 1px solid #b8e6be; background: #f2fff4; border-radius: 4px;">
                        <strong>All required Essential button contract checks passed.</strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="oxy-converter-container">
                <div class="oxy-converter-input">
                    <h2>HTML Input</h2>
                    <textarea id="oxy-html-input" placeholder="Paste your HTML here..."></textarea>

                    <div class="oxy-converter-options">
                        <div class="oxy-converter-preset">
                            <label for="oxy-convert-preset">Conversion Preset</label>
                            <select id="oxy-convert-preset">
                                <option value="balanced" selected>Balanced (Recommended)</option>
                                <option value="safe">Safe Import</option>
                                <option value="fidelity">Max Fidelity</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <label>
                            <input type="checkbox" id="oxy-wrap-container" checked>
                            Wrap in container element
                        </label>
                        <label>
                            <input type="checkbox" id="oxy-include-css" checked>
                            Include extracted CSS Code element
                        </label>
                        <label>
                            <input type="checkbox" id="oxy-inline-styles" checked>
                            Apply inline/class styles to Oxygen design properties
                        </label>
                        <label>
                            <input type="checkbox" id="oxy-safe-mode">
                            Safe mode (strip scripts, event handlers, and external head assets)
                        </label>
                    </div>

                    <div class="oxy-converter-actions">
                        <button type="button" id="oxy-preview-btn" class="button">Preview</button>
                        <button type="button" id="oxy-convert-btn" class="button button-primary">Convert</button>
                        <button type="button" id="oxy-copy-btn" class="button" disabled>Copy to Clipboard</button>
                    </div>
                </div>

                <div class="oxy-converter-output">
                    <h2>Output</h2>

                    <div id="oxy-preview-result" class="oxy-preview-box" style="display: none;">
                        <h3>Preview Summary</h3>
                        <div id="oxy-preview-content"></div>
                    </div>

                    <div id="oxy-json-result" class="oxy-json-box" style="display: none;">
                        <h3>Oxygen JSON <span class="oxy-json-status"></span></h3>
                        
                        <div id="oxy-report-summary" class="oxy-report-summary" style="margin-bottom: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; display: none;">
                            <h4>Conversion Report</h4>
                            <div class="report-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px;">
                                <div class="stat-item"><strong>Elements:</strong> <span id="report-elements">0</span></div>
                                <div class="stat-item"><strong>Tailwind:</strong> <span id="report-tailwind">0</span></div>
                                <div class="stat-item"><strong>Custom Classes:</strong> <span id="report-custom">0</span></div>
                            </div>
                            <div id="report-warnings" class="report-section" style="margin-bottom: 10px;">
                                <strong>Warnings:</strong>
                                <ul style="margin: 5px 0; color: #d9534f; font-size: 13px;"></ul>
                            </div>
                            <div id="report-info" class="report-section">
                                <strong>Optimizations:</strong>
                                <ul style="margin: 5px 0; color: #5bc0de; font-size: 13px;"></ul>
                            </div>
                        </div>

                        <textarea id="oxy-json-output" readonly></textarea>
                    </div>

                    <div id="oxy-error-result" class="oxy-error-box" style="display: none;">
                        <h3>Error</h3>
                        <div id="oxy-error-content"></div>
                    </div>
                </div>
            </div>

            <div class="oxy-converter-instructions">
                <h2>How to Use</h2>
                <ol>
                    <li>Paste your HTML code in the input area</li>
                    <li>Click "Preview" to see what will be converted</li>
                    <li>Click "Convert" to generate Oxygen JSON</li>
                    <li>Click "Copy to Clipboard" to copy the result</li>
                    <li>In Oxygen Builder, press <kbd>Ctrl</kbd>+<kbd>V</kbd> (or <kbd>Cmd</kbd>+<kbd>V</kbd>) to paste</li>
                </ol>

                <h3>Supported Elements</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>HTML Element</th>
                            <th>Oxygen Element</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>&lt;div&gt;</code>, <code>&lt;section&gt;</code>, <code>&lt;article&gt;</code>, etc.</td><td>Container</td></tr>
                        <tr><td><code>&lt;p&gt;</code>, <code>&lt;h1&gt;</code>-<code>&lt;h6&gt;</code>, <code>&lt;span&gt;</code></td><td>Text</td></tr>
                        <tr><td><code>&lt;ul&gt;</code>, <code>&lt;ol&gt;</code>, <code>&lt;table&gt;</code></td><td>Rich Text</td></tr>
                        <tr><td><code>&lt;a&gt;</code></td><td>Text Link</td></tr>
                        <tr><td><code>&lt;button&gt;</code></td><td>Container or Essential Button (mode-dependent)</td></tr>
                        <tr><td><code>&lt;img&gt;</code></td><td>Image</td></tr>
                        <tr><td><code>&lt;iframe&gt;</code>, <code>&lt;svg&gt;</code>, <code>&lt;form&gt;</code></td><td>HTML Code</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
