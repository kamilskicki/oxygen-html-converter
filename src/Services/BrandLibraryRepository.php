<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class BrandLibraryRepository
{
    public const OPTION_NAME = 'oxy_html_converter_brand_library';

    /**
     * @return array<string, mixed>
     */
    public function getLibrary(): array
    {
        $raw = get_option(self::OPTION_NAME, []);

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
        $library = $this->getLibrary();
        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        $tokenPlan = is_array($importPlan['tokens'] ?? null) ? $importPlan['tokens'] : [];
        $componentPlan = is_array($importPlan['components'] ?? null) ? $importPlan['components'] : [];
        $designTokens = is_array($designDocument['tokens'] ?? null) ? $designDocument['tokens'] : [];
        $designComponents = is_array($designDocument['componentCandidates'] ?? null)
            ? $designDocument['componentCandidates']
            : [];

        $tokenChanges = 0;
        foreach ($this->tokenGroups() as $group => $type) {
            $incomingTokens = is_array($tokenPlan[$group] ?? null) && $tokenPlan[$group] !== []
                ? $tokenPlan[$group]
                : (is_array($designTokens[$group] ?? null) ? $designTokens[$group] : []);

            foreach ($incomingTokens as $token) {
                if (!is_array($token)) {
                    continue;
                }

                if ($this->mergeToken($library, $group, $type, $token)) {
                    $tokenChanges++;
                }
            }
        }

        $componentChanges = 0;
        $incomingComponents = $componentPlan !== [] ? $componentPlan : $designComponents;
        foreach ($incomingComponents as $component) {
            if (!is_array($component)) {
                continue;
            }

            if ($this->mergeComponent($library, $component)) {
                $componentChanges++;
            }
        }

        $globalSettingsChanges = $this->mergeGlobalSettings($library, $payload);
        $designProfileChanges = $this->mergeDesignProfile($library, $payload);

        $library['updatedAt'] = gmdate('c');
        update_option(self::OPTION_NAME, wp_json_encode($library));

        return [
            'saved' => true,
            'tokenChanges' => $tokenChanges,
            'componentChanges' => $componentChanges,
            'globalSettingsChanges' => $globalSettingsChanges,
            'designProfileChanges' => $designProfileChanges,
            'library' => $library,
        ];
    }

    /**
     * @param array<string, mixed> $library
     * @return array<string, mixed>
     */
    private function normalizeLibrary(array $library): array
    {
        $tokens = is_array($library['tokens'] ?? null) ? $library['tokens'] : [];

        return [
            'version' => 1,
            'updatedAt' => is_string($library['updatedAt'] ?? null) ? $library['updatedAt'] : '',
            'tokens' => [
                'colors' => array_values(is_array($tokens['colors'] ?? null) ? $tokens['colors'] : []),
                'fonts' => array_values(is_array($tokens['fonts'] ?? null) ? $tokens['fonts'] : []),
                'spacing' => array_values(is_array($tokens['spacing'] ?? null) ? $tokens['spacing'] : []),
                'images' => array_values(is_array($tokens['images'] ?? null) ? $tokens['images'] : []),
                'measurements' => array_values(is_array($tokens['measurements'] ?? null) ? $tokens['measurements'] : []),
                'numbers' => array_values(is_array($tokens['numbers'] ?? null) ? $tokens['numbers'] : []),
            ],
            'components' => array_values(is_array($library['components'] ?? null) ? $library['components'] : []),
            'globalSettings' => is_array($library['globalSettings'] ?? null) ? $library['globalSettings'] : [],
            'designProfile' => is_array($library['designProfile'] ?? null) ? $library['designProfile'] : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function tokenGroups(): array
    {
        return [
            'colors' => 'color',
            'fonts' => 'font',
            'spacing' => 'spacing',
            'images' => 'image',
            'measurements' => 'measurement',
            'numbers' => 'number',
        ];
    }

    /**
     * @param array<string, mixed> $library
     * @param array<string, mixed> $token
     */
    private function mergeToken(array &$library, string $group, string $type, array $token): bool
    {
        $value = trim((string) ($token['value'] ?? ''));
        $suggestedName = trim((string) ($token['suggestedName'] ?? ''));

        if ($value === '' || $suggestedName === '') {
            return false;
        }

        $id = $this->deterministicId('token:' . $type . ':' . strtolower($value));
        $existingIndex = $this->findById($library['tokens'][$group], $id);
        $now = gmdate('c');
        $entry = [
            'id' => $id,
            'type' => $type,
            'value' => $value,
            'suggestedName' => $suggestedName,
            'uses' => (int) ($token['uses'] ?? 0),
            'status' => is_string($token['status'] ?? null) ? $token['status'] : 'proposed',
            'firstSeenAt' => $now,
            'lastSeenAt' => $now,
        ];

        if (array_key_exists('dynamicData', $token)) {
            $entry['dynamicData'] = $token['dynamicData'];
        }

        if ($existingIndex === null) {
            $library['tokens'][$group][] = $entry;
            return true;
        }

        $existing = is_array($library['tokens'][$group][$existingIndex]) ? $library['tokens'][$group][$existingIndex] : [];
        $entry['firstSeenAt'] = is_string($existing['firstSeenAt'] ?? null) ? $existing['firstSeenAt'] : $now;
        $entry['uses'] += (int) ($existing['uses'] ?? 0);
        $library['tokens'][$group][$existingIndex] = array_merge($existing, $entry);

        return true;
    }

    /**
     * @param array<string, mixed> $library
     * @param array<string, mixed> $component
     */
    private function mergeComponent(array &$library, array $component): bool
    {
        $suggestedName = trim((string) ($component['suggestedName'] ?? ''));
        $signature = trim((string) ($component['signature'] ?? ''));

        if ($suggestedName === '' || $signature === '') {
            return false;
        }

        $id = $this->deterministicId('component:' . strtolower($signature));
        $existingIndex = $this->findById($library['components'], $id);
        $now = gmdate('c');
        $classes = is_array($component['classes'] ?? null)
            ? array_values(array_unique(array_map('strval', $component['classes'])))
            : [];
        $entry = [
            'id' => $id,
            'suggestedName' => $suggestedName,
            'signature' => $signature,
            'occurrences' => (int) ($component['occurrences'] ?? $component['count'] ?? 0),
            'classes' => $classes,
            'status' => is_string($component['status'] ?? null) ? $component['status'] : 'proposed',
            'firstSeenAt' => $now,
            'lastSeenAt' => $now,
        ];

        if ($existingIndex === null) {
            $library['components'][] = $entry;
            return true;
        }

        $existing = is_array($library['components'][$existingIndex]) ? $library['components'][$existingIndex] : [];
        $entry['firstSeenAt'] = is_string($existing['firstSeenAt'] ?? null) ? $existing['firstSeenAt'] : $now;
        $entry['occurrences'] += (int) ($existing['occurrences'] ?? 0);
        $entry['classes'] = array_values(array_unique(array_merge(
            is_array($existing['classes'] ?? null) ? array_map('strval', $existing['classes']) : [],
            $classes
        )));
        $library['components'][$existingIndex] = array_merge($existing, $entry);

        return true;
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

    /**
     * @param array<string, mixed> $library
     * @param array<string, mixed> $payload
     */
    private function mergeGlobalSettings(array &$library, array $payload): int
    {
        $incoming = $this->resolveGlobalSettings($payload);
        if ($incoming === []) {
            return 0;
        }

        $before = $library['globalSettings'] ?? [];
        $library['globalSettings'] = $this->mergeRecursive(is_array($before) ? $before : [], $incoming);

        return $library['globalSettings'] === $before ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $library
     * @param array<string, mixed> $payload
     */
    private function mergeDesignProfile(array &$library, array $payload): int
    {
        $incoming = $this->resolveDesignProfile($payload);
        if ($incoming === []) {
            return 0;
        }

        $before = $library['designProfile'] ?? [];
        $library['designProfile'] = $this->mergeDesignProfileData(is_array($before) ? $before : [], $incoming);

        return $library['designProfile'] === $before ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeDesignProfileData(array $current, array $incoming): array
    {
        $merged = $this->mergeRecursive($current, $incoming);

        foreach ([
            'semanticClasses' => ['semanticClass', 'styleSignature', 'sourceClass'],
            'duplicateStylePatterns' => ['semanticClass', 'styleSignature'],
            'skippedStylePatterns' => ['reason', 'styleSignature'],
            'elementApplications' => ['tag', 'id', 'sourceClasses', 'appliedClasses'],
        ] as $field => $keyFields) {
            $existing = is_array($current[$field] ?? null) ? $current[$field] : [];
            $next = is_array($incoming[$field] ?? null) ? $incoming[$field] : [];
            $merged[$field] = $this->mergeListBySignature($existing, $next, $keyFields);
        }

        return $merged;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $incoming
     * @param list<string> $keyFields
     * @return list<array<string, mixed>>
     */
    private function mergeListBySignature(array $existing, array $incoming, array $keyFields): array
    {
        $items = [];
        $indexBySignature = [];

        foreach (array_merge($existing, $incoming) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $signature = $this->profileItemSignature($item, $keyFields);
            if (isset($indexBySignature[$signature])) {
                $items[$indexBySignature[$signature]] = array_merge($items[$indexBySignature[$signature]], $item);
                continue;
            }

            $indexBySignature[$signature] = count($items);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keyFields
     */
    private function profileItemSignature(array $item, array $keyFields): string
    {
        $parts = [];

        foreach ($keyFields as $field) {
            $value = $item[$field] ?? null;
            if (is_array($value)) {
                sort($value);
                $parts[] = $field . ':' . implode(',', array_map('strval', $value));
            } elseif (is_scalar($value)) {
                $parts[] = $field . ':' . (string) $value;
            }
        }

        if ($parts === []) {
            $encoded = json_encode($item);
            return 'hash:' . sha1(is_string($encoded) ? $encoded : serialize($item));
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveGlobalSettings(array $payload): array
    {
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return is_array($payload[$key]['settings'] ?? null) ? $payload[$key] : ['settings' => $payload[$key]];
            }
        }

        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($importPlan[$key]) && is_array($importPlan[$key])) {
                return is_array($importPlan[$key]['settings'] ?? null) ? $importPlan[$key] : ['settings' => $importPlan[$key]];
            }
        }

        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($designDocument[$key]) && is_array($designDocument[$key])) {
                $settings = $designDocument[$key];
                return is_array($settings['settings'] ?? null) ? $settings : ['settings' => $settings];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveDesignProfile(array $payload): array
    {
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        if (isset($importPlan['designProfile']) && is_array($importPlan['designProfile'])) {
            return $importPlan['designProfile'];
        }

        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        if (isset($designDocument['designProfile']) && is_array($designDocument['designProfile'])) {
            return $designDocument['designProfile'];
        }

        return [];
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

    private function deterministicId(string $seed): string
    {
        return substr(sha1('oxy-html-converter-brand:' . $seed), 0, 16);
    }
}
