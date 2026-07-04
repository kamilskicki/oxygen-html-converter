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
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $selectorCount = is_array($selectorPayload['selectors'] ?? null) ? count($selectorPayload['selectors']) : 0;
        $designDocument = is_array($result['designDocument'] ?? null) ? $result['designDocument'] : [];
        $designSummary = is_array($designDocument['summary'] ?? null) ? $designDocument['summary'] : [];
        $designTokens = is_array($designDocument['tokens'] ?? null) ? $designDocument['tokens'] : [];
        $designClassStrategy = is_array($designDocument['classStrategy'] ?? null) ? $designDocument['classStrategy'] : [];
        $designFollowUp = $this->normalizeMessages($designDocument['followUp'] ?? []);
        $importPlan = is_array($result['importPlan'] ?? null) ? $result['importPlan'] : [];
        $nativeCoverage = is_array($importPlan['nativeCoverage'] ?? null) ? $importPlan['nativeCoverage'] : [];
        $importPlanActions = $this->normalizeMessages($importPlan['actions'] ?? []);
        $importPlanBlockers = $this->normalizeMessages($importPlan['blockers'] ?? []);
        $fallbacks = is_array($importPlan['fallbacks'] ?? null) ? $importPlan['fallbacks'] : [];

        if (!empty($options['unsafeModeExplicit'])) {
            $warnings[] = 'Unsafe preservation mode was explicitly requested; scripts, event handlers, and external head assets may be preserved.';
        }

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
                'selectorCount' => $selectorCount,
                'hasExtractedCss' => trim((string) ($result['extractedCss'] ?? '')) !== '',
                'designSections' => (int) ($designSummary['sectionCount'] ?? 0),
                'componentCandidates' => (int) ($designSummary['componentCandidatesCount'] ?? 0),
                'colorTokens' => (int) ($designSummary['colorTokenCount'] ?? 0),
                'fontTokens' => (int) ($designSummary['fontTokenCount'] ?? 0),
                'spacingTokens' => (int) ($designSummary['spacingTokenCount'] ?? 0),
                'buttonVariants' => (int) ($designSummary['buttonVariantCount'] ?? 0),
                'classStrategy' => (string) ($designClassStrategy['recommendation'] ?? ''),
                'importStatus' => (string) ($importPlan['status'] ?? ''),
                'nativeCoveragePercent' => (float) ($nativeCoverage['percent'] ?? 0),
                'fallbackCount' => count($fallbacks),
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
                'strictNative' => !empty($options['strictNative']),
                'info' => $info,
                'designDocument' => [
                    'version' => (int) ($designDocument['version'] ?? 0),
                    'sections' => array_slice(is_array($designDocument['sections'] ?? null) ? $designDocument['sections'] : [], 0, 8),
                    'componentCandidates' => array_slice(is_array($designDocument['componentCandidates'] ?? null) ? $designDocument['componentCandidates'] : [], 0, 8),
                    'tokens' => [
                        'colors' => array_slice(is_array($designTokens['colors'] ?? null) ? $designTokens['colors'] : [], 0, 8),
                        'fonts' => array_slice(is_array($designTokens['fonts'] ?? null) ? $designTokens['fonts'] : [], 0, 8),
                        'spacing' => array_slice(is_array($designTokens['spacing'] ?? null) ? $designTokens['spacing'] : [], 0, 8),
                    ],
                    'classStrategy' => $designClassStrategy,
                ],
                'importPlan' => [
                    'version' => (int) ($importPlan['version'] ?? 0),
                    'status' => (string) ($importPlan['status'] ?? ''),
                    'canImport' => (bool) ($importPlan['canImport'] ?? false),
                    'nativeCoverage' => $nativeCoverage,
                    'fallbacks' => array_slice($fallbacks, 0, 8),
                    'persistence' => is_array($importPlan['persistence'] ?? null) ? $importPlan['persistence'] : [],
                ],
            ],
            'stripped' => $stripped,
            'followUp' => array_values(array_unique(array_merge(
                $followUp,
                $designFollowUp,
                $importPlanBlockers,
                $importPlanActions,
                $validationWarnings
            ))),
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
