<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class OxygenSixStorageAdapter implements OxygenStorageAdapter
{
    private OxygenDocumentTree $documentTree;

    public function __construct(private readonly OxygenStorageContract $contract, ?OxygenDocumentTree $documentTree = null)
    {
        $this->documentTree = $documentTree ?? new OxygenDocumentTree();
    }

    public function supports(string $oxygenVersion): bool
    {
        return $oxygenVersion === OxygenStorageContract::SUPPORTED_OXYGEN_VERSION;
    }

    public function getAdapterId(): string
    {
        return 'oxygen6';
    }

    public function getContractVersion(): string
    {
        return $this->contract->getOxygenVersion();
    }

    public function getContract(): OxygenStorageContract
    {
        return $this->contract;
    }

    public function buildDocumentTree(array $rootOrTree): array
    {
        return $this->normalizeDocumentTreeForStorage($rootOrTree);
    }

    public function validateDocumentTree(array $tree): array
    {
        $errors = [];
        $allowedTopLevelFields = [
            'root' => true,
            '_nextNodeId' => true,
            'exportedLookupTable' => true,
        ];

        foreach (array_keys($tree) as $field) {
            if (!isset($allowedTopLevelFields[(string) $field])) {
                $errors[] = sprintf('Unexpected document tree top-level field "%s".', (string) $field);
            }
        }

        if (!isset($tree['root']) || !is_array($tree['root'])) {
            $errors[] = 'root must be an array.';
        } else {
            $this->validateTreeNode($tree['root'], 'root', $errors);
        }

        if (!isset($tree['_nextNodeId']) || !is_int($tree['_nextNodeId']) || $tree['_nextNodeId'] < 1) {
            $errors[] = '_nextNodeId must be a positive integer.';
        }

        if (isset($tree['exportedLookupTable']) && !is_array($tree['exportedLookupTable'])) {
            $errors[] = 'exportedLookupTable must be an array when present.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function validatePageDocumentEnvelope(array $metaValue): array
    {
        $errors = [];
        $allowedFields = [
            'tree_json_string' => true,
        ];

        foreach (array_keys($metaValue) as $field) {
            if (!isset($allowedFields[(string) $field])) {
                $errors[] = sprintf('Unexpected _oxygen_data field "%s".', (string) $field);
            }
        }

        if (!isset($metaValue['tree_json_string']) || !is_string($metaValue['tree_json_string']) || $metaValue['tree_json_string'] === '') {
            $errors[] = 'tree_json_string must be a non-empty JSON string.';
            return [
                'valid' => false,
                'errors' => $errors,
            ];
        }

        try {
            $tree = json_decode($metaValue['tree_json_string'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = 'tree_json_string contains invalid JSON: ' . $e->getMessage() . '.';
            return [
                'valid' => false,
                'errors' => $errors,
            ];
        }

        if (!is_array($tree)) {
            $errors[] = 'tree_json_string must decode to an object.';
            return [
                'valid' => false,
                'errors' => $errors,
            ];
        }

        $treeValidation = $this->validateDocumentTree($tree);
        $errors = array_merge($errors, $treeValidation['errors']);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function readPageDocument(int $postId): array
    {
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid post ID.',
                'errors' => ['postId must be a positive integer.'],
            ];
        }

        $metaKey = $this->getOxygenDataMetaKey();
        $rawMeta = function_exists('get_post_meta') ? get_post_meta($postId, $metaKey, true) : '';
        $metaValue = $this->decodeStoredPageDocumentEnvelope($rawMeta);

        if ($metaValue === null) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '_oxygen_data is missing or invalid.',
                'errors' => ['Unable to decode ' . $metaKey . ' as an Oxygen page document envelope.'],
                'metaKey' => $metaKey,
            ];
        }

        $validation = $this->validatePageDocumentEnvelope($metaValue);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '_oxygen_data does not match the Oxygen 6 page document contract.',
                'errors' => $validation['errors'],
                'metaKey' => $metaKey,
                'payload' => $metaValue,
            ];
        }

        $tree = json_decode((string) $metaValue['tree_json_string'], true);

        return [
            'success' => true,
            'status' => 200,
            'metaKey' => $metaKey,
            'payload' => $metaValue,
            'tree' => is_array($tree) ? $tree : [],
            'treeHash' => sha1((string) $metaValue['tree_json_string']),
        ];
    }

    public function writePageDocument(int $postId, array $tree, array $rollbackSnapshot = []): array
    {
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid post ID.',
                'errors' => ['postId must be a positive integer.'],
            ];
        }

        $tree = $this->normalizeDocumentTreeForStorage($tree);
        $encodedTree = wp_json_encode($tree);
        if (!is_string($encodedTree)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to encode Oxygen document tree.',
                'errors' => ['wp_json_encode returned a non-string value for the document tree.'],
            ];
        }

        $metaPayload = [
            'tree_json_string' => $encodedTree,
        ];
        $validation = $this->validatePageDocumentEnvelope($metaPayload);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen document tree failed contract validation.',
                'errors' => $validation['errors'],
            ];
        }

        $metaKey = $this->getOxygenDataMetaKey();
        $previousMeta = function_exists('get_post_meta') ? get_post_meta($postId, $metaKey, true) : null;
        $rollbackMetaKey = is_string($rollbackSnapshot['rollbackMetaKey'] ?? null)
            ? (string) $rollbackSnapshot['rollbackMetaKey']
            : '_oxy_html_converter_previous_oxygen_data';
        $rollbackAvailable = $previousMeta !== null && $previousMeta !== '';

        if ($rollbackAvailable && function_exists('update_post_meta')) {
            update_post_meta($postId, $rollbackMetaKey, $previousMeta);
        }

        if (function_exists('\Breakdance\Data\set_meta')) {
            \Breakdance\Data\set_meta($postId, $metaKey, $metaPayload);
        } else {
            update_post_meta($postId, $metaKey, wp_slash(wp_json_encode($metaPayload)));
        }

        return [
            'success' => true,
            'status' => 200,
            'metaKey' => $metaKey,
            'treeHash' => sha1($encodedTree),
            'treeBytes' => strlen($encodedTree),
            'rollbackAvailable' => $rollbackAvailable,
            'adapter' => $this->getAdapterId(),
        ];
    }

    public function createOrUpdateDocumentPost(array $postSpec): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function readSelectors(): array
    {
        $selectors = function_exists('get_option') ? get_option('oxygen_oxy_selectors_json_string', '[]') : '[]';
        $collections = function_exists('get_option') ? get_option('oxygen_oxy_selectors_collections_json_string', '[]') : '[]';

        return [
            'success' => true,
            'status' => 200,
            'selectors' => $this->decodeListOption($selectors),
            'collections' => $this->decodeStringListOption($collections),
        ];
    }

    public function writeSelectors(array $selectors, array $collections, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateSelectors($selectors, $collections);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen selector payload failed contract validation.',
                'errors' => $validation['errors'],
            ];
        }

        $breakdanceClassesPayload = $this->encodeJson($this->buildBreakdanceSelectorRecords($selectors));
        $this->invalidateSelectorCaches();

        if (function_exists('\Breakdance\BreakdanceOxygen\Selectors\saveSelectors')) {
            \Breakdance\BreakdanceOxygen\Selectors\saveSelectors($this->encodeJson([
                'selectors' => $selectors,
                'collections' => $collections,
            ]));
            $this->persistBreakdanceClassesPayload($breakdanceClassesPayload);
            $this->invalidateSelectorCaches();
            $this->regenerateGlobalSettingsCache();

            return $this->successfulGlobalWrite('selectors', [
                'saved' => count($selectors),
                'total' => count($selectors),
                'collections' => $collections,
                'cacheRegenerated' => true,
            ]);
        }

        if (function_exists('\Breakdance\Data\set_global_option')) {
            \Breakdance\Data\set_global_option('oxy_selectors_collections_json_string', $collections);
            \Breakdance\Data\set_global_option('oxy_selectors_json_string', $selectors);
            \Breakdance\Data\set_global_option('breakdance_classes_json_string', $breakdanceClassesPayload);
            $this->addSelectorRevision($selectors);
            $this->invalidateSelectorCaches();
            $cacheRegenerated = $this->regenerateGlobalSettingsCache();

            return $this->successfulGlobalWrite('selectors', [
                'saved' => count($selectors),
                'total' => count($selectors),
                'collections' => $collections,
                'cacheRegenerated' => $cacheRegenerated,
            ]);
        }

        update_option('oxygen_oxy_selectors_collections_json_string', $this->encodeJson($collections));
        update_option('oxygen_oxy_selectors_json_string', $this->encodeJson($selectors));
        update_option('breakdance_classes_json_string', $breakdanceClassesPayload);
        $this->addSelectorRevision($selectors);
        $this->invalidateSelectorCaches();
        $cacheRegenerated = $this->regenerateGlobalSettingsCache();

        return $this->successfulGlobalWrite('selectors', [
            'saved' => count($selectors),
            'total' => count($selectors),
            'collections' => $collections,
            'cacheRegenerated' => $cacheRegenerated,
            'optionNames' => [
                'oxygen_oxy_selectors_json_string',
                'oxygen_oxy_selectors_collections_json_string',
                'breakdance_classes_json_string',
            ],
        ]);
    }

    public function readVariables(): array
    {
        $variables = function_exists('get_option') ? get_option('oxygen_variables_json_string', '[]') : '[]';
        $collections = function_exists('get_option') ? get_option('oxygen_variables_collections_json_string', '[]') : '[]';

        return [
            'success' => true,
            'status' => 200,
            'variables' => $this->decodeListOption($variables),
            'collections' => $this->decodeStringListOption($collections),
        ];
    }

    public function writeVariables(array $variables, array $collections, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateVariables($variables, $collections);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen variable payload failed contract validation.',
                'errors' => $validation['errors'],
            ];
        }

        $payload = $this->encodeJson([
            'variables' => $variables,
            'collections' => $collections,
        ]);

        if (function_exists('\Breakdance\Variables\saveVariables')) {
            \Breakdance\Variables\saveVariables($payload);

            return $this->successfulGlobalWrite('variables', [
                'saved' => count($variables),
                'total' => count($variables),
                'collections' => $collections,
                'cacheRegenerated' => true,
            ]);
        }

        if (function_exists('\Breakdance\Data\set_global_option')) {
            \Breakdance\Data\set_global_option('variables_collections_json_string', $collections);
            \Breakdance\Data\set_global_option('variables_json_string', $variables);
            $cacheRegenerated = $this->regenerateGlobalSettingsCache();

            return $this->successfulGlobalWrite('variables', [
                'saved' => count($variables),
                'total' => count($variables),
                'collections' => $collections,
                'cacheRegenerated' => $cacheRegenerated,
            ]);
        }

        if (function_exists('update_option')) {
            update_option('oxygen_variables_collections_json_string', $this->encodeJson($collections));
            update_option('oxygen_variables_json_string', $this->encodeJson($variables));
        }

        return $this->successfulGlobalWrite('variables', [
            'saved' => count($variables),
            'total' => count($variables),
            'collections' => $collections,
            'cacheRegenerated' => $this->regenerateGlobalSettingsCache(),
            'optionNames' => [
                'oxygen_variables_json_string',
                'oxygen_variables_collections_json_string',
            ],
        ]);
    }

    public function readGlobalSettings(): array
    {
        $settings = function_exists('get_option') ? get_option('oxygen_global_settings_json_string', '{}') : '{}';

        return [
            'success' => true,
            'status' => 200,
            'settings' => $this->decodeObjectOption($settings),
        ];
    }

    public function writeGlobalSettings(array $settings, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateGlobalSettings($settings);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen global settings payload failed contract validation.',
                'errors' => $validation['errors'],
            ];
        }

        $encoded = $this->encodeJson($settings);
        $cacheRegenerated = false;

        if (function_exists('\Breakdance\Data\save_global_settings')) {
            \Breakdance\Data\save_global_settings($encoded);

            return $this->successfulGlobalWrite('global_settings', [
                'cacheRegenerated' => true,
            ]);
        }

        if (function_exists('\Breakdance\Data\set_global_option')) {
            \Breakdance\Data\set_global_option('global_settings_json_string', $encoded);
            $cacheRegenerated = $this->regenerateGlobalSettingsCache();
        } elseif (function_exists('update_option')) {
            update_option('oxygen_global_settings_json_string', $encoded);
            $cacheRegenerated = $this->regenerateGlobalSettingsCache();
        }

        return $this->successfulGlobalWrite('global_settings', [
            'cacheRegenerated' => $cacheRegenerated,
            'optionNames' => ['oxygen_global_settings_json_string'],
        ]);
    }

    public function readTemplateSettings(int $postId): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function validateTemplate(string $postType, array $tree, string $settingsJson): array
    {
        $errors = $this->validateTemplatePostType($postType);
        $treeValidation = $this->validateDocumentTree($this->normalizeDocumentTreeForStorage($tree));
        $settingsValidation = $this->validateTemplateSettingsJson($settingsJson);
        $errors = array_merge($errors, $treeValidation['errors'], $settingsValidation['errors']);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function writeTemplate(string $postType, array $tree, string $settingsJson, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateTemplate($postType, $tree, $settingsJson);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen template payload failed contract validation.',
                'errors' => $validation['errors'],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        return [
            'success' => false,
            'status' => 'stub_not_exposed',
            'message' => 'Template writes are validated but not exposed until the M5 template import milestone.',
            'postType' => $postType,
            'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
        ];
    }

    public function validateBlock(array $tree, array $blockSettings): array
    {
        $treeValidation = $this->validateDocumentTree($this->normalizeDocumentTreeForStorage($tree));
        $settingsValidation = $this->validateBlockSettings($blockSettings);
        $errors = array_merge($treeValidation['errors'], $settingsValidation['errors']);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function writeBlock(array $tree, array $blockSettings, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateBlock($tree, $blockSettings);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen block payload failed contract validation.',
                'errors' => $validation['errors'],
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            ];
        }

        return [
            'success' => false,
            'status' => 'stub_not_exposed',
            'message' => 'Block writes are validated but not exposed until the M6 component import milestone.',
            'postType' => 'oxygen_block',
            'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
        ];
    }

    public function writeComponentInstance(array $componentNode): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function readGlobalStyles(): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function writeGlobalStyles(array $library, array $rollbackSnapshot = []): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function readPageStyles(int $postId): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function writePageStyles(int $postId, array $pageStyles, array $rollbackSnapshot = []): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    public function captureRollbackSnapshot(array $stores): array
    {
        $entries = [];

        foreach ($stores as $store) {
            if (!is_string($store) || trim($store) === '') {
                continue;
            }

            foreach ($this->expandRollbackStore($store) as $entry) {
                $entries[] = $entry;
            }
        }

        return [
            'success' => true,
            'version' => 1,
            'adapter' => $this->getAdapterId(),
            'capturedAt' => gmdate('c'),
            'stores' => $entries,
        ];
    }

    public function restoreRollbackSnapshot(array $snapshot): array
    {
        $stores = is_array($snapshot['stores'] ?? null) ? $snapshot['stores'] : [];
        $errors = [];
        $restored = 0;

        for ($index = count($stores) - 1; $index >= 0; $index--) {
            $entry = $stores[$index];
            if (!is_array($entry)) {
                continue;
            }

            if (!$this->restoreAdapterSnapshotEntry($entry)) {
                $errors[] = (string) ($entry['store'] ?? 'unknown') . ':' . (string) ($entry['key'] ?? '');
                break;
            }

            $restored++;
        }

        return [
            'success' => $errors === [],
            'status' => $errors === [] ? 200 : 500,
            'adapter' => $this->getAdapterId(),
            'restored' => $restored,
            'errors' => $errors,
        ];
    }

    public function invalidateDocumentCaches(int $postId): void
    {
        if (function_exists('clean_post_cache')) {
            clean_post_cache($postId);
        }
    }

    public function invalidateGlobalCaches(): void
    {
    }

    public function resetOptionalIntegrationCaches(array $integrationIds): array
    {
        return $this->notMigrated(__FUNCTION__);
    }

    /**
     * @param array<int, string> $errors
     */
    private function validateTreeNode(array $node, string $path, array &$errors): void
    {
        if (!isset($node['id']) || !is_int($node['id'])) {
            $errors[] = $path . '.id must be an integer.';
        }

        if (!isset($node['data']) || !is_array($node['data'])) {
            $errors[] = $path . '.data must be an array.';
        } else {
            if (!isset($node['data']['type']) || !is_string($node['data']['type']) || trim($node['data']['type']) === '') {
                $errors[] = $path . '.data.type must be a non-empty string.';
            }

            if (!isset($node['data']['properties']) || !is_array($node['data']['properties'])) {
                $errors[] = $path . '.data.properties must be an array.';
            }
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            $errors[] = $path . '.children must be an array.';
            return;
        }

        foreach ($node['children'] as $index => $child) {
            if (!is_array($child)) {
                $errors[] = $path . '.children.' . (int) $index . ' must be an array.';
                continue;
            }

            $this->validateTreeNode($child, $path . '.children.' . (int) $index, $errors);
        }
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function normalizeDocumentTreeForStorage(array $tree): array
    {
        $tree = $this->documentTree->build($tree);
        unset($tree['status']);

        return $tree;
    }

    private function getOxygenDataMetaKey(): string
    {
        if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
            return \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix') . 'data';
        }

        return '_oxygen_data';
    }

    /**
     * @param mixed $rawMeta
     * @return array<string, mixed>|null
     */
    private function decodeStoredPageDocumentEnvelope($rawMeta): ?array
    {
        if (is_array($rawMeta)) {
            return $rawMeta;
        }

        if (!is_string($rawMeta) || $rawMeta === '') {
            return null;
        }

        try {
            $decoded = json_decode(stripslashes($rawMeta), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<int, string> $collections
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateSelectors(array $selectors, array $collections): array
    {
        $errors = [];

        foreach ($collections as $index => $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                $errors[] = 'collections.' . (int) $index . ' must be a non-empty string.';
            }
        }

        foreach ($selectors as $index => $selector) {
            $path = 'selectors.' . (int) $index;

            if (!is_string($selector['id'] ?? null) || trim((string) $selector['id']) === '') {
                $errors[] = $path . '.id must be a non-empty string.';
            }

            if (!is_string($selector['name'] ?? null) || trim((string) $selector['name']) === '') {
                $errors[] = $path . '.name must be a non-empty string.';
            }

            if (!is_string($selector['type'] ?? null) || !in_array($selector['type'], ['class', 'custom'], true)) {
                $errors[] = $path . '.type must be class or custom.';
            }

            if (!is_string($selector['collection'] ?? null)) {
                $errors[] = $path . '.collection must be a string.';
            }

            if (!isset($selector['children']) || !is_array($selector['children'])) {
                $errors[] = $path . '.children must be an array.';
            }

            $properties = $selector['properties'] ?? null;
            if (!is_array($properties) && !$properties instanceof \stdClass) {
                $errors[] = $path . '.properties must be an object.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $variables
     * @param array<int, string> $collections
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateVariables(array $variables, array $collections): array
    {
        $errors = [];

        foreach ($collections as $index => $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                $errors[] = 'collections.' . (int) $index . ' must be a non-empty string.';
            }
        }

        foreach ($variables as $index => $variable) {
            $path = 'variables.' . (int) $index;

            foreach (['id', 'cssVariableName', 'label', 'type', 'collection'] as $field) {
                if (!is_string($variable[$field] ?? null) || trim((string) $variable[$field]) === '') {
                    $errors[] = $path . '.' . $field . ' must be a non-empty string.';
                }
            }

            $name = is_string($variable['cssVariableName'] ?? null) ? $variable['cssVariableName'] : '';
            if ($name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
                $errors[] = $path . '.cssVariableName must be a valid Oxygen CSS variable name without leading dashes.';
            }

            if (!array_key_exists('value', $variable)) {
                $errors[] = $path . '.value is required.';
            }

            if (!array_key_exists('dynamicData', $variable)) {
                $errors[] = $path . '.dynamicData is required; use null for static variables.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateGlobalSettings(array $settings): array
    {
        $errors = [];

        if (!isset($settings['settings']) || !is_array($settings['settings'])) {
            $errors[] = 'settings must contain a top-level settings object.';
        }

        foreach (['colors', 'typography', 'containers', 'code'] as $section) {
            if (isset($settings['settings'][$section]) && !is_array($settings['settings'][$section])) {
                $errors[] = 'settings.' . $section . ' must be an object when present.';
            }
        }

        if (isset($settings['settings']['colors']) && is_array($settings['settings']['colors'])) {
            $palette = $settings['settings']['colors']['palette'] ?? null;
            if ($palette !== null && !is_array($palette)) {
                $errors[] = 'settings.colors.palette must be an object when present.';
            } elseif (is_array($palette)) {
                if (isset($palette['colors']) && !is_array($palette['colors'])) {
                    $errors[] = 'settings.colors.palette.colors must be an array when present.';
                }

                if (isset($palette['gradients']) && !is_array($palette['gradients'])) {
                    $errors[] = 'settings.colors.palette.gradients must be an array when present.';
                } elseif (isset($palette['gradients'])) {
                    foreach ($palette['gradients'] as $index => $gradient) {
                        $path = 'settings.colors.palette.gradients.' . (int) $index;
                        if (!is_array($gradient)) {
                            $errors[] = $path . ' must be an object.';
                            continue;
                        }

                        foreach (['label', 'cssVariableName'] as $field) {
                            if (!is_string($gradient[$field] ?? null) || trim((string) $gradient[$field]) === '') {
                                $errors[] = $path . '.' . $field . ' must be a non-empty string.';
                            }
                        }

                        if (!isset($gradient['value']) || !is_array($gradient['value'])) {
                            $errors[] = $path . '.value must be an object with svgValue.';
                            continue;
                        }

                        if (!is_string($gradient['value']['svgValue'] ?? null) || trim((string) $gradient['value']['svgValue']) === '') {
                            $errors[] = $path . '.value.svgValue must be a non-empty string.';
                        }
                    }
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function validateTemplatePostType(string $postType): array
    {
        if (in_array($postType, ['oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_part'], true)) {
            return [];
        }

        return [
            sprintf('Unsupported Oxygen template post type "%s".', $postType),
        ];
    }

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateTemplateSettingsJson(string $settingsJson): array
    {
        $errors = [];

        try {
            $settings = json_decode($settingsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'valid' => false,
                'errors' => ['_oxygen_template_settings contains invalid JSON: ' . $e->getMessage() . '.'],
            ];
        }

        if ($settings === null) {
            return [
                'valid' => true,
                'errors' => [],
            ];
        }

        if (!is_array($settings)) {
            return [
                'valid' => false,
                'errors' => ['_oxygen_template_settings must decode to an object or null.'],
            ];
        }

        if (array_key_exists('type', $settings) && (!is_string($settings['type']) || trim($settings['type']) === '')) {
            $errors[] = '_oxygen_template_settings.type must be a non-empty string when present.';
        }

        if (array_key_exists('ruleGroups', $settings)) {
            if (!is_array($settings['ruleGroups'])) {
                $errors[] = '_oxygen_template_settings.ruleGroups must be an array when present.';
            } else {
                foreach ($settings['ruleGroups'] as $groupIndex => $group) {
                    $groupPath = '_oxygen_template_settings.ruleGroups.' . (int) $groupIndex;
                    if (!is_array($group)) {
                        $errors[] = $groupPath . ' must be an array.';
                        continue;
                    }

                    foreach ($group as $ruleIndex => $rule) {
                        $errors = array_merge($errors, $this->validateTemplateRule($rule, $groupPath . '.' . (int) $ruleIndex));
                    }
                }
            }
        }

        if (array_key_exists('triggers', $settings) && !is_array($settings['triggers'])) {
            $errors[] = '_oxygen_template_settings.triggers must be an array when present.';
        }

        if (array_key_exists('priority', $settings) && !is_int($settings['priority'])) {
            $errors[] = '_oxygen_template_settings.priority must be an integer when present.';
        }

        if (array_key_exists('fallback', $settings) && !is_bool($settings['fallback'])) {
            $errors[] = '_oxygen_template_settings.fallback must be a boolean when present.';
        }

        if (array_key_exists('disabled', $settings) && !is_bool($settings['disabled'])) {
            $errors[] = '_oxygen_template_settings.disabled must be a boolean when present.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param mixed $rule
     * @return array<int, string>
     */
    private function validateTemplateRule($rule, string $path): array
    {
        if (!is_array($rule)) {
            return [$path . ' must be an object.'];
        }

        $errors = [];
        $operandsRequiringValue = [
            'is' => true,
            'is not' => true,
            'is one of' => true,
            'is all of' => true,
            'is none of' => true,
            'is before' => true,
            'is after' => true,
            'is greater than' => true,
            'is less than' => true,
            'contains' => true,
            'does not contain' => true,
        ];
        $operandsWithoutValue = [
            'is empty' => true,
            'is not empty' => true,
        ];

        $operand = is_string($rule['operand'] ?? null) ? trim((string) $rule['operand']) : '';
        if ($operand === '') {
            $errors[] = $path . '.operand must be a non-empty string.';
        } elseif (!isset($operandsRequiringValue[$operand]) && !isset($operandsWithoutValue[$operand])) {
            $errors[] = $path . '.operand must be a registered Oxygen template operand.';
        }

        if (!is_string($rule['ruleSlug'] ?? null) || trim((string) $rule['ruleSlug']) === '') {
            $errors[] = $path . '.ruleSlug must be a non-empty string.';
        }

        if ($operand !== '' && isset($operandsRequiringValue[$operand]) && !array_key_exists('value', $rule)) {
            $errors[] = $path . '.value is required for operand "' . $operand . '".';
        }

        if (array_key_exists('value', $rule) && !$this->isValidTemplateRuleValue($rule['value'])) {
            $errors[] = $path . '.value must be a string, string array, or object-value array.';
        }

        return $errors;
    }

    /**
     * @param mixed $value
     */
    private function isValidTemplateRuleValue($value): bool
    {
        if (is_string($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                continue;
            }

            if (is_array($item) && is_string($item['value'] ?? null)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $blockSettings
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateBlockSettings(array $blockSettings): array
    {
        $errors = [];

        if (isset($blockSettings['preview']) && !is_array($blockSettings['preview'])) {
            $errors[] = '_breakdance_block_settings.preview must be an object when present.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @return array<int, array<string, mixed>>
     */
    private function buildBreakdanceSelectorRecords(array $selectors): array
    {
        $records = [];

        foreach ($selectors as $selector) {
            $name = is_string($selector['name'] ?? null) ? trim($selector['name']) : '';

            if ($name === '') {
                continue;
            }

            $records[] = [
                'name' => '.' . ltrim($name, '.'),
                'type' => 'class',
                'properties' => new \stdClass(),
            ];
        }

        return $records;
    }

    private function persistBreakdanceClassesPayload(string $payloadJson): void
    {
        if (function_exists('\Breakdance\Data\set_global_option')) {
            \Breakdance\Data\set_global_option('breakdance_classes_json_string', $payloadJson);
            return;
        }

        update_option('breakdance_classes_json_string', $payloadJson);
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     */
    private function addSelectorRevision(array $selectors): void
    {
        if (function_exists('\Breakdance\Data\GlobalRevisions\add_new_revision')) {
            \Breakdance\Data\GlobalRevisions\add_new_revision($selectors, 'oxygen_selectors');
        }
    }

    private function invalidateSelectorCaches(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient('oxymade_selectors_option_cache');
        }

        if (!function_exists('wp_cache_delete')) {
            return;
        }

        wp_cache_delete('oxygen_oxy_selectors_json_string', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private function regenerateGlobalSettingsCache(): bool
    {
        if (function_exists('\Breakdance\Render\generateCacheForGlobalSettings')) {
            \Breakdance\Render\generateCacheForGlobalSettings();
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        $encoded = wp_json_encode($value);
        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function decodeListOption($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn($item): bool => is_array($item)));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function decodeStringListOption($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeObjectOption($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function successfulGlobalWrite(string $store, array $extra): array
    {
        return array_merge([
            'success' => true,
            'status' => 200,
            'store' => $store,
            'adapter' => $this->getAdapterId(),
        ], $extra);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expandRollbackStore(string $store): array
    {
        if (str_starts_with($store, 'option:')) {
            return [$this->captureAdapterOptionStore(substr($store, strlen('option:')))];
        }

        if (str_starts_with($store, 'post_meta:')) {
            $parts = explode(':', $store, 3);
            if (count($parts) === 3) {
                return [$this->captureAdapterPostMetaStore((int) $parts[1], $parts[2])];
            }
        }

        $optionStores = [
            'selectors' => [
                'oxygen_oxy_selectors_json_string',
                'oxygen_oxy_selectors_collections_json_string',
                'breakdance_classes_json_string',
            ],
            'variables' => [
                'oxygen_variables_json_string',
                'oxygen_variables_collections_json_string',
            ],
            'global_settings' => [
                'oxygen_global_settings_json_string',
            ],
            'global_styles' => [
                GlobalStyleRepository::OPTION_NAME,
            ],
            'brand_library' => [
                BrandLibraryRepository::OPTION_NAME,
            ],
        ];

        if (!isset($optionStores[$store])) {
            return [];
        }

        $entries = [];
        foreach ($optionStores[$store] as $optionName) {
            $entries[] = $this->captureAdapterOptionStore($optionName, $store);
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAdapterOptionStore(string $key, string $store = 'option'): array
    {
        $exists = $this->adapterOptionExists($key);

        return [
            'owner' => 'oxygen6-storage-adapter',
            'storeType' => 'option',
            'store' => $store,
            'key' => $key,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_option') ? get_option($key) : null,
            'restoreOperation' => $exists ? 'update_option' : 'delete_option',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAdapterPostMetaStore(int $postId, string $key): array
    {
        $exists = $this->adapterPostMetaExists($postId, $key);

        return [
            'owner' => 'oxygen6-storage-adapter',
            'storeType' => 'post_meta',
            'store' => 'post_meta',
            'postId' => $postId,
            'key' => $key,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : null,
            'restoreOperation' => $exists ? 'update_post_meta' : 'delete_post_meta',
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function restoreAdapterSnapshotEntry(array $entry): bool
    {
        $storeType = (string) ($entry['storeType'] ?? '');
        $key = (string) ($entry['key'] ?? '');
        $exists = (bool) ($entry['oldExists'] ?? false);
        $value = $entry['oldValue'] ?? null;

        if ($storeType === 'option') {
            if ($exists) {
                if (!function_exists('update_option')) {
                    return true;
                }

                update_option($key, $value);
                return $this->adapterOptionExists($key) && get_option($key) === $value;
            }

            if (!$this->adapterOptionExists($key)) {
                return true;
            }

            if (function_exists('delete_option')) {
                delete_option($key);
            } elseif (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
                unset($GLOBALS['__wp_options'][$key]);
            }

            return $this->adapterOptionIsAbsent($key);
        }

        if ($storeType === 'post_meta') {
            $postId = (int) ($entry['postId'] ?? 0);
            if ($exists) {
                if (!function_exists('update_post_meta')) {
                    return true;
                }

                update_post_meta($postId, $key, $value);
                return $this->adapterPostMetaExists($postId, $key) && get_post_meta($postId, $key, true) === $value;
            }

            if (!$this->adapterPostMetaExists($postId, $key)) {
                return true;
            }

            if (function_exists('delete_post_meta')) {
                delete_post_meta($postId, $key);
            } elseif (isset($GLOBALS['__wp_post_meta']) && is_array($GLOBALS['__wp_post_meta'])) {
                unset($GLOBALS['__wp_post_meta'][$postId][$key]);
            }

            return $this->adapterPostMetaIsAbsent($postId, $key);
        }

        return false;
    }

    private function adapterOptionExists(string $key): bool
    {
        if (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
            return array_key_exists($key, $GLOBALS['__wp_options']);
        }

        return function_exists('get_option') && get_option($key, null) !== null;
    }

    private function adapterOptionIsAbsent(string $key): bool
    {
        return !$this->adapterOptionExists($key);
    }

    private function adapterPostMetaExists(int $postId, string $key): bool
    {
        if (isset($GLOBALS['__wp_post_meta']) && is_array($GLOBALS['__wp_post_meta'])) {
            return array_key_exists($key, $GLOBALS['__wp_post_meta'][$postId] ?? []);
        }

        return function_exists('get_post_meta') && get_post_meta($postId, $key, true) !== '';
    }

    private function adapterPostMetaIsAbsent(int $postId, string $key): bool
    {
        return !$this->adapterPostMetaExists($postId, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function notMigrated(string $method): array
    {
        return [
            'success' => false,
            'status' => 'not_migrated',
            'message' => $method . ' is declared by the Oxygen storage adapter but is not migrated until the matching M2 item.',
        ];
    }
}
