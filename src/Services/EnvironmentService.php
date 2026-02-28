<?php

namespace OxyHtmlConverter\Services;

/**
 * Service for detecting the environment and plugin dependencies
 */
class EnvironmentService
{
    private ?BuilderContractService $builderContractService = null;
    private ?array $essentialButtonContractStatus = null;

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

    /**
     * Check if Breakdance Elements for Oxygen plugin is active.
     */
    public function isBreakdanceElementsForOxygenActive(): bool
    {
        if (defined('BREAKDANCE_ELEMENTS_FOR_OXYGEN_VERSION')) {
            return true;
        }

        if (class_exists('\\EssentialElements\\Button')) {
            return true;
        }

        if (function_exists('is_plugin_active')) {
            return is_plugin_active('breakdance-elements-for-oxygen/plugin.php');
        }

        return false;
    }

    /**
     * Get element mapping mode.
     *
     * @return string 'auto', 'oxygen', or 'essential'
     */
    public function getElementMappingMode(): string
    {
        $mode = 'auto';
        if (function_exists('get_option')) {
            $mode = (string) get_option('oxy_html_converter_element_mapping_mode', 'auto');
        }

        if (!in_array($mode, ['auto', 'oxygen', 'essential'], true)) {
            return 'auto';
        }

        return $mode;
    }

    /**
     * Determine if converter should prefer EssentialElements mappings.
     */
    public function shouldPreferEssentialElements(): bool
    {
        $mode = $this->getElementMappingMode();

        if ($mode === 'oxygen') {
            return false;
        }

        if (!$this->isBreakdanceElementsForOxygenActive()) {
            return false;
        }

        if ($mode === 'essential') {
            return $this->isEssentialButtonContractCompatible();
        }

        // auto
        return $this->isEssentialButtonContractCompatible();
    }

    /**
     * Check if the EssentialElements Button contract is compatible.
     */
    public function isEssentialButtonContractCompatible(): bool
    {
        $status = $this->getEssentialButtonContractStatus();
        return (bool) ($status['compatible'] ?? false);
    }

    /**
     * Get detailed status for the EssentialElements Button contract.
     *
     * @return array{compatible:bool,class:string,issues:array,details:array}
     */
    public function getEssentialButtonContractStatus(): array
    {
        if ($this->essentialButtonContractStatus !== null) {
            return $this->essentialButtonContractStatus;
        }

        $this->essentialButtonContractStatus = $this->getBuilderContractService()->evaluateEssentialButtonContract();
        return $this->essentialButtonContractStatus;
    }

    /**
     * Get compatibility issues for EssentialElements Button contract.
     */
    public function getEssentialButtonContractIssues(): array
    {
        $status = $this->getEssentialButtonContractStatus();
        $issues = $status['issues'] ?? [];
        return is_array($issues) ? $issues : [];
    }

    private function getBuilderContractService(): BuilderContractService
    {
        if ($this->builderContractService === null) {
            $this->builderContractService = new BuilderContractService();
        }

        return $this->builderContractService;
    }
}
