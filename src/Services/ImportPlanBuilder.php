<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;

class ImportPlanBuilder
{
    private const COMPONENT_MIN_OCCURRENCES = 3;
    private const COMPONENT_MIN_CONFIDENCE = 0.75;
    private const COMPONENT_MIN_EDITABLE_PROPERTIES = 1;
    private const ADVANCED_COMPONENT_SCOPE = [
        'variants' => [
            'label' => 'variants',
            'status' => 'future',
            'extensionPoint' => 'oxy_html_converter_component_variant_mapper',
            'reason' => 'Variant serialization and Builder controls do not yet have a verified Oxygen 6 Core contract.',
        ],
        'repeated_regions' => [
            'label' => 'repeated regions',
            'status' => 'future',
            'extensionPoint' => 'oxy_html_converter_component_repeated_region_mapper',
            'reason' => 'Component-internal repeater and repeated-region controls are deferred until a verified Oxygen 6 fixture exists.',
        ],
        'lists' => [
            'label' => 'lists',
            'status' => 'future',
            'extensionPoint' => 'oxy_html_converter_component_list_mapper',
            'reason' => 'Editable list/repeater component properties are deferred; Core may still import static list markup.',
        ],
        'forms' => [
            'label' => 'forms',
            'status' => 'unsupported',
            'extensionPoint' => 'oxy_html_converter_component_form_mapper',
            'reason' => 'Functional form component properties require an approved form integration and are unsupported in Core safe mode.',
        ],
        'dynamic_data' => [
            'label' => 'dynamic data',
            'status' => 'pro',
            'extensionPoint' => 'oxy_html_converter_pro_dynamic_component_mapper',
            'reason' => 'Dynamic data, loops, archives, and CMS bindings are Pro integration scope.',
        ],
        'component_scoped_css' => [
            'label' => 'component-scoped CSS',
            'status' => 'core',
            'extensionPoint' => 'oxy_html_converter_component_scoped_css_mapper',
            'reason' => 'Component CSS ownership and host-page merge are supported by the Core component CSS bridge.',
        ],
    ];

    private const SITE_OPERATION_SCOPE = [
        'homepage' => [
            'label' => 'homepage assignment',
            'status' => 'core',
            'extensionPoint' => 'oxy_html_converter_site_homepage_importer',
            'reason' => 'Core can assign static homepage and posts-page options from a site-kit manifest.',
        ],
        'menus' => [
            'label' => 'WordPress menus',
            'status' => 'core',
            'extensionPoint' => 'oxy_html_converter_site_menu_importer',
            'reason' => 'Core can create/select WordPress menus, items, and theme-location placements from a site-kit manifest.',
        ],
        'single_templates' => [
            'label' => 'single templates',
            'status' => 'core',
            'extensionPoint' => 'oxy_html_converter_template_importer',
            'reason' => 'Core can persist Oxygen template posts with verified static single-template conditions.',
        ],
        'archive_templates' => [
            'label' => 'archive templates',
            'status' => 'core',
            'extensionPoint' => 'oxy_html_converter_template_importer',
            'reason' => 'Core can persist Oxygen template posts with verified static archive-template conditions.',
        ],
        'dynamic_bindings' => [
            'label' => 'dynamic bindings',
            'status' => 'pro',
            'extensionPoint' => 'oxy_html_converter_pro_dynamic_binding_mapper',
            'reason' => 'CMS dynamic bindings require a verified dynamic-data mapper; Core must not serialize guessed binding paths.',
        ],
        'loops' => [
            'label' => 'loops and repeaters',
            'status' => 'pro',
            'extensionPoint' => 'oxy_html_converter_pro_loop_mapper',
            'reason' => 'Query loops and repeaters require CMS-aware mapping and runtime semantics outside public Core.',
        ],
        'woocommerce' => [
            'label' => 'WooCommerce areas',
            'status' => 'pro',
            'extensionPoint' => 'oxy_html_converter_pro_woocommerce_mapper',
            'reason' => 'Product, cart, checkout, and account areas require WooCommerce-aware Pro integration.',
        ],
    ];

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
        $surface = $this->summarizeConversionResultSurface($result);
        $hasVisibleCssCodeFallback = !empty($options['includeCssElement']) && $surface['cssCodeBlocks'] > 0;
        $advancedComponentScope = $this->buildAdvancedComponentScope($designDocument);
        $manifestSections = $this->buildManifestSections($result, $designDocument, $options);
        $siteOperationScope = $this->buildSiteOperationScope($result, $designDocument, $options, $manifestSections);
        $fallbacks = array_merge(
            $this->buildFallbacks($result, $designSummary, $surface, $options),
            $this->advancedComponentScopeFallbacks($advancedComponentScope),
            $this->siteOperationScopeFallbacks($siteOperationScope)
        );
        $coverage = $this->buildNativeCoverage($stats, $surface, $fallbacks);
        $styleRoutes = $this->buildStyleRoutes($result, $hasVisibleCssCodeFallback);
        $globalStyleCount = $this->countGlobalStyleRoutes($styleRoutes, $result);
        $pageStyleCount = $this->countPageStyleRoutes($styleRoutes, $result, $hasVisibleCssCodeFallback);
        $tokens = $this->buildTokenPlan($designDocument);
        $components = $this->buildComponentPlan($designDocument, $options);
        $componentPersistence = $this->summarizeComponentPersistence($components);
        $componentThreshold = $this->componentThreshold([], $options);
        $classPlan = $this->buildClassPlan($designDocument);
        $templateManifestCount = $this->countTemplateManifestSections($manifestSections);
        $selectors = $this->countSelectors($result);
        $oxygenGlobalSettings = $this->resolveGlobalSettings($designDocument);
        $globalSettingsProposed = $this->countGlobalSettingsProposals($oxygenGlobalSettings, $tokens);
        $strictNative = !empty($options['strictNative']);
        $validationErrors = $this->normalizeMessages($result['validationErrors'] ?? []);
        $errors = $this->normalizeMessages($stats['errors'] ?? []);
        $warnings = $this->normalizeMessages($stats['warnings'] ?? []);
        $tokenUsage = is_array($result['tokenUsage'] ?? null) ? $result['tokenUsage'] : [];
        $blockers = [];

        foreach ($validationErrors as $validationError) {
            $blockers[] = 'Builder validation failed: ' . $validationError;
        }

        foreach ($errors as $error) {
            $blockers[] = 'Conversion error: ' . $error;
        }

