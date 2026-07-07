<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class StyleRoutingService
{
    /**
     * @return array<string, mixed>
     */
    public function route(string $css, bool $windPressMode = false): array
    {
        $css = trim($css);
        $sections = $this->splitSections($css);
        $pageSections = [];
        $globalSections = [];
        $pageScopedSections = [];
        $routes = [];
        $droppedSections = [];
        $windPressSafetySections = [];
        $cascadeOrder = 0;

        foreach ($sections as $section) {
            $content = trim($section['css']);
            if ($content === '') {
                continue;
            }

            $type = $this->classifySection($content);

            if ($type === 'global_asset') {
                $partitioned = $this->partitionGlobalAssetSection($content);

                if ($partitioned['global'] !== '') {
                    $globalSections[] = $partitioned['global'];
                    $routes[] = $this->routeItem($type, 'global_styles', $partitioned['global'], 'Global asset CSS', $cascadeOrder += 10);
                }

                if ($partitioned['page'] !== '') {
                    $pageType = str_contains($partitioned['page'], '/* Extracted from <style> tag */')
                        ? 'source_style'
                        : 'page_fallback';
                    $pageSections[] = $partitioned['page'];
                    $routes[] = $this->routeItem($pageType, 'page_css', $partitioned['page'], $this->labelForType($pageType), $cascadeOrder += 10);
                }

                continue;
            }

            if ($type === 'tailwind_utility_fallback' && $windPressMode) {
                $pageScopedSections[] = $content;
                $windPressSafetySections[] = $content;
                $routes[] = $this->routeItem(
                    $type,
                    'page_scoped_styles',
                    $content,
                    'Tailwind utility fallback safety CSS for WindPress',
                    $cascadeOrder += 10
                );
                continue;
            }

            $pageSections[] = $content;
            $routes[] = $this->routeItem($type, 'page_css', $content, $this->labelForType($type), $cascadeOrder += 10);
        }

        $pageCss = $this->joinSections($pageSections);
        $globalCss = $this->joinSections($globalSections);
        $pageScopedCss = $this->joinSections($pageScopedSections);

        return [
            'version' => 1,
            'mode' => $windPressMode ? 'windpress' : 'native',
            'pageCss' => $pageCss,
            'globalCss' => $globalCss,
            'pageScopedCss' => $pageScopedCss,
            'routes' => $routes,
            'summary' => [
                'pageCssBytes' => strlen($pageCss),
                'globalCssBytes' => strlen($globalCss),
                'pageScopedCssBytes' => strlen($pageScopedCss),
                'droppedCssBytes' => strlen($this->joinSections($droppedSections)),
                'windPressSafetyCssBytes' => strlen($this->joinSections($windPressSafetySections)),
                'routeCount' => count($routes),
                'hasPageCss' => $pageCss !== '',
                'hasGlobalCss' => $globalCss !== '',
                'hasPageScopedCss' => $pageScopedCss !== '',
                'usesWindPressRuntime' => $windPressMode && $windPressSafetySections !== [],
                'ownerCounts' => $this->countRouteOwners($routes),
                'pluginDependencies' => $this->collectPluginDependencies($routes),
                'hasPluginDependentCss' => $this->collectPluginDependencies($routes) !== [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $routing
     * @param list<array<string, mixed>> $componentCssRecords
     * @return array<string, mixed>
     */
    public function mergeComponentCssIntoHostRouting(array $routing, array $componentCssRecords): array
    {
        $routing = $this->normalizeRoutingEnvelope($routing);
        $records = $this->normalizeComponentCssRecords($componentCssRecords);

        if ($records === []) {
            return $routing;
        }

        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        $pageScopedSections = [$this->nonEmptyString($routing['pageScopedCss'] ?? null, '')];
        $existingHashes = [];
        $cascadeOrder = $this->maxRouteCascadeOrder($routes);

        foreach ($this->splitCssForHashes((string) ($routing['pageCss'] ?? '')) as $css) {
            $existingHashes[$this->componentCssHash($css)] = true;
        }

        foreach ($this->splitCssForHashes((string) ($routing['pageScopedCss'] ?? '')) as $css) {
            $existingHashes[$this->componentCssHash($css)] = true;
        }
        $existingCssText = "\n" . (string) ($routing['pageCss'] ?? '') . "\n" . (string) ($routing['pageScopedCss'] ?? '') . "\n";

        foreach ($records as $record) {
            $hash = (string) $record['hash'];
            $css = (string) $record['css'];
            if (isset($existingHashes[$hash]) || str_contains($existingCssText, $css)) {
                continue;
            }

            $existingHashes[$hash] = true;
            $existingCssText .= $css . "\n";
            $pageScopedSections[] = $css;
            $cascadeOrder += 10;
            $routes[] = $this->componentHostBridgeRouteItem($record, $cascadeOrder);
        }

        $routing['routes'] = $routes;
        $routing['pageScopedCss'] = $this->joinSections($pageScopedSections);
        $routing['summary'] = $this->buildSummary($routing);

        return $routing;
    }

    /**
     * @param array<string, mixed> $routing
     * @return array<string, mixed>
     */
    private function normalizeRoutingEnvelope(array $routing): array
    {
        $summary = is_array($routing['summary'] ?? null) ? $routing['summary'] : [];

        return [
            'version' => (int) ($routing['version'] ?? 1),
            'mode' => is_string($routing['mode'] ?? null) ? (string) $routing['mode'] : 'native',
            'pageCss' => $this->nonEmptyString($routing['pageCss'] ?? null, ''),
            'globalCss' => $this->nonEmptyString($routing['globalCss'] ?? null, ''),
            'pageScopedCss' => $this->nonEmptyString($routing['pageScopedCss'] ?? null, ''),
            'routes' => is_array($routing['routes'] ?? null) ? array_values($routing['routes']) : [],
            'summary' => $summary,
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function normalizeComponentCssRecords(array $records): array
    {
        $normalized = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $css = $this->nonEmptyString($record['css'] ?? null, '');
            if ($css === '') {
                continue;
            }

            $hash = $this->nonEmptyString($record['hash'] ?? null, $this->componentCssHash($css));
            $normalized[] = [
                'componentId' => (int) ($record['componentId'] ?? 0),
                'componentName' => $this->nonEmptyString($record['componentName'] ?? null, ''),
                'signature' => $this->nonEmptyString($record['signature'] ?? null, ''),
                'css' => $css,
                'hash' => $hash,
                'bytes' => strlen($css),
                'ruleCount' => $this->countRules($css),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            $leftId = (int) $left['componentId'];
            $rightId = (int) $right['componentId'];
            if ($leftId !== $rightId) {
                return $leftId <=> $rightId;
            }

            return (string) $left['hash'] <=> (string) $right['hash'];
        });

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function maxRouteCascadeOrder(array $routes): int
    {
        $max = 0;

        foreach ($routes as $route) {
            if (is_array($route) && isset($route['cascadeOrder'])) {
                $max = max($max, (int) $route['cascadeOrder']);
            }
        }

        return $max;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function componentHostBridgeRouteItem(array $record, int $cascadeOrder): array
    {
        return [
            'type' => 'component_css_host_bridge',
            'destination' => 'page_scoped_styles',
            'label' => 'Component CSS host bridge',
            'owner' => 'component',
            'componentId' => (int) ($record['componentId'] ?? 0),
            'componentName' => (string) ($record['componentName'] ?? ''),
            'signature' => (string) ($record['signature'] ?? ''),
            'cascadeOrder' => $cascadeOrder,
            'exportBehavior' => 'export_with_page_manifest',
            'rollbackStore' => 'page_styles',
            'pluginDependency' => null,
            'bytes' => (int) ($record['bytes'] ?? 0),
            'ruleCount' => (int) ($record['ruleCount'] ?? 1),
            'hash' => (string) ($record['hash'] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitCssForHashes(string $css): array
    {
        $css = trim($css);
        if ($css === '') {
            return [];
        }

        $sections = preg_split('/\R{2,}/', $css);
        if (!is_array($sections)) {
            return [$css];
        }

        return array_values(array_filter(
            array_map('trim', $sections),
            static fn (string $section): bool => $section !== ''
        ));
    }

    /**
     * @param array<string, mixed> $routing
     * @return array<string, mixed>
     */
    private function buildSummary(array $routing): array
    {
        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        $pageCss = (string) ($routing['pageCss'] ?? '');
        $globalCss = (string) ($routing['globalCss'] ?? '');
        $pageScopedCss = (string) ($routing['pageScopedCss'] ?? '');
        $dependencies = $this->collectPluginDependencies($routes);

        return [
            'pageCssBytes' => strlen($pageCss),
            'globalCssBytes' => strlen($globalCss),
            'pageScopedCssBytes' => strlen($pageScopedCss),
            'droppedCssBytes' => 0,
            'windPressSafetyCssBytes' => $this->windPressSafetyCssBytes($routes),
            'routeCount' => count($routes),
            'hasPageCss' => trim($pageCss) !== '',
            'hasGlobalCss' => trim($globalCss) !== '',
            'hasPageScopedCss' => trim($pageScopedCss) !== '',
            'usesWindPressRuntime' => $this->usesWindPressRuntime($routes),
            'ownerCounts' => $this->countRouteOwners($routes),
            'pluginDependencies' => $dependencies,
            'hasPluginDependentCss' => $dependencies !== [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function windPressSafetyCssBytes(array $routes): int
    {
        $bytes = 0;
        foreach ($routes as $route) {
            if (is_array($route) && ($route['type'] ?? null) === 'tailwind_utility_fallback') {
                $bytes += (int) ($route['bytes'] ?? 0);
            }
        }

        return $bytes > 0 ? $bytes : 0;
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function usesWindPressRuntime(array $routes): bool
    {
        foreach ($routes as $route) {
            if (is_array($route) && ($route['type'] ?? null) === 'tailwind_utility_fallback') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{css:string}>
     */
    private function splitSections(string $css): array
    {
        if ($css === '') {
            return [];
        }

        $pattern = '/(?=\/\*\s*(?:Extracted from <style> tag|Tailwind utility fallback)\s*\*\/)/';
        $parts = preg_split($pattern, $css, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts)) {
            return [['css' => $css]];
        }

        $sections = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $sections[] = ['css' => $part];
            }
        }

        return $sections;
    }

    private function classifySection(string $css): string
    {
        if (str_contains($css, '/* Tailwind utility fallback */')) {
            return 'tailwind_utility_fallback';
        }

        if (preg_match('/\.ohc-native-\d+\s*\{/', $css) === 1) {
            return 'native_mirror';
        }

        if (
            str_contains($css, '.material-symbols-outlined')
            || preg_match('/@font-face/i', $css) === 1
            || $this->containsGoogleFontImport($css)
        ) {
            return 'global_asset';
        }

        if (str_contains($css, '/* Extracted from <style> tag */')) {
            return 'source_style';
        }

        return 'page_fallback';
    }

    private function labelForType(string $type): string
    {
        return match ($type) {
            'native_mirror' => 'Native style mirror CSS',
            'tailwind_utility_fallback' => 'Tailwind utility fallback CSS',
            'source_style' => 'Source style CSS',
            'page_fallback' => 'Page fallback CSS',
            default => 'CSS',
        };
    }

    /**
     * @return array{global:string,page:string}
     */
    private function partitionGlobalAssetSection(string $css): array
    {
        $matches = [];
        $matched = preg_match_all(
            '/(?:\/\*.*?\*\/\s*)?(?:@import\s+[^;]+;|@font-face\s*\{[^{}]*\}|[^{}]+\{[^{}]*\})/is',
            $css,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return [
                'global' => $css,
                'page' => '',
            ];
        }

        $global = [];
        $page = [];
        $remainder = $css;

        foreach ($matches[0] as $match) {
            $rule = trim((string) $match);
            if ($rule === '') {
                continue;
            }

            $remainder = str_replace($match, '', $remainder);
            if ($this->isGlobalAssetRule($rule)) {
                $global[] = $rule;
            } else {
                $page[] = $rule;
            }
        }

        $remainder = trim($remainder);
        if ($remainder !== '') {
            $page[] = $remainder;
        }

        return [
            'global' => $this->joinSections($global),
            'page' => $this->joinSections($page),
        ];
    }

    private function isGlobalAssetRule(string $css): bool
    {
        return str_contains($css, '.material-symbols-outlined')
            || preg_match('/@font-face/i', $css) === 1
            || $this->containsGoogleFontImport($css);
    }

    private function containsGoogleFontImport(string $css): bool
    {
        return preg_match('/@import\s+(?:url\(\s*)?(?:"|\')?[^;)]*fonts\.googleapis\.com/i', $css) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeItem(string $type, string $destination, string $css, string $label, int $cascadeOrder): array
    {
        $owner = $this->ownerForRoute($type, $destination);
        $pluginDependency = $this->pluginDependencyForRoute($type, $destination);

        return [
            'type' => $type,
            'destination' => $destination,
            'label' => $label,
            'owner' => $owner,
            'cascadeOrder' => $cascadeOrder,
            'exportBehavior' => $this->exportBehaviorForOwner($owner),
            'rollbackStore' => $this->rollbackStoreForDestination($destination),
            'pluginDependency' => $pluginDependency,
            'bytes' => strlen($css),
            'ruleCount' => $this->countRules($css),
            'hash' => substr(sha1($type . ':' . $destination . ':' . $css), 0, 16),
        ];
    }

    private function ownerForRoute(string $type, string $destination): string
    {
        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'runtime_plugin_dependency';
        }

        if ($destination === 'global_styles') {
            return 'global';
        }

        return 'page';
    }

    private function exportBehaviorForOwner(string $owner): string
    {
        return match ($owner) {
            'global' => 'export_with_global_styles',
            'runtime_plugin_dependency' => 'requires_runtime_plugin',
            default => 'export_with_page_manifest',
        };
    }

    private function rollbackStoreForDestination(string $destination): string
    {
        return $destination === 'global_styles' ? 'global_styles' : 'page_styles';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pluginDependencyForRoute(string $type, string $destination): ?array
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

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, int>
     */
    private function countRouteOwners(array $routes): array
    {
        $counts = [];
        foreach ($routes as $route) {
            $owner = is_string($route['owner'] ?? null) ? $route['owner'] : 'page';
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private function collectPluginDependencies(array $routes): array
    {
        $dependencies = [];
        $seen = [];

        foreach ($routes as $route) {
            $dependency = is_array($route['pluginDependency'] ?? null) ? $route['pluginDependency'] : [];
            $slug = is_string($dependency['slug'] ?? null) ? $dependency['slug'] : '';
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    private function countRules(string $css): int
    {
        $count = substr_count($css, '{');

        return max(1, $count);
    }

    /**
     * @param mixed $value
     */
    private function nonEmptyString($value, string $fallback): string
    {
        if (!is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function componentCssHash(string $css): string
    {
        return substr(sha1('oxy-html-converter-component-css:' . trim($css)), 0, 16);
    }

    /**
     * @param list<string> $sections
     */
    private function joinSections(array $sections): string
    {
        $sections = array_values(array_filter(array_map('trim', $sections), static fn (string $section): bool => $section !== ''));

        return implode("\n\n", $sections);
    }
}
