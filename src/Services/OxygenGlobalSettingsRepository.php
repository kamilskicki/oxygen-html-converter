<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenGlobalSettingsRepository
{
    public const OPTION_NAME = 'oxygen_global_settings_json_string';
    private ?OxygenStorageAdapter $storageAdapter;

    public function __construct(?OxygenStorageAdapter $storageAdapter = null)
    {
        $this->storageAdapter = $storageAdapter;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveFromPayload(array $payload): array
    {
        $incoming = $this->buildIncomingSettings($payload);

        if ($incoming === []) {
            return [
                'saved' => false,
                'changes' => 0,
                'sections' => [],
                'paletteColors' => 0,
                'cacheRegenerated' => false,
                'skippedReason' => 'no_global_settings_or_color_tokens',
            ];
        }

        $settings = $this->getCurrentSettings();
        $merged = $this->mergeGlobalSettings($settings, $incoming);

        if (!$merged['changed']) {
            return [
                'saved' => false,
                'changes' => 0,
                'sections' => $merged['sections'],
                'paletteColors' => $merged['paletteColors'],
                'cacheRegenerated' => false,
                'skippedReason' => 'already_current',
            ];
        }

        $cacheRegenerated = $this->persistGlobalSettings($merged['settings']);

        return [
            'saved' => true,
            'changes' => $merged['changes'],
            'sections' => $merged['sections'],
            'paletteColors' => $merged['paletteColors'],
            'cacheRegenerated' => $cacheRegenerated,
            'skippedReason' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentSettings(): array
    {
        if (function_exists('\Breakdance\Data\get_global_settings_array')) {
            $settings = \Breakdance\Data\get_global_settings_array();
            return is_array($settings) ? $settings : [];
        }

        if (function_exists('get_option')) {
            $raw = get_option(self::OPTION_NAME, '');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function persistGlobalSettings(array $settings): bool
    {
        $result = $this->getStorageAdapter()->writeGlobalSettings($settings);

        if (empty($result['success'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Failed to persist Oxygen global settings.'));
        }

        return (bool) ($result['cacheRegenerated'] ?? false);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array{settings: array<string, mixed>, changed: bool, changes: int, sections: array<int, string>, paletteColors: int}
     */
    public function mergeGlobalSettings(array $current, array $incoming): array
    {
        $merged = $current;
        $merged['settings'] = is_array($merged['settings'] ?? null) ? $merged['settings'] : [];
        $incomingSettings = is_array($incoming['settings'] ?? null) ? $incoming['settings'] : $incoming;
        $sections = [];
        $changes = 0;

        foreach (['colors', 'typography', 'containers', 'code'] as $section) {
            if (!isset($incomingSettings[$section]) || !is_array($incomingSettings[$section])) {
                continue;
            }

            $before = $merged['settings'][$section] ?? [];
            $merged['settings'][$section] = $this->mergeSection($section, is_array($before) ? $before : [], $incomingSettings[$section]);

            if (($merged['settings'][$section] ?? []) !== $before) {
                $changes++;
            }

            $sections[] = $section;
        }

        return [
            'settings' => $merged,
            'changed' => $merged !== $current,
            'changes' => $changes,
            'sections' => array_values(array_unique($sections)),
            'paletteColors' => count($merged['settings']['colors']['palette']['colors'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeSection(string $section, array $current, array $incoming): array
    {
        if ($section === 'colors') {
            return $this->mergeColorsSection($current, $incoming);
        }

        return $this->mergeRecursive($current, $incoming);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeColorsSection(array $current, array $incoming): array
    {
        $incomingPaletteColors = $incoming['palette']['colors'] ?? null;
        unset($incoming['palette']['colors']);

        $merged = $this->mergeRecursive($current, $incoming);

        if (is_array($incomingPaletteColors)) {
            $existingColors = is_array($current['palette']['colors'] ?? null) ? $current['palette']['colors'] : [];
            $merged['palette'] = is_array($merged['palette'] ?? null) ? $merged['palette'] : [];
            $merged['palette']['colors'] = $this->mergePaletteColors($existingColors, $incomingPaletteColors);
        }

        return $merged;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergePaletteColors(array $existing, array $incoming): array
    {
        $colors = [];
        $indexByName = [];

        foreach ($existing as $color) {
            if (!is_array($color)) {
                continue;
            }

            $name = $this->normalizePaletteColorName((string) ($color['cssVariableName'] ?? ''));
            if ($name === '') {
                continue;
            }

            $color['cssVariableName'] = $name;
            $indexByName[$name] = count($colors);
            $colors[] = $color;
        }

        foreach ($incoming as $color) {
            if (!is_array($color)) {
                continue;
            }

            $name = $this->normalizePaletteColorName((string) ($color['cssVariableName'] ?? ''));
            $value = is_scalar($color['value'] ?? null) ? trim((string) $color['value']) : '';

            if ($name === '' || $value === '') {
                continue;
            }

            $record = [
                'label' => is_scalar($color['label'] ?? null) && trim((string) $color['label']) !== ''
                    ? trim((string) $color['label'])
                    : ucwords(str_replace('-', ' ', preg_replace('/^ohc-/', '', $name) ?? $name)),
                'cssVariableName' => $name,
                'value' => $value,
            ];

            if (isset($indexByName[$name])) {
                $colors[$indexByName[$name]] = array_merge($colors[$indexByName[$name]], $record);
            } else {
                $indexByName[$name] = count($colors);
                $colors[] = $record;
            }
        }

        return $colors;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildIncomingSettings(array $payload): array
    {
        $settings = $this->extractExplicitSettings($payload);
        $tokenSettings = $this->buildSettingsFromTokens($payload);

        if ($settings === []) {
            return $tokenSettings;
        }

        if ($tokenSettings === []) {
            return $settings;
        }

        return $this->mergeGlobalSettings($settings, $tokenSettings)['settings'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractExplicitSettings(array $payload): array
    {
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return is_array($payload[$key]['settings'] ?? null) ? $payload[$key] : ['settings' => $payload[$key]];
            }
        }

        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        if (isset($designDocument['oxygenGlobalSettings']) && is_array($designDocument['oxygenGlobalSettings'])) {
            $settings = $designDocument['oxygenGlobalSettings'];
            return is_array($settings['settings'] ?? null) ? $settings : ['settings' => $settings];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSettingsFromTokens(array $payload): array
    {
        $tokens = $this->resolveTokens($payload);
        $colors = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];
        $paletteColors = [];

        foreach ($colors as $token) {
            if (!is_array($token)) {
                continue;
            }

            $value = is_scalar($token['value'] ?? null) ? trim((string) $token['value']) : '';
            $suggestedName = is_scalar($token['suggestedName'] ?? null) ? trim((string) $token['suggestedName']) : '';

            if ($value === '' || $suggestedName === '' || preg_match('/^#[0-9a-f]{3,8}$/i', $value) !== 1) {
                continue;
            }

            $name = $this->normalizeCssVariableName($suggestedName);
            $paletteColors[] = [
                'label' => ucwords(str_replace('-', ' ', preg_replace('/^ohc-/', '', $name) ?? $name)),
                'cssVariableName' => $name,
                'value' => strtoupper($value),
            ];
        }

        if ($paletteColors === []) {
            return [];
        }

        return [
            'settings' => [
                'colors' => [
                    'palette' => [
                        'colors' => $paletteColors,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveTokens(array $payload): array
    {
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        if (is_array($importPlan['tokens'] ?? null)) {
            return $importPlan['tokens'];
        }

        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        if (is_array($designDocument['tokens'] ?? null)) {
            return $designDocument['tokens'];
        }

        return [];
    }

    private function normalizeCssVariableName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = ltrim($name, '-');
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            return '';
        }

        return str_starts_with($name, 'ohc-') ? $name : 'ohc-' . $name;
    }

    private function normalizePaletteColorName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = ltrim($name, '-');
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';

        return trim($name, '-');
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($current[$key] ?? null)) {
                $current[$key] = $this->mergeRecursive($current[$key], $value);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }
}