        if ((int) ($tokenUsage['orphanCount'] ?? 0) > 0) {
            $blockers[] = 'Token binding left ' . (int) $tokenUsage['orphanCount'] . ' supported token variable(s) unused.';
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
                'selectors' => $selectors,
                'unsupportedItems' => count(is_array($stats['unsupportedItems'] ?? null) ? $stats['unsupportedItems'] : []),
            ],
            'fallbacks' => $fallbacks,
            'styleRoutes' => $styleRoutes,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'tokenUsage' => $tokenUsage,
            'tokens' => $tokens,
            'components' => $components,
            'advancedComponentScope' => $advancedComponentScope,
            'siteOperationScope' => $siteOperationScope,
            'classes' => $classPlan,
            'manifestSections' => $manifestSections,
            'oxygenGlobalSettings' => $oxygenGlobalSettings,
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
                'templates' => [
                    'action' => $templateManifestCount > 0 ? 'review_manifest_sections' : 'none',
                    'proposed' => $templateManifestCount,
                    'sections' => [
                        'templates' => count($manifestSections['templates']),
                        'headers' => count($manifestSections['headers']),
                        'footers' => count($manifestSections['footers']),
                        'parts' => count($manifestSections['parts']),
                    ],
                    'postTypes' => [
                        'templates' => 'oxygen_template',
                        'headers' => 'oxygen_header',
                        'footers' => 'oxygen_footer',
                        'parts' => 'oxygen_part',
                    ],
                ],
                'siteConfiguration' => [
                    'action' => $this->hasDetectedCoreSiteOperation($siteOperationScope, ['homepage', 'menus'])
                        ? 'apply_site_configuration'
                        : 'none',
                    'target' => 'wordpress_site_configuration',
                    'repository' => SiteConfigurationImporter::class,
                    'extensionPoint' => 'oxy_html_converter_site_configuration_importer',
                    'homepage' => $this->siteOperationDetectedCount($siteOperationScope, 'homepage') > 0,
                    'menus' => $this->siteOperationDetectedCount($siteOperationScope, 'menus'),
                ],
                'variables' => [
                    'action' => $this->countTokenPlanItems($tokens) > 0 ? 'save_or_update' : 'none',
                    'proposed' => $this->countTokenPlanItems($tokens),
                    'target' => 'oxygen_variables',
                    'repository' => OxygenVariableRepository::OPTION_NAME,
                    'mode' => 'merge_by_css_variable_name',
                ],
                'components' => [
                    'action' => $componentPersistence['candidates'] > 0
                        ? (
                            $componentPersistence['ready'] + $componentPersistence['created'] + $componentPersistence['updated'] > 0
                                ? 'save_or_update_blocks'
                                : 'review_component_candidates'
                        )
                        : 'none',
                    'target' => OxygenBlockRepository::POST_TYPE,
                    'repository' => OxygenBlockRepository::POST_TYPE,
                    'metaKeys' => [
                        '_oxygen_data',
                        '_breakdance_block_settings',
                    ],
                    'threshold' => $componentThreshold,
                    'rollbackStores' => [
                        'post',
                        '_oxygen_data',
                        '_breakdance_block_settings',
                    ],
                    'candidates' => $componentPersistence['candidates'],
                    'ready' => $componentPersistence['ready'],
                    'created' => $componentPersistence['created'],
                    'updated' => $componentPersistence['updated'],
                    'skipped' => $componentPersistence['skipped'],
                    'deduplicatedInstances' => $componentPersistence['deduplicatedInstances'],
                    'skippedCandidates' => $componentPersistence['skippedCandidates'],
                    'reasons' => $componentPersistence['reasons'],
                ],
                'globalSettings' => [
                    'action' => $globalSettingsProposed > 0 ? 'save_or_update' : 'none',
                    'proposed' => $globalSettingsProposed,
                    'target' => 'oxygen_global_settings',
                    'repository' => OxygenGlobalSettingsRepository::OPTION_NAME,
                    'mode' => 'merge_inferred_palette_typography_containers_code_and_explicit_sections',
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
                    'bytes' => strlen($this->pageStyleCssForResult($result, $hasVisibleCssCodeFallback)),
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
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,componentNodes:int,assetNodes:int,imageNodes:int,videoNodes:int,classAssignments:int,totalNodes:int} $surface
     * @return list<array<string, mixed>>
     */
    private function buildFallbacks(array $result, array $designSummary, array $surface, array $options): array
    {
        $fallbacks = [];
        $htmlCodeBlocks = max((int) ($designSummary['htmlCodeBlocks'] ?? 0), $surface['htmlCodeBlocks']);
        $cssCodeBlocks = max((int) ($designSummary['cssCodeBlocks'] ?? 0), $surface['cssCodeBlocks']);
        $javascriptCodeBlocks = $surface['javascriptCodeBlocks'];
        $cssRoutes = $this->classifyCssRoutes($result, !empty($designSummary['fallbackCss']));
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
            $pageCssPersistence = !empty($options['includeCssElement'])
                ? [
                    'target' => 'page_css_code',
                    'action' => 'insert_with_page',
                ]
                : [
                    'target' => 'post_meta_stylesheet',
                    'action' => 'save_or_update',
                ];
            $fallbacks[] = [
                'type' => 'extracted_css',
                'label' => 'extracted CSS fallback',
                'count' => 1,
                'severity' => 'blocking',
                'category' => 'page_fallback',
                'route' => (string) $pageCssPersistence['target'],
                'location' => 'source stylesheet',
                'reason' => 'Extracted CSS could not be fully represented through native selectors or owned style stores.',
                'owner' => 'Core import plan',
                'remediation' => 'Normalize the CSS into Oxygen selectors/global styles or approve a page fallback.',
                'persistence' => $pageCssPersistence,
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

        foreach ($this->buildUnsupportedItemFallbacks($result) as $fallback) {
            $fallbacks[] = $fallback;
        }

        return $fallbacks;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function buildUnsupportedItemFallbacks(array $result): array
    {
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $items = is_array($stats['unsupportedItems'] ?? null) ? $stats['unsupportedItems'] : [];
        $fallbacks = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->unsupportedFallbackLabel($item);
            $fallbacks[] = [
                'type' => 'unsupported_item',
                'label' => $label,
                'count' => 1,
                'severity' => $this->stringField($item, 'severity', 'blocking'),
                'category' => $this->stringField($item, 'fallbackCategory', 'unsupported_structure'),
                'route' => 'unsupported_report',
                'location' => $this->stringField($item, 'location', 'unknown'),
                'selector' => $this->stringField($item, 'selector', 'unknown'),
                'sourceSnippet' => $this->stringField($item, 'sourceSnippet', 'not captured'),
                'safeModeImpact' => $this->stringField($item, 'safeModeImpact', 'Requires review before import.'),
                'reason' => $this->stringField($item, 'reason', 'Unsupported structure requires review.'),
                'owner' => $this->stringField($item, 'owner', 'Core native profile'),
                'remediation' => $this->stringField($item, 'remediation', 'Map natively, remove it, or choose an explicit fallback.'),
                'persistence' => [
                    'target' => 'report_only',
                    'action' => 'do_not_persist_without_resolution',
                ],
                'blockingInStrictNative' => true,
            ];
        }

        return $fallbacks;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function unsupportedFallbackLabel(array $item): string
    {
        $selector = $this->stringField($item, 'selector', 'unsupported structure');

        return 'Unsupported structure: ' . $selector;
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

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function buildStyleRoutes(array $result, bool $pageCssUsesVisibleCssCodeFallback): array
    {
        $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
        $routes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];
        $normalized = [];

        foreach ($routes as $index => $route) {
            if (!is_array($route)) {
                continue;
            }

            $type = trim((string) ($route['type'] ?? 'css'));
            $destination = trim((string) ($route['destination'] ?? 'page_css'));
            $label = trim((string) ($route['label'] ?? 'CSS'));

            if ($type === '' || $destination === '') {
                continue;
            }

            $actualDestination = $pageCssUsesVisibleCssCodeFallback && $destination === 'page_css'
                ? 'page_css_code'
                : $destination;

            $normalized[] = [
                'type' => $type,
                'destination' => $actualDestination,
                'label' => $label === '' ? $this->styleRouteLabel($type, $actualDestination) : $label,
                'owner' => is_string($route['owner'] ?? null) ? $route['owner'] : $this->styleRouteOwner($type, $actualDestination),
                'cascadeOrder' => (int) ($route['cascadeOrder'] ?? $this->styleRouteCascadeOrder($actualDestination, $index)),
                'exportBehavior' => $pageCssUsesVisibleCssCodeFallback && $destination === 'page_css'
                    ? $this->styleRouteExportBehavior($type, $actualDestination)
                    : (is_string($route['exportBehavior'] ?? null) ? $route['exportBehavior'] : $this->styleRouteExportBehavior($type, $actualDestination)),
                'rollbackStore' => $pageCssUsesVisibleCssCodeFallback && $destination === 'page_css'
                    ? $this->styleRouteRollbackStore($actualDestination)
                    : (is_string($route['rollbackStore'] ?? null) ? $route['rollbackStore'] : $this->styleRouteRollbackStore($actualDestination)),
                'pluginDependency' => is_array($route['pluginDependency'] ?? null) ? $route['pluginDependency'] : $this->styleRoutePluginDependency($type, $actualDestination),
                'bytes' => max(0, (int) ($route['bytes'] ?? 0)),
                'ruleCount' => max(0, (int) ($route['ruleCount'] ?? 0)),
                'hash' => is_scalar($route['hash'] ?? null) ? (string) $route['hash'] : '',
                'persistence' => $this->styleRoutePersistence($actualDestination),
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
                'target' => 'post_meta_stylesheet',
                'action' => 'save_or_update',
            ],
            'page_css_code' => [
                'target' => 'page_css_code',
                'action' => 'insert_with_page',
            ],
            default => [
                'target' => $destination,
                'action' => 'review',
            ],
        };
    }

    private function styleRouteOwner(string $type, string $destination): string
    {
        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'runtime_plugin_dependency';
        }

        return $destination === 'global_styles' ? 'global' : 'page';
    }

    private function styleRouteCascadeOrder(string $destination, int $index): int
    {
        $base = $destination === 'global_styles' ? 100 : 1000;

        return $base + (($index + 1) * 10);
    }

    private function styleRouteExportBehavior(string $type, string $destination): string
    {
        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'requires_runtime_plugin';
        }

        return $destination === 'global_styles' ? 'export_with_global_styles' : 'export_with_page_manifest';
    }

