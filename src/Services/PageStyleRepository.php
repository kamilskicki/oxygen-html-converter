<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class PageStyleRepository
{
    public const META_KEY = '_oxy_html_converter_page_styles';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForPost(int $postId, array $payload): array
    {
        if ($postId < 1) {
            return [
                'saved' => false,
                'bytes' => 0,
                'hash' => '',
            ];
        }

        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        $hasVisibleCssCodeFallback = $this->payloadContainsVisibleCssCodeFallback($payload);
        $pageCss = $hasVisibleCssCodeFallback ? '' : (is_string($payload['pageCss'] ?? null)
            ? trim($payload['pageCss'])
            : trim((string) ($routing['pageCss'] ?? '')));
        $pageScopedCss = is_string($payload['pageScopedCss'] ?? null)
            ? trim($payload['pageScopedCss'])
            : trim((string) ($routing['pageScopedCss'] ?? ''));
        $pageStyleCss = $this->joinCssSections([$pageCss, $pageScopedCss]);
        $destinations = $hasVisibleCssCodeFallback ? ['page_scoped_styles'] : ['page_css', 'page_scoped_styles'];
        $routes = $this->routesForDestinations($routing, $destinations);
        $owner = $this->singleOwner($routes, 'page');
        $ownerCounts = $this->ownerCounts($routes);
        $pluginDependency = $this->firstPluginDependency($routes);

        if ($pageStyleCss === '') {
            if (function_exists('delete_post_meta')) {
                delete_post_meta($postId, self::META_KEY);
            }

            return [
                'saved' => false,
                'bytes' => 0,
                'hash' => '',
            ];
        }

        $payload = [
            'version' => 1,
            'updatedAt' => gmdate('c'),
            'owner' => $owner,
            'owners' => array_keys($ownerCounts),
            'ownerCounts' => $ownerCounts,
            'hasMixedOwners' => count($ownerCounts) > 1,
            'postId' => $postId,
            'cascadeOrder' => $this->firstCascadeOrder($routes, 1000),
            'exportBehavior' => $pluginDependency !== null ? 'requires_runtime_plugin' : 'export_with_page_manifest',
            'rollbackStore' => 'page_styles',
            'pluginDependency' => $pluginDependency,
            'pluginDependencyNotice' => is_array($pluginDependency) ? (string) ($pluginDependency['notice'] ?? '') : '',
            'routes' => $routes,
            'css' => $pageStyleCss,
            'bytes' => strlen($pageStyleCss),
            'hash' => substr(sha1('oxy-html-converter-page-style:' . $pageStyleCss), 0, 16),
        ];

        if (function_exists('update_post_meta')) {
            update_post_meta($postId, self::META_KEY, wp_slash(wp_json_encode($payload)));
        }

        return [
            'saved' => true,
            'bytes' => $payload['bytes'],
            'hash' => $payload['hash'],
            'owner' => $payload['owner'],
            'owners' => $payload['owners'],
            'ownerCounts' => $payload['ownerCounts'],
            'hasMixedOwners' => $payload['hasMixedOwners'],
            'cascadeOrder' => $payload['cascadeOrder'],
            'exportBehavior' => $payload['exportBehavior'],
            'rollbackStore' => $payload['rollbackStore'],
            'pluginDependency' => $payload['pluginDependency'],
        ];
    }

    public function getCssForPost(int $postId): string
    {
        if ($postId < 1 || !function_exists('get_post_meta')) {
            return '';
        }

        $raw = get_post_meta($postId, self::META_KEY, true);
        $raw = is_string($raw) ? $raw : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) && $raw !== '') {
            $decoded = json_decode(stripslashes($raw), true);
        }

        if (!is_array($decoded)) {
            return '';
        }

        return is_string($decoded['css'] ?? null) ? trim($decoded['css']) : '';
    }

    /**
     * @param array<string, mixed> $routing
     * @return list<array<string, mixed>>
     */
    private function routesForDestinations(array $routing, array $destinations): array
    {
        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        $matching = [];

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $destination = is_string($route['destination'] ?? null) ? $route['destination'] : '';
            if (!in_array($destination, $destinations, true)) {
                continue;
            }

            $type = $this->nonEmptyString($route['type'] ?? null, 'page_fallback');
            $record = [
                'type' => $type,
                'destination' => $destination,
                'label' => $this->nonEmptyString($route['label'] ?? null, $this->styleRouteLabel($type, $destination)),
                'owner' => $this->nonEmptyString($route['owner'] ?? null, $this->styleRouteOwner($type, $destination)),
                'cascadeOrder' => (int) ($route['cascadeOrder'] ?? 1000 + (count($matching) * 10)),
                'exportBehavior' => $this->nonEmptyString($route['exportBehavior'] ?? null, $this->styleRouteExportBehavior($type, $destination)),
                'rollbackStore' => $this->nonEmptyString($route['rollbackStore'] ?? null, $this->styleRouteRollbackStore($destination)),
                'pluginDependency' => is_array($route['pluginDependency'] ?? null)
                    ? $route['pluginDependency']
                    : $this->styleRoutePluginDependency($type, $destination),
                'hash' => is_string($route['hash'] ?? null) ? $route['hash'] : '',
            ];

            if ($type === 'component_css_host_bridge') {
                $record['componentId'] = (int) ($route['componentId'] ?? 0);
                $record['componentName'] = $this->nonEmptyString($route['componentName'] ?? null, '');
                $record['signature'] = $this->nonEmptyString($route['signature'] ?? null, '');
            }

            $matching[] = $record;
        }

        return $matching;
    }

    /**
     * @param list<string> $sections
     */
    private function joinCssSections(array $sections): string
    {
        $sections = array_values(array_filter(array_map('trim', $sections), static fn (string $section): bool => $section !== ''));

        return implode("\n\n", $sections);
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function singleOwner(array $routes, string $fallback): string
    {
        foreach ($routes as $route) {
            if (is_string($route['owner'] ?? null) && trim($route['owner']) !== '') {
                return $route['owner'];
            }
        }

        return $fallback;
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, mixed>|null
     */
    private function firstPluginDependency(array $routes): ?array
    {
        foreach ($routes as $route) {
            if (is_array($route['pluginDependency'] ?? null)) {
                return $route['pluginDependency'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function firstCascadeOrder(array $routes, int $fallback): int
    {
        foreach ($routes as $route) {
            if (isset($route['cascadeOrder'])) {
                return (int) $route['cascadeOrder'];
            }
        }

        return $fallback;
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, int>
     */
    private function ownerCounts(array $routes): array
    {
        $counts = [];

        foreach ($routes as $route) {
            $owner = is_string($route['owner'] ?? null) ? $route['owner'] : 'page';
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }

        if ($counts === []) {
            $counts['page'] = 1;
        }

        ksort($counts);

        return $counts;
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

    private function styleRouteOwner(string $type, string $destination): string
    {
        if ($type === 'component_css_host_bridge') {
            return 'component';
        }

        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'runtime_plugin_dependency';
        }

        return $destination === 'global_styles' ? 'global' : 'page';
    }

    private function styleRouteExportBehavior(string $type, string $destination): string
    {
        if ($type === 'component_css_host_bridge') {
            return 'export_with_page_manifest';
        }

        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'requires_runtime_plugin';
        }

        return $destination === 'global_styles' ? 'export_with_global_styles' : 'export_with_page_manifest';
    }

    private function styleRouteRollbackStore(string $destination): string
    {
        return $destination === 'global_styles' ? 'global_styles' : 'page_styles';
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
            return match ($type) {
                'component_css_host_bridge' => 'Component CSS host bridge',
                'tailwind_utility_fallback' => 'Tailwind utility fallback CSS',
                default => 'Page scoped style asset',
            };
        }

        return match ($type) {
            'native_mirror' => 'Native style mirror CSS',
            'source_style' => 'Source style CSS',
            default => 'Page scoped style asset',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadContainsVisibleCssCodeFallback(array $payload): bool
    {
        foreach (['documentTree', 'element'] as $field) {
            if (isset($payload[$field]) && is_array($payload[$field])) {
                return $this->nodeContainsCssCode($payload[$field]);
            }
        }

        return isset($payload['cssElement']) && is_array($payload['cssElement']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeContainsCssCode(array $node): bool
    {
        $root = isset($node['root']) && is_array($node['root']) ? $node['root'] : $node;
        $type = is_string($root['data']['type'] ?? null) ? $root['data']['type'] : '';
        if ($type === 'OxygenElements\\CssCode' || str_ends_with($type, '\\CssCode')) {
            return true;
        }

        $children = is_array($root['children'] ?? null) ? $root['children'] : [];
        foreach ($children as $child) {
            if (is_array($child) && $this->nodeContainsCssCode($child)) {
                return true;
            }
        }

        return false;
    }
}
