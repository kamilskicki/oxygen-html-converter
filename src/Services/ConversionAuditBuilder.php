<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;

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
        $unsupportedItems = $this->normalizeUnsupportedItems($stats['unsupportedItems'] ?? []);
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
        $designProfile = is_array($designDocument['designProfile'] ?? null) ? $designDocument['designProfile'] : [];
        $designFollowUp = $this->normalizeMessages($designDocument['followUp'] ?? []);
        $importPlan = is_array($result['importPlan'] ?? null) ? $result['importPlan'] : [];
        $nativeCoverage = is_array($importPlan['nativeCoverage'] ?? null) ? $importPlan['nativeCoverage'] : [];
        $importPlanActions = $this->normalizeMessages($importPlan['actions'] ?? []);
        $importPlanBlockers = $this->normalizeMessages($importPlan['blockers'] ?? []);
        $fallbacks = is_array($importPlan['fallbacks'] ?? null) ? $importPlan['fallbacks'] : [];
        $productBoundaryDeferrals = $this->productBoundaryDeferrals($fallbacks);
        $styleRoutes = is_array($importPlan['styleRoutes'] ?? null) ? $importPlan['styleRoutes'] : [];
        $pluginDependentStyleRoutes = $this->pluginDependentStyleRoutes($styleRoutes);
        $assetNormalization = is_array($result['assetNormalization'] ?? null) ? $result['assetNormalization'] : [];
        $assetNormalizationSummary = is_array($assetNormalization['summary'] ?? null) ? $assetNormalization['summary'] : [];
        $assetNormalizationAudit = $this->summarizeAssetNormalizationForAudit($assetNormalization, $assetNormalizationSummary);
        $tokenUsage = is_array($result['tokenUsage'] ?? null)
            ? $result['tokenUsage']
            : (is_array($importPlan['tokenUsage'] ?? null) ? $importPlan['tokenUsage'] : []);
        $surface = $this->summarizeConversionResultSurface($result);

        if (!empty($options['allowExecutableCode'])) {
            $warnings[] = 'Executable-code fallback was explicitly approved; scripts, event handlers, and unsafe HtmlCode may be preserved.';
        } elseif (!empty($options['unsafeModeExplicit'])) {
            $warnings[] = 'Unsafe mode was requested without executable-code approval; scripts, event handlers, and unsafe HtmlCode remain stripped or report-only.';
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
        if ($pluginDependentStyleRoutes !== []) {
            $followUp[] = 'Review plugin-dependent CSS runtime requirements before importing.';
        }
        if ((int) ($assetNormalizationSummary['rejected'] ?? 0) > 0) {
            $followUp[] = 'Replace rejected temporary or unsafe assets before production import.';
        }
        if ((int) ($assetNormalizationSummary['manualFollowUp'] ?? 0) > 0) {
            $followUp[] = 'Review external asset licensing, localization, and cache policy before importing.';
        }

        $audit = [
            'summary' => [
                'elements' => $surface['totalNodes'] > 0 ? $surface['totalNodes'] : (int) ($stats['elements'] ?? 0),
                'tailwindClasses' => (int) ($stats['tailwindClasses'] ?? 0),
                'customClasses' => (int) ($stats['customClasses'] ?? 0),
                'warningsCount' => count($warnings),
                'errorsCount' => count($errors) + count($validationErrors),
                'unsupportedCount' => count($unsupportedItems),
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
                'imageTokens' => (int) ($designSummary['imageTokenCount'] ?? 0),
                'measurementTokens' => (int) ($designSummary['measurementTokenCount'] ?? 0),
                'numberTokens' => (int) ($designSummary['numberTokenCount'] ?? 0),
                'boundTokens' => (int) ($tokenUsage['bound'] ?? 0),
                'orphanTokens' => (int) ($tokenUsage['orphanCount'] ?? 0),
                'buttonVariants' => (int) ($designSummary['buttonVariantCount'] ?? 0),
                'classStrategy' => (string) ($designClassStrategy['recommendation'] ?? ''),
                'semanticClasses' => (int) ($designSummary['semanticClassCount'] ?? count(is_array($designClassStrategy['classMap'] ?? null) ? $designClassStrategy['classMap'] : [])),
                'classApplications' => (int) ($designSummary['classApplicationCount'] ?? count(is_array($designProfile['elementApplications'] ?? null) ? $designProfile['elementApplications'] : [])),
                'importStatus' => (string) ($importPlan['status'] ?? ''),
                'nativeCoveragePercent' => (float) ($nativeCoverage['percent'] ?? 0),
                'fallbackCount' => count($fallbacks),
                'productBoundaryDeferralCount' => count($productBoundaryDeferrals),
                'pluginDependentCssCount' => count($pluginDependentStyleRoutes),
                'codeBlockCount' => $surface['htmlCodeBlocks'] + $surface['cssCodeBlocks'] + $surface['javascriptCodeBlocks'],
                'htmlCodeBlocks' => $surface['htmlCodeBlocks'],
                'cssCodeBlocks' => $surface['cssCodeBlocks'],
                'javascriptCodeBlocks' => $surface['javascriptCodeBlocks'],
                'componentNodes' => $surface['componentNodes'],
                'assetNodes' => $surface['assetNodes'],
                'imageNodes' => $surface['imageNodes'],
                'videoNodes' => $surface['videoNodes'],
                'classAssignments' => $surface['classAssignments'],
                'assetCount' => (int) ($assetNormalizationSummary['total'] ?? 0),
                'localizedAssetCount' => (int) ($assetNormalizationSummary['localized'] ?? 0),
                'stableAssetCount' => (int) ($assetNormalizationSummary['stable'] ?? 0),
                'rejectedAssetCount' => (int) ($assetNormalizationSummary['rejected'] ?? 0),
                'manualAssetFollowUpCount' => (int) ($assetNormalizationSummary['manualFollowUp'] ?? 0),
            ],
            'preserved' => [
                'customClasses' => array_values(array_map('strval', $customClasses)),
                'iconLibraries' => $iconLibraries,
                'headAssets' => [
                    'links' => $headLinkCount,
                    'scripts' => $headScriptCount,
                    'iconScripts' => $iconScriptCount,
                ],
                'assetNormalization' => [
                    'summary' => $assetNormalizationSummary,
                    'assets' => $assetNormalizationAudit['assets'],
                    'byStatus' => $assetNormalizationAudit['byStatus'],
                    'truncated' => $assetNormalizationAudit['truncated'],
                    'totalListed' => $assetNormalizationAudit['totalListed'],
                ],
            ],
            'transformed' => [
                'wrapInContainer' => !empty($options['wrapInContainer']),
                'includeCssElement' => !empty($options['includeCssElement']),
                'inlineStyles' => !empty($options['inlineStyles']),
                'safeMode' => !empty($options['safeMode']),
                'strictNative' => !empty($options['strictNative']),
                'info' => $info,
                'convertedSurface' => [
                    'totalNodes' => $surface['totalNodes'],
                    'codeBlocks' => [
                        'total' => $surface['htmlCodeBlocks'] + $surface['cssCodeBlocks'] + $surface['javascriptCodeBlocks'],
                        'html' => $surface['htmlCodeBlocks'],
                        'css' => $surface['cssCodeBlocks'],
                        'javascript' => $surface['javascriptCodeBlocks'],
                    ],
                    'components' => $surface['componentNodes'],
                    'assetNodes' => $surface['assetNodes'],
                    'imageNodes' => $surface['imageNodes'],
                    'videoNodes' => $surface['videoNodes'],
                    'classAssignments' => $surface['classAssignments'],
                ],
                'assetNormalization' => [
                    'summary' => $assetNormalizationSummary,
                    'assets' => $assetNormalizationAudit['assets'],
                    'byStatus' => $assetNormalizationAudit['byStatus'],
                    'truncated' => $assetNormalizationAudit['truncated'],
                    'totalListed' => $assetNormalizationAudit['totalListed'],
                ],
                'designDocument' => [
                    'version' => (int) ($designDocument['version'] ?? 0),
                    'sections' => array_slice(is_array($designDocument['sections'] ?? null) ? $designDocument['sections'] : [], 0, 8),
                    'componentCandidates' => array_slice(is_array($designDocument['componentCandidates'] ?? null) ? $designDocument['componentCandidates'] : [], 0, 8),
                    'tokens' => [
                        'colors' => array_slice(is_array($designTokens['colors'] ?? null) ? $designTokens['colors'] : [], 0, 8),
                        'fonts' => array_slice(is_array($designTokens['fonts'] ?? null) ? $designTokens['fonts'] : [], 0, 8),
                        'spacing' => array_slice(is_array($designTokens['spacing'] ?? null) ? $designTokens['spacing'] : [], 0, 8),
                        'images' => array_slice(is_array($designTokens['images'] ?? null) ? $designTokens['images'] : [], 0, 8),
                        'measurements' => array_slice(is_array($designTokens['measurements'] ?? null) ? $designTokens['measurements'] : [], 0, 8),
                        'numbers' => array_slice(is_array($designTokens['numbers'] ?? null) ? $designTokens['numbers'] : [], 0, 8),
                    ],
                    'classStrategy' => $designClassStrategy,
                    'designProfile' => [
                        'semanticClasses' => array_slice(is_array($designProfile['semanticClasses'] ?? null) ? $designProfile['semanticClasses'] : [], 0, 8),
                        'duplicateStylePatterns' => array_slice(is_array($designProfile['duplicateStylePatterns'] ?? null) ? $designProfile['duplicateStylePatterns'] : [], 0, 8),
                        'skippedStylePatterns' => array_slice(is_array($designProfile['skippedStylePatterns'] ?? null) ? $designProfile['skippedStylePatterns'] : [], 0, 8),
                        'elementApplications' => array_slice(is_array($designProfile['elementApplications'] ?? null) ? $designProfile['elementApplications'] : [], 0, 8),
                    ],
                ],
                'importPlan' => [
                    'version' => (int) ($importPlan['version'] ?? 0),
                    'status' => (string) ($importPlan['status'] ?? ''),
                    'canImport' => (bool) ($importPlan['canImport'] ?? false),
                    'nativeCoverage' => $nativeCoverage,
                    'fallbacks' => array_slice($fallbacks, 0, 8),
                    'productBoundaryDeferrals' => $productBoundaryDeferrals,
                    'styleRoutes' => array_slice($styleRoutes, 0, 8),
                    'pluginDependentStyleRoutes' => array_slice($pluginDependentStyleRoutes, 0, 8),
                    'tokenUsage' => $tokenUsage,
                    'classes' => is_array($importPlan['classes'] ?? null) ? $importPlan['classes'] : [],
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
                'unsupportedItems' => $unsupportedItems,
            ],
        ];

        return (array) apply_filters('oxy_html_converter_conversion_audit', $audit, $result, $options);
    }

    /**
     * @param array<int, mixed> $fallbacks
     * @return list<array<string, mixed>>
     */
    private function productBoundaryDeferrals(array $fallbacks): array
    {
        $deferrals = [];

        foreach ($fallbacks as $fallback) {
            if (!is_array($fallback)) {
                continue;
            }

            $type = (string) ($fallback['type'] ?? '');
            $route = (string) ($fallback['route'] ?? '');
            if (
                !in_array($type, ['advanced_component_scope_deferred', 'site_operation_scope_deferred'], true)
                && !in_array($route, ['component_scope_report', 'product_boundary_report'], true)
            ) {
                continue;
            }

            if (!is_string($fallback['target'] ?? null) || trim((string) $fallback['target']) === '') {
                $fallback['target'] = $route;
            }

            if (!is_array($fallback['persistence'] ?? null)) {
                $fallback['persistence'] = [];
            }
            if (!is_string($fallback['persistence']['target'] ?? null) || trim((string) $fallback['persistence']['target']) === '') {
                $fallback['persistence']['target'] = $route;
            }
            if (!is_string($fallback['persistence']['action'] ?? null) || trim((string) $fallback['persistence']['action']) === '') {
                $fallback['persistence']['action'] = 'report_only';
            }

            $deferrals[] = $fallback;
        }

        return $deferrals;
    }

    /**
     * @param array<int, mixed> $styleRoutes
     * @return list<array<string, mixed>>
     */
    private function pluginDependentStyleRoutes(array $styleRoutes): array
    {
        $routes = [];

        foreach ($styleRoutes as $route) {
            if (!is_array($route) || !is_array($route['pluginDependency'] ?? null)) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $result
     * @return array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,componentNodes:int,assetNodes:int,imageNodes:int,videoNodes:int,classAssignments:int,totalNodes:int}
     */
    private function summarizeConversionResultSurface(array $result): array
    {
        $summary = [
            'htmlCodeBlocks' => 0,
            'cssCodeBlocks' => 0,
            'javascriptCodeBlocks' => 0,
            'componentNodes' => 0,
            'assetNodes' => 0,
            'imageNodes' => 0,
            'videoNodes' => 0,
            'classAssignments' => 0,
            'totalNodes' => 0,
        ];

        $this->walkConvertedElement($result['element'] ?? null, $summary);

        foreach (['cssElement', 'headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
            $value = $result[$key] ?? null;
            if ($key === 'cssElement') {
                $this->walkConvertedElement($value, $summary);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $node) {
                $this->walkConvertedElement($node, $summary);
            }
        }

        return $summary;
    }

    /**
     * @param mixed $element
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,componentNodes:int,assetNodes:int,imageNodes:int,videoNodes:int,classAssignments:int,totalNodes:int} $summary
     */
    private function walkConvertedElement($element, array &$summary): void
    {
        if (!is_array($element)) {
            return;
        }

        $summary['totalNodes']++;
        $type = (string) ($element['data']['type'] ?? $element['type'] ?? '');

        if ($type === ElementTypes::HTML_CODE || str_ends_with($type, 'HtmlCode')) {
            $summary['htmlCodeBlocks']++;
        }

        if ($type === ElementTypes::CSS_CODE || str_ends_with($type, 'CssCode')) {
            $summary['cssCodeBlocks']++;
        }

        if ($type === ElementTypes::JAVASCRIPT_CODE || str_ends_with($type, 'JavaScriptCode')) {
            $summary['javascriptCodeBlocks']++;
        }

        if ($type === ElementTypes::COMPONENT || str_ends_with($type, 'Component')) {
            $summary['componentNodes']++;
        }

        if ($type === ElementTypes::IMAGE || str_ends_with($type, 'Image')) {
            $summary['imageNodes']++;
            $summary['assetNodes']++;
        }

        if ($type === ElementTypes::HTML5_VIDEO || str_ends_with($type, 'Html5Video')) {
            $summary['videoNodes']++;
            $summary['assetNodes']++;
        }

        $classes = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (is_array($classes)) {
            $summary['classAssignments'] += count(array_filter($classes, 'is_string'));
        }

        $children = $element['children'] ?? [];
        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->walkConvertedElement($child, $summary);
        }
    }

    /**
     * @param array<string, mixed> $assetNormalization
     * @param array<string, mixed> $summary
     * @return array{assets:list<array<string,mixed>>,byStatus:array<string,list<array<string,mixed>>>,truncated:bool,totalListed:int}
     */
    private function summarizeAssetNormalizationForAudit(array $assetNormalization, array $summary): array
    {
        $assets = is_array($assetNormalization['assets'] ?? null) ? $assetNormalization['assets'] : [];
        $bucketLimits = [
            'rejected' => 12,
            'manual_follow_up' => 12,
            'stable_reference' => 6,
            'localized' => 6,
        ];
        $byStatus = [
            'rejected' => [],
            'manual_follow_up' => [],
            'stable_reference' => [],
            'localized' => [],
        ];
        $listed = [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $status = (string) ($asset['status'] ?? 'stable_reference');
            if (!array_key_exists($status, $byStatus)) {
                $status = 'stable_reference';
            }

            if (count($byStatus[$status]) >= $bucketLimits[$status]) {
                continue;
            }

            $byStatus[$status][] = $asset;
            $listed[] = $asset;
        }

        $truncated = count($listed) < count(array_filter($assets, 'is_array'))
            || (int) ($summary['total'] ?? 0) > count($listed);

        return [
            'assets' => $listed,
            'byStatus' => $byStatus,
            'truncated' => $truncated,
            'totalListed' => count($listed),
        ];
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

    /**
     * @param mixed $items
     * @return list<array<string, string>>
     */
    private function normalizeUnsupportedItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'location' => $this->stringField($item, 'location', 'unknown'),
                'selector' => $this->stringField($item, 'selector', 'unknown'),
                'sourceSnippet' => $this->stringField($item, 'sourceSnippet', 'not captured'),
                'reason' => $this->stringField($item, 'reason', 'Unsupported structure requires review.'),
                'severity' => $this->stringField($item, 'severity', 'review'),
                'fallbackCategory' => $this->stringField($item, 'fallbackCategory', 'unsupported_structure'),
                'safeModeImpact' => $this->stringField($item, 'safeModeImpact', 'Requires review before import.'),
                'owner' => $this->stringField($item, 'owner', 'core'),
                'remediation' => $this->stringField($item, 'remediation', 'Map natively, remove it, or choose an explicit fallback.'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function stringField(array $item, string $field, string $default): string
    {
        $value = $item[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
