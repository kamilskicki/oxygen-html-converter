<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Define any constants needed by the plugin if necessary
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('OXY_HTML_CONVERTER_URL')) {
    define('OXY_HTML_CONVERTER_URL', 'http://example.test/wp-content/plugins/oxygen-html-converter/');
}

if (!defined('OXY_HTML_CONVERTER_VERSION')) {
    define('OXY_HTML_CONVERTER_VERSION', '1.0.0-test');
}

if (!defined('OXY_HTML_CONVERTER_API_VERSION')) {
    define('OXY_HTML_CONVERTER_API_VERSION', '1.0.0');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID;
        public string $post_type = 'page';
        public string $post_status = 'draft';
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data)
        {
            foreach ($data as $key => $value) {
                $key = (string) $key;

                if (!property_exists($this, $key)) {
                    continue;
                }

                if ($key === 'ID') {
                    $this->ID = (int) $value;
                } else {
                    $this->{$key} = (string) $value;
                }
            }
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private readonly string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'nav_menu';

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data)
        {
            foreach ($data as $key => $value) {
                $key = (string) $key;

                if (!property_exists($this, $key)) {
                    continue;
                }

                if ($key === 'term_id') {
                    $this->term_id = (int) $value;
                } else {
                    $this->{$key} = (string) $value;
                }
            }
        }
    }
}

// Minimal WordPress test stubs for pure unit tests.
$GLOBALS['__test_wp_filters'] = $GLOBALS['__test_wp_filters'] ?? [];
$GLOBALS['__wp_send_json_last'] = null;
$GLOBALS['__wp_check_ajax_referer'] = true;
$GLOBALS['__wp_current_user_can'] = true;
$GLOBALS['__wp_options'] = $GLOBALS['__wp_options'] ?? [];
$GLOBALS['__wp_registered_settings'] = [];
$GLOBALS['__wp_admin_pages'] = [];
$GLOBALS['__wp_enqueued_scripts'] = [];
$GLOBALS['__wp_enqueued_styles'] = [];
$GLOBALS['__wp_registered_styles'] = [];
$GLOBALS['__wp_inline_styles'] = [];
$GLOBALS['__wp_localized_scripts'] = [];
$GLOBALS['__wp_posts'] = $GLOBALS['__wp_posts'] ?? [];
$GLOBALS['__wp_post_meta'] = $GLOBALS['__wp_post_meta'] ?? [];
$GLOBALS['__wp_next_post_id'] = $GLOBALS['__wp_next_post_id'] ?? 1;
$GLOBALS['__wp_cleaned_post_cache'] = $GLOBALS['__wp_cleaned_post_cache'] ?? [];
$GLOBALS['__wp_nav_menus'] = $GLOBALS['__wp_nav_menus'] ?? [];
$GLOBALS['__wp_nav_menu_items'] = $GLOBALS['__wp_nav_menu_items'] ?? [];
$GLOBALS['__wp_next_nav_menu_id'] = $GLOBALS['__wp_next_nav_menu_id'] ?? 1;
$GLOBALS['__wp_next_nav_menu_item_id'] = $GLOBALS['__wp_next_nav_menu_item_id'] ?? 1000;
$GLOBALS['__wp_theme_mods'] = $GLOBALS['__wp_theme_mods'] ?? [];

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['__test_wp_filters'][$tag] = $GLOBALS['__test_wp_filters'][$tag] ?? [];
        $GLOBALS['__test_wp_filters'][$tag][$priority] = $GLOBALS['__test_wp_filters'][$tag][$priority] ?? [];
        $GLOBALS['__test_wp_filters'][$tag][$priority][] = [
            'callback' => $function_to_add,
            'accepted_args' => (int) $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        return add_filter($tag, $function_to_add, $priority, $accepted_args);
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters($tag = null)
    {
        if ($tag === null) {
            $GLOBALS['__test_wp_filters'] = [];
            return true;
        }

        unset($GLOBALS['__test_wp_filters'][$tag]);
        return true;
    }
}

