<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class GlobalStyleRepository
{
    public const OPTION_NAME = 'oxy_html_converter_global_styles';

    /**
     * @return array<string, mixed>
     */
    public function getLibrary(): array
    {
        $raw = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return $this->normalizeLibrary(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveFromPayload(array $payload): array
    {
        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        $globalCss = is_string($payload['globalCss'] ?? null)
            ? trim($payload['globalCss'])
            : trim((string) ($routing['globalCss'] ?? ''));
        $routes = $this->routesForDestination($routing, 'global_styles');

        if ($globalCss === '') {
            return [
                'saved' => false,
                'changes' => 0,
                'library' => $this->getLibrary(),
            ];
        }

        $library = $this->getLibrary();
        $entry = [
            'id' => $this->deterministicId($globalCss),
            'type' => 'global_css',
            'label' => 'Imported global CSS asset',
            'owner' => $this->singleOwner($routes, 'global'),
            'cascadeOrder' => $this->firstCascadeOrder($routes, 100),
            'exportBehavior' => 'export_with_global_styles',
            'rollbackStore' => 'global_styles',
            'pluginDependency' => $this->firstPluginDependency($routes),
            'routes' => $routes,
            'css' => $globalCss,
            'bytes' => strlen($globalCss),
            'firstSeenAt' => gmdate('c'),
            'lastSeenAt' => gmdate('c'),
        ];

        $existingIndex = $this->findById($library['styles'], $entry['id']);
        $changes = 0;
        if ($existingIndex === null) {
            $library['styles'][] = $entry;
            $changes = 1;
        } else {
            $existing = is_array($library['styles'][$existingIndex]) ? $library['styles'][$existingIndex] : [];
            $entry['firstSeenAt'] = is_string($existing['firstSeenAt'] ?? null) ? $existing['firstSeenAt'] : $entry['firstSeenAt'];
            $library['styles'][$existingIndex] = array_merge($existing, $entry);
        }

        $library['updatedAt'] = gmdate('c');
        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, wp_json_encode($library));
        }

        return [
            'saved' => true,
            'changes' => $changes,
            'library' => $library,
        ];
    }

    public function getCombinedCss(): string
    {
        $library = $this->getLibrary();
        $styles = $library['styles'];
        usort($styles, static function ($left, $right): int {
            $leftOrder = is_array($left) ? (int) ($left['cascadeOrder'] ?? 0) : 0;
            $rightOrder = is_array($right) ? (int) ($right['cascadeOrder'] ?? 0) : 0;
            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            $leftId = is_array($left) && is_scalar($left['id'] ?? null) ? (string) $left['id'] : '';
            $rightId = is_array($right) && is_scalar($right['id'] ?? null) ? (string) $right['id'] : '';

            return $leftId <=> $rightId;
        });
        $css = [];

        foreach ($styles as $style) {
            if (!is_array($style)) {
                continue;
            }

            $value = is_string($style['css'] ?? null) ? trim($style['css']) : '';
            if ($value !== '') {
                $css[] = $value;
            }
        }

        return implode("\n\n", array_values(array_unique($css)));
    }

    /**
     * @param array<string, mixed> $library
     * @return array<string, mixed>
     */
    private function normalizeLibrary(array $library): array
    {
        return [
            'version' => 1,
            'updatedAt' => is_string($library['updatedAt'] ?? null) ? $library['updatedAt'] : '',
            'styles' => array_values(is_array($library['styles'] ?? null) ? $library['styles'] : []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function findById(array $items, string $id): ?int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        return null;
    }

    private function deterministicId(string $css): string
    {
        return substr(sha1('oxy-html-converter-global-style:' . $css), 0, 16);
    }

    /**
     * @param array<string, mixed> $routing
     * @return list<array<string, mixed>>
     */
    private function routesForDestination(array $routing, string $destination): array
    {
        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        $matching = [];

        foreach ($routes as $route) {
            if (!is_array($route) || ($route['destination'] ?? null) !== $destination) {
                continue;
            }

            $matching[] = [
                'type' => is_string($route['type'] ?? null) ? $route['type'] : 'global_asset',
                'destination' => $destination,
                'label' => is_string($route['label'] ?? null) ? $route['label'] : 'Global style asset',
                'owner' => is_string($route['owner'] ?? null) ? $route['owner'] : 'global',
                'cascadeOrder' => (int) ($route['cascadeOrder'] ?? 100 + (count($matching) * 10)),
                'exportBehavior' => is_string($route['exportBehavior'] ?? null) ? $route['exportBehavior'] : 'export_with_global_styles',
                'rollbackStore' => is_string($route['rollbackStore'] ?? null) ? $route['rollbackStore'] : 'global_styles',
                'pluginDependency' => is_array($route['pluginDependency'] ?? null) ? $route['pluginDependency'] : null,
                'hash' => is_string($route['hash'] ?? null) ? $route['hash'] : '',
            ];
        }

        return $matching;
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
}
