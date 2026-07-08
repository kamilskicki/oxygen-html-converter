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
                'skippedReason' => 'no_global_settings_or_tokens',
            ];
        }

        $settings = $this->getCurrentSettings();
        $merged = $this->mergeGlobalSettings($settings, $incoming, $this->shouldOverwriteGlobalSettings($payload));

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
    public function mergeGlobalSettings(array $current, array $incoming, bool $overwrite = true): array
    {
        $merged = $current;
        $merged['settings'] = is_array($merged['settings'] ?? null) ? $merged['settings'] : [];
        $incomingSettings = is_array($incoming['settings'] ?? null) ? $incoming['settings'] : $incoming;
        $sections = [];
        $changes = 0;

        foreach (['colors', 'typography', 'containers', 'code', 'other'] as $section) {
            if (!isset($incomingSettings[$section]) || !is_array($incomingSettings[$section])) {
                continue;
            }

            $before = $merged['settings'][$section] ?? [];
            $merged['settings'][$section] = $this->mergeSection($section, is_array($before) ? $before : [], $incomingSettings[$section], $overwrite);

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
    private function mergeSection(string $section, array $current, array $incoming, bool $overwrite): array
    {
        if ($section === 'colors') {
            return $this->mergeColorsSection($current, $incoming, $overwrite);
        }

        return $this->mergeRecursive($current, $incoming, $overwrite);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeColorsSection(array $current, array $incoming, bool $overwrite): array
    {
        $incomingPaletteColors = $incoming['palette']['colors'] ?? null;
        $incomingPaletteGradients = $incoming['palette']['gradients'] ?? null;
        unset($incoming['palette']['colors']);
        unset($incoming['palette']['gradients']);

        $merged = $this->mergeRecursive($current, $incoming, $overwrite);

        if (is_array($incomingPaletteColors)) {
            $existingColors = is_array($current['palette']['colors'] ?? null) ? $current['palette']['colors'] : [];
            $merged['palette'] = is_array($merged['palette'] ?? null) ? $merged['palette'] : [];
            $merged['palette']['colors'] = $this->mergePaletteColors($existingColors, $incomingPaletteColors, $overwrite);
        }

        if (is_array($incomingPaletteGradients)) {
            $existingGradients = is_array($current['palette']['gradients'] ?? null) ? $current['palette']['gradients'] : [];
            $merged['palette'] = is_array($merged['palette'] ?? null) ? $merged['palette'] : [];
            $merged['palette']['gradients'] = $this->mergePaletteGradients($existingGradients, $incomingPaletteGradients, $overwrite);
        }

        return $merged;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergePaletteColors(array $existing, array $incoming, bool $overwrite): array
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

            if (isset($indexByName[$name]) && $overwrite) {
                $colors[$indexByName[$name]] = array_merge($colors[$indexByName[$name]], $record);
            } elseif (!isset($indexByName[$name])) {
                $indexByName[$name] = count($colors);
                $colors[] = $record;
            }
        }

        return $colors;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergePaletteGradients(array $existing, array $incoming, bool $overwrite): array
    {
        $gradients = [];
        $indexByName = [];

        foreach ($existing as $gradient) {
            if (!is_array($gradient)) {
                continue;
            }

            $name = $this->normalizePaletteColorName((string) ($gradient['cssVariableName'] ?? ''));
            if ($name === '') {
                continue;
            }

            $gradient['cssVariableName'] = $name;
            $indexByName[$name] = count($gradients);
            $gradients[] = $gradient;
        }

        foreach ($incoming as $gradient) {
            if (!is_array($gradient)) {
                continue;
            }

            $name = $this->normalizePaletteColorName((string) ($gradient['cssVariableName'] ?? ''));
            $value = is_array($gradient['value'] ?? null) ? $gradient['value'] : null;
            $svgValue = is_array($value) && is_string($value['svgValue'] ?? null) ? trim((string) $value['svgValue']) : '';

            if ($name === '' || $svgValue === '') {
                continue;
            }

            $record = [
                'label' => is_scalar($gradient['label'] ?? null) && trim((string) $gradient['label']) !== ''
                    ? trim((string) $gradient['label'])
                    : ucwords(str_replace('-', ' ', preg_replace('/^ohc-/', '', $name) ?? $name)),
                'cssVariableName' => $name,
                'value' => $value,
            ];

            if (isset($indexByName[$name]) && $overwrite) {
                $gradients[$indexByName[$name]] = array_merge($gradients[$indexByName[$name]], $record);
            } elseif (!isset($indexByName[$name])) {
                $indexByName[$name] = count($gradients);
                $gradients[] = $record;
            }
        }

        return $gradients;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildIncomingSettings(array $payload): array
    {
        $settings = $this->extractExplicitSettings($payload);
        // Oxygen 6.1 stable stores global settings, but Oxygen mode disables the
        // default global-settings UI/CSS template unless an add-on re-enables it.
        // Core therefore keeps token output on variables/selectors by default.
        $tokenSettings = $this->shouldInferDormantGlobalSettingsFromTokens($payload)
            ? $this->buildSettingsFromTokens($payload)
            : [];

        if ($settings === []) {
            return $tokenSettings;
        }

        if ($tokenSettings === []) {
            return $settings;
        }

        return $this->mergeGlobalSettings($tokenSettings, $settings, true)['settings'];
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

        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($importPlan[$key]) && is_array($importPlan[$key])) {
                $settings = $importPlan[$key];
                return is_array($settings['settings'] ?? null) ? $settings : ['settings' => $settings];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldInferDormantGlobalSettingsFromTokens(array $payload): bool
    {
        foreach ([
            ['inferDormantGlobalSettingsFromTokens'],
            ['globalSettingsInferFromTokens'],
            ['options', 'inferDormantGlobalSettingsFromTokens'],
            ['manifest', 'inferDormantGlobalSettingsFromTokens'],
            ['importManifest', 'inferDormantGlobalSettingsFromTokens'],
            ['importPlan', 'inferDormantGlobalSettingsFromTokens'],
            ['importPlan', 'globalSettings', 'inferFromTokens'],
            ['importPlan', 'persistence', 'globalSettings', 'inferFromTokens'],
        ] as $path) {
            $value = $this->valueAtPath($payload, $path);
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldOverwriteGlobalSettings(array $payload): bool
    {
        foreach ([
            ['overwriteGlobalSettings'],
            ['globalSettingsOverwrite'],
            ['options', 'overwriteGlobalSettings'],
            ['manifest', 'overwriteGlobalSettings'],
            ['importManifest', 'overwriteGlobalSettings'],
            ['importPlan', 'overwriteGlobalSettings'],
            ['importPlan', 'globalSettings', 'overwrite'],
            ['importPlan', 'persistence', 'globalSettings', 'overwrite'],
        ] as $path) {
            $value = $this->valueAtPath($payload, $path);
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $path
     * @return mixed
     */
    private function valueAtPath(array $payload, array $path)
    {
        $current = $payload;

        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSettingsFromTokens(array $payload): array
    {
        return (new OxygenGlobalSettingsInferenceService())->infer($this->resolveTokens($payload));
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
    private function mergeRecursive(array $current, array $incoming, bool $overwrite): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && $value !== [] && array_is_list($value) && is_array($current[$key] ?? null)) {
                $current[$key] = $this->mergeList($current[$key], $value, $overwrite);
                continue;
            }

            if (is_array($value) && is_array($current[$key] ?? null)) {
                $current[$key] = $this->mergeRecursive($current[$key], $value, $overwrite);
            } elseif (array_key_exists($key, $current) && !$overwrite) {
                continue;
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    /**
     * @param array<int, mixed> $current
     * @param array<int, mixed> $incoming
     * @return array<int, mixed>
     */
    private function mergeList(array $current, array $incoming, bool $overwrite): array
    {
        $merged = array_values($current);
        $indexByKey = [];

        foreach ($merged as $index => $item) {
            $indexByKey[$this->listItemKey($item)] = $index;
        }

        foreach ($incoming as $item) {
            $key = $this->listItemKey($item);
            if (isset($indexByKey[$key]) && $overwrite) {
                $merged[$indexByKey[$key]] = $item;
            } elseif (!isset($indexByKey[$key])) {
                $indexByKey[$key] = count($merged);
                $merged[] = $item;
            }
        }

        return $merged;
    }

    /**
     * @param mixed $item
     */
    private function listItemKey($item): string
    {
        if (is_array($item)) {
            foreach (['name', 'cssVariableName', 'id'] as $field) {
                if (is_scalar($item[$field] ?? null) && trim((string) $item[$field]) !== '') {
                    return $field . ':' . strtolower(trim((string) $item[$field]));
                }
            }

            $preset = $item['preset'] ?? null;
            if (is_array($preset) && is_scalar($preset['id'] ?? null) && trim((string) $preset['id']) !== '') {
                return 'preset:' . strtolower(trim((string) $preset['id']));
            }


        }

        $encoded = json_encode($item);

        return 'hash:' . sha1(is_string($encoded) ? $encoded : serialize($item));
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }
}
