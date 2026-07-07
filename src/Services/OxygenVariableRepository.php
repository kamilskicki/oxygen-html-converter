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
    private const SUPPORTED_TOKEN_GROUPS = [
        'colors' => true,
        'spacing' => true,
        'fonts' => true,
        'images' => true,
        'measurements' => true,
        'numbers' => true,
    ];

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
        $normalizationRewriteNeeded = $this->existingVariablesNeedNormalizationRewrite();
        $cacheRegenerated = ($changes > 0 || $normalizationRewriteNeeded)
            ? $this->persistVariables($merge['variables'], $collections)
            : false;

        return [
            'saved' => $changes > 0 || $normalizationRewriteNeeded,
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

        foreach ($this->normalizeTokenGroup($tokens['images'] ?? []) as $token) {
            $variable = $this->buildImageUrlVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        foreach ($this->normalizeTokenGroup($tokens['measurements'] ?? []) as $token) {
            $variable = $this->buildMeasurementVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        foreach ($this->normalizeTokenGroup($tokens['numbers'] ?? []) as $token) {
            $variable = $this->buildNumberVariable($token);
            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        return $this->dedupeVariables($variables);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<array<string, mixed>>, summary: array{proposed:int,supported:int,unsupported:int}}
     */
    public function buildTokenReferencesFromPayload(array $payload): array
    {
        $tokens = $this->resolveTokens($payload);
        $existingByName = [];

        foreach ($this->getExistingVariables() as $existing) {
            if (!is_array($existing)) {
                continue;
            }

            $name = $this->normalizeExistingCssVariableName($existing['cssVariableName'] ?? null);
            if ($name !== '') {
                $existingByName[$name] = $existing;
            }
        }

        $itemsByName = [];
        $proposed = 0;

        foreach (array_keys(self::SUPPORTED_TOKEN_GROUPS) as $group) {
            foreach ($this->normalizeTokenGroup($tokens[$group] ?? []) as $token) {
                $proposed++;
                $variable = $this->buildVariableForGroup($group, $token);
                if ($variable === null) {
                    continue;
                }

                $name = $this->normalizeExistingCssVariableName($variable['cssVariableName'] ?? null);
                if ($name === '' || isset($itemsByName[$name])) {
                    continue;
                }

                $existing = $existingByName[$name] ?? [];
                $variableId = is_string($existing['id'] ?? null) && trim((string) $existing['id']) !== ''
                    ? trim((string) $existing['id'])
                    : (string) $variable['id'];
                $normalizedValue = $this->referenceValueForVariable($variable);

                if ($normalizedValue === '') {
                    continue;
                }

                $itemsByName[$name] = [
                    'group' => $group,
                    'variableType' => (string) $variable['type'],
                    'suggestedName' => (string) ($token['suggestedName'] ?? ''),
                    'sourceValue' => $this->referenceSourceValue($token['value'] ?? null),
                    'normalizedValue' => $normalizedValue,
                    'uses' => (int) ($token['uses'] ?? 0),
                    'variableId' => $variableId,
                    'cssVariableName' => $name,
                    'cssReference' => 'var(--' . $name . ')',
                    'selectorReference' => '{var-' . $variableId . '}',
                    'dynamicData' => $variable['dynamicData'] ?? null,
                ];
            }
        }

        $items = array_values($itemsByName);

        return [
            'items' => $items,
            'summary' => [
                'proposed' => $proposed,
                'supported' => count($items),
                'unsupported' => max(0, $proposed - count($items)),
            ],
        ];
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

            $value = $token['value'] ?? null;
            $suggestedName = is_scalar($token['suggestedName'] ?? null) ? trim((string) $token['suggestedName']) : '';

            if (!$this->hasUsableTokenValue($value) || $suggestedName === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'suggestedName' => $suggestedName,
                'uses' => (int) ($token['uses'] ?? 0),
                'dynamicData' => array_key_exists('dynamicData', $token) ? $token['dynamicData'] : null,
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
        $value = $this->normalizeColorValue($this->scalarTokenValue($token['value'] ?? null));

        if ($value === '') {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'color', $this->labelForToken($name), $value, 'Imported HTML Colors', $token['dynamicData'] ?? null);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildUnitVariable(array $token): ?array
    {
        $value = $this->normalizeUnitValue($this->scalarTokenValue($token['value'] ?? null));

        if ($value === null) {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'unit', $this->labelForToken($name), $value, 'Imported HTML Spacing', $token['dynamicData'] ?? null);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildFontFamilyVariable(array $token): ?array
    {
        $value = $this->normalizeFontFamilyValue($this->scalarTokenValue($token['value'] ?? null));

        if ($value === '') {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'font_family', $this->labelForToken($name), $value, 'Imported HTML Fonts', $token['dynamicData'] ?? null);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildImageUrlVariable(array $token): ?array
    {
        $value = $this->normalizeImageUrlValue($token['value'] ?? null);

        if ($value === null) {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'image_url', $this->labelForToken($name), $value, 'Imported HTML Images', $token['dynamicData'] ?? null);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildMeasurementVariable(array $token): ?array
    {
        $value = $this->normalizeUnitValue($this->scalarTokenValue($token['value'] ?? null));

        if ($value === null) {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'unit', $this->labelForToken($name), $value, 'Imported HTML Measurements', $token['dynamicData'] ?? null);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildNumberVariable(array $token): ?array
    {
        $value = $this->normalizeNumberValue($token['value'] ?? null);

        if ($value === null) {
            return null;
        }

        $name = $this->normalizeCssVariableName((string) $token['suggestedName']);

        return $this->variableRecord($name, 'number', $this->labelForToken($name), $value, 'Imported HTML Numbers', $token['dynamicData'] ?? null);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function variableRecord(string $name, string $type, string $label, $value, string $collection, $dynamicData = null): array
    {
        $record = [
            'id' => self::ID_PREFIX . substr(sha1($type . ':' . $name), 0, 16),
            'cssVariableName' => $name,
            'label' => $label,
            'value' => $value,
            'type' => $type,
            'collection' => $collection,
        ];

        if (is_array($dynamicData)) {
            $record['dynamicData'] = $dynamicData;
        }

        return $record;
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }

    private function existingVariablesNeedNormalizationRewrite(): bool
    {
        foreach ($this->variableSources() as $source) {
            if (is_string($source)) {
                $decoded = json_decode($source, true);
                $source = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $variable) {
                if (is_array($variable) && array_key_exists('dynamicData', $variable) && $variable['dynamicData'] === null) {
                    return true;
                }
            }
        }

        return false;
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
     * @param mixed $value
     */
    private function scalarTokenValue($value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value) && is_scalar($value['value'] ?? null)) {
            return trim((string) $value['value']);
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function referenceSourceValue($value): string
    {
        if (is_array($value) && is_scalar($value['url'] ?? null)) {
            return trim((string) $value['url']);
        }

        return $this->scalarTokenValue($value);
    }

    /**
     * @param array<string, mixed> $variable
     */
    private function referenceValueForVariable(array $variable): string
    {
        $type = (string) ($variable['type'] ?? '');
        $value = $variable['value'] ?? null;

        if ($type === 'unit' && is_array($value) && is_scalar($value['style'] ?? null)) {
            return trim((string) $value['style']);
        }

        if ($type === 'image_url' && is_array($value) && is_scalar($value['url'] ?? null)) {
            return trim((string) $value['url']);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
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
     * @param mixed $value
     * @return array{url:string}|null
     */
    private function normalizeImageUrlValue($value): ?array
    {
        $url = '';

        if (is_scalar($value)) {
            $url = trim((string) $value);
        } elseif (is_array($value) && is_scalar($value['url'] ?? null)) {
            $url = trim((string) $value['url']);
        }

        if ($url === '' || preg_match('/[\s<>{}]/', $url) === 1) {
            return null;
        }

        if (
            preg_match('#^https?://#i', $url) !== 1
            && preg_match('#^/(?!/)#', $url) !== 1
            && preg_match('#^[A-Za-z0-9_.~/-]+\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?\#].*)?$#i', $url) !== 1
        ) {
            return null;
        }

        if (preg_match('/^(?:javascript|vbscript|data):/i', $url) === 1) {
            return null;
        }

        return ['url' => $url];
    }

    /**
     * @param mixed $value
     * @return int|float|null
     */
    private function normalizeNumberValue($value)
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        return $number == (int) $number ? (int) $number : $number;
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
            $label = is_string($variable['label'] ?? null) ? trim($variable['label']) : '';
            $collection = is_string($variable['collection'] ?? null) ? trim($variable['collection']) : '';

            if ($id === '' || $name === '' || $type === '' || $label === '' || $collection === '' || !array_key_exists('value', $variable)) {
                continue;
            }

            if (array_key_exists('dynamicData', $variable) && $variable['dynamicData'] === null) {
                unset($variable['dynamicData']);
            }

            if (!$this->isCompleteVariableRecord($variable)) {
                continue;
            }

            $variables[] = $variable;
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $variable
     */
    private function isCompleteVariableRecord(array $variable): bool
    {
        $allowed = [
            'id' => true,
            'cssVariableName' => true,
            'label' => true,
            'value' => true,
            'type' => true,
            'dynamicData' => true,
            'collection' => true,
        ];

        foreach ($variable as $key => $value) {
            if (!isset($allowed[(string) $key])) {
                return false;
            }
        }

        $name = is_string($variable['cssVariableName'] ?? null) ? (string) $variable['cssVariableName'] : '';
        if ($name === '' || str_starts_with($name, '--') || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
            return false;
        }

        if (array_key_exists('dynamicData', $variable) && !is_array($variable['dynamicData'])) {
            return false;
        }

        return match ((string) ($variable['type'] ?? '')) {
            'color' => $this->isValidExistingColorValue($variable['value'] ?? null),
            'unit' => $this->isValidExistingUnitValue($variable['value'] ?? null),
            'number' => is_int($variable['value'] ?? null) || is_float($variable['value'] ?? null),
            'font_family' => is_string($variable['value'] ?? null) && trim((string) $variable['value']) !== '',
            'image_url' => $this->normalizeImageUrlValue($variable['value'] ?? null) !== null,
            default => false,
        };
    }

    /**
     * @param mixed $value
     */
    private function isValidExistingColorValue($value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) && is_string($value['value'] ?? null) && trim((string) $value['value']) !== '';
    }

    /**
     * @param mixed $value
     */
    private function isValidExistingUnitValue($value): bool
    {
        return is_array($value)
            && array_key_exists('number', $value)
            && (is_int($value['number']) || is_float($value['number']) || is_string($value['number']) || $value['number'] === null)
            && is_string($value['unit'] ?? null)
            && is_string($value['style'] ?? null)
            && trim((string) $value['style']) !== '';
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
     * @return array<string, mixed>
     */
    private function buildSkippedSummary(array $payload): array
    {
        $tokens = $this->resolveTokens($payload);
        $proposed = 0;
        $unsupported = [];

        foreach ($tokens as $group => $groupTokens) {
            $group = (string) $group;
            foreach ($this->normalizeTokenGroup($groupTokens) as $token) {
                $proposed++;

                if (!isset(self::SUPPORTED_TOKEN_GROUPS[$group])) {
                    $unsupported[] = $this->skippedToken($group, $token, 'unsupported_token_group');
                    continue;
                }

                if ($this->buildVariableForGroup($group, $token) === null) {
                    $unsupported[] = $this->skippedToken($group, $token, 'unsupported_or_malformed_value');
                }
            }
        }

        return [
            'proposed' => $proposed,
            'persistable' => count($this->buildVariablesFromPayload($payload)),
            'unsupported' => count($unsupported),
            'items' => $unsupported,
        ];
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private function buildVariableForGroup(string $group, array $token): ?array
    {
        return match ($group) {
            'colors' => $this->buildColorVariable($token),
            'spacing' => $this->buildUnitVariable($token),
            'fonts' => $this->buildFontFamilyVariable($token),
            'images' => $this->buildImageUrlVariable($token),
            'measurements' => $this->buildMeasurementVariable($token),
            'numbers' => $this->buildNumberVariable($token),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private function skippedToken(string $group, array $token, string $reason): array
    {
        return [
            'group' => $group,
            'suggestedName' => (string) ($token['suggestedName'] ?? ''),
            'value' => $this->skippedTokenValue($token['value'] ?? null),
            'reason' => $reason,
        ];
    }

    /**
     * @param mixed $value
     */
    private function skippedTokenValue($value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value) && is_scalar($value['value'] ?? null)) {
            return (string) $value['value'];
        }

        if (is_array($value) && is_scalar($value['url'] ?? null)) {
            return (string) $value['url'];
        }

        return '';
    }
}
