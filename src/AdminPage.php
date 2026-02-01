<?php

namespace OxyHtmlConverter;

/**
 * Admin page for HTML conversion
 */
class AdminPage
{
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
    }

    /**
     * Add admin menu page
     */
    public function addMenuPage(): void
    {
        add_submenu_page(
            'oxygen_admin', // Parent slug (Oxygen's admin menu)
            'HTML Converter',
            'HTML Converter',
            'edit_posts',
            'oxy-html-converter',
            [$this, 'renderPage']
        );

        // Also add under Tools menu as fallback
        add_management_page(
            'Oxygen HTML Converter',
            'Oxygen HTML Converter',
            'edit_posts',
            'oxy-html-converter-tool',
            [$this, 'renderPage']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, ['oxygen_page_oxy-html-converter', 'tools_page_oxy-html-converter-tool'])) {
            return;
        }

        wp_enqueue_style(
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/css/admin.css',
            [],
            OXY_HTML_CONVERTER_VERSION
        );

        wp_enqueue_script(
            'oxy-html-converter-admin',
            OXY_HTML_CONVERTER_URL . 'assets/js/admin.js',
            ['jquery'],
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
        $classMode = get_option('oxy_html_converter_class_mode', 'auto');
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

                    <?php submit_button('Save Settings', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="oxy-converter-container">
                <div class="oxy-converter-input">
                    <h2>HTML Input</h2>
                    <textarea id="oxy-html-input" placeholder="Paste your HTML here..."></textarea>

                    <div class="oxy-converter-options">
                        <label>
                            <input type="checkbox" id="oxy-wrap-container" checked>
                            Wrap in container element
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
                        <tr><td><code>&lt;img&gt;</code></td><td>Image</td></tr>
                        <tr><td><code>&lt;iframe&gt;</code>, <code>&lt;svg&gt;</code>, <code>&lt;form&gt;</code></td><td>HTML Code</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
