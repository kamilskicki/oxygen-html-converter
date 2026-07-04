<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenVariableRepository
{
    public const OPTION_NAME = 'oxygen_variables_json_string';
    public const COLLECTIONS_OPTION_NAME = 'oxygen_variables_collections_json_string';
    private const GLOBAL_OPTION_KEY = 'variables_json_string';
    private const COLLECTIONS_GLOBAL_OPTION_KEY = 'variables_collections_json_string';
    private const ID_PREFIX = 'ohc-var-';
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
        $incoming = $this->buildVariablesFromPayload($payload);

        if ($incoming === []) {
            return [
                'saved' => false,
                'changes' => 0,
                'created' => 0,
                'updated' => 0,
                'linkedExisting' => 0,
                'skipped' => $this->buildSkippedSummary($payload),
                'total' => count($this->getExistingVariables()),
                'collections' => $this->getExistingCollections(),
                'cacheRegenerated' => false,
            ];
        }

        $existing = $this->getExistingVariables();
        $merge = $this->mergeVariables($existing, $incoming);
        $collections = $this->mergeCollections($merge['variables']);
        $changes = $merge['created'] + $merge['updated'];
        $cacheRegenerated = $changes > 0
            ? $this->persistVariables($merge['variables'], $collections)
            : false;

        return [
            'saved' => $changes > 0,
            'changes' => $changes,
            'created' => $merge['created'],
            'updated' => $merge['updated'],
            'linkedExisting' => $merge['linkedExisting'],
            'skipped' => $this->buildSkippedSummary($payload),
            'total' => count($merge['variables']),
            'collections' => $collections,
            'cacheRegenerated' => $cacheRegenerated,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getExistingVariables(): array
    {
        foreach ($this->variableSources() as $source) {
            $variables = $this->decodeVariableList($source);

            if ($variables !== []) {
                return $variables;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getExistingCollections(): array
    {
        foreach ($this->collectionSources() as $source) {
            if (is_string($source)) {
                $decoded = json_decode($source, true);
                $source = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($source)) {
                continue;
            }

            $collections = $this->normalizeCollections($source);

            if ($collections !== []) {
                return $collections;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function buildVariablesFromPayload(array $payload): array
    {
        $tokens = $this->resolveTokens($payload);
        $variables = [];

        foreach ($this->normalizeTokenGroup($tokens['colors'] ?? []) as $token) {
            $variable = $this->buildColorVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        foreach ($this->normalizeTokenGroup($tokens['spacing'] ?? []) as $token) {
            $variable = $this->buildUnitVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        foreach ($this->normalizeTokenGroup($tokens['fonts'] ?? []) as $token) {
            $variable = $this->buildFontFamilyVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        return $this->dedupeVariables($variables);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @return array{variables: array<int, array<string, mixed>>, created: int, updated: int, linkedExisting: int}
     */
    public function mergeVariables(array $existing, array $incoming): array
    {
        $merged = array_values($existing);
        $indexById = [];
        $indexByName = [];
        $created = 0;
        $updated = 0;
        $linkedExisting = 0;

        foreach ($merged as $index => $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $id = is_string($variable['id'] ?? null) ? trim($variable['id']) : '';
            $name = $this->normalizeExistingCssVariableName($variable['cssVariableName'] ?? null);

            if ($id !== '') {
                $indexById[$id] = $index;
            }

            if ($name !== '') {
                $indexByName[$name] = $index;
            }
        }

        foreach ($incoming as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $id = is_string($variable['id'] ?? null) ? trim($variable['id']) : '';
            $name = $this->normalizeExistingCssVariableName($variable['cssVariableName'] ?? null);

            if ($id !== '' && isset($indexById[$id])) {
                $index = $indexById[$id];
                if ($this->variablesDiffer($merged[$index], $variable)) {
                    $merged[$index] = array_merge($merged[$index], $variable);
                    $updated++;
                }
                continue;
            }

            if ($name !== '' && isset($indexByName[$name])) {
                $index = $indexByName[$name];
                $existingId = is_string($merged[$index]['id'] ?? null) ? (string) $merged[$index]['id'] : '';

                if (str_starts_with($existingId, self::ID_PREFIX) && $this->variablesDiffer($merged[$index], $variable)) {
                    $merged[$index] = array_merge($merged[$index], $variable);
                    $updated++;
                } else {
                    $linkedExisting++;
                }

                continue;
            }

            $merged[] = $variable;
            if ($id !== '') {
                $indexById[$id] = count($merged) - 1;
            }
            if ($name !== '') {
                $indexByName[$name] = count($merged) - 1;
            }
            $created++;
        }

        return [
            'variables' => array_values($merged),
            'created' => $created,
            'updated' => $updated,
            'linkedExisting' => $linkedExisting,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $variables
     * @param array<int, string> $collections
     */
    public function persistVariables(array $variables, array $collections): bool
    {
        $result = $this->getStorageAdapter()->writeVariables($variables, $collections);

        if (empty($result['success'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Failed to persist Oxygen variables.'));
        }

        return (bool) ($result['cacheRegenerated'] ?? false);
    }

    /**
     * @param array<int, array<string, mixed>> $variables
     * @return array<int, string>
     */
    private function mergeCollections(array $variables): array
    {
        $collections = $this->getExistingCollections();

        foreach ($variables as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $collection = is_string($variable['collection'] ?? null) ? trim($variable['collection']) : '';
            if ($collection !== '') {
                $collections[] = $collection;
            }
        }

        return $this->normalizeCollections($collections);
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

    /**
     * @param mixed $tokens
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTokenGroup($tokens): array
    {
        if (!is_array($tokens)) {
            return [];
        }

        $normalized = [];

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $value = is_scalar($token['value'] ?? null) ? trim((string) $token['value']) : '';
            $suggestedName = is_scalar($token['suggestedName'] ?? null) ? trim((string) $token['suggestedName']) : '';

            if ($value === '' || $suggestedName === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'suggestedName' => $suggestedName,
                'uses' => (int) ($token['uses'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildColorVariable(array $token): ?array
    {
        $value = $this->normalizeColorValue((string) $token['value']);

        if ($value === '') {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'color', $this->labelForToken($name), $value, 'Imported HTML Colors');
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildUnitVariable(array $token): ?array
    {
        $value = $this->normalizeUnitValue((string) $token['value']);

        if ($value === null) {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'unit', $this->labelForToken($name), $value, 'Imported HTML Spacing');
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildFontFamilyVariable(array $token): ?array
    {
        $value = $this->normalizeFontFamilyValue((string) $token['value']);

        if ($value === '') {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'font_family', $this->labelForToken($name), $value, 'Imported HTML Fonts');
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function variableRecord(string $name, string $type, string $label, $value, string $collection): array
    {
        return [
            'id' => self::ID_PREFIX . substr(sha1($type . ':' . $name), 0, 16),
            'type' => $type,
            'label' => $label,
            'cssVariableName' => $name,
            'collection' => $collection,
            'value' => $value,
            'dynamicData' => null,
        ];
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }

    private function normalizeCssVariableName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = ltrim($name, '-');
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'token';
        }

        if (!str_starts_with($name, 'ohc-')) {
            $name = 'ohc-' . $name;
        }

        return $name;
    }

    /**
     * @param mixed $name
     */
    private function normalizeExistingCssVariableName($name): string
    {
        if (!is_scalar($name)) {
            return '';
        }

        return strtolower(trim(ltrim((string) $name, '-')));
    }

    private function labelForToken(string $name): string
    {
        $label = preg_replace('/^ohc-/', '', $name) ?? $name;
        $label = str_replace('-', ' ', $label);

        return ucwords($label);
    }

    private function normalizeColorValue(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1) {
            return strtoupper($value);
        }

        if (preg_match('/^(?:rgba?|hsla?)\([^)]+\)$/i', $value) === 1) {
            return strtolower(preg_replace('/\s+/', '', $value) ?? $value);
        }

        if (preg_match('/^(?:linear|radial|conic)-gradient\(.+\)$/i', $value) === 1) {
            return $value;
        }

        return '';
    }

    /**
     * @return array{number:int|float|string, unit:string, style:string}|null
     */
    private function normalizeUnitValue(string $value): ?array
    {
        $value = trim(strtolower($value));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)(px|rem|em|vw|vh|%|ch|fr)$/', $value, $matches) === 1) {
            $number = str_contains($matches[1], '.') ? (float) $matches[1] : (int) $matches[1];

            return [
                'number' => $number,
                'unit' => $matches[2],
                'style' => $value,
            ];
        }

        if (preg_match('/^(?:clamp|calc|min|max|var)\(.+\)$/', $value) === 1) {
            return [
                'number' => $value,
                'unit' => 'custom',
                'style' => $value,
            ];
        }

        return null;
    }

    private function normalizeFontFamilyValue(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($value === '' || preg_match('/[;{}]/', $value) === 1) {
            return '';
        }

        if ($this->isIconFontFamily($value)) {
            return '';
        }

        return preg_match('/\s/', $value) === 1 ? '"' . $value . '"' : $value;
    }

    private function isIconFontFamily(string $value): bool
    {
        $normalized = strtolower($value);

        foreach (['material symbols', 'material icons', 'font awesome', 'dashicons', 'swiper-icons'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $variables
     * @return array<int, array<string, mixed>>
     */
    private function dedupeVariables(array $variables): array
    {
        $seen = [];
        $deduped = [];

        foreach ($variables as $variable) {
            $name = $this->normalizeExistingCssVariableName($variable['cssVariableName'] ?? null);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $deduped[] = $variable;
        }

        return $deduped;
    }

    /**
     * @return array<int, mixed>
     */
    private function variableSources(): array
    {
        $sources = [];

        if (function_exists('\Breakdance\Data\get_global_option')) {
            $sources[] = \Breakdance\Data\get_global_option(self::GLOBAL_OPTION_KEY);
        }

        if (function_exists('get_option')) {
            $sources[] = get_option(self::OPTION_NAME, []);
        }

        return $sources;
    }

    /**
     * @return array<int, mixed>
     */
    private function collectionSources(): array
    {
        $sources = [];

        if (function_exists('\Breakdance\Data\get_global_option')) {
            $sources[] = \Breakdance\Data\get_global_option(self::COLLECTIONS_GLOBAL_OPTION_KEY);
        }

        if (function_exists('get_option')) {
            $sources[] = get_option(self::COLLECTIONS_OPTION_NAME, []);
        }

        return $sources;
    }

    /**
     * @param mixed $source
     * @return array<int, array<string, mixed>>
     */
    private function decodeVariableList($source): array
    {
        if (is_string($source)) {
            $decoded = json_decode($source, true);
            $source = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($source)) {
            return [];
        }

        $variables = [];

        foreach ($source as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $id = is_string($variable['id'] ?? null) ? trim($variable['id']) : '';
            $name = is_string($variable['cssVariableName'] ?? null) ? trim($variable['cssVariableName']) : '';
            $type = is_string($variable['type'] ?? null) ? trim($variable['type']) : '';

            if ($id === '' || $name === '' || $type === '') {
                continue;
            }

            if (!array_key_exists('dynamicData', $variable)) {
                $variable['dynamicData'] = null;
            }

            $variables[] = $variable;
        }

        return $variables;
    }

    /**
     * @param array<int, mixed> $collections
     * @return array<int, string>
     */
    private function normalizeCollections(array $collections): array
    {
        $normalized = [];

        foreach ($collections as $collection) {
            if (!is_scalar($collection)) {
                continue;
            }

            $collection = trim((string) $collection);
            if ($collection !== '') {
                $normalized[] = $collection;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function variablesDiffer(array $left, array $right): bool
    {
        $leftComparable = $left;
        $rightComparable = $right;
        ksort($leftComparable);
        ksort($rightComparable);

        return $leftComparable !== $rightComparable;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function buildSkippedSummary(array $payload): array
    {
        $tokens = $this->resolveTokens($payload);
        $proposed = 0;

        foreach (['colors', 'spacing', 'fonts'] as $group) {
            $proposed += count($this->normalizeTokenGroup($tokens[$group] ?? []));
        }

        return [
            'proposed' => $proposed,
            'persistable' => count($this->buildVariablesFromPayload($payload)),
        ];
    }
}
