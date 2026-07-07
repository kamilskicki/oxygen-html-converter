<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Shared UI/docs configuration exposed to admin and builder clients.
 */
class UiConfigProvider
{
    /**
     * @var array<string, bool>
     */
    private const DEFAULT_FEATURE_FLAGS = [
        'core' => true,
        'pro' => false,
        'batch_convert' => true,
        'preview' => true,
        'tailwind_native_mapping' => true,
        'tailwind_fallback_css' => true,
        'tailwind_runtime_integration' => false,
        'windpress_integration' => false,
        'windpress_class_mode' => false,
        'windpress_cache_reset' => false,
    ];

    /**
     * @param array<string, mixed> $baseFlags
     * @return array<string, mixed>
     */
    public function getFeatureFlags(array $baseFlags = []): array
    {
        $flags = array_merge(self::DEFAULT_FEATURE_FLAGS, $baseFlags);

        return (array) apply_filters('oxy_html_converter_feature_flags', $flags);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $baseDocsUrl = 'https://github.com/kamilskicki/oxygen-html-converter';
        $featureFlags = $this->getFeatureFlags();

        $config = [
            'docs' => [
                'readme' => $baseDocsUrl . '#readme',
                'supportedScope' => $baseDocsUrl . '/blob/master/docs/SUPPORTED_SCOPE.md',
                'releaseChecklist' => $baseDocsUrl . '/blob/master/docs/RELEASE_CHECKLIST.md',
            ],
            'featureFlags' => $featureFlags,
            'integrations' => [
                'tailwind' => [
                    'scope' => 'core_native_hints',
                    'nativeMapping' => (bool) ($featureFlags['tailwind_native_mapping'] ?? true),
                    'fallbackCss' => (bool) ($featureFlags['tailwind_fallback_css'] ?? true),
                    'runtimeIntegration' => (bool) ($featureFlags['tailwind_runtime_integration'] ?? false),
                    'extensionPoint' => 'oxy_html_converter_convert_options',
                ],
                'windpress' => [
                    'scope' => 'pro_optional',
                    'enabled' => (bool) ($featureFlags['windpress_integration'] ?? false),
                    'classModeSelection' => (bool) ($featureFlags['windpress_class_mode'] ?? false),
                    'cacheReset' => (bool) ($featureFlags['windpress_cache_reset'] ?? false),
                    'extensionPoint' => 'oxy_html_converter_feature_flags',
                ],
            ],
            'productBoundaries' => [
                'advancedComponents' => [
                    'status' => 'future',
                    'active' => false,
                    'extensionPoint' => 'oxy_html_converter_component_variant_mapper',
                    'remediation' => 'Defer advanced component patterns or implement a verified extension mapper.',
                ],
                'forms' => [
                    'status' => 'unsupported',
                    'active' => false,
                    'extensionPoint' => 'oxy_html_converter_component_form_mapper',
                    'remediation' => 'Use static Core output or an approved form integration.',
                ],
                'dynamicData' => [
                    'status' => 'pro',
                    'active' => false,
                    'extensionPoint' => 'oxy_html_converter_pro_dynamic_binding_mapper',
                    'remediation' => 'Use static Core output or a verified dynamic-data mapper.',
                ],
                'loops' => [
                    'status' => 'pro',
                    'active' => false,
                    'extensionPoint' => 'oxy_html_converter_pro_loop_mapper',
                    'remediation' => 'Use static Core output or a verified loop mapper.',
                ],
                'woocommerce' => [
                    'status' => 'pro',
                    'active' => false,
                    'extensionPoint' => 'oxy_html_converter_pro_woocommerce_mapper',
                    'remediation' => 'Use static Core output or a verified WooCommerce mapper.',
                ],
            ],
            'examples' => [
                'hero' => "<section class=\"hero\">\n  <h1>Build native Oxygen pages from HTML</h1>\n  <p>Paste full landing-page sections and keep them editable.</p>\n  <a href=\"#cta\" class=\"btn btn-primary\">Start importing</a>\n</section>",
            ],
        ];

        return (array) apply_filters('oxy_html_converter_ui_config', $config);
    }
}
