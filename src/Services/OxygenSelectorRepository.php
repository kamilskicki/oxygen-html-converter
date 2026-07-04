<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenSelectorRepository
{
    private ?OxygenStorageAdapter $storageAdapter;

    public function __construct(?OxygenStorageAdapter $storageAdapter = null)
    {
        $this->storageAdapter = $storageAdapter;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function savePayload(array $payload): array
    {
        $selectors = $this->normalizeSelectorRecords($payload['selectors'] ?? []);

        if ($selectors === []) {
            return [
                'saved' => 0,
                'total' => count($this->getExistingSelectors()),
                'collections' => $this->mergeSelectorCollections($this->getExistingSelectors(), $payload['collections'] ?? []),
            ];
        }

        $existing = $this->getExistingSelectors();
        $merged = $this->mergeSelectorsById($existing, $selectors);
        $collections = $this->mergeSelectorCollections($merged, $payload['collections'] ?? []);
        $persistence = $this->persistSelectors($merged, $collections);

        if (empty($persistence['success'])) {
            throw new \RuntimeException((string) ($persistence['message'] ?? 'Failed to persist Oxygen selectors.'));
        }

        return [
            'saved' => count($selectors),
            'total' => count($merged),
            'collections' => $collections,
            'adapter' => $persistence['adapter'] ?? '',
        ];
    }

    /**
     * @param mixed $records
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSelectorRecords($records): array
    {
        if (!is_array($records)) {
            return [];
        }

        $selectors = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $id = is_string($record['id'] ?? null) ? trim($record['id']) : '';
            $name = is_string($record['name'] ?? null) ? trim($record['name']) : '';

            if ($id === '' || $name === '') {
                continue;
            }

            $properties = is_array($record['properties'] ?? null) ? $record['properties'] : [];

            // Drop importer-only fields such as "selector" and "persistence" before storing Oxygen selector options.
            $selectors[] = [
                'id' => $id,
                'name' => $name,
                'type' => 'class',
                'collection' => is_string($record['collection'] ?? null) && trim($record['collection']) !== ''
                    ? trim($record['collection'])
                    : 'Imported HTML',
                'locked' => (bool) ($record['locked'] ?? false),
                'children' => is_array($record['children'] ?? null) ? $record['children'] : [],
                'properties' => $properties === [] ? new \stdClass() : $properties,
            ];
        }

        return $selectors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getExistingSelectors(): array
    {
        // Read the raw global option before Oxygen's helper. getOxySelectors()
        // keeps a request-local static cache, which can be stale during batch
        // imports that save selector payloads several times in one PHP process.
        if (function_exists('\Breakdance\Data\get_global_option')) {
            $selectors = \Breakdance\Data\get_global_option('oxy_selectors_json_string');
            if (is_string($selectors)) {
                $selectors = json_decode($selectors, true);
            }

            return is_array($selectors) ? $this->normalizeSelectorRecords($selectors) : [];
        }

        if (function_exists('\Breakdance\BreakdanceOxygen\Selectors\getOxySelectors')) {
            $selectors = \Breakdance\BreakdanceOxygen\Selectors\getOxySelectors();
            return is_array($selectors) ? $this->normalizeSelectorRecords($selectors) : [];
        }

        $existingJson = get_option('oxygen_oxy_selectors_json_string', '[]');
        $selectors = is_string($existingJson) ? json_decode($existingJson, true) : null;

        return is_array($selectors) ? $this->normalizeSelectorRecords($selectors) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    public function mergeSelectorsById(array $existing, array $incoming): array
    {
        $mergedById = [];

        foreach (array_merge($existing, $incoming) as $selector) {
            if (!is_array($selector) || !is_string($selector['id'] ?? null)) {
                continue;
            }

            $mergedById[$selector['id']] = $selector;
        }

        return array_values($mergedById);
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param mixed $incomingCollections
     * @return array<int, string>
     */
    public function mergeSelectorCollections(array $selectors, $incomingCollections): array
    {
        $collections = [];

        if (is_array($incomingCollections)) {
            foreach ($incomingCollections as $collection) {
                if (is_string($collection) && trim($collection) !== '') {
                    $collections[] = trim($collection);
                }
            }
        }

        foreach ($selectors as $selector) {
            if (is_string($selector['collection'] ?? null) && trim($selector['collection']) !== '') {
                $collections[] = trim($selector['collection']);
            }
        }

        return array_values(array_unique($collections ?: ['Imported HTML']));
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<int, string> $collections
     */
    public function persistSelectors(array $selectors, array $collections): array
    {
        return $this->getStorageAdapter()->writeSelectors($selectors, $collections);
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }
}
