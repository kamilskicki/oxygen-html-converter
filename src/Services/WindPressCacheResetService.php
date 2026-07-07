<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class WindPressCacheResetService
{
    public const FEATURE_FLAG_INTEGRATION = 'windpress_integration';
    public const FEATURE_FLAG_CACHE_RESET = 'windpress_cache_reset';

    /**
     * @param array<string, mixed>|null $featureFlags
     */
    public function isCacheResetEnabled(?array $featureFlags = null): bool
    {
        $featureFlags = $featureFlags ?? $this->featureFlags();
        $enabled = !empty($featureFlags[self::FEATURE_FLAG_CACHE_RESET]);

        if (function_exists('apply_filters')) {
            return (bool) apply_filters('oxy_html_converter_windpress_cache_reset_enabled', $enabled, $featureFlags);
        }

        return $enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function resetIfEnabled(?bool $enabled = null): array
    {
        if (($enabled ?? $this->isCacheResetEnabled()) === false) {
            return $this->emptyResult(false, 'windpress_cache_reset_disabled');
        }

        return $this->resetIfAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    public function resetIfAvailable(): array
    {
        $windPressActive = class_exists('\\WindPress\\WindPress\\Plugin');
        $result = [
            'enabled' => true,
            'attempted' => $windPressActive,
            'active' => $windPressActive,
            'cacheFileDeleted' => false,
            'objectCacheFlushed' => false,
            'path' => '',
            'reason' => $windPressActive ? 'windpress_active' : 'windpress_inactive',
            'errors' => [],
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

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(bool $enabled, string $reason): array
    {
        return [
            'enabled' => $enabled,
            'attempted' => false,
            'active' => false,
            'cacheFileDeleted' => false,
            'objectCacheFlushed' => false,
            'path' => '',
            'reason' => $reason,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureFlags(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        return (array) apply_filters('oxy_html_converter_feature_flags', [
            self::FEATURE_FLAG_INTEGRATION => false,
            self::FEATURE_FLAG_CACHE_RESET => false,
        ]);
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
