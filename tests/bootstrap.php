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
        $GLOBALS['__wp_options'][$option] = $value;
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
        $post = $GLOBALS['__wp_posts'][$postId] ?? null;
        unset($GLOBALS['__wp_posts'][$postId], $GLOBALS['__wp_post_meta'][$postId]);
        return $post;
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

        return $single ? $meta[(string) $key] : [$meta[(string) $key]];
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
        echo '<input type="hidden" name="option_page" value="' . htmlspecialchars((string) $group, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true)
    {
        $html = '<button type="submit" name="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '" class="button ' . htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '</button>';
        echo $html;
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        $result = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        if ($echo) {
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
        throw new RuntimeException((string) $message);
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
