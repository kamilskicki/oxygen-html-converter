<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenPageImporter
{
    public const MANIFEST_META_KEY = '_oxy_html_converter_import_manifest';
    public const ROLLBACK_META_KEY = '_oxy_html_converter_previous_oxygen_data';

    private readonly OxygenDocumentTree $documentTree;
    private readonly OxygenSelectorRepository $selectorRepository;
    private readonly GlobalStyleRepository $globalStyleRepository;
    private readonly PageStyleRepository $pageStyleRepository;
    private readonly WindPressCacheResetService $windPressCacheResetService;
    private readonly OxygenVariableRepository $variableRepository;
    private readonly OxygenGlobalSettingsRepository $oxygenGlobalSettingsRepository;
    private readonly BrandLibraryRepository $brandLibraryRepository;
    private ?OxygenStorageAdapter $storageAdapter;

    public function __construct(
        ?OxygenDocumentTree $documentTree = null,
        ?OxygenSelectorRepository $selectorRepository = null,
        ?GlobalStyleRepository $globalStyleRepository = null,
        ?PageStyleRepository $pageStyleRepository = null,
        ?WindPressCacheResetService $windPressCacheResetService = null,
        ?OxygenVariableRepository $variableRepository = null,
        ?OxygenGlobalSettingsRepository $oxygenGlobalSettingsRepository = null,
        ?BrandLibraryRepository $brandLibraryRepository = null,
        ?OxygenStorageAdapter $storageAdapter = null
    ) {
        $this->documentTree = $documentTree ?? new OxygenDocumentTree();
        $this->selectorRepository = $selectorRepository ?? new OxygenSelectorRepository();
        $this->globalStyleRepository = $globalStyleRepository ?? new GlobalStyleRepository();
        $this->pageStyleRepository = $pageStyleRepository ?? new PageStyleRepository();
        $this->windPressCacheResetService = $windPressCacheResetService ?? new WindPressCacheResetService();
        $this->variableRepository = $variableRepository ?? new OxygenVariableRepository();
        $this->oxygenGlobalSettingsRepository = $oxygenGlobalSettingsRepository ?? new OxygenGlobalSettingsRepository();
        $this->brandLibraryRepository = $brandLibraryRepository ?? new BrandLibraryRepository();
        $this->storageAdapter = $storageAdapter;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function import(array $payload): array
    {
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];

        if (($importPlan['canImport'] ?? true) === false || ($importPlan['status'] ?? '') === 'blocked') {
            return [
                'success' => false,
                'status' => 422,
                'message' => __('Import plan is blocked. Resolve blockers before creating a page.', 'oxygen-html-converter'),
                'errors' => $this->normalizeMessages($importPlan['blockers'] ?? []),
            ];
        }

        try {
            $documentTree = $this->resolveDocumentTree($payload);
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'status' => 500,
                'message' => __('Oxygen storage adapter is unavailable.', 'oxygen-html-converter'),
                'errors' => [$e->getMessage()],
            ];
        }

        if ($documentTree === null) {
            return [
                'success' => false,
                'status' => 400,
                'message' => __('Missing Oxygen document tree or element payload.', 'oxygen-html-converter'),
                'errors' => [],
            ];
        }

        $treeValidation = $this->getStorageAdapter()->validateDocumentTree($documentTree);
        if (!$treeValidation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => __('Oxygen document tree failed storage validation.', 'oxygen-html-converter'),
                'errors' => $treeValidation['errors'],
            ];
        }

        $authorization = $this->authorizeImport($payload);
        if (empty($authorization['success'])) {
            return $authorization;
        }

        $postResult = $this->createOrUpdatePost($payload);

        if (empty($postResult['postId'])) {
            return [
                'success' => false,
                'status' => 500,
                'message' => (string) ($postResult['message'] ?? __('Failed to create import draft.', 'oxygen-html-converter')),
                'errors' => [],
            ];
        }

        $postId = (int) $postResult['postId'];
        $rollbackBaseline = $this->captureRollbackStores($postId, $postResult);
        $windPressCacheReset = $this->emptyWindPressCacheResetResult();

        try {
            $selectorPayload = is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : [];
            $selectorPersistence = $this->selectorRepository->savePayload($selectorPayload);
            $globalStylePersistence = $this->globalStyleRepository->saveFromPayload($payload);
            $pageStylePersistence = $this->pageStyleRepository->saveForPost($postId, $payload);
            $variablePersistence = $this->variableRepository->saveFromPayload($payload);
            $oxygenGlobalSettingsPersistence = $this->oxygenGlobalSettingsRepository->saveFromPayload($payload);
            $brandLibraryPersistence = $this->hasBrandLibraryPayload($payload)
                ? $this->brandLibraryRepository->saveFromPayload($payload)
                : ['saved' => false, 'tokenChanges' => 0, 'componentChanges' => 0, 'library' => $this->brandLibraryRepository->getLibrary()];
            $windPressCacheReset = $this->windPressCacheResetService->resetIfAvailable();
            $metaResult = $this->persistDocumentTree($postId, $documentTree);
            if (empty($metaResult['success'])) {
                $restore = $this->restoreRollbackSnapshot($this->buildRollbackSnapshot($postId, $rollbackBaseline, $windPressCacheReset));

                return [
                    'success' => false,
                    'status' => (int) ($metaResult['status'] ?? 500),
                    'postId' => $postId,
                    'message' => (string) ($metaResult['message'] ?? __('Failed to persist Oxygen document tree.', 'oxygen-html-converter')),
                    'errors' => $this->normalizeMessages($metaResult['errors'] ?? []),
                    'restore' => $restore,
                ];
            }

            $rollbackSnapshot = $this->buildRollbackSnapshot($postId, $rollbackBaseline, $windPressCacheReset);
            $manifest = $this->buildManifest(
                $payload,
                $postId,
                $documentTree,
                $selectorPersistence,
                $globalStylePersistence,
                $pageStylePersistence,
                $variablePersistence,
                $oxygenGlobalSettingsPersistence,
                $brandLibraryPersistence,
                $windPressCacheReset,
                $postResult,
                $metaResult,
                $rollbackSnapshot
            );
            $this->persistManifest($postId, $manifest);
            $this->refreshRenderCache($postId);
        } catch (\Throwable $e) {
            $restore = $this->restoreRollbackSnapshot($this->buildRollbackSnapshot($postId, $rollbackBaseline, $windPressCacheReset));

            return [
                'success' => false,
                'status' => 500,
                'postId' => $postId,
                'message' => __('Import failed after partial persistence and rollback was attempted.', 'oxygen-html-converter'),
                'errors' => [$e->getMessage()],
                'restore' => $restore,
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'postId' => $postId,
            'postAction' => $postResult['action'],
            'postStatus' => $postResult['postStatus'],
            'title' => $postResult['title'],
            'slug' => $postResult['slug'],
            'permalink' => function_exists('get_permalink') ? get_permalink($postId) : '',
            'metaKey' => $metaResult['metaKey'],
            'selectorPersistence' => $selectorPersistence,
            'globalStylePersistence' => $globalStylePersistence,
            'pageStylePersistence' => $pageStylePersistence,
            'variablePersistence' => $variablePersistence,
            'oxygenGlobalSettingsPersistence' => $oxygenGlobalSettingsPersistence,
            'brandLibraryPersistence' => $brandLibraryPersistence,
            'windPressCacheReset' => $windPressCacheReset,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(int $postId): array
    {
        if ($postId < 1) {
            return [
                'success' => false,
                'status' => 400,
                'message' => __('Invalid post ID for rollback.', 'oxygen-html-converter'),
                'errors' => [],
            ];
        }

        if (!$this->currentUserCan('edit_post', $postId)) {
            return [
                'success' => false,
                'status' => 403,
                'message' => __('You are not allowed to rollback this page.', 'oxygen-html-converter'),
                'errors' => ['edit_post capability is required for rollback.'],
            ];
        }

        $manifest = $this->loadManifest($postId);
        $snapshot = is_array($manifest['rollback']['snapshot'] ?? null) ? $manifest['rollback']['snapshot'] : [];

        if ($snapshot !== []) {
            $restore = $this->restoreRollbackSnapshot($snapshot);
            if (empty($restore['success'])) {
                return [
                    'success' => false,
                    'status' => 500,
                    'postId' => $postId,
                    'message' => __('Rollback failed and was reverted to the pre-rollback state.', 'oxygen-html-converter'),
                    'errors' => $this->normalizeMessages($restore['errors'] ?? []),
                    'restore' => $restore,
                ];
            }

            if ($this->postExists($postId)) {
                delete_post_meta($postId, self::ROLLBACK_META_KEY);
                $this->markManifestRolledBack($postId);
            }
            $this->refreshRenderCache($postId);

            return [
                'success' => true,
                'status' => 200,
                'postId' => $postId,
                'metaKey' => $this->getOxygenDataMetaKey(),
                'rollbackRestored' => true,
                'restoredStores' => (int) ($restore['restored'] ?? 0),
            ];
        }

        $previousMeta = function_exists('get_post_meta')
            ? get_post_meta($postId, self::ROLLBACK_META_KEY, true)
            : '';

        if (!is_string($previousMeta) || $previousMeta === '') {
            return [
                'success' => false,
                'status' => 404,
                'message' => __('No rollback payload is available for this post.', 'oxygen-html-converter'),
                'errors' => [],
            ];
        }

        $metaKey = $this->getOxygenDataMetaKey();
        update_post_meta($postId, $metaKey, $previousMeta);
        delete_post_meta($postId, self::ROLLBACK_META_KEY);
        $this->markManifestRolledBack($postId);
        $this->refreshRenderCache($postId);

        return [
            'success' => true,
            'status' => 200,
            'postId' => $postId,
            'metaKey' => $metaKey,
            'rollbackRestored' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveDocumentTree(array $payload): ?array
    {
        if (isset($payload['documentTree']) && is_array($payload['documentTree'])) {
            return $this->getStorageAdapter()->buildDocumentTree($payload['documentTree']);
        }

        if (isset($payload['element']) && is_array($payload['element'])) {
            return $this->getStorageAdapter()->buildDocumentTree($payload['element']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function authorizeImport(array $payload): array
    {
        $slug = is_string($payload['slug'] ?? null) ? sanitize_title($payload['slug']) : '';
        $replaceExisting = !empty($payload['replaceExisting']);
        $postStatus = $this->normalizePostStatus($payload['postStatus'] ?? 'draft');
        $existingPost = $this->findExistingPageBySlug($slug);

        if (!$this->currentUserCan('edit_pages')) {
            return [
                'success' => false,
                'status' => 403,
                'message' => __('You are not allowed to create or import pages.', 'oxygen-html-converter'),
                'errors' => ['edit_pages capability is required for imports.'],
            ];
        }

        if (in_array($postStatus, ['publish', 'private'], true) && !$this->currentUserCan('publish_pages')) {
            return [
                'success' => false,
                'status' => 403,
                'message' => __('You are not allowed to publish imported pages.', 'oxygen-html-converter'),
                'errors' => ['publish_pages capability is required for publish/private imports.'],
            ];
        }

        if ($replaceExisting && $existingPost instanceof \WP_Post && !$this->currentUserCan('edit_post', (int) $existingPost->ID)) {
            return [
                'success' => false,
                'status' => 403,
                'message' => __('You are not allowed to update the target page.', 'oxygen-html-converter'),
                'errors' => ['edit_post capability is required for existing page updates.'],
            ];
        }

        return [
            'success' => true,
            'status' => 200,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createOrUpdatePost(array $payload): array
    {
        $title = is_string($payload['title'] ?? null) && trim($payload['title']) !== ''
            ? sanitize_text_field($payload['title'])
            : __('Imported Oxygen Page', 'oxygen-html-converter');
        $slug = is_string($payload['slug'] ?? null) ? sanitize_title($payload['slug']) : '';
        $replaceExisting = !empty($payload['replaceExisting']);
        $postStatus = $this->normalizePostStatus($payload['postStatus'] ?? 'draft');
        $existingPost = $this->findExistingPageBySlug($slug);
        $previousPost = $existingPost instanceof \WP_Post ? $this->snapshotPostRecord($existingPost) : null;

        $postPayload = [
            'post_type' => 'page',
            'post_status' => $postStatus,
            'post_title' => $title,
            'post_content' => '<!-- Imported by Oxygen HTML Converter -->',
        ];

        if ($slug !== '') {
            $postPayload['post_name'] = $slug;
        }

        if ($replaceExisting && $existingPost instanceof \WP_Post) {
            $postPayload['ID'] = (int) $existingPost->ID;
            $postId = wp_update_post($postPayload, true);
            $action = 'updated';
        } else {
            $postId = wp_insert_post($postPayload, true);
            $action = 'created';
        }

        if (function_exists('is_wp_error') && is_wp_error($postId)) {
            return [
                'postId' => 0,
                'message' => $postId->get_error_message(),
            ];
        }

        if (!is_numeric($postId) || (int) $postId < 1) {
            return [
                'postId' => 0,
                'message' => __('WordPress did not return a valid post ID.', 'oxygen-html-converter'),
            ];
        }

        return [
            'postId' => (int) $postId,
            'action' => $action,
            'postStatus' => $postStatus,
            'title' => $title,
            'slug' => $slug,
            'previousPost' => $previousPost,
        ];
    }

    /**
     * @param mixed $status
     */
    private function normalizePostStatus($status): string
    {
        $status = is_scalar($status) ? strtolower(trim((string) $status)) : 'draft';

        return in_array($status, ['draft', 'pending', 'private', 'publish'], true) ? $status : 'draft';
    }

    private function findExistingPageBySlug(string $slug): ?\WP_Post
    {
        if ($slug === '' || !function_exists('get_page_by_path')) {
            return null;
        }

        $existingPost = get_page_by_path($slug, OBJECT, 'page');

        return $existingPost instanceof \WP_Post ? $existingPost : null;
    }

    private function currentUserCan(string $capability, int $postId = 0): bool
    {
        if (!function_exists('current_user_can')) {
            return true;
        }

        if ($postId > 0) {
            return (bool) current_user_can($capability, $postId);
        }

        return (bool) current_user_can($capability);
    }

    /**
     * @param array<string, mixed> $documentTree
     * @return array<string, mixed>
     */
    private function persistDocumentTree(int $postId, array $documentTree): array
    {
        return $this->getStorageAdapter()->writePageDocument($postId, $documentTree, [
            'rollbackMetaKey' => self::ROLLBACK_META_KEY,
        ]);
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $adapter = (new OxygenStorageAdapterFactory())->create();

            if ($adapter instanceof OxygenSixStorageAdapter) {
                $adapter = new OxygenSixStorageAdapter($adapter->getContract(), $this->documentTree);
            }

            $this->storageAdapter = $adapter;
        }

        return $this->storageAdapter;
    }

    private function getOxygenDataMetaKey(): string
    {
        if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
            return \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix') . 'data';
        }

        return '_oxygen_data';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $documentTree
     * @param array<string, mixed> $selectorPersistence
     * @param array<string, mixed> $globalStylePersistence
     * @param array<string, mixed> $pageStylePersistence
     * @param array<string, mixed> $variablePersistence
     * @param array<string, mixed> $oxygenGlobalSettingsPersistence
     * @param array<string, mixed> $brandLibraryPersistence
     * @param array<string, mixed> $windPressCacheReset
     * @param array<string, mixed> $postResult
     * @param array<string, mixed> $metaResult
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    private function buildManifest(
        array $payload,
        int $postId,
        array $documentTree,
        array $selectorPersistence,
        array $globalStylePersistence,
        array $pageStylePersistence,
        array $variablePersistence,
        array $oxygenGlobalSettingsPersistence,
        array $brandLibraryPersistence,
        array $windPressCacheReset,
        array $postResult,
        array $metaResult,
        array $rollbackSnapshot
    ): array {
        $sourceHash = is_string($payload['sourceHash'] ?? null)
            ? trim($payload['sourceHash'])
            : sha1($this->encodeJson($documentTree));
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];

        return [
            'version' => 1,
            'importId' => $this->deterministicImportId($sourceHash, $documentTree),
            'importedAt' => gmdate('c'),
            'pluginVersion' => defined('OXY_HTML_CONVERTER_VERSION') ? OXY_HTML_CONVERTER_VERSION : '',
            'postId' => $postId,
            'postAction' => $postResult['action'] ?? '',
            'postStatus' => $postResult['postStatus'] ?? '',
            'sourceHash' => $sourceHash,
            'treeHash' => $metaResult['treeHash'] ?? '',
            'selectorPersistence' => [
                'saved' => (int) ($selectorPersistence['saved'] ?? 0),
                'total' => (int) ($selectorPersistence['total'] ?? 0),
                'collections' => is_array($selectorPersistence['collections'] ?? null)
                    ? array_values(array_map('strval', $selectorPersistence['collections']))
                    : [],
            ],
            'globalStylePersistence' => $this->summarizeGlobalStylePersistence($globalStylePersistence),
            'pageStylePersistence' => [
                'saved' => (bool) ($pageStylePersistence['saved'] ?? false),
                'bytes' => (int) ($pageStylePersistence['bytes'] ?? 0),
                'hash' => (string) ($pageStylePersistence['hash'] ?? ''),
            ],
            'variablePersistence' => $this->summarizeVariablePersistence($variablePersistence),
            'brandLibraryPersistence' => [
                'saved' => (bool) ($brandLibraryPersistence['saved'] ?? false),
                'tokenChanges' => (int) ($brandLibraryPersistence['tokenChanges'] ?? 0),
                'componentChanges' => (int) ($brandLibraryPersistence['componentChanges'] ?? 0),
            ],
            'oxygenGlobalSettingsPersistence' => [
                'saved' => (bool) ($oxygenGlobalSettingsPersistence['saved'] ?? false),
                'changes' => (int) ($oxygenGlobalSettingsPersistence['changes'] ?? 0),
                'sections' => is_array($oxygenGlobalSettingsPersistence['sections'] ?? null)
                    ? array_values(array_map('strval', $oxygenGlobalSettingsPersistence['sections']))
                    : [],
                'paletteColors' => (int) ($oxygenGlobalSettingsPersistence['paletteColors'] ?? 0),
                'cacheRegenerated' => (bool) ($oxygenGlobalSettingsPersistence['cacheRegenerated'] ?? false),
            ],
            'windPressCacheReset' => [
                'attempted' => (bool) ($windPressCacheReset['attempted'] ?? false),
                'active' => (bool) ($windPressCacheReset['active'] ?? false),
                'cacheFileDeleted' => (bool) ($windPressCacheReset['cacheFileDeleted'] ?? false),
                'objectCacheFlushed' => (bool) ($windPressCacheReset['objectCacheFlushed'] ?? false),
            ],
            'importPlan' => [
                'status' => (string) ($importPlan['status'] ?? ''),
                'nativeCoverage' => is_array($importPlan['nativeCoverage'] ?? null) ? $importPlan['nativeCoverage'] : [],
            ],
            'rollback' => [
                'available' => !empty($rollbackSnapshot['stores']),
                'metaKey' => self::ROLLBACK_META_KEY,
                'snapshot' => $rollbackSnapshot,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $persistence
     * @return array<string, mixed>
     */
    private function summarizeGlobalStylePersistence(array $persistence): array
    {
        $library = is_array($persistence['library'] ?? null) ? $persistence['library'] : [];
        $styles = is_array($library['styles'] ?? null) ? $library['styles'] : [];
        $ids = [];

        foreach ($styles as $style) {
            if (!is_array($style) || !is_scalar($style['id'] ?? null)) {
                continue;
            }

            $ids[] = (string) $style['id'];
        }

        return [
            'saved' => (bool) ($persistence['saved'] ?? false),
            'changes' => (int) ($persistence['changes'] ?? 0),
            'total' => count($ids),
            'styleIds' => array_values(array_unique($ids)),
        ];
    }

    /**
     * @param array<string, mixed> $persistence
     * @return array<string, mixed>
     */
    private function summarizeVariablePersistence(array $persistence): array
    {
        return [
            'saved' => (bool) ($persistence['saved'] ?? false),
            'changes' => (int) ($persistence['changes'] ?? 0),
            'created' => (int) ($persistence['created'] ?? 0),
            'updated' => (int) ($persistence['updated'] ?? 0),
            'linkedExisting' => (int) ($persistence['linkedExisting'] ?? 0),
            'total' => (int) ($persistence['total'] ?? 0),
            'collections' => is_array($persistence['collections'] ?? null)
                ? array_values(array_map('strval', $persistence['collections']))
                : [],
            'cacheRegenerated' => (bool) ($persistence['cacheRegenerated'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasBrandLibraryPayload(array $payload): bool
    {
        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];

        return !empty($designDocument['tokens'])
            || !empty($designDocument['componentCandidates'])
            || !empty($importPlan['tokens'])
            || !empty($importPlan['components']);
    }

    /**
     * @return array{attempted: bool, active: bool, cacheFileDeleted: bool, objectCacheFlushed: bool, errors: array<int, string>}
     */
    private function emptyWindPressCacheResetResult(): array
    {
        return [
            'attempted' => false,
            'active' => false,
            'cacheFileDeleted' => false,
            'objectCacheFlushed' => false,
            'errors' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captureRollbackStores(int $postId, array $postResult): array
    {
        $previousPost = is_array($postResult['previousPost'] ?? null) ? $postResult['previousPost'] : null;
        $stores = [
            $this->capturePostStore($postId, $previousPost),
        ];

        foreach ([
            ['store' => 'page_document', 'key' => $this->getOxygenDataMetaKey()],
            ['store' => 'rollback_meta', 'key' => self::ROLLBACK_META_KEY],
            ['store' => 'page_styles', 'key' => PageStyleRepository::META_KEY],
            ['store' => 'import_manifest', 'key' => self::MANIFEST_META_KEY],
        ] as $store) {
            $stores[] = $this->capturePostMetaStore($postId, $store['store'], $store['key']);
        }

        foreach ([
            'oxygen_selectors' => 'oxygen_oxy_selectors_json_string',
            'oxygen_selector_collections' => 'oxygen_oxy_selectors_collections_json_string',
            'breakdance_classes' => 'breakdance_classes_json_string',
            'oxygen_variables' => OxygenVariableRepository::OPTION_NAME,
            'oxygen_variable_collections' => OxygenVariableRepository::COLLECTIONS_OPTION_NAME,
            'oxygen_global_settings' => OxygenGlobalSettingsRepository::OPTION_NAME,
            'global_styles' => GlobalStyleRepository::OPTION_NAME,
            'brand_library' => BrandLibraryRepository::OPTION_NAME,
        ] as $store => $key) {
            $stores[] = $this->captureOptionStore((string) $store, $key);
        }

        return $stores;
    }

    /**
     * @param array<int, array<string, mixed>> $baseline
     * @param array<string, mixed> $windPressCacheReset
     * @return array<string, mixed>
     */
    private function buildRollbackSnapshot(int $postId, array $baseline, array $windPressCacheReset): array
    {
        $stores = [];

        foreach ($baseline as $entry) {
            $current = $this->readSnapshotEntryCurrentValue($entry);
            $entry['newExists'] = $current['exists'];
            $entry['newValue'] = $current['value'];
            if ($this->snapshotEntryWasTouched($entry)) {
                $stores[] = $entry;
            }
        }

        $stores[] = [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'cache',
            'store' => 'render_cache',
            'postId' => $postId,
            'key' => 'oxygen_render_cache',
            'oldExists' => false,
            'oldValue' => null,
            'newExists' => true,
            'newValue' => [
                'windPressResetAttempted' => (bool) ($windPressCacheReset['attempted'] ?? false),
                'windPressCacheFileDeleted' => (bool) ($windPressCacheReset['cacheFileDeleted'] ?? false),
                'windPressObjectCacheFlushed' => (bool) ($windPressCacheReset['objectCacheFlushed'] ?? false),
            ],
            'restoreOperation' => 'invalidate_cache',
        ];

        return [
            'version' => 1,
            'atomic' => true,
            'capturedAt' => gmdate('c'),
            'stores' => $stores,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function snapshotEntryWasTouched(array $entry): bool
    {
        if (($entry['storeType'] ?? '') === 'post') {
            return true;
        }

        if (($entry['store'] ?? '') === 'page_document') {
            return true;
        }

        if (($entry['store'] ?? '') === 'import_manifest') {
            return true;
        }

        return (bool) ($entry['oldExists'] ?? false) !== (bool) ($entry['newExists'] ?? false)
            || ($entry['oldValue'] ?? null) !== ($entry['newValue'] ?? null);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function restoreRollbackSnapshot(array $snapshot): array
    {
        $stores = is_array($snapshot['stores'] ?? null) ? $snapshot['stores'] : [];
        $preRestore = [];
        $failures = [];
        $restored = 0;

        foreach ($stores as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $current = $this->readSnapshotEntryCurrentValue($entry);
            $entry['currentExists'] = $current['exists'];
            $entry['currentValue'] = $current['value'];
            $preRestore[] = $entry;
        }

        for ($index = count($stores) - 1; $index >= 0; $index--) {
            $entry = $stores[$index];
            if (!is_array($entry)) {
                continue;
            }

            if (!$this->restoreSnapshotEntry($entry, 'old')) {
                $failures[] = (string) ($entry['store'] ?? 'unknown') . ':' . (string) ($entry['key'] ?? '');
                break;
            }

            $restored++;
        }

        if ($failures !== []) {
            for ($index = count($preRestore) - 1; $index >= 0; $index--) {
                $this->restoreSnapshotEntry($preRestore[$index], 'current');
            }

            return [
                'success' => false,
                'restored' => $restored,
                'errors' => $failures,
            ];
        }

        return [
            'success' => true,
            'restored' => $restored,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capturePostStore(int $postId, ?array $previousPost): array
    {
        $oldExists = $previousPost !== null;

        return [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'post',
            'store' => 'target_post',
            'postId' => $postId,
            'key' => 'wp_posts:' . $postId,
            'oldExists' => $oldExists,
            'oldValue' => $previousPost,
            'restoreOperation' => $oldExists ? 'wp_update_post' : 'wp_delete_post',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capturePostMetaStore(int $postId, string $store, string $key): array
    {
        $exists = $this->postMetaExists($postId, $key);

        return [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'post_meta',
            'store' => $store,
            'postId' => $postId,
            'key' => $key,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : null,
            'restoreOperation' => $exists ? 'update_post_meta' : 'delete_post_meta',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureOptionStore(string $store, string $key): array
    {
        $exists = $this->optionExists($key);

        return [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'option',
            'store' => $store,
            'key' => $key,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_option') ? get_option($key) : null,
            'restoreOperation' => $exists ? 'update_option' : 'delete_option',
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{exists: bool, value: mixed}
     */
    private function readSnapshotEntryCurrentValue(array $entry): array
    {
        $storeType = (string) ($entry['storeType'] ?? '');
        $key = (string) ($entry['key'] ?? '');

        if ($storeType === 'post') {
            $postId = (int) ($entry['postId'] ?? 0);
            $post = $this->getPostRecord($postId);

            return [
                'exists' => $post !== null,
                'value' => $post,
            ];
        }

        if ($storeType === 'post_meta') {
            $postId = (int) ($entry['postId'] ?? 0);
            $exists = $this->postMetaExists($postId, $key);

            return [
                'exists' => $exists,
                'value' => $exists && function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : null,
            ];
        }

        if ($storeType === 'option') {
            $exists = $this->optionExists($key);

            return [
                'exists' => $exists,
                'value' => $exists && function_exists('get_option') ? get_option($key) : null,
            ];
        }

        return [
            'exists' => false,
            'value' => null,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function restoreSnapshotEntry(array $entry, string $source): bool
    {
        $storeType = (string) ($entry['storeType'] ?? '');
        $key = (string) ($entry['key'] ?? '');
        $exists = (bool) ($entry[$source . 'Exists'] ?? false);
        $value = $entry[$source . 'Value'] ?? null;

        if ($storeType === 'cache') {
            $this->refreshRenderCache((int) ($entry['postId'] ?? 0));
            return true;
        }

        if ($storeType === 'post') {
            $postId = (int) ($entry['postId'] ?? 0);
            if ($exists) {
                return $this->restorePostRecord($postId, is_array($value) ? $value : []);
            }

            return $this->deletePostRecord($postId);
        }

        if ($storeType === 'post_meta') {
            $postId = (int) ($entry['postId'] ?? 0);
            if ($exists) {
                if (!function_exists('update_post_meta')) {
                    return true;
                }

                update_post_meta($postId, $key, $value);
                return $this->postMetaExists($postId, $key)
                    && get_post_meta($postId, $key, true) === $value;
            }

            if (!$this->postMetaExists($postId, $key)) {
                return true;
            }

            if (function_exists('delete_post_meta')) {
                delete_post_meta($postId, $key);
            }

            return $this->postMetaIsAbsent($postId, $key);
        }

        if ($storeType === 'option') {
            if ($exists) {
                if (!function_exists('update_option')) {
                    return true;
                }

                update_option($key, $value);
                return $this->optionExists($key)
                    && get_option($key) === $value;
            }

            if (!$this->optionExists($key)) {
                return true;
            }

            if (function_exists('delete_option')) {
                delete_option($key);
            }

            if (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
                unset($GLOBALS['__wp_options'][$key]);
            }

            return $this->optionIsAbsent($key);
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPostRecord(int $postId): ?array
    {
        if ($postId < 1) {
            return null;
        }

        if (isset($GLOBALS['__wp_posts']) && is_array($GLOBALS['__wp_posts'])) {
            $post = $GLOBALS['__wp_posts'][$postId] ?? null;
            return $post instanceof \WP_Post ? $this->snapshotPostRecord($post) : null;
        }

        if (function_exists('get_post')) {
            $post = get_post($postId);
            return $post instanceof \WP_Post ? $this->snapshotPostRecord($post) : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPostRecord(\WP_Post $post): array
    {
        return [
            'ID' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_content' => (string) $post->post_content,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function restorePostRecord(int $postId, array $record): bool
    {
        if ($postId < 1 || $record === []) {
            return false;
        }

        $record['ID'] = $postId;

        if (function_exists('wp_update_post')) {
            $result = wp_update_post($record, true);
            if (function_exists('is_wp_error') && is_wp_error($result)) {
                return false;
            }
        } elseif (isset($GLOBALS['__wp_posts']) && is_array($GLOBALS['__wp_posts'])) {
            $GLOBALS['__wp_posts'][$postId] = new \WP_Post($record);
        } else {
            return false;
        }

        return $this->getPostRecord($postId) === $this->normalizePostRecord($record);
    }

    private function deletePostRecord(int $postId): bool
    {
        if ($postId < 1 || !$this->postExists($postId)) {
            return true;
        }

        if (function_exists('wp_delete_post')) {
            wp_delete_post($postId, true);
        } elseif (isset($GLOBALS['__wp_posts']) && is_array($GLOBALS['__wp_posts'])) {
            unset($GLOBALS['__wp_posts'][$postId], $GLOBALS['__wp_post_meta'][$postId]);
        } else {
            return false;
        }

        return $this->postIsAbsent($postId);
    }

    private function postExists(int $postId): bool
    {
        return $this->getPostRecord($postId) !== null;
    }

    private function postIsAbsent(int $postId): bool
    {
        return !$this->postExists($postId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizePostRecord(array $record): array
    {
        return [
            'ID' => (int) ($record['ID'] ?? 0),
            'post_type' => (string) ($record['post_type'] ?? ''),
            'post_status' => (string) ($record['post_status'] ?? ''),
            'post_title' => (string) ($record['post_title'] ?? ''),
            'post_name' => (string) ($record['post_name'] ?? ''),
            'post_content' => (string) ($record['post_content'] ?? ''),
        ];
    }

    private function postMetaExists(int $postId, string $key): bool
    {
        if (isset($GLOBALS['__wp_post_meta']) && is_array($GLOBALS['__wp_post_meta'])) {
            return array_key_exists($key, $GLOBALS['__wp_post_meta'][$postId] ?? []);
        }

        return function_exists('get_post_meta') && get_post_meta($postId, $key, true) !== '';
    }

    private function postMetaIsAbsent(int $postId, string $key): bool
    {
        return !$this->postMetaExists($postId, $key);
    }

    private function optionExists(string $key): bool
    {
        if (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
            return array_key_exists($key, $GLOBALS['__wp_options']);
        }

        return function_exists('get_option') && get_option($key, null) !== null;
    }

    private function optionIsAbsent(string $key): bool
    {
        return !$this->optionExists($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(int $postId): array
    {
        if (!function_exists('get_post_meta')) {
            return [];
        }

        $rawManifest = get_post_meta($postId, self::MANIFEST_META_KEY, true);
        $rawManifest = is_string($rawManifest) ? stripslashes($rawManifest) : '';
        $manifest = $rawManifest !== '' ? json_decode($rawManifest, true) : null;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param array<string, mixed> $documentTree
     */
    private function deterministicImportId(string $sourceHash, array $documentTree): string
    {
        return substr(sha1('oxy-html-converter-import:' . $sourceHash . ':' . $this->encodeJson($documentTree)), 0, 16);
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
     * @param array<string, mixed> $manifest
     */
    private function persistManifest(int $postId, array $manifest): void
    {
        if (!function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($postId, self::MANIFEST_META_KEY, wp_slash($this->encodeJson($manifest)));
    }

    private function markManifestRolledBack(int $postId): void
    {
        if (!function_exists('get_post_meta') || !function_exists('update_post_meta')) {
            return;
        }

        $rawManifest = get_post_meta($postId, self::MANIFEST_META_KEY, true);
        $rawManifest = is_string($rawManifest) ? stripslashes($rawManifest) : '';
        $manifest = $rawManifest !== '' ? json_decode($rawManifest, true) : null;

        if (!is_array($manifest)) {
            $manifest = [
                'version' => 1,
                'postId' => $postId,
            ];
        }

        $manifest['rollback'] = is_array($manifest['rollback'] ?? null) ? $manifest['rollback'] : [];
        $manifest['rollback']['available'] = false;
        $manifest['rollback']['restoredAt'] = gmdate('c');

        update_post_meta($postId, self::MANIFEST_META_KEY, wp_slash($this->encodeJson($manifest)));
    }

    private function refreshRenderCache(int $postId): void
    {
        $metaPrefix = function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')
            ? \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix')
            : '_oxygen_';

        if (function_exists('delete_post_meta')) {
            delete_post_meta($postId, $metaPrefix . 'dependency_cache');
            delete_post_meta($postId, $metaPrefix . 'css_file_paths_cache');
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($postId);
        }

        $cacheGenerator = function_exists('apply_filters')
            ? (string) apply_filters('oxy_html_converter_cache_generator', '\Breakdance\Render\generateCacheForPost')
            : '\Breakdance\Render\generateCacheForPost';
        if (is_callable($cacheGenerator)) {
            $cacheGenerator($postId);
        }
    }

    /**
     * @param mixed $messages
     * @return list<string>
     */
    private function normalizeMessages($messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];

        foreach ($messages as $message) {
            if (!is_scalar($message)) {
                continue;
            }

            $value = trim((string) $message);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