if (!function_exists('remove_all_actions')) {
    function remove_all_actions($tag = null)
    {
        return remove_all_filters($tag);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        if (empty($GLOBALS['__test_wp_filters'][$tag])) {
            return $value;
        }

        ksort($GLOBALS['__test_wp_filters'][$tag]);

        foreach ($GLOBALS['__test_wp_filters'][$tag] as $callbacks) {
            foreach ($callbacks as $item) {
                $accepted = max(1, (int) ($item['accepted_args'] ?? 1));
                $params = array_slice(array_merge([$value], $args), 0, $accepted);
                $value = call_user_func_array($item['callback'], $params);
            }
        }

        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args)
    {
        if (empty($GLOBALS['__test_wp_filters'][$tag])) {
            return null;
        }

        ksort($GLOBALS['__test_wp_filters'][$tag]);

        foreach ($GLOBALS['__test_wp_filters'][$tag] as $callbacks) {
            foreach ($callbacks as $item) {
                $accepted = max(0, (int) ($item['accepted_args'] ?? 1));
                $params = array_slice($args, 0, $accepted);
                call_user_func_array($item['callback'], $params);
            }
        }

        return null;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        return array_merge($defaults, (array) $args);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_string($str) ? trim($str) : '';
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        $title = strtolower(trim((string) $title));
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        return trim($title, '-');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (!empty($GLOBALS['__wp_track_unslash_calls'])) {
            $GLOBALS['__wp_unslash_calls'] = (int) ($GLOBALS['__wp_unslash_calls'] ?? 0) + 1;
        }

        if (!empty($GLOBALS['__wp_apply_unslash'])) {
            if (is_array($value)) {
                return array_map('wp_unslash', $value);
            }

            return is_string($value) ? stripslashes($value) : $value;
        }

        return $value;
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0, $depth = 512)
    {
        return json_encode($value, $flags, $depth);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return is_string($url) ? $url : '';
    }
}

if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $GLOBALS['__wp_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        $option = (string) $option;
        $oldExists = array_key_exists($option, $GLOBALS['__wp_options']);
        $oldValue = $oldExists ? $GLOBALS['__wp_options'][$option] : null;

        $GLOBALS['__wp_options'][$option] = $value;

        return !$oldExists || $oldValue !== $value;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        unset($GLOBALS['__wp_options'][$option]);
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false)
    {
        if (!is_array($postarr)) {
            return $wp_error ? new WP_Error('Invalid post payload.') : 0;
        }

        $postId = (int) ($GLOBALS['__wp_next_post_id'] ?? 1);
        $GLOBALS['__wp_next_post_id'] = $postId + 1;
        $postarr['ID'] = $postId;
        $postarr['post_name'] = isset($postarr['post_name']) && trim((string) $postarr['post_name']) !== ''
            ? sanitize_title($postarr['post_name'])
            : sanitize_title($postarr['post_title'] ?? ('post-' . $postId));
        $GLOBALS['__wp_posts'][$postId] = new WP_Post($postarr);

        return $postId;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr, $wp_error = false)
    {
        if (!is_array($postarr) || empty($postarr['ID']) || empty($GLOBALS['__wp_posts'][(int) $postarr['ID']])) {
            return $wp_error ? new WP_Error('Post not found.') : 0;
        }

        $postId = (int) $postarr['ID'];
        $existing = $GLOBALS['__wp_posts'][$postId];
        $data = [
            'ID' => $postId,
            'post_type' => $postarr['post_type'] ?? $existing->post_type,
            'post_status' => $postarr['post_status'] ?? $existing->post_status,
            'post_title' => $postarr['post_title'] ?? $existing->post_title,
            'post_name' => isset($postarr['post_name']) ? sanitize_title($postarr['post_name']) : $existing->post_name,
            'post_content' => $postarr['post_content'] ?? $existing->post_content,
        ];
        $GLOBALS['__wp_posts'][$postId] = new WP_Post($data);

        return $postId;
    }
}

