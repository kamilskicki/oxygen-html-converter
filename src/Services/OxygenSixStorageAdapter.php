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
        return $oxygenVersion === OxygenStorageContract::SUPPORTED_OXYGEN_VERSION
            || $oxygenVersion === '6.1.0';
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
            'status' => true,
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

        if (!isset($tree['status']) || !is_string($tree['status']) || trim($tree['status']) === '') {
            $errors[] = 'status must be a non-empty string.';
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
        $encodedTree = wp_json_encode($this->prepareDocumentTreeForJson($tree));
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
        $postType = $this->stringField($postSpec, 'post_type');
        $postTypeErrors = $this->validateTemplatePostType($postType);
        if ($postTypeErrors !== []) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen template payload failed contract validation.',
                'errors' => $postTypeErrors,
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        $oxygenData = is_array($postSpec['_oxygen_data'] ?? null) ? $postSpec['_oxygen_data'] : [];
        $settingsJson = is_string($postSpec['_oxygen_template_settings'] ?? null)
            ? (string) $postSpec['_oxygen_template_settings']
            : '';
        $tree = $this->decodeTreeFromEnvelope($oxygenData);
        if ($tree === null) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen template payload failed contract validation.',
                'errors' => ['_oxygen_data.tree_json_string must decode to an Oxygen document tree.'],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

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

        if (!function_exists('wp_insert_post') || !function_exists('wp_update_post')) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'WordPress post persistence functions are unavailable.',
                'errors' => ['wp_insert_post/wp_update_post are required for template persistence.'],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        $postId = (int) ($postSpec['ID'] ?? $postSpec['post_id'] ?? 0);
        $isUpdate = $postId > 0;
        $postPayload = [
            'post_type' => $postType,
            'post_status' => $this->nonEmptyString($postSpec['post_status'] ?? null, 'publish'),
            'post_title' => $this->nonEmptyString(
                $postSpec['post_title'] ?? null,
                'Imported ' . str_replace('_', ' ', $postType)
            ),
            'post_name' => $this->nonEmptyString($postSpec['post_name'] ?? null, ''),
            'post_content' => $this->nonEmptyString($postSpec['post_content'] ?? null, ''),
        ];

        if ($isUpdate) {
            $postPayload['ID'] = $postId;
            $persistedPostId = wp_update_post($postPayload, true);
        } else {
            $persistedPostId = wp_insert_post($postPayload, true);
        }

        if (is_wp_error($persistedPostId)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to persist Oxygen template post.',
                'errors' => [$persistedPostId->get_error_message()],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        $postId = (int) $persistedPostId;
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to persist Oxygen template post.',
                'errors' => ['WordPress returned an invalid post ID.'],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        $rollbackSnapshot = is_array($postSpec['rollbackSnapshot'] ?? null) ? $postSpec['rollbackSnapshot'] : [];
        $documentWrite = $this->writePageDocument($postId, $tree, $rollbackSnapshot);
        if (empty($documentWrite['success'])) {
            return array_merge($documentWrite, [
                'postId' => $postId,
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ]);
        }

        $settingsWrite = $this->writeTemplateSettingsMeta($postId, $settingsJson, $rollbackSnapshot);
        if (empty($settingsWrite['success'])) {
            return array_merge($settingsWrite, [
                'postId' => $postId,
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ]);
        }

        $this->invalidateDocumentCaches($postId);

        return [
            'success' => true,
            'status' => 200,
            'action' => $isUpdate ? 'updated' : 'created',
            'postId' => $postId,
            'postType' => $postType,
            'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            'oxygenDataMetaKey' => (string) ($documentWrite['metaKey'] ?? $this->getOxygenDataMetaKey()),
            'templateSettingsMetaKey' => (string) ($settingsWrite['metaKey'] ?? $this->getTemplateSettingsMetaKey()),
            'treeHash' => (string) ($documentWrite['treeHash'] ?? ''),
            'settingsHash' => (string) ($settingsWrite['settingsHash'] ?? ''),
            'adapter' => $this->getAdapterId(),
        ];
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
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid post ID.',
                'errors' => ['postId must be a positive integer.'],
            ];
        }

        $metaKey = $this->getTemplateSettingsMetaKey();
        $rawMeta = function_exists('get_post_meta') ? get_post_meta($postId, $metaKey, true) : '';
        $settingsJson = $this->decodeStoredTemplateSettings($rawMeta);
        if ($settingsJson === null) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '_oxygen_template_settings is missing or invalid.',
                'errors' => ['Unable to decode ' . $metaKey . ' as an Oxygen template settings JSON string.'],
                'metaKey' => $metaKey,
            ];
        }

        $validation = $this->validateTemplateSettingsJson($settingsJson);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '_oxygen_template_settings does not match the Oxygen 6 template contract.',
                'errors' => $validation['errors'],
                'metaKey' => $metaKey,
                'settingsJson' => $settingsJson,
            ];
        }

        $settings = json_decode($settingsJson, true);

        return [
            'success' => true,
            'status' => 200,
            'metaKey' => $metaKey,
            'settingsJson' => $settingsJson,
            'settings' => is_array($settings) ? $settings : null,
            'settingsHash' => sha1($settingsJson),
        ];
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

        $tree = $this->normalizeDocumentTreeForStorage($tree);
        $encodedTree = wp_json_encode($tree);
        if (!is_string($encodedTree)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to encode Oxygen document tree.',
                'errors' => ['wp_json_encode returned a non-string value for the document tree.'],
                'postType' => $postType,
                'metaKeys' => ['_oxygen_data', '_oxygen_template_settings'],
            ];
        }

        return $this->createOrUpdateDocumentPost([
            'post_type' => $postType,
            'post_title' => 'Imported ' . str_replace('_', ' ', $postType),
            '_oxygen_data' => [
                'tree_json_string' => $encodedTree,
            ],
            '_oxygen_template_settings' => $settingsJson,
            'rollbackSnapshot' => $rollbackSnapshot,
        ]);
    }

    public function validateBlock(array $tree, array $blockSettings): array
    {
        $treeValidation = $this->validateDocumentTree($this->normalizeDocumentTreeForStorage($tree));
        $settingsValidation = $this->validateBlockSettings($this->blockSettingsForStorage($blockSettings));
        $errors = array_merge($treeValidation['errors'], $settingsValidation['errors']);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function writeBlock(array $tree, array $blockSettings, array $rollbackSnapshot = []): array
    {
        $settingsForStorage = $this->blockSettingsForStorage($blockSettings);
        $validation = $this->validateBlock($tree, $settingsForStorage);
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

        if (!function_exists('wp_insert_post') || !function_exists('wp_update_post')) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'WordPress post persistence functions are unavailable.',
                'errors' => ['wp_insert_post/wp_update_post are required for block persistence.'],
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            ];
        }

        $post = is_array($blockSettings['_post'] ?? null) ? $blockSettings['_post'] : [];
        $postId = (int) ($post['ID'] ?? $post['postId'] ?? $post['post_id'] ?? 0);
        $isUpdate = $postId > 0;
        $postPayload = [
            'post_type' => 'oxygen_block',
            'post_status' => $this->nonEmptyString($post['post_status'] ?? null, 'publish'),
            'post_title' => $this->nonEmptyString(
                $post['post_title'] ?? $post['title'] ?? $settingsForStorage['label'] ?? $settingsForStorage['name'] ?? null,
                'Imported Oxygen Block'
            ),
            'post_name' => $this->nonEmptyString($post['post_name'] ?? $post['slug'] ?? null, ''),
            'post_content' => $this->nonEmptyString($post['post_content'] ?? null, ''),
        ];

        $postRollback = [];
        if ($isUpdate) {
            $postRollback = $this->captureBlockPostRollback($postId);
            $postPayload['ID'] = $postId;
            $persistedPostId = wp_update_post($postPayload, true);
        } else {
            $persistedPostId = wp_insert_post($postPayload, true);
        }

        if (is_wp_error($persistedPostId)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to persist Oxygen block post.',
                'errors' => [$persistedPostId->get_error_message()],
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            ];
        }

        $postId = (int) $persistedPostId;
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to persist Oxygen block post.',
                'errors' => ['WordPress returned an invalid post ID.'],
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            ];
        }

        if ($postRollback !== [] && function_exists('update_post_meta')) {
            $rollbackMetaKey = is_string($rollbackSnapshot['blockPostRollbackMetaKey'] ?? null)
                ? (string) $rollbackSnapshot['blockPostRollbackMetaKey']
                : '_oxy_html_converter_previous_oxygen_block_post';
            update_post_meta($postId, $rollbackMetaKey, wp_json_encode($postRollback));
        }

        $rollbackPostStore = $this->blockPostRollbackStore($postId, $isUpdate, $postRollback);

        $tree = $this->normalizeDocumentTreeForStorage($tree);
        $documentWrite = $this->writePageDocument($postId, $tree, $rollbackSnapshot);
        if (empty($documentWrite['success'])) {
            $failureRollback = $this->rollbackFailedBlockWrite(
                $postId,
                $isUpdate,
                $postRollback,
                false,
                $rollbackSnapshot,
                $documentWrite
            );

            return array_merge($documentWrite, [
                'postId' => $postId,
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
                'rollback' => $failureRollback,
            ]);
        }

        $settingsWrite = $this->writeBlockSettingsMeta($postId, $settingsForStorage, $rollbackSnapshot);
        if (empty($settingsWrite['success'])) {
            $failureRollback = $this->rollbackFailedBlockWrite(
                $postId,
                $isUpdate,
                $postRollback,
                true,
                $rollbackSnapshot,
                $documentWrite
            );

            return array_merge($settingsWrite, [
                'postId' => $postId,
                'postType' => 'oxygen_block',
                'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
                'rollback' => $failureRollback,
            ]);
        }

        $this->invalidateDocumentCaches($postId);
        $cacheRegenerated = $this->generateBlockRenderCache($postId);

        return [
            'success' => true,
            'status' => 200,
            'action' => $isUpdate ? 'updated' : 'created',
            'postId' => $postId,
            'postType' => 'oxygen_block',
            'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            'oxygenDataMetaKey' => (string) ($documentWrite['metaKey'] ?? $this->getOxygenDataMetaKey()),
            'blockSettingsMetaKey' => (string) ($settingsWrite['metaKey'] ?? $this->getBlockSettingsMetaKey()),
            'treeHash' => (string) ($documentWrite['treeHash'] ?? ''),
            'settingsHash' => (string) ($settingsWrite['settingsHash'] ?? ''),
            'rollback' => [
                'post' => $isUpdate ? $postRollback !== [] : true,
                'postStore' => $rollbackPostStore,
                'oxygenData' => (bool) ($documentWrite['rollbackAvailable'] ?? false),
                'blockSettings' => (bool) ($settingsWrite['rollbackAvailable'] ?? false),
            ],
            'cacheRegenerated' => $cacheRegenerated,
            'adapter' => $this->getAdapterId(),
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

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function prepareDocumentTreeForJson(array $tree): array
    {
        if (isset($tree['root']) && is_array($tree['root'])) {
            $tree['root'] = $this->prepareTreeNodeForJson($tree['root']);
        }

        if (array_key_exists('exportedLookupTable', $tree) && $tree['exportedLookupTable'] === []) {
            $tree['exportedLookupTable'] = new \stdClass();
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function prepareTreeNodeForJson(array $node): array
    {
        if (isset($node['data']) && is_array($node['data'])) {
            if (array_key_exists('properties', $node['data']) && $node['data']['properties'] === []) {
                $node['data']['properties'] = new \stdClass();
            }

            if (($node['data']['type'] ?? null) === 'OxygenElements\\Component'
                && isset($node['data']['properties']['content']['content']['block'])
                && is_array($node['data']['properties']['content']['content']['block'])
                && array_key_exists('properties', $node['data']['properties']['content']['content']['block'])
                && $node['data']['properties']['content']['content']['block']['properties'] === []
            ) {
                $node['data']['properties']['content']['content']['block']['properties'] = new \stdClass();
            }
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $index => $child) {
                if (is_array($child)) {
                    $node['children'][$index] = $this->prepareTreeNodeForJson($child);
                }
            }
        }

        return $node;
    }

    private function getOxygenDataMetaKey(): string
    {
        if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
            return \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix') . 'data';
        }

        return '_oxygen_data';
    }

    private function getTemplateSettingsMetaKey(): string
    {
        if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
            return \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix') . 'template_settings';
        }

        return '_oxygen_template_settings';
    }

    private function getBlockSettingsMetaKey(): string
    {
        return '_breakdance_block_settings';
    }

    /**
     * @param array<string, mixed> $blockSettings
     * @return array<string, mixed>
     */
    private function blockSettingsForStorage(array $blockSettings): array
    {
        unset($blockSettings['_post']);

        return $blockSettings;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * @param mixed $value
     */
    private function nonEmptyString($value, string $default): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $oxygenData
     * @return array<string, mixed>|null
     */
    private function decodeTreeFromEnvelope(array $oxygenData): ?array
    {
        if (!is_string($oxygenData['tree_json_string'] ?? null)) {
            return null;
        }

        try {
            $tree = json_decode((string) $oxygenData['tree_json_string'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($tree) ? $tree : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureBlockPostRollback(int $postId): array
    {
        $post = null;
        if (function_exists('get_post')) {
            $post = get_post($postId);
        } elseif (isset($GLOBALS['__wp_posts'][$postId])) {
            $post = $GLOBALS['__wp_posts'][$postId];
        }

        if (!is_object($post)) {
            return [];
        }

        return [
            'ID' => (int) ($post->ID ?? $postId),
            'post_type' => (string) ($post->post_type ?? ''),
            'post_status' => (string) ($post->post_status ?? ''),
            'post_title' => (string) ($post->post_title ?? ''),
            'post_name' => (string) ($post->post_name ?? ''),
            'post_content' => (string) ($post->post_content ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $postRollback
     * @return array<string, mixed>
     */
    private function blockPostRollbackStore(int $postId, bool $isUpdate, array $postRollback): array
    {
        return [
            'owner' => 'oxygen6-storage-adapter',
            'storeType' => 'post',
            'store' => 'oxygen_block',
            'postId' => $postId,
            'oldExists' => $isUpdate && $postRollback !== [],
            'oldValue' => $isUpdate ? $postRollback : null,
            'restoreOperation' => $isUpdate ? 'wp_update_post' : 'wp_delete_post',
        ];
    }

    /**
     * @param array<string, mixed> $postRollback
     * @param array<string, mixed> $rollbackSnapshot
     * @param array<string, mixed> $documentWrite
     * @return array<string, mixed>
     */
    private function rollbackFailedBlockWrite(
        int $postId,
        bool $isUpdate,
        array $postRollback,
        bool $documentTouched,
        array $rollbackSnapshot,
        array $documentWrite
    ): array {
        $errors = [];
        $deletedCreatedPost = false;
        $restoredPost = false;
        $restoredDocument = false;

        if (!$isUpdate) {
            if (function_exists('wp_delete_post')) {
                $deletedCreatedPost = wp_delete_post($postId, true) !== false;
            } elseif (isset($GLOBALS['__wp_posts']) && is_array($GLOBALS['__wp_posts'])) {
                unset($GLOBALS['__wp_posts'][$postId], $GLOBALS['__wp_post_meta'][$postId]);
                $deletedCreatedPost = true;
            }

            if (!$deletedCreatedPost) {
                $errors[] = 'Failed to delete partially created oxygen_block post ' . (string) $postId . '.';
            }

            return [
                'post' => true,
                'postStore' => $this->blockPostRollbackStore($postId, false, []),
                'deletedCreatedPost' => $deletedCreatedPost,
                'oxygenData' => false,
                'blockSettings' => false,
                'errors' => $errors,
            ];
        }

        if ($postRollback !== [] && function_exists('wp_update_post')) {
            $restoreResult = wp_update_post($postRollback, true);
            $restoredPost = !is_wp_error($restoreResult) && (int) $restoreResult > 0;
            if (!$restoredPost) {
                $errors[] = 'Failed to restore oxygen_block post record ' . (string) $postId . '.';
            }
        }

        if ($documentTouched) {
            $restoredDocument = $this->restoreBlockMetaAfterFailedWrite(
                $postId,
                $this->getOxygenDataMetaKey(),
                is_string($rollbackSnapshot['rollbackMetaKey'] ?? null)
                    ? (string) $rollbackSnapshot['rollbackMetaKey']
                    : '_oxy_html_converter_previous_oxygen_data',
                (bool) ($documentWrite['rollbackAvailable'] ?? false)
            );

            if (!$restoredDocument) {
                $errors[] = 'Failed to restore _oxygen_data after block write failure.';
            }
        }

        return [
            'post' => $postRollback !== [],
            'postStore' => $this->blockPostRollbackStore($postId, true, $postRollback),
            'restoredPost' => $restoredPost,
            'oxygenData' => $documentTouched,
            'restoredOxygenData' => $restoredDocument,
            'blockSettings' => false,
            'errors' => $errors,
        ];
    }

    private function restoreBlockMetaAfterFailedWrite(
        int $postId,
        string $metaKey,
        string $rollbackMetaKey,
        bool $rollbackAvailable
    ): bool {
        if (!$rollbackAvailable) {
            if (function_exists('delete_post_meta')) {
                delete_post_meta($postId, $metaKey);
            } elseif (isset($GLOBALS['__wp_post_meta']) && is_array($GLOBALS['__wp_post_meta'])) {
                unset($GLOBALS['__wp_post_meta'][$postId][$metaKey]);
            }

            return !$this->adapterPostMetaExists($postId, $metaKey);
        }

        $previous = function_exists('get_post_meta') ? get_post_meta($postId, $rollbackMetaKey, true) : '';
        if ($previous === '' || !function_exists('update_post_meta')) {
            return false;
        }

        update_post_meta($postId, $metaKey, $previous);

        return function_exists('get_post_meta') && get_post_meta($postId, $metaKey, true) === $previous;
    }

    private function generateBlockRenderCache(int $postId): bool
    {
        if (function_exists('Breakdance\\Render\\generateCacheForPost')) {
            \Breakdance\Render\generateCacheForPost($postId);
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    private function writeTemplateSettingsMeta(int $postId, string $settingsJson, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateTemplateSettingsJson($settingsJson);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen template settings failed contract validation.',
                'errors' => $validation['errors'],
                'metaKey' => $this->getTemplateSettingsMetaKey(),
            ];
        }

        $metaKey = $this->getTemplateSettingsMetaKey();
        $previousMeta = function_exists('get_post_meta') ? get_post_meta($postId, $metaKey, true) : null;
        $rollbackMetaKey = is_string($rollbackSnapshot['templateSettingsRollbackMetaKey'] ?? null)
            ? (string) $rollbackSnapshot['templateSettingsRollbackMetaKey']
            : '_oxy_html_converter_previous_oxygen_template_settings';
        $rollbackAvailable = $previousMeta !== null && $previousMeta !== '';

        if ($rollbackAvailable && function_exists('update_post_meta')) {
            update_post_meta($postId, $rollbackMetaKey, $previousMeta);
        }

        if (function_exists('\Breakdance\Data\set_meta')) {
            \Breakdance\Data\set_meta($postId, $metaKey, $settingsJson);
        } else {
            update_post_meta($postId, $metaKey, wp_slash(wp_json_encode($settingsJson)));
        }

        return [
            'success' => true,
            'status' => 200,
            'metaKey' => $metaKey,
            'settingsHash' => sha1($settingsJson),
            'settingsBytes' => strlen($settingsJson),
            'rollbackAvailable' => $rollbackAvailable,
            'adapter' => $this->getAdapterId(),
        ];
    }

    /**
     * @param array<string, mixed> $blockSettings
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    private function writeBlockSettingsMeta(int $postId, array $blockSettings, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateBlockSettings($blockSettings);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen block settings failed contract validation.',
                'errors' => $validation['errors'],
                'metaKey' => $this->getBlockSettingsMetaKey(),
            ];
        }

        $encodedSettings = wp_json_encode($blockSettings);
        if (!is_string($encodedSettings)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to encode Oxygen block settings.',
                'errors' => ['wp_json_encode returned a non-string value for block settings.'],
                'metaKey' => $this->getBlockSettingsMetaKey(),
            ];
        }

        $metaKey = $this->getBlockSettingsMetaKey();
        $previousMeta = function_exists('get_post_meta') ? get_post_meta($postId, $metaKey, true) : null;
        $rollbackMetaKey = is_string($rollbackSnapshot['blockSettingsRollbackMetaKey'] ?? null)
            ? (string) $rollbackSnapshot['blockSettingsRollbackMetaKey']
            : '_oxy_html_converter_previous_breakdance_block_settings';
        $rollbackAvailable = $previousMeta !== null && $previousMeta !== '';

        if ($rollbackAvailable && function_exists('update_post_meta')) {
            update_post_meta($postId, $rollbackMetaKey, $previousMeta);
        }

        if (function_exists('\Breakdance\Data\set_meta')) {
            \Breakdance\Data\set_meta($postId, $metaKey, $blockSettings);
        } else {
            update_post_meta($postId, $metaKey, wp_slash($encodedSettings));
        }

        return [
            'success' => true,
            'status' => 200,
            'metaKey' => $metaKey,
            'settingsHash' => sha1($encodedSettings),
            'settingsBytes' => strlen($encodedSettings),
            'rollbackAvailable' => $rollbackAvailable,
            'adapter' => $this->getAdapterId(),
        ];
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

        foreach ([$rawMeta, stripslashes($rawMeta)] as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param mixed $rawMeta
     */
    private function decodeStoredTemplateSettings($rawMeta): ?string
    {
        if (!is_string($rawMeta) || $rawMeta === '') {
            return null;
        }

        foreach ([$rawMeta, stripslashes($rawMeta)] as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                continue;
            }

            if (is_string($decoded)) {
                return $decoded;
            }
        }

        $validation = $this->validateTemplateSettingsJson($rawMeta);
        if ($validation['valid']) {
            return $rawMeta;
        }

        return null;
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

            foreach ($variable as $key => $value) {
                if (!in_array((string) $key, ['id', 'cssVariableName', 'label', 'value', 'type', 'dynamicData', 'collection'], true)) {
                    $errors[] = $path . '.' . (string) $key . ' is not a supported Oxygen variable field.';
                }
            }

            if (array_key_exists('dynamicData', $variable) && !is_array($variable['dynamicData'])) {
                $errors[] = $path . '.dynamicData must be an object when present.';
            }

            $type = is_string($variable['type'] ?? null) ? (string) $variable['type'] : '';
            if ($type !== '' && !in_array($type, ['color', 'unit', 'number', 'font_family', 'image_url'], true)) {
                $errors[] = $path . '.type must be color, unit, number, font_family, or image_url.';
            }

            if ($type === 'unit') {
                $value = $variable['value'] ?? null;
                if (
                    !is_array($value)
                    || !array_key_exists('number', $value)
                    || !is_string($value['unit'] ?? null)
                    || !is_string($value['style'] ?? null)
                    || trim((string) $value['style']) === ''
                ) {
                    $errors[] = $path . '.value must be a measurement object with number, unit, and style.';
                }
            }

            if ($type === 'number' && !is_int($variable['value'] ?? null) && !is_float($variable['value'] ?? null)) {
                $errors[] = $path . '.value must be a number for number variables.';
            }

            if ($type === 'image_url') {
                $value = $variable['value'] ?? null;
                if (!is_array($value) || !is_string($value['url'] ?? null) || trim((string) $value['url']) === '') {
                    $errors[] = $path . '.value.url is required for image_url variables.';
                }
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
        $validation = (new \OxyHtmlConverter\Validation\OxygenSchemaValidator())->validateGlobalSettings($settings);
        $errors = array_map(
            static fn (array $error): string => $error['message'],
            $validation['errors']
        );

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
        $validation = (new \OxyHtmlConverter\Validation\OxygenSchemaValidator())->validateTemplateSettingsJson($settingsJson);
        $errors = array_map(
            static fn (array $error): string => $error['message'],
            $validation['errors']
        );

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
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
