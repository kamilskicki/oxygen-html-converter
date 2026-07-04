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
        foreach (['colors' => 'color', 'fonts' => 'font', 'spacing' => 'spacing'] as $group => $type) {
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

        $library['updatedAt'] = gmdate('c');
        update_option(self::OPTION_NAME, wp_json_encode($library));

        return [
            'saved' => true,
            'tokenChanges' => $tokenChanges,
            'componentChanges' => $componentChanges,
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
            ],
            'components' => array_values(is_array($library['components'] ?? null) ? $library['components'] : []),
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

    private function deterministicId(string $seed): string
    {
        return substr(sha1('oxy-html-converter-brand:' . $seed), 0, 16);
    }
}