if (!function_exists('get_page_by_path')) {
    function get_page_by_path($page_path, $output = OBJECT, $post_type = 'page')
    {
        $slug = sanitize_title($page_path);

        foreach ($GLOBALS['__wp_posts'] as $post) {
            if ($post instanceof WP_Post && $post->post_type === $post_type && $post->post_name === $slug) {
                return $post;
            }
        }

        return null;
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($postid, $force_delete = false)
    {
        $postId = (int) $postid;
        foreach ($GLOBALS['__wp_nav_menu_items'] ?? [] as $menuId => $items) {
            if (isset($items[$postId])) {
                $item = $items[$postId];
                unset($GLOBALS['__wp_nav_menu_items'][$menuId][$postId]);
                return $item;
            }
        }

        $post = $GLOBALS['__wp_posts'][$postId] ?? null;
        unset($GLOBALS['__wp_posts'][$postId], $GLOBALS['__wp_post_meta'][$postId]);
        return $post;
    }
}

if (!function_exists('wp_get_nav_menu_object')) {
    function wp_get_nav_menu_object($menu)
    {
        if (is_object($menu) && isset($menu->term_id)) {
            return $menu;
        }

        $lookup = is_numeric($menu) ? (int) $menu : sanitize_title((string) $menu);
        foreach ($GLOBALS['__wp_nav_menus'] as $navMenu) {
            if (!is_object($navMenu)) {
                continue;
            }

            if (is_int($lookup) && (int) $navMenu->term_id === $lookup) {
                return $navMenu;
            }

            if (!is_int($lookup)
                && ((string) $navMenu->slug === $lookup || sanitize_title((string) $navMenu->name) === $lookup)
            ) {
                return $navMenu;
            }
        }

        return false;
    }
}

if (!function_exists('wp_create_nav_menu')) {
    function wp_create_nav_menu($menu_name)
    {
        $name = trim((string) $menu_name);
        if ($name === '') {
            return new WP_Error('Invalid menu name.');
        }

        $existing = wp_get_nav_menu_object($name);
        if (is_object($existing)) {
            return (int) $existing->term_id;
        }

        $menuId = (int) ($GLOBALS['__wp_next_nav_menu_id'] ?? 1);
        $GLOBALS['__wp_next_nav_menu_id'] = $menuId + 1;
        $GLOBALS['__wp_nav_menus'][$menuId] = (object) [
            'term_id' => $menuId,
            'name' => $name,
            'slug' => sanitize_title($name),
            'taxonomy' => 'nav_menu',
        ];
        $GLOBALS['__wp_nav_menu_items'][$menuId] = $GLOBALS['__wp_nav_menu_items'][$menuId] ?? [];

        return $menuId;
    }
}

if (!function_exists('wp_get_nav_menu_items')) {
    function wp_get_nav_menu_items($menu)
    {
        $menuObject = wp_get_nav_menu_object($menu);
        if (!is_object($menuObject)) {
            return [];
        }

        return array_values($GLOBALS['__wp_nav_menu_items'][(int) $menuObject->term_id] ?? []);
    }
}

if (!function_exists('wp_update_nav_menu_item')) {
    function wp_update_nav_menu_item($menu_id, $menu_item_db_id, $menu_item_data = [])
    {
        if (isset($GLOBALS['__wp_update_nav_menu_item_result'])) {
            $result = is_callable($GLOBALS['__wp_update_nav_menu_item_result'])
                ? $GLOBALS['__wp_update_nav_menu_item_result']($menu_id, $menu_item_db_id, $menu_item_data)
                : $GLOBALS['__wp_update_nav_menu_item_result'];
            if ($result !== null) {
                return $result;
            }
        }

        $menuId = (int) $menu_id;
        if (!isset($GLOBALS['__wp_nav_menus'][$menuId])) {
            return new WP_Error('Menu not found.');
        }

        $itemId = (int) $menu_item_db_id;
        if ($itemId < 1) {
            $itemId = (int) ($GLOBALS['__wp_next_nav_menu_item_id'] ?? 1000);
            $GLOBALS['__wp_next_nav_menu_item_id'] = $itemId + 1;
        }

        $GLOBALS['__wp_nav_menu_items'][$menuId][$itemId] = (object) [
            'ID' => $itemId,
            'db_id' => $itemId,
            'menu_item_parent' => (string) ($menu_item_data['menu-item-parent-id'] ?? '0'),
            'object_id' => (string) ($menu_item_data['menu-item-object-id'] ?? '0'),
            'object' => (string) ($menu_item_data['menu-item-object'] ?? ''),
            'type' => (string) ($menu_item_data['menu-item-type'] ?? ''),
            'title' => (string) ($menu_item_data['menu-item-title'] ?? ''),
            'url' => (string) ($menu_item_data['menu-item-url'] ?? ''),
            'menu_order' => (int) ($menu_item_data['menu-item-position'] ?? 0),
            'post_status' => (string) ($menu_item_data['menu-item-status'] ?? 'publish'),
        ];

        return $itemId;
    }
}

if (!function_exists('wp_delete_nav_menu')) {
    function wp_delete_nav_menu($menu)
    {
        $menuObject = wp_get_nav_menu_object($menu);
        if (!is_object($menuObject)) {
            return false;
        }

        $menuId = (int) $menuObject->term_id;
        unset($GLOBALS['__wp_nav_menus'][$menuId], $GLOBALS['__wp_nav_menu_items'][$menuId]);
        return true;
    }
}

if (!function_exists('get_theme_mod')) {
    function get_theme_mod($name, $default = false)
    {
        return $GLOBALS['__wp_theme_mods'][$name] ?? $default;
    }
}

if (!function_exists('set_theme_mod')) {
    function set_theme_mod($name, $value)
    {
        $GLOBALS['__wp_theme_mods'][$name] = $value;
    }
}

if (!function_exists('remove_theme_mod')) {
    function remove_theme_mod($name)
    {
        unset($GLOBALS['__wp_theme_mods'][$name]);
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post)
    {
        $postId = $post instanceof WP_Post ? $post->ID : (int) $post;
        return 'http://example.test/?page_id=' . $postId;
    }
}

if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post)
    {
        $GLOBALS['__wp_cleaned_post_cache'][] = $post instanceof WP_Post ? $post->ID : (int) $post;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        $postId = (int) $post_id;
        $GLOBALS['__wp_post_meta'][$postId] = $GLOBALS['__wp_post_meta'][$postId] ?? [];
        $GLOBALS['__wp_post_meta'][$postId][(string) $meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        $postId = (int) $post_id;
        $meta = $GLOBALS['__wp_post_meta'][$postId] ?? [];

        if ($key === '') {
            return $meta;
        }

        if (!array_key_exists((string) $key, $meta)) {
            return $single ? '' : [];
        }

        $value = $meta[(string) $key];
        if (
            is_string($value)
            && (
                (string) $key === '_oxy_html_converter_import_manifest'
                || !empty($GLOBALS['__wp_get_post_meta_returns_unslashed'])
            )
        ) {
            $value = stripslashes($value);
        }

        return $single ? $value : [$value];
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $meta_key)
    {
        $postId = (int) $post_id;
        unset($GLOBALS['__wp_post_meta'][$postId][(string) $meta_key]);
        return true;
    }
}

