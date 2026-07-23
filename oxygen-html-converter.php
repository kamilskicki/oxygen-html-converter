<?php
/**
 * Plugin Name: Oxygen HTML Converter
 * Description: Convert HTML to native Oxygen Builder elements. Paste entire HTML pages and edit them natively in Oxygen 6.
 * Version: 0.9.0-beta
 * Author: Kamil Skicki
 * Author URI: https://kamilskicki.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oxygen-html-converter
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('OXY_HTML_CONVERTER_VERSION', '0.9.0-beta');
define('OXY_HTML_CONVERTER_PATH', plugin_dir_path(__FILE__));
define('OXY_HTML_CONVERTER_URL', plugin_dir_url(__FILE__));
define('OXY_HTML_CONVERTER_IS_CORE', true);
define('OXY_HTML_CONVERTER_API_VERSION', '1.0.0');

// Load compatibility helpers used by the converter runtime.
require_once OXY_HTML_CONVERTER_PATH . 'src/polyfills.php';

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'OxyHtmlConverter\\';
    $base_dir = OXY_HTML_CONVERTER_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Render the missing Oxygen dependency notice for plugin administrators.
 */
function oxy_html_converter_render_missing_oxygen_notice(?string $detected_version = null): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $message = $detected_version === null
        ? __('Oxygen HTML Converter requires Oxygen Builder 6.1.0 or newer to be active.', 'oxygen-html-converter')
        : sprintf(
            /* translators: %s: detected Oxygen Builder version. */
            __('Oxygen HTML Converter requires Oxygen Builder 6.1.0 or newer. Detected version: %s.', 'oxygen-html-converter'),
            $detected_version
        );

    echo '<div class="notice notice-error is-dismissible"><p>'
        . esc_html($message)
        . '</p></div>';
}

// Initialize plugin
add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'oxygen-html-converter',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    do_action('oxy_html_converter_before_boot');

    // Oxygen 6.1 exposes __BREAKDANCE_VERSION while classic Oxygen exposes CT_VERSION.
    // A class marker alone has no trustworthy version provenance and must not boot Core.
    $modernOxygen = defined('__BREAKDANCE_PLUGIN_FILE__')
        && defined('BREAKDANCE_MODE')
        && BREAKDANCE_MODE === 'oxygen';
    $legacyOxygen = defined('CT_VERSION');
    $oxygenVersion = \OxyHtmlConverter\Services\OxygenStorageAdapterFactory::detectRuntimeOxygenVersion();
    $oxygenActive = ($modernOxygen || $legacyOxygen)
        && $oxygenVersion !== null
        && version_compare($oxygenVersion, '6.1.0', '>=');

    if (!$oxygenActive) {
        add_action('admin_notices', static function () use ($oxygenVersion): void {
            oxy_html_converter_render_missing_oxygen_notice($oxygenVersion);
        });
        return;
    }

    // Initialize main plugin class
    $plugin = \OxyHtmlConverter\Plugin::getInstance();
    do_action('oxy_html_converter_loaded', $plugin);
});
