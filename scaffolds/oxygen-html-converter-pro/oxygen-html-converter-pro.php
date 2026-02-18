<?php
/**
 * Plugin Name: Oxygen HTML Converter Pro
 * Description: Premium add-on for Oxygen HTML Converter core.
 * Version: 0.1.0
 * Author: Kamil Skicki
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OXY_HTML_CONVERTER_PRO_VERSION', '0.1.0');
define('OXY_HTML_CONVERTER_PRO_PATH', plugin_dir_path(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'OxyHtmlConverterPro\\';
    $baseDir = OXY_HTML_CONVERTER_PRO_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

add_action('plugins_loaded', function () {
    $coreActive = defined('OXY_HTML_CONVERTER_IS_CORE') || class_exists('\\OxyHtmlConverter\\Plugin');
    if (!$coreActive) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>Oxygen HTML Converter Pro requires Oxygen HTML Converter (Core).</p></div>';
        });
        return;
    }

    \OxyHtmlConverterPro\Plugin::getInstance();
});
