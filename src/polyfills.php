<?php
/**
 * PHP 8.2+ compatibility guards for PHP 8 string helper functions.
 *
 * The plugin now requires PHP 8.2+, so these functions should already be
 * provided by the runtime. The guarded definitions remain defensive for
 * isolated test/bootstrap contexts and unexpected partial loads.
 */

if (!function_exists('str_starts_with')) {
    /**
     * Checks if a string starts with a given substring
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool True if haystack starts with needle, false otherwise
     */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Checks if a string ends with a given substring
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool True if haystack ends with needle, false otherwise
     */
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    /**
     * Checks if a string contains a given substring
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool True if haystack contains needle, false otherwise
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
