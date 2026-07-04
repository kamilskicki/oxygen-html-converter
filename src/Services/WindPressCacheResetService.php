<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class WindPressCacheResetService
{
    /**
     * @return array<string, mixed>
     */
    public function resetIfAvailable(): array
    {
        $windPressActive = class_exists('\\WindPress\\WindPress\\Plugin');
        $result = [
            'attempted' => $windPressActive,
            'active' => $windPressActive,
            'cacheFileDeleted' => false,
            'objectCacheFlushed' => false,
            'path' => '',
        ];

        if (!$windPressActive) {
            return $result;
        }

        $cachePath = $this->resolveCachePath();
        $result['path'] = $cachePath;

        if ($cachePath !== '' && is_file($cachePath) && is_writable($cachePath)) {
            $result['cacheFileDeleted'] = @unlink($cachePath);
        }

        $utilsCacheClass = '\\WindPress\\WindPress\\Utils\\Cache';
        if (is_callable([$utilsCacheClass, 'flush_cache_plugin'])) {
            call_user_func([$utilsCacheClass, 'flush_cache_plugin']);
            $result['objectCacheFlushed'] = true;
        } elseif (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $result['objectCacheFlushed'] = true;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('last_full_build', 'windpress');
        }

        do_action('oxy_html_converter_windpress_cache_reset', $result);

        return $result;
    }

    private function resolveCachePath(): string
    {
        $cacheClass = '\\WindPress\\WindPress\\Core\\Cache';

        if (!is_callable([$cacheClass, 'get_cache_path'])) {
            return '';
        }

        $cacheFile = defined($cacheClass . '::CSS_CACHE_FILE')
            ? (string) constant($cacheClass . '::CSS_CACHE_FILE')
            : 'tailwind.css';
        $path = call_user_func([$cacheClass, 'get_cache_path'], $cacheFile);

        return is_string($path) ? $path : '';
    }
}
