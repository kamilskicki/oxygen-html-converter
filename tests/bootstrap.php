<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Define any constants needed by the plugin if necessary
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress test stubs for pure unit tests.
$GLOBALS['__test_wp_filters'] = $GLOBALS['__test_wp_filters'] ?? [];
$GLOBALS['__wp_send_json_last'] = null;
$GLOBALS['__wp_check_ajax_referer'] = true;
$GLOBALS['__wp_current_user_can'] = true;

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

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return is_string($url) ? $url : '';
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
    function current_user_can($capability)
    {
        $GLOBALS['__wp_current_user_can_last_capability'] = $capability;
        $value = $GLOBALS['__wp_current_user_can'] ?? true;
        if (is_callable($value)) {
            return (bool) call_user_func($value, $capability);
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