    private function styleRouteRollbackStore(string $destination): string
    {
        return match ($destination) {
            'global_styles' => 'global_styles',
            'page_css_code' => 'page_document',
            default => 'page_styles',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function styleRoutePluginDependency(string $type, string $destination): ?array
    {
        if ($type !== 'tailwind_utility_fallback' || $destination !== 'page_scoped_styles') {
            return null;
        }

        return [
            'slug' => 'windpress',
            'name' => 'WindPress',
            'required' => true,
            'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
        ];
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
    private function countPageStyleRoutes(array $styleRoutes, array $result, bool $skipPageCss): int
    {
        $count = 0;

        foreach ($styleRoutes as $route) {
            $destination = (string) ($route['destination'] ?? '');
            if ($destination === 'page_css' && $skipPageCss) {
                continue;
            }

            if (in_array($destination, ['page_css', 'page_scoped_styles'], true)) {
                $count++;
            }
        }

        if ($count === 0 && $this->pageStyleCssForResult($result, $skipPageCss) !== '') {
            return 1;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function pageStyleCssForResult(array $result, bool $skipPageCss): string
    {
        $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
        $pageCss = $skipPageCss ? '' : (is_string($result['pageCss'] ?? null)
            ? trim($result['pageCss'])
            : trim((string) ($styleRouting['pageCss'] ?? '')));
        $pageScopedCss = is_string($result['pageScopedCss'] ?? null)
            ? trim($result['pageScopedCss'])
            : trim((string) ($styleRouting['pageScopedCss'] ?? ''));

        return trim($pageCss . "\n\n" . $pageScopedCss);
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
     * @param array{htmlCodeBlocks:int,cssCodeBlocks:int,javascriptCodeBlocks:int,componentNodes:int,assetNodes:int,imageNodes:int,videoNodes:int,classAssignments:int,totalNodes:int} $surface
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
     * @return array{colors:list<array<string,mixed>>,fonts:list<array<string,mixed>>,spacing:list<array<string,mixed>>,images:list<array<string,mixed>>,measurements:list<array<string,mixed>>,numbers:list<array<string,mixed>>}
     */
    private function buildTokenPlan(array $designDocument): array
    {
        $tokens = is_array($designDocument['tokens'] ?? null) ? $designDocument['tokens'] : [];

        return [
            'colors' => $this->normalizeTokenGroup($tokens['colors'] ?? [], 'color'),
            'fonts' => $this->normalizeTokenGroup($tokens['fonts'] ?? [], 'font'),
            'spacing' => $this->normalizeTokenGroup($tokens['spacing'] ?? [], 'spacing'),
            'images' => $this->normalizeTokenGroup($tokens['images'] ?? [], 'image'),
            'measurements' => $this->normalizeTokenGroup($tokens['measurements'] ?? [], 'measurement'),
            'numbers' => $this->normalizeTokenGroup($tokens['numbers'] ?? [], 'number'),
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

            $value = $token['value'] ?? null;
            $suggestedName = is_scalar($token['suggestedName'] ?? null) ? trim((string) $token['suggestedName']) : '';

            if (!$this->hasUsableTokenValue($value) || $suggestedName === '') {
                continue;
            }

            $record = [
                'type' => $type,
                'value' => $value,
                'uses' => (int) ($token['uses'] ?? 0),
                'suggestedName' => $suggestedName,
                'action' => 'map_or_create_variable',
                'status' => 'proposed',
            ];

            if (array_key_exists('dynamicData', $token)) {
                $record['dynamicData'] = $token['dynamicData'];
            }

            $normalized[] = $record;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function hasUsableTokenValue($value): bool
    {
        if (is_scalar($value)) {
            return trim((string) $value) !== '';
        }

        if (!is_array($value)) {
            return false;
        }

        foreach (['value', 'url'] as $field) {
            if (is_scalar($value[$field] ?? null) && trim((string) $value[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return list<array<string, mixed>>
     */
    private function buildComponentPlan(array $designDocument, array $options): array
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

            $threshold = $this->componentThreshold($candidate, $options);
            $occurrences = max(0, (int) ($candidate['occurrences'] ?? $candidate['count'] ?? 0));
            $confidence = $this->componentConfidence($candidate, $occurrences, $threshold['minOccurrences']);
            $hasTree = $this->componentCandidateHasTree($candidate);
            $persistenceResult = is_array($candidate['persistenceResult'] ?? null) ? $candidate['persistenceResult'] : [];
            $persistedAction = is_string($persistenceResult['action'] ?? null) ? (string) $persistenceResult['action'] : '';
            $advancedPatterns = $this->advancedComponentPatternsForCandidate($candidate);
            $editablePropertyCount = $this->componentEditablePropertyCount($candidate);
            $editablePropertiesSufficient = $editablePropertyCount >= (int) $threshold['minEditableProperties'];
            $candidateInstances = $this->componentCandidateInstances($candidate, $occurrences);
            $reasons = $this->componentSkipReasons(
                $candidate,
                $occurrences,
                $confidence,
                $hasTree,
                $threshold,
                $editablePropertyCount
            );
            if ($this->deferredAdvancedComponentPatterns($advancedPatterns) !== []) {
                $reasons[] = 'advanced_component_scope_deferred';
                $reasons = array_values(array_unique($reasons));
            }
            $status = $this->componentStatus($reasons, $persistedAction);
            $action = match ($status) {
                'created', 'updated' => 'persisted_oxygen_block',
                'ready' => 'save_or_update_oxygen_block',
                default => 'skip_component_candidate',
            };

            $components[] = [
                'suggestedName' => $suggestedName,
                'signature' => (string) ($candidate['signature'] ?? ''),
                'occurrences' => $occurrences,
                'confidence' => $confidence,
                'threshold' => $threshold,
                'eligible' => $reasons === [],
                'editablePropertyCount' => $editablePropertyCount,
                'editablePropertiesSufficient' => $editablePropertiesSufficient,
                'candidateInstances' => $candidateInstances,
                'deduplicatedInstances' => $status === 'skipped' ? [] : $candidateInstances,
                'deduplicatedInstanceCount' => $status === 'skipped' ? 0 : count($candidateInstances),
                'classes' => array_values(array_map('strval', is_array($candidate['classes'] ?? null) ? $candidate['classes'] : [])),
                'action' => $action,
                'status' => $status,
                'reason' => $reasons[0] ?? '',
                'reasons' => $reasons,
                'advancedPatterns' => $advancedPatterns,
                'deferredPatterns' => $this->deferredAdvancedComponentPatterns($advancedPatterns),
                'postType' => OxygenBlockRepository::POST_TYPE,
                'postId' => (int) ($persistenceResult['postId'] ?? $candidate['postId'] ?? $candidate['post_id'] ?? 0),
                'treeHash' => is_scalar($persistenceResult['treeHash'] ?? $candidate['treeHash'] ?? null)
                    ? (string) ($persistenceResult['treeHash'] ?? $candidate['treeHash'])
                    : '',
                'settingsHash' => is_scalar($persistenceResult['settingsHash'] ?? $candidate['settingsHash'] ?? null)
                    ? (string) ($persistenceResult['settingsHash'] ?? $candidate['settingsHash'])
                    : '',
                'persistence' => [
                    'target' => OxygenBlockRepository::POST_TYPE,
                    'metaKeys' => [
                        '_oxygen_data',
                        '_breakdance_block_settings',
                    ],
                    'rollbackStores' => [
                        'post',
                        '_oxygen_data',
                        '_breakdance_block_settings',
                    ],
                ],
            ];
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return array<string, mixed>
     */
    private function buildAdvancedComponentScope(array $designDocument): array
    {
        $detected = [];
        $candidates = is_array($designDocument['componentCandidates'] ?? null)
            ? $designDocument['componentCandidates']
            : [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $suggestedName = is_string($candidate['suggestedName'] ?? null) ? trim((string) $candidate['suggestedName']) : '';
            foreach ($this->advancedComponentPatternsForCandidate($candidate) as $pattern) {
                $key = (string) ($pattern['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                if (!isset($detected[$key])) {
                    $detected[$key] = array_merge($this->advancedComponentScopeRecord($key), [
                        'count' => 0,
                        'candidates' => [],
                    ]);
                }

                $detected[$key]['count'] = (int) $detected[$key]['count'] + 1;
                if ($suggestedName !== '') {
                    $detected[$key]['candidates'][] = $suggestedName;
                    $detected[$key]['candidates'] = array_values(array_unique($detected[$key]['candidates']));
                }
            }
        }

        $matrix = [];
        foreach (array_keys(self::ADVANCED_COMPONENT_SCOPE) as $key) {
            $matrix[] = $this->advancedComponentScopeRecord($key);
        }

        $detectedValues = array_values($detected);
        $deferred = array_values(array_filter(
            $detectedValues,
            static fn (array $pattern): bool => ($pattern['status'] ?? '') !== 'core'
        ));

        return [
            'version' => 1,
            'matrix' => $matrix,
            'detected' => $detectedValues,
            'deferred' => $deferred,
            'summary' => [
                'detected' => count($detectedValues),
                'deferred' => count($deferred),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $advancedComponentScope
     * @return list<array<string, mixed>>
     */
    private function advancedComponentScopeFallbacks(array $advancedComponentScope): array
    {
        $fallbacks = [];
        foreach (is_array($advancedComponentScope['deferred'] ?? null) ? $advancedComponentScope['deferred'] : [] as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }

            $status = (string) ($pattern['status'] ?? 'future');
            $label = (string) ($pattern['label'] ?? 'advanced component pattern');
            $fallbacks[] = [
                'type' => 'advanced_component_scope_deferred',
                'label' => 'Advanced component pattern deferred: ' . $label,
                'count' => (int) ($pattern['count'] ?? 1),
                'severity' => $status === 'unsupported' ? 'blocking' : 'review',
                'category' => 'advanced_component_' . $status,
                'route' => 'component_scope_report',
                'target' => 'component_scope_report',
                'location' => 'componentCandidates',
                'reason' => (string) ($pattern['reason'] ?? 'Advanced component pattern is outside current Core scope.'),
                'owner' => 'Core component scope boundary',
                'remediation' => 'Use static Core output, defer the pattern, or implement '
                    . (string) ($pattern['extensionPoint'] ?? 'a documented extension point')
                    . ' in a verified extension.',
                'extensionPoint' => (string) ($pattern['extensionPoint'] ?? ''),
                'persistence' => [
                    'target' => 'component_scope_report',
                    'action' => 'report_only',
                ],
                'blockingInStrictNative' => $status === 'unsupported',
            ];
        }

        return $fallbacks;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $designDocument
     * @param array<string, mixed> $options
     * @param array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>} $manifestSections
     * @return array<string, mixed>
     */
    private function buildSiteOperationScope(array $result, array $designDocument, array $options, array $manifestSections): array
    {
        $manifest = $this->sourceManifest($result, $designDocument, $options);
        $detected = [];

        if ($this->normalizeHomepageManifest($manifest['homepage'] ?? null) !== []) {
            $this->addSiteOperationDetection($detected, 'homepage', '$.homepage');
        }

        foreach ($this->manifestList($manifest, 'menus') as $index => $menu) {
            $this->addSiteOperationDetection($detected, 'menus', '$.menus[' . (int) $index . ']');
        }

        foreach ($manifestSections['templates'] as $index => $template) {
            $operationScope = is_string($template['operationScope'] ?? null) ? (string) $template['operationScope'] : '';
            if ($operationScope === 'single_template') {
                $this->addSiteOperationDetection($detected, 'single_templates', '$.templates[' . (int) $index . ']');
            } elseif ($operationScope === 'archive_template') {
                $this->addSiteOperationDetection($detected, 'archive_templates', '$.templates[' . (int) $index . ']');
            }
        }

        foreach (['unsupportedItems', 'unsupported'] as $field) {
            foreach ($this->manifestList($manifest, $field) as $index => $item) {
                $key = $this->siteOperationKeyFromManifestItem($item);
                if ($key !== '') {
                    $this->addSiteOperationDetection($detected, $key, '$.' . $field . '[' . (int) $index . ']');
                }
            }
        }
        $this->addTopLevelSiteOperationDetections($detected, $manifest, ['dynamicBindings', 'dynamic_bindings'], 'dynamic_bindings');
        $this->addTopLevelSiteOperationDetections($detected, $manifest, ['loops', 'queryLoops', 'query_loops'], 'loops');
        $this->addTopLevelSiteOperationDetections($detected, $manifest, ['woocommerce', 'wooCommerce'], 'woocommerce');

        $matrix = [];
        foreach (array_keys(self::SITE_OPERATION_SCOPE) as $key) {
            $matrix[] = $this->siteOperationScopeRecord($key);
        }

        $detectedValues = array_values($detected);
        $deferred = array_values(array_filter(
            $detectedValues,
            static fn (array $operation): bool => ($operation['status'] ?? '') !== 'core'
        ));

        return [
            'version' => 1,
            'matrix' => $matrix,
            'detected' => $detectedValues,
            'deferred' => $deferred,
            'summary' => [
                'detected' => count($detectedValues),
                'deferred' => count($deferred),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $siteOperationScope
     * @return list<array<string, mixed>>
     */
    private function siteOperationScopeFallbacks(array $siteOperationScope): array
    {
        $fallbacks = [];
        foreach (is_array($siteOperationScope['deferred'] ?? null) ? $siteOperationScope['deferred'] : [] as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $status = (string) ($operation['status'] ?? 'future');
            $label = (string) ($operation['label'] ?? 'site operation');
            $fallbacks[] = [
                'type' => 'site_operation_scope_deferred',
                'label' => 'Site operation deferred: ' . $label,
                'count' => (int) ($operation['count'] ?? 1),
                'severity' => $status === 'unsupported' ? 'blocking' : 'review',
                'category' => 'site_operation_' . $status,
                'route' => 'product_boundary_report',
                'target' => 'product_boundary_report',
                'location' => 'siteKitManifest',
                'reason' => (string) ($operation['reason'] ?? 'Site operation is outside current Core scope.'),
                'owner' => 'Core product boundary',
                'remediation' => 'Keep static Core output, defer the operation, or implement '
                    . (string) ($operation['extensionPoint'] ?? 'a documented extension point')
                    . ' in a verified extension.',
                'extensionPoint' => (string) ($operation['extensionPoint'] ?? ''),
                'persistence' => [
                    'target' => 'product_boundary_report',
                    'action' => 'report_only',
                ],
                'blockingInStrictNative' => $status === 'unsupported',
            ];
        }

        return $fallbacks;
    }

    /**
     * @param array<string, array<string, mixed>> $detected
     */
    private function addSiteOperationDetection(array &$detected, string $key, string $location): void
    {
        if ($key === '' || !isset(self::SITE_OPERATION_SCOPE[$key])) {
            return;
        }

        if (!isset($detected[$key])) {
            $detected[$key] = array_merge($this->siteOperationScopeRecord($key), [
                'count' => 0,
                'locations' => [],
            ]);
        }

        $detected[$key]['count'] = (int) $detected[$key]['count'] + 1;
        $locations = is_array($detected[$key]['locations'] ?? null) ? $detected[$key]['locations'] : [];
        $locations[] = $location;
        $detected[$key]['locations'] = array_values(array_unique(array_map('strval', $locations)));
    }

    /**
     * @param array<string, array<string, mixed>> $detected
     * @param array<string, mixed> $manifest
     * @param list<string> $fields
     */
    private function addTopLevelSiteOperationDetections(array &$detected, array $manifest, array $fields, string $key): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $manifest)) {
                continue;
            }

            $value = $manifest[$field];
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if ($item !== null && $item !== false && $item !== []) {
                        $this->addSiteOperationDetection($detected, $key, '$.' . $field . '[' . (int) $index . ']');
                    }
                }
                continue;
            }

            if ($value !== null && $value !== false && $value !== []) {
                $this->addSiteOperationDetection($detected, $key, '$.' . $field);
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function manifestList(array $manifest, string $field): array
    {
        $items = is_array($manifest[$field] ?? null) ? $manifest[$field] : [];
        $records = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }

        return $records;
    }

    /**
     * @param mixed $homepage
     * @return array<string, mixed>
     */
    private function normalizeHomepageManifest($homepage): array
    {
        if (is_array($homepage)) {
            return $homepage;
        }

        if (is_scalar($homepage) && trim((string) $homepage) !== '') {
            return ['pageId' => trim((string) $homepage)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function siteOperationKeyFromManifestItem(array $item): string
    {
        foreach (['operation', 'operationType', 'scope', 'type', 'feature', 'id'] as $field) {
            $key = $this->canonicalSiteOperationKey($item[$field] ?? null);
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function canonicalSiteOperationKey($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $key = strtolower(trim((string) $value));
        $key = str_replace(['-', ' '], '_', $key);

        return match ($key) {
            'homepage', 'homepage_assignment', 'front_page', 'front_page_assignment' => 'homepage',
            'menu', 'menus', 'nav_menu', 'navigation_menu', 'wordpress_menu' => 'menus',
            'single', 'single_template', 'single_templates', 'singular_template', 'singular_templates' => 'single_templates',
            'archive', 'archive_template', 'archive_templates', 'post_type_archive' => 'archive_templates',
            'dynamic', 'dynamic_data', 'dynamic_binding', 'dynamic_bindings', 'cms_binding', 'cms_bindings' => 'dynamic_bindings',
            'loop', 'loops', 'repeater', 'repeaters', 'query_loop', 'query_loops' => 'loops',
            'woocommerce', 'woo_commerce', 'woo', 'product_template', 'cart', 'checkout', 'account' => 'woocommerce',
            default => isset(self::SITE_OPERATION_SCOPE[$key]) ? $key : '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function siteOperationScopeRecord(string $key): array
    {
        $record = self::SITE_OPERATION_SCOPE[$key] ?? [
            'label' => $key,
            'status' => 'future',
            'extensionPoint' => 'oxy_html_converter_site_operation_mapper',
            'reason' => 'Site operation is outside current Core scope.',
        ];

        return [
            'key' => $key,
            'label' => $record['label'],
            'status' => $record['status'],
            'extensionPoint' => $record['extensionPoint'],
            'reason' => $record['reason'],
        ];
    }

    /**
     * @param array<string, mixed> $siteOperationScope
     * @param list<string> $keys
     */
    private function hasDetectedCoreSiteOperation(array $siteOperationScope, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->siteOperationDetectedCount($siteOperationScope, $key) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $siteOperationScope
     */
    private function siteOperationDetectedCount(array $siteOperationScope, string $key): int
    {
        foreach (is_array($siteOperationScope['detected'] ?? null) ? $siteOperationScope['detected'] : [] as $operation) {
            if (!is_array($operation) || ($operation['key'] ?? '') !== $key) {
                continue;
            }

            return (int) ($operation['count'] ?? 0);
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $patterns
     * @return list<array<string, mixed>>
     */
    private function deferredAdvancedComponentPatterns(array $patterns): array
    {
        return array_values(array_filter(
            $patterns,
            static fn (array $pattern): bool => ($pattern['status'] ?? '') !== 'core'
        ));
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function advancedComponentPatternsForCandidate(array $candidate): array
    {
        $keys = [];

        foreach (['advancedPatternTypes', 'advancedPatterns', 'advancedComponentPatterns', 'componentScopePatterns'] as $field) {
            foreach (is_array($candidate[$field] ?? null) ? $candidate[$field] : [] as $pattern) {
                $key = $this->canonicalAdvancedComponentPatternKey($pattern);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        foreach ($this->advancedComponentPatternsFromCandidateTree($candidate) as $key) {
            $keys[] = $key;
        }

        $records = [];
        foreach (array_values(array_unique($keys)) as $key) {
            $records[] = $this->advancedComponentScopeRecord($key);
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function advancedComponentPatternsFromCandidateTree(array $candidate): array
    {
        foreach (['documentTree', 'blockTree', 'tree', 'oxygenTree'] as $key) {
            if (is_array($candidate[$key] ?? null)) {
                return $this->advancedComponentPatternsFromTree($candidate[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<string>
     */
    private function advancedComponentPatternsFromTree(array $tree): array
    {
        $root = is_array($tree['root'] ?? null) ? $tree['root'] : $tree;
        $patterns = [];
        $this->collectAdvancedComponentPatternsFromNode($root, $patterns);

        return array_values(array_unique($patterns));
    }

    /**
     * @param mixed $node
     * @param list<string> $patterns
     */
    private function collectAdvancedComponentPatternsFromNode($node, array &$patterns): void
    {
        if (!is_array($node)) {
            return;
        }

        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        $tag = $this->tagForPlanTreeNode($node);
        $properties = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];
        $classSignature = strtolower(implode(' ', $this->classesForPlanTreeNode($node)));
        $attributes = is_array($properties['settings']['advanced']['attributes'] ?? null)
            ? $properties['settings']['advanced']['attributes']
            : [];

        if ($type === ElementTypes::CSS_CODE || str_ends_with($type, '\\CssCode')) {
            $patterns[] = 'component_scoped_css';
        }

        if (str_contains($classSignature, 'variant-') || $this->planTreeNodeHasAttribute($attributes, ['data-variant', 'data-state'])) {
            $patterns[] = 'variants';
        }

        if ($this->planTreeNodeHasAttribute($attributes, ['data-repeat', 'data-repeater'])) {
            $patterns[] = 'repeated_regions';
        }

        if (in_array($tag, ['form', 'input', 'select', 'textarea'], true)) {
            $patterns[] = 'forms';
        }

        if (in_array($tag, ['ul', 'ol'], true)) {
            $patterns[] = 'lists';
        }

        if ($this->containsDynamicDataMarker($properties)) {
            $patterns[] = 'dynamic_data';
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->collectAdvancedComponentPatternsFromNode($child, $patterns);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function tagForPlanTreeNode(array $node): string
    {
        foreach ([
            $node['data']['properties']['settings']['advanced']['tag'] ?? null,
            $node['data']['properties']['design']['tag'] ?? null,
        ] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                return strtolower(trim($tag));
            }
        }

        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';

        return match ($type) {
            ElementTypes::TEXT_LINK, ElementTypes::CONTAINER_LINK => 'a',
            ElementTypes::TEXT => 'p',
            ElementTypes::IMAGE => 'img',
            ElementTypes::HTML_CODE => 'html',
            ElementTypes::CSS_CODE => 'style',
            default => '',
        };
    }

    /**
     * @param mixed $value
     */
    private function containsDynamicDataMarker($value): bool
    {
        if (is_string($value)) {
            return preg_match('/(?:\{\{[^}]+\}\}|%%[^%]+%%|data-dynamic|dynamicData)/i', $value) === 1;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->containsDynamicDataMarker($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function classesForPlanTreeNode(array $node): array
    {
        $classes = [];
        foreach ([
            $node['data']['properties']['settings']['advanced']['classes'] ?? null,
            $node['data']['properties']['meta']['classes'] ?? null,
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $className) {
                if (is_string($className) && trim($className) !== '') {
                    $classes[] = trim($className);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param mixed $attributes
     * @param list<string> $names
     */
    private function planTreeNodeHasAttribute($attributes, array $names): bool
    {
        if (!is_array($attributes)) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $name = is_string($attribute['name'] ?? null) ? strtolower(trim((string) $attribute['name'])) : '';
            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $pattern
     */
    private function canonicalAdvancedComponentPatternKey($pattern): string
    {
        if (is_array($pattern)) {
            foreach (['key', 'type', 'pattern', 'id'] as $field) {
                if (is_scalar($pattern[$field] ?? null)) {
                    return $this->canonicalAdvancedComponentPatternKey((string) $pattern[$field]);
                }
            }

            return '';
        }

        if (!is_scalar($pattern)) {
            return '';
        }

        $key = strtolower(trim((string) $pattern));
        $key = str_replace(['-', ' '], '_', $key);

        return match ($key) {
            'variant', 'variants', 'component_variants' => 'variants',
            'repeated_region', 'repeated_regions', 'repeater', 'repeaters', 'repeater_list' => 'repeated_regions',
            'list', 'lists', 'editable_list', 'component_list' => 'lists',
            'form', 'forms', 'functional_form', 'form_controls' => 'forms',
            'dynamic', 'dynamic_data', 'dynamicdata', 'cms_data', 'loop', 'loops' => 'dynamic_data',
            'component_css', 'component_scoped_css', 'component_scoped_styles', 'scoped_css' => 'component_scoped_css',
            default => isset(self::ADVANCED_COMPONENT_SCOPE[$key]) ? $key : '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function advancedComponentScopeRecord(string $key): array
    {
        $record = self::ADVANCED_COMPONENT_SCOPE[$key] ?? [
            'label' => $key,
            'status' => 'future',
            'extensionPoint' => 'oxy_html_converter_component_scope_mapper',
            'reason' => 'Advanced component pattern is outside current Core scope.',
        ];

        return [
            'key' => $key,
            'label' => $record['label'],
            'status' => $record['status'],
            'extensionPoint' => $record['extensionPoint'],
            'reason' => $record['reason'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return array{candidates:int,ready:int,created:int,updated:int,skipped:int,deduplicatedInstances:int,skippedCandidates:list<array<string,mixed>>,reasons:list<string>}
     */
    private function summarizeComponentPersistence(array $components): array
    {
        $summary = [
            'candidates' => count($components),
            'ready' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deduplicatedInstances' => 0,
            'skippedCandidates' => [],
            'reasons' => [],
        ];

        foreach ($components as $component) {
            $status = (string) ($component['status'] ?? '');

            if ($status === 'ready') {
                $summary['ready']++;
            } elseif ($status === 'created') {
                $summary['created']++;
            } elseif ($status === 'updated') {
                $summary['updated']++;
            } elseif ($status === 'skipped') {
                $summary['skipped']++;
                $summary['skippedCandidates'][] = [
                    'suggestedName' => (string) ($component['suggestedName'] ?? ''),
                    'signature' => (string) ($component['signature'] ?? ''),
                    'occurrences' => (int) ($component['occurrences'] ?? 0),
                    'confidence' => (float) ($component['confidence'] ?? 0.0),
                    'editablePropertyCount' => (int) ($component['editablePropertyCount'] ?? 0),
                    'reason' => (string) ($component['reason'] ?? ''),
                    'reasons' => array_values(array_map('strval', is_array($component['reasons'] ?? null) ? $component['reasons'] : [])),
                ];
            }

            if ($status !== 'skipped') {
                $summary['deduplicatedInstances'] += (int) ($component['deduplicatedInstanceCount'] ?? $component['occurrences'] ?? 0);
            }

            foreach (is_array($component['reasons'] ?? null) ? $component['reasons'] : [] as $reason) {
                if (is_string($reason) && $reason !== '') {
                    $summary['reasons'][] = $reason;
                }
            }
        }

        $summary['reasons'] = array_values(array_unique($summary['reasons']));

        return $summary;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function componentConfidence(array $candidate, int $occurrences, int $minOccurrences): float
    {
        if (is_int($candidate['confidence'] ?? null) || is_float($candidate['confidence'] ?? null)) {
            return max(0.0, min(1.0, (float) $candidate['confidence']));
        }

        return max(0.0, min(1.0, $occurrences / max(1, $minOccurrences)));
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $options
     * @return array{minOccurrences:int,minConfidence:float,minEditableProperties:int}
     */
    private function componentThreshold(array $candidate, array $options): array
    {
        $candidateThreshold = is_array($candidate['threshold'] ?? null) ? $candidate['threshold'] : [];
        $minOccurrences = (int) (
            $options['componentMinOccurrences']
            ?? $options['component_min_occurrences']
            ?? $candidateThreshold['minOccurrences']
            ?? self::COMPONENT_MIN_OCCURRENCES
        );
        $minConfidence = (float) (
            $options['componentMinConfidence']
            ?? $options['component_min_confidence']
            ?? $candidateThreshold['minConfidence']
            ?? self::COMPONENT_MIN_CONFIDENCE
        );
        $minEditableProperties = (int) (
            $options['componentMinEditableProperties']
            ?? $options['component_min_editable_properties']
            ?? $candidateThreshold['minEditableProperties']
            ?? self::COMPONENT_MIN_EDITABLE_PROPERTIES
        );

        return [
            'minOccurrences' => max(1, $minOccurrences),
            'minConfidence' => max(0.0, min(1.0, $minConfidence)),
            'minEditableProperties' => max(0, $minEditableProperties),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function componentCandidateHasTree(array $candidate): bool
    {
        foreach (['documentTree', 'blockTree', 'tree', 'oxygenTree'] as $key) {
            if (is_array($candidate[$key] ?? null)) {
                return true;
            }
        }

        if (is_array($candidate['_oxygen_data'] ?? null) && is_string($candidate['_oxygen_data']['tree_json_string'] ?? null)) {
            return true;
        }

        return is_array($candidate['blockSpec']['_oxygen_data'] ?? null)
            && is_string($candidate['blockSpec']['_oxygen_data']['tree_json_string'] ?? null);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function componentEditablePropertyCount(array $candidate): int
    {
        $componentProperties = is_array($candidate['componentProperties'] ?? null)
            ? $candidate['componentProperties']
            : [];
        $targets = is_array($componentProperties['targets'] ?? null) ? $componentProperties['targets'] : [];

        if ($targets !== []) {
            return count(array_filter($targets, 'is_array'));
        }

        if (isset($candidate['editablePropertyCount']) && (is_int($candidate['editablePropertyCount']) || is_float($candidate['editablePropertyCount']))) {
            return max(0, (int) $candidate['editablePropertyCount']);
        }

        $fieldTypes = is_array($candidate['editableFieldTypes'] ?? null) ? $candidate['editableFieldTypes'] : [];
        if ($fieldTypes !== []) {
            return count(array_unique(array_filter($fieldTypes, 'is_string')));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function componentCandidateInstances(array $candidate, int $occurrences): array
    {
        $instances = is_array($candidate['instances'] ?? null) ? $candidate['instances'] : [];
        $normalized = [];

        foreach ($instances as $index => $instance) {
            if (!is_array($instance)) {
                continue;
            }

            $nodeId = $instance['nodeId'] ?? null;
            $classes = is_array($instance['classes'] ?? null) ? $instance['classes'] : [];
            $normalized[] = [
                'index' => (int) $index + 1,
                'nodeId' => is_int($nodeId) ? $nodeId : 0,
                'path' => is_scalar($instance['path'] ?? null) ? (string) $instance['path'] : '',
                'tag' => is_scalar($instance['tag'] ?? $candidate['tag'] ?? null) ? (string) ($instance['tag'] ?? $candidate['tag']) : '',
                'classes' => array_values(array_filter(array_map('strval', $classes), static fn (string $className): bool => trim($className) !== '')),
            ];
        }

        if ($normalized !== []) {
            return $normalized;
        }

        for ($index = 0; $index < $occurrences; $index++) {
            $normalized[] = [
                'index' => $index + 1,
                'nodeId' => 0,
                'path' => '',
                'tag' => is_scalar($candidate['tag'] ?? null) ? (string) $candidate['tag'] : '',
                'classes' => array_values(array_map('strval', is_array($candidate['classes'] ?? null) ? $candidate['classes'] : [])),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function componentSkipReasons(
        array $candidate,
        int $occurrences,
        float $confidence,
        bool $hasTree,
        array $threshold,
        int $editablePropertyCount
    ): array
    {
        $reasons = [];
        $minOccurrences = max(1, (int) ($threshold['minOccurrences'] ?? self::COMPONENT_MIN_OCCURRENCES));
        $minConfidence = max(0.0, min(1.0, (float) ($threshold['minConfidence'] ?? self::COMPONENT_MIN_CONFIDENCE)));
        $minEditableProperties = max(0, (int) ($threshold['minEditableProperties'] ?? self::COMPONENT_MIN_EDITABLE_PROPERTIES));

        if ($occurrences < $minOccurrences) {
            $reasons[] = 'below_occurrence_threshold';
        }

        if ($confidence < $minConfidence) {
            $reasons[] = 'below_confidence_threshold';
        }

        if (!empty($candidate['eligible']) && !$hasTree) {
            $reasons[] = 'missing_component_tree';
        } elseif (!$hasTree) {
            $reasons[] = 'missing_component_tree';
        }

        if ($editablePropertyCount < $minEditableProperties) {
            $reasons[] = 'insufficient_editable_properties';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param list<string> $reasons
     */
    private function componentStatus(array $reasons, string $persistedAction): string
    {
        if ($reasons !== []) {
            return 'skipped';
        }

        if ($persistedAction === 'created' || $persistedAction === 'updated') {
            return $persistedAction;
        }

        return 'ready';
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return array{classMap:list<array<string,mixed>>,duplicateStylePatterns:list<array<string,mixed>>,skippedStylePatterns:list<array<string,mixed>>,elementApplications:list<array<string,mixed>>,selectorCountReduction:int}
     */
    private function buildClassPlan(array $designDocument): array
    {
        $designProfile = is_array($designDocument['designProfile'] ?? null) ? $designDocument['designProfile'] : [];
        $classStrategy = is_array($designDocument['classStrategy'] ?? null) ? $designDocument['classStrategy'] : [];

        return [
            'classMap' => array_values(is_array($designProfile['semanticClasses'] ?? null)
                ? $designProfile['semanticClasses']
                : (is_array($classStrategy['classMap'] ?? null) ? $classStrategy['classMap'] : [])),
            'duplicateStylePatterns' => array_values(is_array($designProfile['duplicateStylePatterns'] ?? null)
                ? $designProfile['duplicateStylePatterns']
                : (is_array($classStrategy['duplicateStylePatterns'] ?? null) ? $classStrategy['duplicateStylePatterns'] : [])),
            'skippedStylePatterns' => array_values(is_array($designProfile['skippedStylePatterns'] ?? null)
                ? $designProfile['skippedStylePatterns']
                : (is_array($classStrategy['skippedPatterns'] ?? null) ? $classStrategy['skippedPatterns'] : [])),
            'elementApplications' => array_values(is_array($designProfile['elementApplications'] ?? null) ? $designProfile['elementApplications'] : []),
            'selectorCountReduction' => (int) ($classStrategy['selectorCountReduction'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $designDocument
     * @param array<string, mixed> $options
     * @return array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    private function buildManifestSections(array $result, array $designDocument, array $options): array
    {
        $manifest = $this->sourceManifest($result, $designDocument, $options);
        $templateSections = (new OxygenTemplateRepository())->normalizeManifestSections($manifest);

        return [
            'pages' => $this->normalizeManifestPages($manifest),
            'templates' => $templateSections['templates'],
            'headers' => $templateSections['headers'],
            'footers' => $templateSections['footers'],
            'parts' => $templateSections['parts'],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $designDocument
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sourceManifest(array $result, array $designDocument, array $options): array
    {
        foreach ([$options, $designDocument, $result] as $source) {
            foreach (['siteKitManifest', 'importManifest', 'manifest'] as $key) {
                if (is_array($source[$key] ?? null)) {
                    return $source[$key];
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function normalizeManifestPages(array $manifest): array
    {
        $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
        $normalized = [];

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $tree = is_array($page['documentTree'] ?? null) ? $page['documentTree'] : null;
            $treeSummary = $this->manifestTreeSummary($tree);
            $normalized[] = [
                'id' => $this->manifestRecordString($page, 'id', 'page-' . ((int) $index + 1)),
                'title' => $this->manifestRecordString($page, 'title', 'Page ' . ((int) $index + 1)),
                'slug' => $this->manifestRecordString($page, 'slug', ''),
                'postType' => 'page',
                'postId' => (int) ($page['postId'] ?? $page['post_id'] ?? 0),
                'hasDocumentTree' => $tree !== null,
                'treeHash' => $treeSummary['treeHash'],
                'nodeCount' => $treeSummary['nodeCount'],
                'elementTypes' => $treeSummary['elementTypes'],
                'semanticTags' => $treeSummary['semanticTags'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>} $sections
     */
    private function countTemplateManifestSections(array $sections): int
    {
        return count($sections['templates'])
            + count($sections['headers'])
            + count($sections['footers'])
            + count($sections['parts']);
    }

    /**
     * @param array<string, mixed>|null $tree
     * @return array{treeHash: string, nodeCount: int, elementTypes: list<string>, semanticTags: list<string>}
     */
    private function manifestTreeSummary(?array $tree): array
    {
        if ($tree === null) {
            return [
                'treeHash' => '',
                'nodeCount' => 0,
                'elementTypes' => [],
                'semanticTags' => [],
            ];
        }

        $encoded = wp_json_encode($tree);
        $summary = [
            'nodeCount' => 0,
            'elementTypes' => [],
            'semanticTags' => [],
        ];
        $this->walkManifestTreeNode($tree['root'] ?? null, $summary);

        return [
            'treeHash' => is_string($encoded) ? sha1($encoded) : '',
            'nodeCount' => $summary['nodeCount'],
            'elementTypes' => array_values(array_unique($summary['elementTypes'])),
            'semanticTags' => array_values(array_unique($summary['semanticTags'])),
        ];
    }

    /**
     * @param mixed $node
     * @param array{nodeCount: int, elementTypes: list<string>, semanticTags: list<string>} $summary
     */
    private function walkManifestTreeNode($node, array &$summary): void
    {
        if (!is_array($node)) {
            return;
        }

        $summary['nodeCount']++;
        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type !== '') {
            $summary['elementTypes'][] = $type;
        }

        $tag = is_string($node['data']['properties']['settings']['advanced']['tag'] ?? null)
            ? (string) $node['data']['properties']['settings']['advanced']['tag']
            : '';
        if ($tag !== '') {
            $summary['semanticTags'][] = $tag;
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            $this->walkManifestTreeNode($child, $summary);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function manifestRecordString(array $record, string $field, string $default): string
    {
        $value = $record[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, list<array<string,mixed>>> $tokens
     */
    private function countTokenPlanItems(array $tokens): int
    {
        $count = 0;

        foreach ($tokens as $group) {
            $count += count($group);
        }

        return $count;
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
     * @param array<string, list<array<string,mixed>>> $tokens
     */
    private function countGlobalSettingsProposals(array $globalSettings, array $tokens): int
    {
        $proposed = count($tokens['colors'] ?? []);
        $settings = is_array($globalSettings['settings'] ?? null) ? $globalSettings['settings'] : $globalSettings;
        foreach (['colors', 'typography', 'containers', 'code', 'other'] as $section) {
            if (isset($settings[$section]) && is_array($settings[$section]) && $settings[$section] !== []) {
                $proposed++;
            }
        }

        return $proposed;
    }

    /**
     * @param array<string, mixed> $designDocument
     * @return array<string, mixed>
     */
    private function resolveGlobalSettings(array $designDocument): array
    {
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($designDocument[$key]) && is_array($designDocument[$key])) {
                $settings = $designDocument[$key];
                return is_array($settings['settings'] ?? null) ? $settings : ['settings' => $settings];
            }
        }

        return [];
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
     * @param list<array<string, mixed>> $fallbacks
     * @param array<string, list<array<string,mixed>>> $tokens
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