if (!function_exists('register_setting')) {
    function register_setting($group, $name, $args = [])
    {
        $GLOBALS['__wp_registered_settings'][$name] = [
            'group' => $group,
            'args' => $args,
        ];
        return true;
    }
}

if (!function_exists('settings_fields')) {
    function settings_fields($group)
    {
        echo '<input type="hidden" name="option_page" value="' . esc_attr((string) $group) . '">';
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true)
    {
        echo '<button type="submit" name="' . esc_attr((string) $name) . '" class="button ' . esc_attr((string) $type) . '">' . esc_html((string) $text) . '</button>';
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        $result = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        if ($echo) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress selected() emits an attribute fragment.
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test-nonce';
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '')
    {
        $GLOBALS['__wp_admin_pages'][] = compact('parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback');
        return $menu_slug;
    }
}

if (!function_exists('add_management_page')) {
    function add_management_page($page_title, $menu_title, $capability, $menu_slug, $callback = '')
    {
        $GLOBALS['__wp_admin_pages'][] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback');
        return $menu_slug;
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return !empty($GLOBALS['__wp_is_admin']);
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        $GLOBALS['__wp_enqueued_styles'][$handle] = compact('src', 'deps', 'ver', 'media');
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        $GLOBALS['__wp_registered_styles'][$handle] = compact('src', 'deps', 'ver', 'media');
        return true;
    }
}

if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style($handle, $data)
    {
        $GLOBALS['__wp_inline_styles'][$handle] = $GLOBALS['__wp_inline_styles'][$handle] ?? [];
        $GLOBALS['__wp_inline_styles'][$handle][] = (string) $data;
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        $GLOBALS['__wp_enqueued_scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        $GLOBALS['__wp_localized_scripts'][$handle] = [
            'object_name' => $object_name,
            'l10n' => $l10n,
        ];
    }
}

if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $status = 'enqueued')
    {
        return isset($GLOBALS['__wp_enqueued_scripts'][$handle]);
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '')
    {
        throw new RuntimeException(esc_html((string) $message));
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
    {
        $value = $GLOBALS['__wp_check_ajax_referer'] ?? true;
        if (is_callable($value)) {
            return (bool) call_user_func($value, $action, $query_arg, $stop);
        }
        return (bool) $value;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        $GLOBALS['__wp_current_user_can_last_capability'] = $capability;
        $GLOBALS['__wp_current_user_can_last_args'] = $args;
        $value = $GLOBALS['__wp_current_user_can'] ?? true;
        if (is_callable($value)) {
            return (bool) call_user_func($value, $capability, ...$args);
        }
        return (bool) $value;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null)
    {
        $GLOBALS['__wp_send_json_last'] = [
            'success' => false,
            'data' => $data,
            'status_code' => $status_code,
        ];
        return null;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null)
    {
        $GLOBALS['__wp_send_json_last'] = [
            'success' => true,
            'data' => $data,
            'status_code' => $status_code,
        ];
        return null;
    }
}
