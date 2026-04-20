<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class ConversionAuditBuilder
{
    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $result, array $options): array
    {
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $warnings = $this->normalizeMessages($stats['warnings'] ?? []);
        $errors = $this->normalizeMessages($stats['errors'] ?? []);
        $info = $this->normalizeMessages($stats['info'] ?? []);
        $validationErrors = $this->normalizeMessages($result['validationErrors'] ?? []);
        $validationWarnings = $this->normalizeMessages($result['validationWarnings'] ?? []);
        $iconLibraries = $this->normalizeMessages($result['detectedIconLibraries'] ?? []);
        $headLinkCount = is_array($result['headLinkElements'] ?? null) ? count($result['headLinkElements']) : 0;
        $headScriptCount = is_array($result['headScriptElements'] ?? null) ? count($result['headScriptElements']) : 0;
        $iconScriptCount = is_array($result['iconScriptElements'] ?? null) ? count($result['iconScriptElements']) : 0;
        $customClasses = is_array($result['customClasses'] ?? null) ? $result['customClasses'] : [];

        $stripped = [];
        if (!empty($options['safeMode'])) {
            $stripped[] = 'Scripts, event handlers, and external head assets were removed by Safe Mode.';
        }

        $followUp = [];
        if ($validationErrors) {
            $followUp[] = 'Converted output failed builder validation. Review the reported issues before importing.';
        }
        if ($warnings) {
            $followUp[] = 'Review conversion warnings for unsupported or partially transformed constructs.';
        }
        if (!empty($customClasses)) {
            $followUp[] = 'Verify residual custom classes and CSS fallbacks on the frontend.';
        }

        $audit = [
            'summary' => [
                'elements' => (int) ($stats['elements'] ?? 0),
                'tailwindClasses' => (int) ($stats['tailwindClasses'] ?? 0),
                'customClasses' => (int) ($stats['customClasses'] ?? 0),
                'warningsCount' => count($warnings),
                'errorsCount' => count($errors) + count($validationErrors),
                'headLinkCount' => $headLinkCount,
                'headScriptCount' => $headScriptCount,
                'iconScriptCount' => $iconScriptCount,
                'hasExtractedCss' => trim((string) ($result['extractedCss'] ?? '')) !== '',
            ],
            'preserved' => [
                'customClasses' => array_values(array_map('strval', $customClasses)),
                'iconLibraries' => $iconLibraries,
                'headAssets' => [
                    'links' => $headLinkCount,
                    'scripts' => $headScriptCount,
                    'iconScripts' => $iconScriptCount,
                ],
            ],
            'transformed' => [
                'wrapInContainer' => !empty($options['wrapInContainer']),
                'includeCssElement' => !empty($options['includeCssElement']),
                'inlineStyles' => !empty($options['inlineStyles']),
                'safeMode' => !empty($options['safeMode']),
                'info' => $info,
            ],
            'stripped' => $stripped,
            'followUp' => array_values(array_unique(array_merge($followUp, $validationWarnings))),
            'diagnostics' => [
                'warnings' => $warnings,
                'errors' => $errors,
                'validationErrors' => $validationErrors,
                'validationWarnings' => $validationWarnings,
            ],
        ];

        return (array) apply_filters('oxy_html_converter_conversion_audit', $audit, $result, $options);
    }

    /**
     * @param mixed $messages
     * @return array<int, string>
     */
    private function normalizeMessages($messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];

        foreach ($messages as $message) {
            if (!is_scalar($message)) {
                continue;
            }

            $value = trim((string) $message);
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }
}
