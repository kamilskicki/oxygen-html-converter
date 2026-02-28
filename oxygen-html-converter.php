<?php
/**
 * Plugin Name: Oxygen HTML Converter
 * Description: Convert HTML to native Oxygen Builder elements. Paste entire HTML pages and edit them natively in Oxygen 6.
 * Version: 0.8.0-beta
 * Author: Kamil Skicki
 * Author URI: https://kamilskicki.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oxygen-html-converter
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('OXY_HTML_CONVERTER_VERSION', '0.8.0-beta');
define('OXY_HTML_CONVERTER_PATH', plugin_dir_path(__FILE__));
define('OXY_HTML_CONVERTER_URL', plugin_dir_url(__FILE__));
define('OXY_HTML_CONVERTER_IS_CORE', true);
define('OXY_HTML_CONVERTER_API_VERSION', '1.0.0');

// Load PHP 7.4 polyfills for PHP 8.0+ functions
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

// Initialize plugin
add_action('plugins_loaded', function () {
    do_action('oxy_html_converter_before_boot');

    // Check if Oxygen Builder is active.
    // Oxygen 6 is built on Breakdance and defines both __BREAKDANCE_PLUGIN_FILE__
    // and BREAKDANCE_MODE='oxygen'. Keep legacy checks for older Oxygen installs.
    $oxygenActive = (defined('__BREAKDANCE_PLUGIN_FILE__') &&
                     defined('BREAKDANCE_MODE') &&
                     BREAKDANCE_MODE === 'oxygen') ||
                    defined('CT_VERSION') ||
                    class_exists('\\OxygenElements\\Container');
    
    if (!$oxygenActive) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>Oxygen HTML Converter requires Oxygen Builder 6 to be active.</p></div>';
        });
        return;
    }

    // Initialize main plugin class
    $plugin = \OxyHtmlConverter\Plugin::getInstance();
    do_action('oxy_html_converter_loaded', $plugin);
});
