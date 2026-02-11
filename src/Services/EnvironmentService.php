<?php

namespace OxyHtmlConverter\Services;

/**
 * Service for detecting the environment and plugin dependencies
 */
class EnvironmentService
{
    /**
     * Check if the WindPress plugin is active
     *
     * @return bool
     */
    public function isWindPressActive(): bool
    {
        // 1. Check for the WindPress class (correct namespace)
        if (class_exists('\\WindPress\\WindPress\\Plugin')) {
            return true;
        }

        // 2. Check for the WIND_PRESS class (alternative detection)
        if (class_exists('\\WIND_PRESS')) {
            return true;
        }

        // 3. Check using WordPress core function if available
        if (function_exists('is_plugin_active')) {
            return is_plugin_active('windpress/windpress.php');
        }

        return false;
    }

    /**
     * Get the current class handling mode
     * 
     * Currently returns 'auto' by default. This is a placeholder for future admin settings.
     *
     * @return string 'auto', 'windpress', or 'oxygen'
     */
    public function getClassHandlingMode(): string
    {
        if (function_exists('get_option')) {
            return get_option('oxy_html_converter_class_mode', 'auto');
        }
        return 'auto';
    }

    /**
     * Determine if WindPress mode should be used for Tailwind classes
     *
     * @return bool
     */
    public function shouldUseWindPressMode(): bool
    {
        $mode = $this->getClassHandlingMode();

        if ($mode === 'windpress') {
            return true;
        }

        if ($mode === 'auto') {
            return $this->isWindPressActive();
        }

        return false;
    }
}