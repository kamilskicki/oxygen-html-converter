<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;

class ImportPlanBuilder
{
    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $designDocument
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $result, array $designDocument, array $options): array
    {
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $designSummary = is_array($designDocument['summary'] ?? null) ? $designDocument['summary'] : [];
        $surface = $this->summarizeConvertedSurface($result['element'] ?? []);
        $fallbacks = $this->buildFallbacks($result, $designSummary, $surface);
        $coverage = $this->buildNativeCoverage($stats, $surface, $fallbacks);
        $styleRoutes = $this->buildStyleRoutes($result);
        $globalStyleCount = $this->countGlobalStyleRoutes($styleRoutes, $result);
        $pageStyleCount = $this->countPageStyleRoutes($styleRoutes, $result);
        $tokens = $this->buildTokenPlan($designDocument);
        $components = $this->buildComponentPlan($designDocument);
        $selectors = $this->countSelectors($result);
        $strictNative = !empty($options['strictNative']);
        $validationErrors = $this->normalizeMessages($result['validationErrors'] ?? []);
        $errors = $this->normalizeMessages($stats['errors'] ?? []);
        $warnings = $this->normalizeMessages($stats['warnings'] ?? []);
        $blockers = [];

        foreach ($validationErrors as $validationError) {
            $blockers[] = 'Builder validation failed: ' . $validationError;
        }

        foreach ($errors as $error) {
            $blockers[] = 'Conversion error: ' . $error;
        }

        if ($strictNative) {
            foreach ($fallbacks as $fallback) {
                if (!empty($fallback['blockingInStrictNative'])) {
                    $blockers[] = 'Strict native mode blocks ' . $fallback['label'] . '.';
                }
            }
        }

        $blockers = array_values(array_unique($blockers));
        $hasFallbacks = $fallbacks !== [];
        $status = $blockers !== []
            ? 'blocked'
            : ($hasFallbacks || $warnings !== [] ? 'needs_review' : 'ready');

        $plan = [
            'version' => 1,
            'status' => $status,
            'canImport' => $status !== 'blocked',
            'mode' => [
                'strictNative' => $strictNative,
                'classStrategy' => (string) ($designDocument['classStrategy']['recommendation'] ?? 'hybrid'),
            ],
            'nativeCoverage' => $coverage,
            'fallbacks' => $fallbacks,
            'styleRoutes' => $styleRoutes,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'tokens' => $tokens,
            'components' => $components,
            'persistence' => [
                'page' => [
                    'action' => $status === 'blocked' ? 'do_not_create' : 'create_draft',
                    'reason' => $status === 'blocked'
                        ? 'Import is blocked until the plan has no blocking issues.'
                        : 'Create a draft page before replacing live content.',
                ],
                'selectors' => [
                    'action' => $selectors > 0 ? 'save_or_update' : 'none',
                    'proposed' => $selectors,
                ],
                'variables' => [
                    'action' => $this->countTokenPlanItems($tokens) > 0 ? 'save_or_update' : 'none',
                    'proposed' => $this->countTokenPlanItems($tokens),
                    'target' => 'oxygen_variables',
                    'repository' => OxygenVariableRepository::OPTION_NAME,
                    'mode' => 'merge_by_css_variable_name',
                ],
                'components' => [
                    'action' => $components !== [] ? 'review_before_global_save' : 'none',
                    'candidates' => count($components),
                ],
                'globalSettings' => [
                    'action' => count($tokens['colors']) > 0 ? 'save_or_update' : 'none',
                    'proposed' => count($tokens['colors']),
                    'target' => 'oxygen_global_settings',
                    'repository' => OxygenGlobalSettingsRepository::OPTION_NAME,
                    'mode' => 'merge_color_palette_and_explicit_sections',
                ],
                'globalStyles' => [
                    'action' => $globalStyleCount > 0 ? 'save_or_update' : 'none',
                    'proposed' => $globalStyleCount,
                    'bytes' => strlen(trim((string) ($result['globalCss'] ?? ''))),
                    'target' => 'oxygen_global_styles',
                    'repository' => GlobalStyleRepository::OPTION_NAME,
                ],
                'pageStyles' => [
                    'action' => $pageStyleCount > 0 ? 'save_or_update' : 'none',
                    'proposed' => $pageStyleCount,
                    'bytes' => strlen(trim((string) ($result['pageScopedCss'] ?? ''))),
                    'target' => 'post_meta_stylesheet',
                    'metaKey' => PageStyleRepository::META_KEY,
                ],
            ],
            'actions' => $this->buildActions($status, $fallbacks, $tokens, $components, $selectors),
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('oxy_html_converter_import_plan', $plan, $result, $designDocument, $options);

            if (is_array($filtered)) {
                return $filtered;
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $designSummary
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,totalNodes:int} $surface
     * @return list<array<string, mixed>>
     */
    private function buildFallbacks(array $result, array $designSummary, array $surface): array
    {
        $fallbacks = [];
        $htmlCodeBlocks = max((int) ($designSummary['htmlCodeBlocks'] ?? 0), $surface['htmlCodeBlocks']);
        $cssCodeBlocks = max((int) ($designSummary['cssCodeBlocks'] ?? 0), $surface['cssCodeBlocks']);
        $javascriptCodeBlocks = $surface['javascriptCodeBlocks'];
        $cssRoutes = $this->classifyCssRoutes($result, !empty($designSummary['fallbackCss']));
        $headScriptCount = is_array($result['headScriptElements'] ?? null) ? count($result['headScriptElements']) : 0;
        $iconScriptCount = is_array($result['iconScriptElements'] ?? null) ? count($result['iconScriptElements']) : 0;

        if ($htmlCodeBlocks > 0) {
            $fallbacks[] = [
                'type' => 'html_code',
                'label' => 'HTML code fallback block(s)',
                'count' => $htmlCodeBlocks,
                'severity' => 'blocking',
                'category' => 'page_fallback',
                'route' => 'page_html_code',
                'location' => 'converted element tree',
                'reason' => 'One or more source structures required a visible HtmlCode fallback.',
                'owner' => 'Core import plan',
                'remediation' => 'Map the structure to native Oxygen elements or explicitly choose an unsafe fallback profile.',
                'blockingInStrictNative' => true,
            ];
        }

        if ($cssCodeBlocks > 0 && ($cssRoutes['pageFallback'] || $cssRoutes['globalAssets'] === [])) {
            $fallbacks[] = [
                'type' => 'css_code',
                'label' => 'CSS code fallback block(s)',
                'count' => $cssCodeBlocks,
                'severity' => 'blocking',
                'category' => 'page_fallback',
                'route' => 'page_css_code',
                'location' => 'converted element tree',
                'reason' => 'One or more source styles required a visible CssCode fallback block.',
                'owner' => 'Core import plan',
                'remediation' => 'Route the CSS into selectors, global styles, or page-scoped metadata before strict import.',
                'persistence' => [
                    'target' => 'page_css_code',
                    'action' => 'insert_with_page',
                ],
                'blockingInStrictNative' => true,
            ];
        } elseif ($cssRoutes['pageFallback']) {
            $fallbacks[] = [
                'type' => 'extracted_css',
                'label' => 'extracted CSS fallback',
                'count' => 1,
                'severity' => 'blocking',
                'category' => 'page_fallback',
                'route' => 'page_css_code',
                'location' => 'source stylesheet',
                'reason' => 'Extracted CSS could not be fully represented through native selectors or owned style stores.',
                'owner' => 'Core import plan',
                'remediation' => 'Normalize the CSS into Oxygen selectors/global styles or approve a page fallback.',
                'persistence' => [
                    'target' => 'page_css_code',
                    'action' => 'insert_with_page',
                ],
                'blockingInStrictNative' => true,
            ];
        }

        foreach ($cssRoutes['globalAssets'] as $globalAsset) {
            $fallbacks[] = $globalAsset;
        }

        foreach ($cssRoutes['pageStyleAssets'] as $pageStyleAsset) {
            $fallbacks[] = $pageStyleAsset;
        }

        if ($javascriptCodeBlocks > 0) {
            $fallbacks[] = [
                'type' => 'javascript_code',
                'label' => 'JavaScript code block(s)',
                'count' => $javascriptCodeBlocks,
                'severity' => 'blocking',
                'category' => 'page_fallback',
                'route' => 'page_javascript_code',
                'location' => 'converted element tree',
                'reason' => 'Source behavior required a visible JavaScriptCode block.',
                'owner' => 'Core import plan',
                'remediation' => 'Remove the script, replace it with a safe native interaction, or explicitly opt in.',
                'blockingInStrictNative' => true,
            ];
        }

        if ($headScriptCount + $iconScriptCount > 0) {
            $fallbacks[] = [
                'type' => 'external_script',
                'label' => 'external or icon script asset(s)',
                'count' => $headScriptCount + $iconScriptCount,
                'severity' => 'review',
                'category' => 'global_asset',
                'route' => 'head_asset',
                'location' => 'document head',
                'reason' => 'External or icon script assets require operator review before persistence.',
                'owner' => 'Core import plan',
                'remediation' => 'Replace with a safe local asset, defer to Pro/future integration, or remove it.',
                'blockingInStrictNative' => true,
            ];
        }

        return $fallbacks;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function buildStyleRoutes(array $result): array
    {
        $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
        $routes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];
        $normalized = [];

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $type = trim((string) ($route['type'] ?? 'css'));
            $destination = trim((string) ($route['destination'] ?? 'page_css'));
            $label = trim((string) ($route['label'] ?? 'CSS'));

            if ($type === '' || $destination === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'destination' => $destination,
                'label' => $label === '' ? $this->styleRouteLabel($type, $destination) : $label,
                'bytes' => max(0, (int) ($route['bytes'] ?? 0)),
                'ruleCount' => max(0, (int) ($route['ruleCount'] ?? 0)),
                'hash' => is_scalar($route['hash'] ?? null) ? (string) $route['hash'] : '',
                'persistence' => $this->styleRoutePersistence($destination),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $styleRoutes
     * @param array<string, mixed> $result
     */
    private function countGlobalStyleRoutes(array $styleRoutes, array $result): int
    {
        $count = 0;

        foreach ($styleRoutes as $route) {
            if (($route['destination'] ?? '') === 'global_styles') {
                $count++;
            }
        }

        if ($count === 0 && trim((string) ($result['globalCss'] ?? '')) !== '') {
            return 1;
        }

        return $count;
    }

    /**
     * @return array<string, string>
     */
    private function styleRoutePersistence(string $destination): array
    {
        return match ($destination) {
            'global_styles' => [
                'target' => 'oxygen_global_styles',
                'action' => 'save_or_update',
            ],
            'windpress_runtime' => [
                'target' => 'windpress_runtime',
                'action' => 'do_not_emit_page_css',
            ],
            'page_scoped_styles' => [
                'target' => 'post_meta_stylesheet',
                'action' => 'save_or_update',
            ],
            'page_css' => [
                'target' => 'page_css_code',
                'action' => 'insert_with_page',
            ],
            default => [
                'target' => $destination,
                'action' => 'review',
            ],
        };
    }

    private function styleRouteLabel(string $type, string $destination): string
    {
        if ($destination === 'global_styles') {
            return 'Global style asset';
        }

        if ($destination === 'page_scoped_styles') {
            return 'Page scoped style asset';
        }

        return match ($type) {
            'native_mirror' => 'Native style mirror CSS',
            'tailwind_utility_fallback' => 'Tailwind utility fallback CSS',
            'source_style' => 'Source style CSS',
            default => 'CSS',
        };
    }

    /**
     * @param list<array<string, mixed>> $styleRoutes
     * @param array<string, mixed> $result
     */
    private function countPageStyleRoutes(array $styleRoutes, array $result): int
    {
        $count = 0;

        foreach ($styleRoutes as $route) {
            if (($route['destination'] ?? '') === 'page_scoped_styles') {
                $count++;
            }
        }

        if ($count === 0 && trim((string) ($result['pageScopedCss'] ?? '')) !== '') {
            return 1;
        }

        return $count;
    }

    /**
     * @return array{pageFallback:bool,globalAssets:list<array<string, mixed>>,pageStyleAssets:list<array<string, mixed>>}
     */
    private function classifyCssRoutes(array $result, bool $designSummaryHasFallbackCss): array
    {
        $extractedCss = trim((string) ($result['extractedCss'] ?? ''));
        $globalCss = trim((string) ($result['globalCss'] ?? ''));
        $css = trim($extractedCss . "\n" . $globalCss);
        $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
        $styleRoutingSummary = is_array($styleRouting['summary'] ?? null) ? $styleRouting['summary'] : [];
        $styleRoutingRoutes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];
        $hasPageCss = $extractedCss !== '' || !empty($styleRoutingSummary['hasPageCss']);
        $globalAssets = [];
        $pageStyleAssets = [];
        $globalOnlyCss = false;

        foreach ($styleRoutingRoutes as $route) {
            if (!is_array($route)) {
                continue;
            }

            if (($route['destination'] ?? '') === 'global_styles') {
                $routeType = (string) ($route['type'] ?? '');
                $label = $routeType === 'global_asset' && $this->containsMaterialSymbolsGlobalStyle($globalCss)
                    ? 'Material Symbols global style'
                    : (string) ($route['label'] ?? 'Global style asset');
                $globalAssets[] = $this->globalStyleAssetFallback($label);
                continue;
            }

            if (($route['destination'] ?? '') === 'page_scoped_styles') {
                $pageStyleAssets[] = $this->pageStyleAssetFallback((string) ($route['label'] ?? 'Page scoped style asset'));
            }
        }

        if ($globalAssets === [] && $this->containsMaterialSymbolsGlobalStyle($css)) {
            $globalAssets[] = $this->globalStyleAssetFallback('Material Symbols global style');
            $globalOnlyCss = $extractedCss === '' || $this->cssContainsOnlyMaterialSymbolsGlobalStyle($extractedCss);
        } elseif ($globalAssets !== []) {
            $globalOnlyCss = !$hasPageCss;
        }

        return [
            'pageFallback' => ($hasPageCss || $designSummaryHasFallbackCss) && !$globalOnlyCss,
            'globalAssets' => $this->dedupeFallbacks($globalAssets),
            'pageStyleAssets' => $this->dedupeFallbacks($pageStyleAssets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function globalStyleAssetFallback(string $label): array
    {
        $label = trim($label);
        if ($label === '' || $label === 'Global asset CSS') {
            $label = 'Global style asset';
        }

        return [
            'type' => 'global_style_asset',
            'label' => $label,
            'count' => 1,
            'severity' => 'review',
            'category' => 'global_asset',
            'route' => 'global_stylesheet',
            'location' => 'document/global CSS',
            'reason' => 'CSS is global asset support that should be owned by Oxygen global styles.',
            'owner' => 'Core import plan',
            'remediation' => 'Persist through the global style repository and include rollback coverage.',
            'persistence' => [
                'target' => 'oxygen_global_styles',
                'action' => 'save_or_update',
            ],
            'blockingInStrictNative' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageStyleAssetFallback(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            $label = 'Page scoped style asset';
        }

        return [
            'type' => 'page_scoped_style_asset',
            'label' => $label,
            'count' => 1,
            'severity' => 'review',
            'category' => 'page_scoped_asset',
            'route' => 'post_meta_stylesheet',
            'location' => 'page-scoped CSS',
            'reason' => 'CSS is required for page fidelity but is not a visible Oxygen code block.',
            'owner' => 'Core import plan',
            'remediation' => 'Persist as page-scoped style metadata with export and rollback ownership.',
            'persistence' => [
                'target' => 'post_meta_stylesheet',
                'action' => 'save_or_update',
            ],
            'blockingInStrictNative' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return list<array<string, mixed>>
     */
    private function dedupeFallbacks(array $fallbacks): array
    {
        $seen = [];
        $deduped = [];

        foreach ($fallbacks as $fallback) {
            $key = (string) ($fallback['type'] ?? '') . ':' . (string) ($fallback['route'] ?? '') . ':' . (string) ($fallback['label'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $fallback;
        }

        return $deduped;
    }

    private function containsMaterialSymbolsGlobalStyle(string $css): bool
    {
        return preg_match('/material\s+symbols|material-symbols/i', $css) === 1;
    }

    private function cssContainsOnlyMaterialSymbolsGlobalStyle(string $css): bool
    {
        $remaining = preg_replace('/\/\*.*?\*\//s', '', $css);
        $remaining = preg_replace('/@font-face\s*\{[^}]*material\s+symbols[^}]*\}\s*/is', '', (string) $remaining);
        $remaining = preg_replace('/\.material-symbols[^{]*\{[^}]*\}\s*/is', '', (string) $remaining);

        return trim((string) $remaining) === '';
    }

    /**
     * @param array<string, mixed> $stats
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,totalNodes:int} $surface
     * @param list<array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    private function buildNativeCoverage(array $stats, array $surface, array $fallbacks): array
    {
        $totalNodes = (int) ($stats['elements'] ?? 0);

        if ($totalNodes < 1) {
            $totalNodes = $surface['totalNodes'];
        }

        $fallbackNodeCount = 0;
        foreach ($fallbacks as $fallback) {
            if (in_array($fallback['type'] ?? '', ['html_code', 'css_code', 'javascript_code'], true)) {
                $fallbackNodeCount += (int) ($fallback['count'] ?? 0);
            }
        }

        $nativeNodes = max(0, $totalNodes - min($fallbackNodeCount, $totalNodes));
        $percent = $totalNodes > 0 ? round(($nativeNodes / $totalNodes) * 100, 2) : 100.0;

        return [
            'totalNodes' => $totalNodes,
            'nativeNodes' => $nativeNodes,
            'fallbackNodes' => $fallbackNodeCount,
            'percent' => $percent,
        ];
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return array{colors:list<array<string,mixed>>,fonts:list<array<string,mixed>>,spacing:list<array<string,mixed>>}
     */
    private function buildTokenPlan(array $designDocument): array
    {
        $tokens = is_array($designDocument['tokens'] ?? null) ? $designDocument['tokens'] : [];

        return [
            'colors' => $this->normalizeTokenGroup($tokens['colors'] ?? [], 'color'),
            'fonts' => $this->normalizeTokenGroup($tokens['fonts'] ?? [], 'font'),
            'spacing' => $this->normalizeTokenGroup($tokens['spacing'] ?? [], 'spacing'),
        ];
    }

    /**
     * @param mixed $tokens
     * @return list<array<string, mixed>>
     */
    private function normalizeTokenGroup($tokens, string $type): array
    {
        if (!is_array($tokens)) {
            return [];
        }

        $normalized = [];

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $value = trim((string) ($token['value'] ?? ''));
            $suggestedName = trim((string) ($token['suggestedName'] ?? ''));

            if ($value === '' || $suggestedName === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'value' => $value,
                'uses' => (int) ($token['uses'] ?? 0),
                'suggestedName' => $suggestedName,
                'action' => 'map_or_create_variable',
                'status' => 'proposed',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return list<array<string, mixed>>
     */
    private function buildComponentPlan(array $designDocument): array
    {
        $candidates = is_array($designDocument['componentCandidates'] ?? null)
            ? $designDocument['componentCandidates']
            : [];
        $components = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $suggestedName = trim((string) ($candidate['suggestedName'] ?? ''));

            if ($suggestedName === '') {
                continue;
            }

            $components[] = [
                'suggestedName' => $suggestedName,
                'signature' => (string) ($candidate['signature'] ?? ''),
                'occurrences' => (int) ($candidate['count'] ?? 0),
                'classes' => array_values(array_map('strval', is_array($candidate['classes'] ?? null) ? $candidate['classes'] : [])),
                'action' => 'review_component_candidate',
                'status' => 'proposed',
            ];
        }

        return $components;
    }

    /**
     * @param array{colors:list<array<string,mixed>>,fonts:list<array<string,mixed>>,spacing:list<array<string,mixed>>} $tokens
     */
    private function countTokenPlanItems(array $tokens): int
    {
        return count($tokens['colors']) + count($tokens['fonts']) + count($tokens['spacing']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function countSelectors(array $result): int
    {
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        return count(is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : []);
    }

    /**
     * @param mixed $element
     * @return array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,totalNodes:int}
     */
    private function summarizeConvertedSurface($element): array
    {
        $summary = [
            'htmlCodeBlocks' => 0,
            'cssCodeBlocks' => 0,
            'javascriptCodeBlocks' => 0,
            'totalNodes' => 0,
        ];

        $this->walkConvertedElement($element, $summary);

        return $summary;
    }

    /**
     * @param mixed $element
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,totalNodes:int} $summary
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

        $children = $element['children'] ?? [];

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->walkConvertedElement($child, $summary);
        }
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @param array{colors:list<array<string,mixed>>,fonts:list<array<string,mixed>>,spacing:list<array<string,mixed>>} $tokens
     * @param list<array<string, mixed>> $components
     * @return list<string>
     */
    private function buildActions(string $status, array $fallbacks, array $tokens, array $components, int $selectors): array
    {
        if ($status === 'blocked') {
            return [
                'Resolve blocking fallback or validation issues before importing.',
                'Use Preview to inspect the import plan after repairs.',
            ];
        }

        $actions = [];

        if ($fallbacks !== []) {
            $actions[] = 'Review fallback items before importing into a production page.';
        }

        if ($this->countTokenPlanItems($tokens) > 0) {
            $actions[] = 'Map detected tokens to existing Oxygen variables or approve new variables.';
        }

        if ($components !== []) {
            $actions[] = 'Review component candidates before saving reusable/global structures.';
        }

        if ($selectors > 0) {
            $actions[] = 'Persist selector payload before inserting elements that reference generated classes.';
        }

        $actions[] = 'Create or update a draft page, then verify editability in Oxygen.';

        return array_values(array_unique($actions));
    }

    /**
     * @param mixed $messages
     * @return list<string>
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
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
