<?php
/**
 * Uninstall cleanup for Oxygen HTML Converter.
 *
 * This removes only plugin-owned options discovered in src/ under the
 * oxy_html_converter_* prefix.
 *
 * Imported content must survive uninstall. Do not delete Oxygen Builder or
 * Breakdance-owned data such as oxygen_oxy_selectors_json_string,
 * oxygen_oxy_selectors_collections_json_string, oxygen_variables_json_string,
 * oxygen_variables_collections_json_string, oxygen_global_settings_json_string,
 * breakdance_classes_json_string, _oxygen_data, Oxygen templates, imported
 * pages, or plugin import/rollback post meta. Those records represent user
 * content or host-builder storage, not disposable plugin settings.
 * Converter page CSS in _oxy_html_converter_page_styles is also retained, but
 * the plugin emits that CSS at runtime. Pages that rely on it can lose styling
 * after deactivation or uninstall unless the CSS is migrated first.
 *
 * No plugin-owned transients were found in src/ for this release. The
 * oxymade_selectors_option_cache transient is owned by the host/OxyMade
 * integration path and is intentionally retained here.
 *
 * @package OxygenHtmlConverter
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$oxy_html_converter_delete_owned_data = static function (): void {
    $oxy_html_converter_options = [
        'oxy_html_converter_class_mode',
        'oxy_html_converter_element_mapping_mode',
        'oxy_html_converter_heuristics',
        'oxy_html_converter_brand_library',
        'oxy_html_converter_global_styles',
        'oxy_html_converter_cache_refresh_notice',
    ];

    foreach ($oxy_html_converter_options as $oxy_html_converter_option) {
        delete_option($oxy_html_converter_option);
    }

    $oxy_html_converter_transients = [];

    foreach ($oxy_html_converter_transients as $oxy_html_converter_transient) {
        delete_transient($oxy_html_converter_transient);
    }
};

if (is_multisite() && function_exists('get_sites') && function_exists('switch_to_blog') && function_exists('restore_current_blog')) {
    $oxy_html_converter_site_ids = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);

    foreach ($oxy_html_converter_site_ids as $oxy_html_converter_site_id) {
        switch_to_blog((int) $oxy_html_converter_site_id);
        $oxy_html_converter_delete_owned_data();
        restore_current_blog();
    }
} else {
    $oxy_html_converter_delete_owned_data();
}
