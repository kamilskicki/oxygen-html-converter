<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Validation\OxygenSchemaValidator;

class OxygenPageImporter
{
    public const MANIFEST_META_KEY = '_oxy_html_converter_import_manifest';
    public const ROLLBACK_META_KEY = '_oxy_html_converter_previous_oxygen_data';

    /**
     * @var array<string, bool>
     */
    private const SITE_KIT_ALLOWED_KEYS = [
        'version' => true,
        'id' => true,
        'title' => true,
        'pages' => true,
        'templates' => true,
        'headers' => true,
        'footers' => true,
        'parts' => true,
        'menus' => true,
        'homepage' => true,
        'globalSettings' => true,
        'oxygenGlobalSettings' => true,
        'variables' => true,
        'oxygenVariables' => true,
        'tokens' => true,
        'designDocument' => true,
        'importPlan' => true,
        'selectors' => true,
        'selectorPayload' => true,
        'collections' => true,
        'fallbackCss' => true,
        'globalCss' => true,
        'pageCss' => true,
        'pageScopedCss' => true,
        'styleRouting' => true,
        'assets' => true,
        'unsupportedItems' => true,
        'unsupported' => true,
        'overwriteGlobalSettings' => true,
    ];

    /**
     * @var array<string, bool>
     */
    private const SITE_KIT_TEMPLATE_SECTIONS = [
        'templates' => true,
        'headers' => true,
        'footers' => true,
        'parts' => true,
    ];

    /**
     * @var array<string, bool>
     */
    private const SITE_KIT_DOCUMENT_SECTIONS = [
        'pages' => true,
        'templates' => true,
        'headers' => true,
        'footers' => true,
        'parts' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const COMPONENT_NODE_TYPE_TAGS = [
        ElementTypes::CONTAINER => 'div',
        ElementTypes::CONTAINER_LINK => 'a',
        ElementTypes::TEXT => 'p',
        ElementTypes::TEXT_LINK => 'a',
        ElementTypes::RICH_TEXT => 'div',
        ElementTypes::IMAGE => 'img',
        ElementTypes::SVG_ICON => 'svg',
        ElementTypes::HTML5_VIDEO => 'video',
        ElementTypes::HTML_CODE => 'html',
        ElementTypes::CSS_CODE => 'style',
        ElementTypes::JAVASCRIPT_CODE => 'script',
    ];

    private readonly OxygenDocumentTree $documentTree;
    private readonly OxygenSelectorRepository $selectorRepository;
    private readonly GlobalStyleRepository $globalStyleRepository;
    private readonly PageStyleRepository $pageStyleRepository;
    private readonly WindPressCacheResetService $windPressCacheResetService;
    private readonly OxygenVariableRepository $variableRepository;
    private readonly OxygenGlobalSettingsRepository $oxygenGlobalSettingsRepository;
    private readonly BrandLibraryRepository $brandLibraryRepository;
    private readonly OxygenBlockRepository $blockRepository;
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
        ?OxygenStorageAdapter $storageAdapter = null,
        ?OxygenBlockRepository $blockRepository = null
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
        $this->blockRepository = $blockRepository ?? new OxygenBlockRepository($storageAdapter);
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
            $variablePersistence = $this->variableRepository->saveFromPayload($payload);
            $oxygenGlobalSettingsPersistence = $this->oxygenGlobalSettingsRepository->saveFromPayload($payload);
            $componentPersistence = $this->persistComponentCandidatesFromPayload($payload);
            $rollbackBaseline = array_merge(
                $rollbackBaseline,
                $this->componentRollbackStoresFromPersistence($componentPersistence)
            );
            if (empty($componentPersistence['success'])) {
                throw new \RuntimeException(
                    'Component block persistence failed: ' . implode(' ', $this->normalizeMessages($componentPersistence['errors'] ?? []))
                );
            }

            $componentInstances = $this->replaceComponentCandidatesInDocumentTree(
                $documentTree,
                $payload,
                $componentPersistence
            );
            $documentTree = $componentInstances['tree'];
            $payload = $this->payloadWithComponentizedDocumentTree($payload, $documentTree);
            $payload = $this->mergeComponentCssIntoHostPayload($payload, $componentPersistence, $componentInstances);
            $pageStylePersistence = $this->pageStyleRepository->saveForPost($postId, $payload);
            $treeValidation = $this->getStorageAdapter()->validateDocumentTree($documentTree);
            if (!$treeValidation['valid']) {
                throw new \RuntimeException(
                    'Componentized Oxygen document tree failed storage validation: '
                    . implode(' ', $treeValidation['errors'])
                );
            }

            $brandLibraryPersistence = $this->hasBrandLibraryPayload($payload)
                ? $this->brandLibraryRepository->saveFromPayload($payload)
                : ['saved' => false, 'tokenChanges' => 0, 'componentChanges' => 0, 'library' => $this->brandLibraryRepository->getLibrary()];
            $windPressCacheReset = $this->windPressCacheResetService->resetIfEnabled();
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
                $componentPersistence,
                $componentInstances,
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
            'componentPersistence' => $componentPersistence,
            'componentInstances' => $componentInstances['summary'],
            'brandLibraryPersistence' => $brandLibraryPersistence,
            'windPressCacheReset' => $windPressCacheReset,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function importSiteKit(array $manifest): array
    {
        $validation = $this->validateSiteKitManifest($manifest);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => __('Site-kit manifest failed validation.', 'oxygen-html-converter'),
                'errors' => $validation['errors'],
            ];
        }

        foreach ($this->siteKitSectionRecords($manifest, 'pages') as $indexedRecord) {
            $record = $indexedRecord['record'];
            $tree = $this->extractSiteKitDocumentTree($record);
            $authorization = $this->authorizeImport($this->siteKitPagePayload($record, $indexedRecord['index'], $tree ?? []));
            if (empty($authorization['success'])) {
                return $authorization;
            }
        }

        if (!$this->currentUserCan('edit_pages')) {
            return [
                'success' => false,
                'status' => 403,
                'message' => __('You are not allowed to import a site kit.', 'oxygen-html-converter'),
                'errors' => ['edit_pages capability is required for site-kit imports.'],
            ];
        }

        $rollbackId = $this->siteKitRollbackId($manifest);
        $adapterSnapshot = $this->getStorageAdapter()->captureRollbackSnapshot([
            'selectors',
            'variables',
            'global_settings',
            'global_styles',
            'brand_library',
        ]);
        $rollbackBaseline = [];
        $objects = $this->emptySiteKitObjectReport();
        $firstPageId = 0;
        $selectorPersistence = ['saved' => 0, 'total' => 0, 'collections' => []];
        $globalStylePersistence = ['saved' => false, 'changes' => 0];
        $pageStylePersistence = ['saved' => false, 'bytes' => 0, 'hash' => ''];
        $variablePersistence = ['saved' => false, 'changes' => 0, 'created' => 0, 'updated' => 0, 'linkedExisting' => 0, 'total' => 0, 'collections' => []];
        $oxygenGlobalSettingsPersistence = ['saved' => false, 'changes' => 0, 'sections' => [], 'paletteColors' => 0, 'cacheRegenerated' => false];
        $brandLibraryPersistence = ['saved' => false, 'tokenChanges' => 0, 'componentChanges' => 0];
        $componentPersistence = $this->emptyComponentPersistence();
        $componentInstances = $this->emptyComponentInstancesSummary();
        $siteConfigurationPersistence = ['success' => true, 'options' => [], 'menus' => [], 'placements' => [], 'rollback' => ['stores' => []]];
        $siteConfigurationRollback = [];
        $windPressCacheReset = $this->emptyWindPressCacheResetResult();
        $designPayload = $this->siteKitDesignPayload($manifest);
        $stylePayload = $this->siteKitStylePayload($manifest);

        try {
            $componentPersistence = $this->persistComponentCandidatesFromPayload($designPayload);
            $rollbackBaseline = array_merge(
                $rollbackBaseline,
                $this->componentRollbackStoresFromPersistence($componentPersistence)
            );
            if (empty($componentPersistence['success'])) {
                throw new \RuntimeException(
                    'Component block persistence failed: ' . implode(' ', $this->normalizeMessages($componentPersistence['errors'] ?? []))
                );
            }

            foreach ($this->siteKitSectionRecords($manifest, 'pages') as $indexedRecord) {
                $page = $this->importSiteKitPageRecord(
                    $indexedRecord['record'],
                    $indexedRecord['index'],
                    $rollbackBaseline,
                    $designPayload,
                    $stylePayload,
                    $componentPersistence,
                    $componentInstances
                );
                $objects['pages'][] = $page;
                $pageStylePersistence = $this->mergeSiteKitPageStylePersistence(
                    $pageStylePersistence,
                    is_array($page['pageStylePersistence'] ?? null) ? $page['pageStylePersistence'] : []
                );
                if ($firstPageId < 1) {
                    $firstPageId = (int) $page['postId'];
                }
            }

            $templateRepository = new OxygenTemplateRepository($this->getStorageAdapter());
            foreach (array_keys(self::SITE_KIT_TEMPLATE_SECTIONS) as $section) {
                foreach ($this->siteKitSectionRecords($manifest, $section) as $indexedRecord) {
                    $template = $this->importSiteKitTemplateRecord(
                        $templateRepository,
                        $indexedRecord['record'],
                        $section,
                        $indexedRecord['index'],
                        $rollbackBaseline,
                        $designPayload,
                        $stylePayload,
                        $componentPersistence,
                        $componentInstances
                    );
                    $objects[$section][] = $template;
                    $pageStylePersistence = $this->mergeSiteKitPageStylePersistence(
                        $pageStylePersistence,
                        is_array($template['pageStylePersistence'] ?? null) ? $template['pageStylePersistence'] : []
                    );
                }
            }

            $selectorPersistence = $this->selectorRepository->savePayload($this->siteKitSelectorPayload($manifest));
            $globalStylePersistence = $this->globalStyleRepository->saveFromPayload($stylePayload);

            $variablePersistence = $this->variableRepository->saveFromPayload($designPayload);
            $oxygenGlobalSettingsPersistence = $this->oxygenGlobalSettingsRepository->saveFromPayload($designPayload);
            $brandLibraryPersistence = $this->hasBrandLibraryPayload($designPayload)
                ? $this->brandLibraryRepository->saveFromPayload($designPayload)
                : ['saved' => false, 'tokenChanges' => 0, 'componentChanges' => 0, 'library' => $this->brandLibraryRepository->getLibrary()];
            $windPressCacheReset = $this->windPressCacheResetService->resetIfEnabled();
            $siteConfigurationImporter = new SiteConfigurationImporter();
            $siteConfigurationPersistence = $siteConfigurationImporter->apply($manifest, $objects['pages']);
            $siteConfigurationRollback = is_array($siteConfigurationPersistence['rollback'] ?? null)
                ? $siteConfigurationPersistence['rollback']
                : [];

            if (empty($siteConfigurationPersistence['success'])) {
                $rollbackSnapshot = $this->buildSiteKitRollbackSnapshot($rollbackBaseline, $adapterSnapshot, $rollbackId);
                $restore = $this->restoreRollbackSnapshot($rollbackSnapshot);

                return [
                    'success' => false,
                    'status' => (int) ($siteConfigurationPersistence['status'] ?? 422),
                    'message' => (string) ($siteConfigurationPersistence['message'] ?? __('Site configuration import failed validation.', 'oxygen-html-converter')),
                    'errors' => $this->normalizeMessages($siteConfigurationPersistence['errors'] ?? []),
                    'rollbackId' => $rollbackId,
                    'restore' => $restore,
                    'siteConfigurationPersistence' => $siteConfigurationPersistence,
                ];
            }

            $rollbackSnapshot = $this->buildSiteKitRollbackSnapshot($rollbackBaseline, $adapterSnapshot, $rollbackId);
            $report = $this->buildSiteKitImportReport(
                $manifest,
                $rollbackId,
                $objects,
                $selectorPersistence,
                $globalStylePersistence,
                $pageStylePersistence,
                $variablePersistence,
                $oxygenGlobalSettingsPersistence,
                $brandLibraryPersistence,
                $componentPersistence,
                $componentInstances,
                $siteConfigurationPersistence,
                $windPressCacheReset,
                $rollbackSnapshot
            );

            if ($firstPageId > 0) {
                $this->persistManifest($firstPageId, $report);
            }
        } catch (\Throwable $e) {
            $siteConfigurationRestore = $siteConfigurationRollback !== []
                ? (new SiteConfigurationImporter())->restore($siteConfigurationRollback)
                : ['success' => true, 'restored' => 0, 'errors' => []];
            $rollbackSnapshot = $this->buildSiteKitRollbackSnapshot($rollbackBaseline, $adapterSnapshot, $rollbackId);
            $restore = $this->restoreRollbackSnapshot($rollbackSnapshot);

            return [
                'success' => false,
                'status' => 500,
                'message' => __('Site-kit import failed after partial persistence and rollback was attempted.', 'oxygen-html-converter'),
                'errors' => [$e->getMessage()],
                'rollbackId' => $rollbackId,
                'restore' => $restore,
                'siteConfigurationRestore' => $siteConfigurationRestore,
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'rollbackId' => $rollbackId,
            'objects' => $objects,
            'unsupportedItems' => $this->normalizeSiteKitList($manifest['unsupportedItems'] ?? $manifest['unsupported'] ?? []),
            'assets' => $this->normalizeSiteKitList($manifest['assets'] ?? []),
            'selectorPersistence' => $selectorPersistence,
            'globalStylePersistence' => $globalStylePersistence,
            'pageStylePersistence' => $pageStylePersistence,
            'variablePersistence' => $variablePersistence,
            'oxygenGlobalSettingsPersistence' => $oxygenGlobalSettingsPersistence,
            'brandLibraryPersistence' => $brandLibraryPersistence,
            'componentPersistence' => $componentPersistence,
            'componentInstances' => $componentInstances,
            'siteConfigurationPersistence' => $siteConfigurationPersistence,
            'windPressCacheReset' => $windPressCacheReset,
            'manifest' => $report,
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
        $siteConfigurationSnapshot = is_array($manifest['siteConfigurationPersistence']['rollback'] ?? null)
            ? $manifest['siteConfigurationPersistence']['rollback']
            : [];

        if ($snapshot !== []) {
            $restore = $this->restoreRollbackSnapshot($snapshot);
            $siteConfigurationRestore = ['success' => true, 'restored' => 0, 'errors' => []];

            if (empty($restore['success'])) {
                return [
                    'success' => false,
                    'status' => 500,
                    'postId' => $postId,
                    'message' => __('Rollback failed and was reverted to the pre-rollback state.', 'oxygen-html-converter'),
                    'errors' => $this->normalizeMessages($restore['errors'] ?? []),
                    'restore' => $restore,
                    'siteConfigurationRestore' => $siteConfigurationRestore,
                ];
            }

            if ($siteConfigurationSnapshot !== []) {
                $siteConfigurationRestore = (new SiteConfigurationImporter())->restore($siteConfigurationSnapshot);
                if (empty($siteConfigurationRestore['success'])) {
                    return [
                        'success' => false,
                        'status' => 500,
                        'postId' => $postId,
                        'message' => __('Site configuration rollback failed.', 'oxygen-html-converter'),
                        'errors' => $this->normalizeMessages($siteConfigurationRestore['errors'] ?? []),
                        'restore' => $restore,
                        'siteConfigurationRestore' => $siteConfigurationRestore,
                    ];
                }
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
                'siteConfigurationRestore' => $siteConfigurationRestore,
                'restoredSiteConfigurationStores' => (int) ($siteConfigurationRestore['restored'] ?? 0),
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
     * @param array<string, mixed> $manifest
     * @return array{valid: bool, errors: list<string>}
     */
    private function validateSiteKitManifest(array $manifest): array
    {
        $errors = [];

        foreach (array_keys($manifest) as $key) {
            if (!is_string($key) || !isset(self::SITE_KIT_ALLOWED_KEYS[$key])) {
                $errors[] = sprintf('Unknown site-kit manifest section "%s".', (string) $key);
            }
        }

        foreach (array_keys(self::SITE_KIT_DOCUMENT_SECTIONS) as $section) {
            if (!array_key_exists($section, $manifest)) {
                continue;
            }

            if (!is_array($manifest[$section])) {
                $errors[] = '$.' . $section . ' expected array of records.';
                continue;
            }

            foreach ($manifest[$section] as $index => $record) {
                if (!is_array($record)) {
                    $errors[] = '$.' . $section . '[' . (int) $index . '] expected object.';
                    continue;
                }

                if (!$this->siteKitRecordHasTitle($record)) {
                    $errors[] = '$.' . $section . '[' . (int) $index . '].title expected non-empty string.';
                }

                $tree = $this->extractSiteKitDocumentTree($record);
                if ($tree === null) {
                    $errors[] = '$.' . $section . '[' . (int) $index . '] missing documentTree.';
                    continue;
                }

                $treeValidation = $this->getStorageAdapter()->validateDocumentTree($tree);
                foreach ($treeValidation['errors'] as $error) {
                    $errors[] = '$.' . $section . '[' . (int) $index . '].documentTree: ' . $error;
                }

                if (isset(self::SITE_KIT_TEMPLATE_SECTIONS[$section])) {
                    if ($section !== 'parts' && $this->extractSiteKitTemplateSettings($record) === null) {
                        $errors[] = '$.' . $section . '[' . (int) $index . '] missing templateSettings.';
                    }

                    $repository = new OxygenTemplateRepository($this->getStorageAdapter());
                    $templateValidation = $repository->validateTemplateSpec(
                        $repository->manifestRecordToTemplateSpec($record, $section, (int) $index)
                    );
                    foreach ($templateValidation['errors'] as $error) {
                        $errors[] = '$.' . $section . '[' . (int) $index . ']: ' . $error;
                    }
                }
            }
        }

        foreach (['menus', 'assets', 'unsupportedItems', 'unsupported', 'selectorPayload', 'selectors', 'collections'] as $key) {
            if (array_key_exists($key, $manifest) && !is_array($manifest[$key])) {
                $errors[] = '$.' . $key . ' expected array.';
            }
        }

        foreach (['globalSettings', 'oxygenGlobalSettings', 'variables', 'oxygenVariables', 'tokens', 'designDocument', 'importPlan', 'styleRouting'] as $key) {
            if (array_key_exists($key, $manifest) && !is_array($manifest[$key])) {
                $errors[] = '$.' . $key . ' expected object.';
            }
        }

        if (array_key_exists('homepage', $manifest)
            && !is_array($manifest['homepage'])
            && !is_string($manifest['homepage'])
            && !is_int($manifest['homepage'])
        ) {
            $errors[] = '$.homepage expected object, string, or integer.';
        }

        foreach (['fallbackCss', 'globalCss', 'pageCss', 'pageScopedCss'] as $key) {
            if (array_key_exists($key, $manifest) && !is_string($manifest[$key])) {
                $errors[] = '$.' . $key . ' expected string.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{index: int, record: array<string, mixed>}>
     */
    private function siteKitSectionRecords(array $manifest, string $section): array
    {
        $records = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];
        $indexed = [];

        foreach ($records as $index => $record) {
            if (is_array($record)) {
                $indexed[] = [
                    'index' => (int) $index,
                    'record' => $record,
                ];
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function siteKitRecordHasTitle(array $record): bool
    {
        return is_string($record['title'] ?? null) && trim((string) $record['title']) !== '';
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function extractSiteKitDocumentTree(array $record): ?array
    {
        foreach (['documentTree', 'tree'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                return $this->getStorageAdapter()->buildDocumentTree($record[$field]);
            }
        }

        $oxygenData = is_array($record['_oxygen_data'] ?? null) ? $record['_oxygen_data'] : null;
        if ($oxygenData !== null && is_string($oxygenData['tree_json_string'] ?? null)) {
            try {
                $tree = json_decode((string) $oxygenData['tree_json_string'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return null;
            }

            return is_array($tree) ? $this->getStorageAdapter()->buildDocumentTree($tree) : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function extractSiteKitTemplateSettings(array $record): ?array
    {
        $settings = $record['templateSettings'] ?? $record['settings'] ?? null;
        if (is_array($settings)) {
            return $settings;
        }

        $settingsJson = $record['_oxygen_template_settings'] ?? null;
        if (!is_string($settingsJson) || trim($settingsJson) === '') {
            return null;
        }

        $decoded = json_decode($settingsJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function siteKitPagePayload(array $record, int $index, array $tree): array
    {
        return [
            'title' => $this->manifestRecordString($record, 'title', 'Page ' . ($index + 1)),
            'slug' => $this->manifestRecordString($record, 'slug', ''),
            'postStatus' => $this->manifestRecordString($record, 'postStatus', $this->manifestRecordString($record, 'post_status', 'draft')),
            'replaceExisting' => (bool) ($record['replaceExisting'] ?? $record['replace_existing'] ?? false),
            'documentTree' => $tree,
            'importPlan' => [
                'status' => 'ready',
                'canImport' => true,
                'nativeCoverage' => ['percent' => 100],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, array<string, mixed>> $rollbackBaseline
     * @return array<string, mixed>
     */
    private function importSiteKitPageRecord(
        array $record,
        int $index,
        array &$rollbackBaseline,
        array $designPayload,
        array $stylePayload,
        array $componentPersistence,
        array &$componentInstances
    ): array
    {
        $tree = $this->extractSiteKitDocumentTree($record);
        if ($tree === null) {
            throw new \RuntimeException('Site-kit page record is missing a document tree.');
        }

        $componentized = $this->replaceComponentCandidatesInDocumentTree($tree, $designPayload, $componentPersistence);
        $tree = $componentized['tree'];
        $recordId = $this->manifestRecordString($record, 'id', 'page-' . ($index + 1));
        $this->mergeComponentInstancesSummary($componentInstances, $componentized['summary'], 'pages', $recordId);

        $treeValidation = $this->getStorageAdapter()->validateDocumentTree($tree);
        if (!$treeValidation['valid']) {
            throw new \RuntimeException(
                'Componentized site-kit page tree failed storage validation: '
                . implode(' ', $treeValidation['errors'])
            );
        }

        $payload = $this->siteKitPagePayload($record, $index, $tree);
        $postResult = $this->createOrUpdatePost($payload);
        if (empty($postResult['postId'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal importer error, not rendered directly.
            throw new \RuntimeException((string) ($postResult['message'] ?? 'Failed to create site-kit page.'));
        }

        $postId = (int) $postResult['postId'];
        $rollbackBaseline = array_merge($rollbackBaseline, $this->captureDocumentPostStores($postId, $postResult, true));
        $metaResult = $this->persistDocumentTree($postId, $tree);
        if (empty($metaResult['success'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal importer error, not rendered directly.
            throw new \RuntimeException((string) ($metaResult['message'] ?? 'Failed to persist site-kit page document tree.'));
        }

        $hostStylePayload = $this->payloadWithComponentizedDocumentTree($stylePayload, $tree);
        $hostStylePayload = $this->mergeComponentCssIntoHostPayload($hostStylePayload, $componentPersistence, $componentized);
        $pageStylePersistence = $this->pageStyleRepository->saveForPost($postId, $hostStylePayload);
        $this->refreshRenderCache($postId);

        return [
            'id' => $recordId,
            'postType' => 'page',
            'postId' => $postId,
            'action' => (string) ($postResult['action'] ?? ''),
            'title' => (string) ($postResult['title'] ?? ''),
            'slug' => (string) ($postResult['slug'] ?? ''),
            'treeHash' => (string) ($metaResult['treeHash'] ?? ''),
            'componentInstances' => $componentized['summary'],
            'pageStylePersistence' => $pageStylePersistence,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, array<string, mixed>> $rollbackBaseline
     * @return array<string, mixed>
     */
    private function importSiteKitTemplateRecord(
        OxygenTemplateRepository $repository,
        array $record,
        string $section,
        int $index,
        array &$rollbackBaseline,
        array $designPayload,
        array $stylePayload,
        array $componentPersistence,
        array &$componentInstances
    ): array {
        $componentized = [
            'tree' => null,
            'summary' => $this->emptyComponentInstancesSummary(),
            'replacements' => [],
        ];
        $tree = $this->extractSiteKitDocumentTree($record);
        $recordId = $this->manifestRecordString($record, 'id', $section . '-' . ($index + 1));

        if ($tree !== null) {
            $componentized = $this->replaceComponentCandidatesInDocumentTree($tree, $designPayload, $componentPersistence);
            $record['documentTree'] = $componentized['tree'];
            $this->mergeComponentInstancesSummary($componentInstances, $componentized['summary'], $section, $recordId);

            $treeValidation = $this->getStorageAdapter()->validateDocumentTree($componentized['tree']);
            if (!$treeValidation['valid']) {
                throw new \RuntimeException(
                    'Componentized site-kit template tree failed storage validation: '
                    . implode(' ', $treeValidation['errors'])
                );
            }
        }

        $spec = $repository->manifestRecordToTemplateSpec($record, $section, $index);
        $postId = (int) ($spec['ID'] ?? 0);
        $preWriteBaseline = [];

        if ($postId > 0) {
            $preWriteBaseline[] = $this->capturePostStore($postId, $this->getPostRecord($postId));
            $preWriteBaseline[] = $this->capturePostMetaStore($postId, 'template_document', $this->getOxygenDataMetaKey());
            $preWriteBaseline[] = $this->capturePostMetaStore($postId, 'template_settings', '_oxygen_template_settings');
            $preWriteBaseline[] = $this->capturePostMetaStore($postId, 'page_styles', PageStyleRepository::META_KEY);
        }

        $result = $repository->createOrUpdateTemplate($spec);
        if (empty($result['success'])) {
            $partialPostId = (int) ($result['postId'] ?? 0);
            if ($preWriteBaseline !== []) {
                $rollbackBaseline = array_merge($rollbackBaseline, $preWriteBaseline);
            } elseif ($partialPostId > 0) {
                $rollbackBaseline[] = $this->capturePostStore($partialPostId, null);
            }

            $message = (string) ($result['message'] ?? 'Failed to persist site-kit template.');
            $errors = implode(' ', $this->normalizeMessages($result['errors'] ?? []));
            if ($errors !== '') {
                $message .= ' ' . $errors;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal importer error, not rendered directly.
            throw new \RuntimeException($message);
        }

        $persistedPostId = (int) $result['postId'];
        if ($preWriteBaseline !== []) {
            $rollbackBaseline = array_merge($rollbackBaseline, $preWriteBaseline);
        } else {
            $rollbackBaseline[] = $this->capturePostStore($persistedPostId, null);
            $rollbackBaseline[] = $this->capturePostMetaStore($persistedPostId, 'page_styles', PageStyleRepository::META_KEY);
        }
        $pageStylePersistence = ['saved' => false, 'bytes' => 0, 'hash' => ''];
        if (is_array($componentized['tree'] ?? null)) {
            $hostStylePayload = $this->payloadWithComponentizedDocumentTree($stylePayload, $componentized['tree']);
            $hostStylePayload = $this->mergeComponentCssIntoHostPayload($hostStylePayload, $componentPersistence, $componentized);
            $pageStylePersistence = $this->pageStyleRepository->saveForPost($persistedPostId, $hostStylePayload);
        }

        return [
            'id' => $recordId,
            'postType' => (string) ($result['postType'] ?? $repository->postTypeForManifestSection($section)),
            'postId' => $persistedPostId,
            'action' => (string) ($result['action'] ?? ''),
            'title' => $this->manifestRecordString($record, 'title', ucfirst(rtrim($section, 's')) . ' ' . ($index + 1)),
            'slug' => $this->manifestRecordString($record, 'slug', ''),
            'treeHash' => (string) ($result['treeHash'] ?? ''),
            'settingsHash' => (string) ($result['settingsHash'] ?? ''),
            'operationScope' => $repository->classifyManifestTemplateOperation($record),
            'componentInstances' => $componentized['summary'],
            'pageStylePersistence' => $pageStylePersistence,
        ];
    }

    /**
     * @return array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    private function emptySiteKitObjectReport(): array
    {
        return [
            'pages' => [],
            'templates' => [],
            'headers' => [],
            'footers' => [],
            'parts' => [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function siteKitSelectorPayload(array $manifest): array
    {
        if (is_array($manifest['selectorPayload'] ?? null)) {
            return $manifest['selectorPayload'];
        }

        return [
            'selectors' => is_array($manifest['selectors'] ?? null) ? $manifest['selectors'] : [],
            'collections' => is_array($manifest['collections'] ?? null) ? $manifest['collections'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function siteKitDesignPayload(array $manifest): array
    {
        $designDocument = is_array($manifest['designDocument'] ?? null) ? $manifest['designDocument'] : [];
        $importPlan = is_array($manifest['importPlan'] ?? null) ? $manifest['importPlan'] : [];

        if (is_array($manifest['tokens'] ?? null)) {
            $designDocument['tokens'] = $manifest['tokens'];
        } elseif (is_array($manifest['variables'] ?? null)) {
            $designDocument['tokens'] = $manifest['variables'];
        }

        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (is_array($manifest[$key] ?? null)) {
                $designDocument[$key] = $manifest[$key];
            }
        }

        if (is_array($manifest['oxygenVariables'] ?? null) && !isset($designDocument['tokens'])) {
            $designDocument['tokens'] = $manifest['oxygenVariables'];
        }

        return [
            'designDocument' => $designDocument,
            'importPlan' => $importPlan,
            'overwriteGlobalSettings' => (bool) ($manifest['overwriteGlobalSettings'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function siteKitStylePayload(array $manifest): array
    {
        if (is_array($manifest['styleRouting'] ?? null)) {
            return [
                'styleRouting' => $manifest['styleRouting'],
                'globalCss' => is_string($manifest['globalCss'] ?? null) ? (string) $manifest['globalCss'] : '',
                'pageCss' => is_string($manifest['pageCss'] ?? null) ? (string) $manifest['pageCss'] : '',
                'pageScopedCss' => is_string($manifest['pageScopedCss'] ?? null) ? (string) $manifest['pageScopedCss'] : '',
            ];
        }

        $css = $this->joinCss([
            is_string($manifest['globalCss'] ?? null) ? (string) $manifest['globalCss'] : '',
            is_string($manifest['fallbackCss'] ?? null) ? (string) $manifest['fallbackCss'] : '',
            is_string($manifest['pageCss'] ?? null) ? (string) $manifest['pageCss'] : '',
            is_string($manifest['pageScopedCss'] ?? null) ? (string) $manifest['pageScopedCss'] : '',
        ]);
        $routing = (new StyleRoutingService())->route($css, false);

        return [
            'styleRouting' => $routing,
            'globalCss' => (string) ($routing['globalCss'] ?? ''),
            'pageCss' => (string) ($routing['pageCss'] ?? ''),
            'pageScopedCss' => (string) ($routing['pageScopedCss'] ?? ''),
        ];
    }

    /**
     * @param list<string> $sections
     */
    private function joinCss(array $sections): string
    {
        $css = [];
        foreach ($sections as $section) {
            $section = trim($section);
            if ($section !== '') {
                $css[] = $section;
            }
        }

        return implode("\n\n", $css);
    }

    /**
     * @param array<int, array<string, mixed>> $baseline
     * @param array<string, mixed> $adapterSnapshot
     * @return array<string, mixed>
     */
    private function buildSiteKitRollbackSnapshot(array $baseline, array $adapterSnapshot, string $rollbackId): array
    {
        $stores = [];
        $entries = array_merge($baseline, is_array($adapterSnapshot['stores'] ?? null) ? $adapterSnapshot['stores'] : []);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $current = $this->readSnapshotEntryCurrentValue($entry);
            $entry['newExists'] = $current['exists'];
            $entry['newValue'] = $current['value'];
            if ($this->snapshotEntryWasTouched($entry)) {
                $stores[] = $entry;
            }
        }

        return [
            'version' => 1,
            'atomic' => true,
            'rollbackId' => $rollbackId,
            'capturedAt' => gmdate('c'),
            'adapterSnapshot' => $adapterSnapshot,
            'stores' => $stores,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>} $objects
     * @param array<string, mixed> $selectorPersistence
     * @param array<string, mixed> $globalStylePersistence
     * @param array<string, mixed> $pageStylePersistence
     * @param array<string, mixed> $variablePersistence
     * @param array<string, mixed> $oxygenGlobalSettingsPersistence
     * @param array<string, mixed> $brandLibraryPersistence
     * @param array<string, mixed> $componentPersistence
     * @param array<string, mixed> $componentInstances
     * @param array<string, mixed> $siteConfigurationPersistence
     * @param array<string, mixed> $windPressCacheReset
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    private function buildSiteKitImportReport(
        array $manifest,
        string $rollbackId,
        array $objects,
        array $selectorPersistence,
        array $globalStylePersistence,
        array $pageStylePersistence,
        array $variablePersistence,
        array $oxygenGlobalSettingsPersistence,
        array $brandLibraryPersistence,
        array $componentPersistence,
        array $componentInstances,
        array $siteConfigurationPersistence,
        array $windPressCacheReset,
        array $rollbackSnapshot
    ): array {
        return [
            'version' => 1,
            'kind' => 'site-kit',
            'sourceManifestId' => is_scalar($manifest['id'] ?? null) ? (string) $manifest['id'] : '',
            'importedAt' => gmdate('c'),
            'pluginVersion' => defined('OXY_HTML_CONVERTER_VERSION') ? OXY_HTML_CONVERTER_VERSION : '',
            'rollbackId' => $rollbackId,
            'objects' => $objects,
            'objectCounts' => array_map('count', $objects),
            'sections' => $this->buildSiteKitReportSections($manifest, $objects),
            'unsupportedItems' => $this->normalizeSiteKitList($manifest['unsupportedItems'] ?? $manifest['unsupported'] ?? []),
            'assets' => $this->normalizeSiteKitList($manifest['assets'] ?? []),
            'homepage' => $manifest['homepage'] ?? null,
            'menus' => is_array($manifest['menus'] ?? null) ? $manifest['menus'] : [],
            'selectorPersistence' => $selectorPersistence,
            'globalStylePersistence' => $this->summarizeGlobalStylePersistence($globalStylePersistence),
            'pageStylePersistence' => [
                'saved' => (bool) ($pageStylePersistence['saved'] ?? false),
                'bytes' => (int) ($pageStylePersistence['bytes'] ?? 0),
                'hash' => (string) ($pageStylePersistence['hash'] ?? ''),
            ],
            'variablePersistence' => $this->summarizeVariablePersistence($variablePersistence),
            'oxygenGlobalSettingsPersistence' => [
                'saved' => (bool) ($oxygenGlobalSettingsPersistence['saved'] ?? false),
                'changes' => (int) ($oxygenGlobalSettingsPersistence['changes'] ?? 0),
                'sections' => is_array($oxygenGlobalSettingsPersistence['sections'] ?? null)
                    ? array_values(array_map('strval', $oxygenGlobalSettingsPersistence['sections']))
                    : [],
                'paletteColors' => (int) ($oxygenGlobalSettingsPersistence['paletteColors'] ?? 0),
                'cacheRegenerated' => (bool) ($oxygenGlobalSettingsPersistence['cacheRegenerated'] ?? false),
            ],
            'brandLibraryPersistence' => [
                'saved' => (bool) ($brandLibraryPersistence['saved'] ?? false),
                'tokenChanges' => (int) ($brandLibraryPersistence['tokenChanges'] ?? 0),
                'componentChanges' => (int) ($brandLibraryPersistence['componentChanges'] ?? 0),
            ],
            'componentPersistence' => $this->summarizeComponentPersistence($componentPersistence),
            'componentInstances' => $componentInstances,
            'siteConfigurationPersistence' => [
                'options' => is_array($siteConfigurationPersistence['options'] ?? null) ? $siteConfigurationPersistence['options'] : [],
                'menus' => is_array($siteConfigurationPersistence['menus'] ?? null) ? $siteConfigurationPersistence['menus'] : [],
                'placements' => is_array($siteConfigurationPersistence['placements'] ?? null) ? $siteConfigurationPersistence['placements'] : [],
                'rollback' => is_array($siteConfigurationPersistence['rollback'] ?? null) ? $siteConfigurationPersistence['rollback'] : [],
            ],
            'windPressCacheReset' => [
                'enabled' => (bool) ($windPressCacheReset['enabled'] ?? false),
                'attempted' => (bool) ($windPressCacheReset['attempted'] ?? false),
                'active' => (bool) ($windPressCacheReset['active'] ?? false),
                'cacheFileDeleted' => (bool) ($windPressCacheReset['cacheFileDeleted'] ?? false),
                'objectCacheFlushed' => (bool) ($windPressCacheReset['objectCacheFlushed'] ?? false),
                'reason' => (string) ($windPressCacheReset['reason'] ?? ''),
            ],
            'rollback' => [
                'available' => !empty($rollbackSnapshot['stores']),
                'id' => $rollbackId,
                'snapshot' => $rollbackSnapshot,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $aggregate
     * @param array<string, mixed> $persistence
     * @return array<string, mixed>
     */
    private function mergeSiteKitPageStylePersistence(array $aggregate, array $persistence): array
    {
        if ($persistence === []) {
            return $aggregate;
        }

        $saved = (bool) ($persistence['saved'] ?? false);
        $hashes = is_array($aggregate['hashes'] ?? null) ? $aggregate['hashes'] : [];
        $hash = is_string($persistence['hash'] ?? null) ? trim((string) $persistence['hash']) : '';
        if ($hash !== '') {
            $hashes[] = $hash;
        }

        $aggregate['saved'] = (bool) ($aggregate['saved'] ?? false) || $saved;
        $aggregate['bytes'] = (int) ($aggregate['bytes'] ?? 0) + (int) ($persistence['bytes'] ?? 0);
        $aggregate['savedHosts'] = (int) ($aggregate['savedHosts'] ?? 0) + ($saved ? 1 : 0);
        $aggregate['hashes'] = array_values(array_unique($hashes));
        $aggregate['hash'] = $aggregate['hashes'] !== []
            ? substr(sha1(implode(':', $aggregate['hashes'])), 0, 16)
            : (string) ($aggregate['hash'] ?? '');

        return $aggregate;
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private function normalizeSiteKitList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
                continue;
            }

            if (is_scalar($item)) {
                $normalized[] = ['value' => (string) $item];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function siteKitRollbackId(array $manifest): string
    {
        $sourceId = is_scalar($manifest['id'] ?? null) ? (string) $manifest['id'] : $this->encodeJson($manifest);

        return substr(sha1('oxy-html-converter-site-kit:' . $sourceId . ':' . microtime(true)), 0, 16);
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
     * @param array<string, mixed> $componentPersistence
     * @param array<string, mixed> $componentInstances
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
        array $componentPersistence,
        array $componentInstances,
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
        $manifestSections = $this->buildManifestSections($payload, $postId, $documentTree);

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
                'owner' => (string) ($pageStylePersistence['owner'] ?? 'page'),
                'owners' => is_array($pageStylePersistence['owners'] ?? null) ? array_values(array_map('strval', $pageStylePersistence['owners'])) : [],
                'ownerCounts' => is_array($pageStylePersistence['ownerCounts'] ?? null) ? $pageStylePersistence['ownerCounts'] : [],
                'hasMixedOwners' => (bool) ($pageStylePersistence['hasMixedOwners'] ?? false),
                'cascadeOrder' => (int) ($pageStylePersistence['cascadeOrder'] ?? 0),
                'exportBehavior' => (string) ($pageStylePersistence['exportBehavior'] ?? ''),
                'rollbackStore' => (string) ($pageStylePersistence['rollbackStore'] ?? 'page_styles'),
                'pluginDependency' => is_array($pageStylePersistence['pluginDependency'] ?? null)
                    ? $pageStylePersistence['pluginDependency']
                    : null,
            ],
            'styleRouting' => $this->summarizeStyleRouting($payload),
            'sections' => $manifestSections,
            'variablePersistence' => $this->summarizeVariablePersistence($variablePersistence),
            'componentPersistence' => $this->summarizeComponentPersistence($componentPersistence),
            'componentInstances' => is_array($componentInstances['summary'] ?? null) ? $componentInstances['summary'] : [],
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
                'enabled' => (bool) ($windPressCacheReset['enabled'] ?? false),
                'attempted' => (bool) ($windPressCacheReset['attempted'] ?? false),
                'active' => (bool) ($windPressCacheReset['active'] ?? false),
                'cacheFileDeleted' => (bool) ($windPressCacheReset['cacheFileDeleted'] ?? false),
                'objectCacheFlushed' => (bool) ($windPressCacheReset['objectCacheFlushed'] ?? false),
                'reason' => (string) ($windPressCacheReset['reason'] ?? ''),
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
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $documentTree
     * @return array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    private function buildManifestSections(array $payload, int $postId, array $documentTree): array
    {
        $sourceManifest = $this->sourceManifest($payload);
        $templateSections = (new OxygenTemplateRepository())->normalizeManifestSections($sourceManifest);
        $pages = $this->normalizeManifestPages($sourceManifest, $postId, $documentTree, $payload);

        return [
            'pages' => $pages,
            'templates' => $templateSections['templates'],
            'headers' => $templateSections['headers'],
            'footers' => $templateSections['footers'],
            'parts' => $templateSections['parts'],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>} $objects
     * @return array{pages: list<array<string, mixed>>, templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    private function buildSiteKitReportSections(array $manifest, array $objects): array
    {
        $templateSections = (new OxygenTemplateRepository($this->getStorageAdapter()))->normalizeManifestSections($manifest);
        foreach (['templates', 'headers', 'footers', 'parts'] as $section) {
            foreach ($templateSections[$section] as $index => $record) {
                $object = is_array($objects[$section][$index] ?? null) ? $objects[$section][$index] : [];
                $templateSections[$section][$index]['postId'] = (int) ($object['postId'] ?? $record['postId'] ?? 0);
            }
        }

        return [
            'pages' => $this->normalizeSiteKitReportPages($manifest, $objects['pages']),
            'templates' => $templateSections['templates'],
            'headers' => $templateSections['headers'],
            'footers' => $templateSections['footers'],
            'parts' => $templateSections['parts'],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $objects
     * @return list<array<string, mixed>>
     */
    private function normalizeSiteKitReportPages(array $manifest, array $objects): array
    {
        $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
        $normalized = [];

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $tree = is_array($page['documentTree'] ?? null) ? $page['documentTree'] : null;
            $treeSummary = $this->manifestTreeSummary($tree);
            $object = is_array($objects[$index] ?? null) ? $objects[$index] : [];
            $normalized[] = [
                'id' => $this->manifestRecordString($page, 'id', 'page-' . ((int) $index + 1)),
                'title' => $this->manifestRecordString($page, 'title', 'Page ' . ((int) $index + 1)),
                'slug' => $this->manifestRecordString($page, 'slug', ''),
                'postType' => 'page',
                'postId' => (int) ($object['postId'] ?? $page['postId'] ?? $page['post_id'] ?? 0),
                'hasDocumentTree' => $tree !== null,
                'treeHash' => $treeSummary['treeHash'],
                'nodeCount' => $treeSummary['nodeCount'],
                'elementTypes' => $treeSummary['elementTypes'],
                'semanticTags' => $treeSummary['semanticTags'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sourceManifest(array $payload): array
    {
        foreach (['siteKitManifest', 'importManifest', 'manifest'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $documentTree
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeManifestPages(array $manifest, int $postId, array $documentTree, array $payload): array
    {
        $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
        if ($pages === []) {
            $pages = [[
                'id' => 'imported-page-' . $postId,
                'title' => is_scalar($payload['title'] ?? null) ? (string) $payload['title'] : 'Imported page',
                'slug' => is_scalar($payload['slug'] ?? null) ? (string) $payload['slug'] : '',
                'postId' => $postId,
                'documentTree' => $documentTree,
            ]];
        }

        $normalized = [];
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $tree = is_array($page['documentTree'] ?? null) ? $page['documentTree'] : null;
            if ($tree === null && $index === 0) {
                $tree = $documentTree;
            }

            $treeSummary = $this->manifestTreeSummary($tree);
            $normalized[] = [
                'id' => $this->manifestRecordString($page, 'id', 'page-' . ((int) $index + 1)),
                'title' => $this->manifestRecordString($page, 'title', 'Page ' . ((int) $index + 1)),
                'slug' => $this->manifestRecordString($page, 'slug', ''),
                'postType' => 'page',
                'postId' => (int) ($page['postId'] ?? $page['post_id'] ?? ($index === 0 ? $postId : 0)),
                'hasDocumentTree' => $tree !== null,
                'treeHash' => $treeSummary['treeHash'],
                'nodeCount' => $treeSummary['nodeCount'],
                'elementTypes' => $treeSummary['elementTypes'],
                'semanticTags' => $treeSummary['semanticTags'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $tree
     * @return array{treeHash: string, nodeCount: int, elementTypes: list<string>, semanticTags: list<string>}
     */
    private function manifestTreeSummary(?array $tree): array
    {
        if ($tree === null) {
            return [
                'treeHash' => '',
                'nodeCount' => 0,
                'elementTypes' => [],
                'semanticTags' => [],
            ];
        }

        $encoded = wp_json_encode($tree);
        $summary = [
            'nodeCount' => 0,
            'elementTypes' => [],
            'semanticTags' => [],
        ];
        $this->walkManifestTreeNode($tree['root'] ?? null, $summary);

        return [
            'treeHash' => is_string($encoded) ? sha1($encoded) : '',
            'nodeCount' => $summary['nodeCount'],
            'elementTypes' => array_values(array_unique($summary['elementTypes'])),
            'semanticTags' => array_values(array_unique($summary['semanticTags'])),
        ];
    }

    /**
     * @param mixed $node
     * @param array{nodeCount: int, elementTypes: list<string>, semanticTags: list<string>} $summary
     */
    private function walkManifestTreeNode($node, array &$summary): void
    {
        if (!is_array($node)) {
            return;
        }

        $summary['nodeCount']++;
        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type !== '') {
            $summary['elementTypes'][] = $type;
        }

        $tag = is_string($node['data']['properties']['settings']['advanced']['tag'] ?? null)
            ? (string) $node['data']['properties']['settings']['advanced']['tag']
            : '';
        if ($tag !== '') {
            $summary['semanticTags'][] = $tag;
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            $this->walkManifestTreeNode($child, $summary);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function manifestRecordString(array $record, string $field, string $default): string
    {
        $value = $record[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
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
        $stylesSummary = [];

        foreach ($styles as $style) {
            if (!is_array($style) || !is_scalar($style['id'] ?? null)) {
                continue;
            }

            $id = (string) $style['id'];
            $ids[] = $id;
            $stylesSummary[] = [
                'id' => $id,
                'owner' => is_string($style['owner'] ?? null) ? $style['owner'] : 'global',
                'cascadeOrder' => (int) ($style['cascadeOrder'] ?? 0),
                'exportBehavior' => is_string($style['exportBehavior'] ?? null) ? $style['exportBehavior'] : '',
                'rollbackStore' => is_string($style['rollbackStore'] ?? null) ? $style['rollbackStore'] : 'global_styles',
                'pluginDependency' => is_array($style['pluginDependency'] ?? null) ? $style['pluginDependency'] : null,
            ];
        }

        return [
            'saved' => (bool) ($persistence['saved'] ?? false),
            'changes' => (int) ($persistence['changes'] ?? 0),
            'total' => count($ids),
            'styleIds' => array_values(array_unique($ids)),
            'styles' => $stylesSummary,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summarizeStyleRouting(array $payload): array
    {
        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        $summary = is_array($routing['summary'] ?? null) ? $routing['summary'] : [];
        $routeSummary = [];
        $pluginDependencies = [];

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $type = $this->nonEmptyString($route['type'] ?? null, '');
            $destination = $this->nonEmptyString($route['destination'] ?? null, '');
            if ($type === '' || $destination === '') {
                continue;
            }

            $owner = $this->nonEmptyString($route['owner'] ?? null, $this->styleRouteOwner($type, $destination));
            $pluginDependency = is_array($route['pluginDependency'] ?? null)
                ? $route['pluginDependency']
                : $this->styleRoutePluginDependency($type, $destination);
            if ($pluginDependency !== null) {
                $pluginDependencies[] = $pluginDependency;
            }

            $record = [
                'type' => $type,
                'destination' => $destination,
                'owner' => $owner,
                'cascadeOrder' => (int) ($route['cascadeOrder'] ?? 0),
                'exportBehavior' => $this->nonEmptyString($route['exportBehavior'] ?? null, $this->styleRouteExportBehavior($type, $destination)),
                'rollbackStore' => $this->nonEmptyString($route['rollbackStore'] ?? null, $this->styleRouteRollbackStore($destination)),
                'pluginDependency' => $pluginDependency,
                'hash' => is_string($route['hash'] ?? null) ? $route['hash'] : '',
            ];

            if ($type === 'component_css_host_bridge') {
                $record['componentId'] = (int) ($route['componentId'] ?? 0);
                $record['componentName'] = $this->nonEmptyString($route['componentName'] ?? null, '');
                $record['signature'] = $this->nonEmptyString($route['signature'] ?? null, '');
            }

            $routeSummary[] = $record;
        }

        $ownerCounts = is_array($summary['ownerCounts'] ?? null)
            ? $summary['ownerCounts']
            : $this->styleRouteOwnerCounts($routeSummary);

        return [
            'version' => (int) ($routing['version'] ?? 1),
            'mode' => is_string($routing['mode'] ?? null) ? $routing['mode'] : '',
            'routeCount' => count($routeSummary),
            'routes' => $routeSummary,
            'ownerCounts' => $ownerCounts,
            'pluginDependencies' => $pluginDependencies,
            'hasPluginDependentCss' => $pluginDependencies !== [] || !empty($summary['hasPluginDependentCss']),
        ];
    }

    /**
     * @param mixed $value
     */
    private function nonEmptyString($value, string $fallback): string
    {
        if (!is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function styleRouteOwner(string $type, string $destination): string
    {
        if ($type === 'component_css_host_bridge') {
            return 'component';
        }

        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'runtime_plugin_dependency';
        }

        return $destination === 'global_styles' ? 'global' : 'page';
    }

    private function styleRouteExportBehavior(string $type, string $destination): string
    {
        if ($type === 'component_css_host_bridge') {
            return 'export_with_page_manifest';
        }

        if ($type === 'tailwind_utility_fallback' && $destination === 'page_scoped_styles') {
            return 'requires_runtime_plugin';
        }

        return $destination === 'global_styles' ? 'export_with_global_styles' : 'export_with_page_manifest';
    }

    private function styleRouteRollbackStore(string $destination): string
    {
        return $destination === 'global_styles' ? 'global_styles' : 'page_styles';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function styleRoutePluginDependency(string $type, string $destination): ?array
    {
        if ($type !== 'tailwind_utility_fallback' || $destination !== 'page_scoped_styles') {
            return null;
        }

        return [
            'slug' => 'windpress',
            'name' => 'WindPress',
            'required' => true,
            'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, int>
     */
    private function styleRouteOwnerCounts(array $routes): array
    {
        $counts = [];
        foreach ($routes as $route) {
            $owner = $this->nonEmptyString($route['owner'] ?? null, 'page');
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
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
     * @param array<string, mixed> $componentPersistence
     * @return array<int, array<string, mixed>>
     */
    private function componentRollbackStoresFromPersistence(array $componentPersistence): array
    {
        $stores = [];

        foreach (is_array($componentPersistence['createdBlocks'] ?? null) ? $componentPersistence['createdBlocks'] : [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $postId = (int) ($block['postId'] ?? 0);
            if ($postId > 0) {
                $stores[] = $this->capturePostStore($postId, null, 'component_block');
            }
        }

        foreach (is_array($componentPersistence['updatedBlocks'] ?? null) ? $componentPersistence['updatedBlocks'] : [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $postId = (int) ($block['postId'] ?? 0);
            if ($postId < 1) {
                continue;
            }

            $rollback = is_array($block['rollback'] ?? null) ? $block['rollback'] : [];
            $postStore = is_array($rollback['postStore'] ?? null) ? $rollback['postStore'] : [];
            if ($postStore !== []) {
                $stores[] = array_merge($postStore, [
                    'owner' => 'oxygen-html-converter',
                    'store' => 'component_block',
                    'key' => 'wp_posts:' . (string) $postId,
                ]);
            }

            $stores[] = $this->componentRollbackMetaStore(
                $postId,
                'component_block_document',
                '_oxygen_data',
                '_oxy_html_converter_previous_oxygen_data'
            );
            $stores[] = $this->componentRollbackMetaStore(
                $postId,
                'component_block_settings',
                '_breakdance_block_settings',
                '_oxy_html_converter_previous_breakdance_block_settings'
            );
        }

        return $stores;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyComponentPersistence(): array
    {
        return [
            'success' => true,
            'status' => 200,
            'postType' => OxygenBlockRepository::POST_TYPE,
            'metaKeys' => ['_oxygen_data', '_breakdance_block_settings'],
            'candidates' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'createdBlocks' => [],
            'updatedBlocks' => [],
            'skippedCandidates' => [],
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentRollbackMetaStore(int $postId, string $store, string $key, string $rollbackKey): array
    {
        $oldExists = $this->postMetaExists($postId, $rollbackKey);

        return [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'post_meta',
            'store' => $store,
            'postId' => $postId,
            'key' => $key,
            'oldExists' => $oldExists,
            'oldValue' => $oldExists && function_exists('get_post_meta') ? get_post_meta($postId, $rollbackKey, true) : null,
            'restoreOperation' => $oldExists ? 'update_post_meta' : 'delete_post_meta',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function persistComponentCandidatesFromPayload(array $payload): array
    {
        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        $candidates = is_array($designDocument['componentCandidates'] ?? null)
            ? $designDocument['componentCandidates']
            : [];

        if ($candidates === []) {
            return $this->emptyComponentPersistence();
        }

        $candidateSelection = $this->componentCandidatesSelectedByImportPlan($candidates, $payload);
        $selectedCandidates = $candidateSelection['candidates'];
        $skippedFromPlan = $candidateSelection['skippedCandidates'];

        if ($selectedCandidates === []) {
            $persistence = $this->emptyComponentPersistence();
        } else {
            $persistence = $this->blockRepository->persistComponentCandidates(
                $selectedCandidates,
                $this->componentPersistenceOptions($payload)
            );
        }

        $persistence['candidates'] = count($candidates);
        if ($skippedFromPlan !== []) {
            $persistence['skippedCandidates'] = array_values(array_merge(
                is_array($persistence['skippedCandidates'] ?? null) ? $persistence['skippedCandidates'] : [],
                $skippedFromPlan
            ));
            $persistence['skipped'] = count($persistence['skippedCandidates']);
        }

        return $persistence;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array{candidates: array<int, mixed>, skippedCandidates: list<array<string, mixed>>}
     */
    private function componentCandidatesSelectedByImportPlan(array $candidates, array $payload): array
    {
        $componentPlan = is_array($payload['importPlan']['components'] ?? null)
            ? $payload['importPlan']['components']
            : [];
        if ($componentPlan === []) {
            return [
                'candidates' => $candidates,
                'skippedCandidates' => [],
            ];
        }

        $plansByKey = [];
        $plansBySignature = [];
        foreach ($componentPlan as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $signature = $this->componentPlanString($plan, 'signature');
            if ($signature === '') {
                continue;
            }

            $key = $this->componentCandidatePlanKey($signature, $this->componentPlanString($plan, 'suggestedName'));
            $plansByKey[$key] = $plan;
            if ($this->componentPlanString($plan, 'suggestedName') === '') {
                $plansBySignature[$signature] = $plan;
            }
        }

        $selected = [];
        $skipped = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $signature = $this->componentPlanString($candidate, 'signature');
            $suggestedName = $this->componentPlanString($candidate, 'suggestedName');
            $plan = $plansByKey[$this->componentCandidatePlanKey($signature, $suggestedName)] ?? null;
            if ($plan === null && $suggestedName === '') {
                $plan = $plansBySignature[$signature] ?? null;
            }

            if ($plan === null) {
                $skipped[] = $this->componentSkippedFromImportPlanRecord($candidate, 'component_not_in_import_plan');
                continue;
            }

            $status = $this->componentPlanString($plan, 'status');
            $action = $this->componentPlanString($plan, 'action');
            if ($status === 'skipped' || $action === 'skip_component_candidate' || ($plan['eligible'] ?? true) === false) {
                $skipped[] = $this->componentSkippedFromImportPlanRecord(
                    $candidate,
                    $this->componentPlanString($plan, 'reason') ?: 'component_not_selected_by_import_plan',
                    is_array($plan['reasons'] ?? null) ? $plan['reasons'] : []
                );
                continue;
            }

            $selected[] = $candidate;
        }

        return [
            'candidates' => $selected,
            'skippedCandidates' => $skipped,
        ];
    }

    private function componentCandidatePlanKey(string $signature, string $suggestedName): string
    {
        return $signature . "\0" . $suggestedName;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function componentPlanString(array $record, string $field): string
    {
        return is_scalar($record[$field] ?? null) ? trim((string) $record[$field]) : '';
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, mixed> $reasons
     * @return array<string, mixed>
     */
    private function componentSkippedFromImportPlanRecord(array $candidate, string $reason, array $reasons = []): array
    {
        $normalizedReasons = array_values(array_filter(
            array_map('strval', $reasons),
            static fn (string $value): bool => trim($value) !== ''
        ));
        if ($normalizedReasons === []) {
            $normalizedReasons = [$reason];
        }

        return [
            'suggestedName' => $this->componentPlanString($candidate, 'suggestedName'),
            'signature' => $this->componentPlanString($candidate, 'signature'),
            'occurrences' => (int) ($candidate['occurrences'] ?? $candidate['count'] ?? 0),
            'confidence' => (float) ($candidate['confidence'] ?? 0.0),
            'eligible' => false,
            'reason' => $reason,
            'reasons' => array_values(array_unique($normalizedReasons)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function componentPersistenceOptions(array $payload): array
    {
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        $persistence = is_array($importPlan['persistence']['components'] ?? null)
            ? $importPlan['persistence']['components']
            : [];
        $threshold = is_array($persistence['threshold'] ?? null) ? $persistence['threshold'] : [];
        $options = [];

        foreach ([
            'componentMinOccurrences' => ['componentMinOccurrences', 'component_min_occurrences', 'minOccurrences'],
            'componentMinConfidence' => ['componentMinConfidence', 'component_min_confidence', 'minConfidence'],
            'componentMinEditableProperties' => ['componentMinEditableProperties', 'component_min_editable_properties', 'minEditableProperties'],
        ] as $target => $keys) {
            foreach ([$payload, $importPlan, $persistence, $threshold] as $source) {
                foreach ($keys as $key) {
                    if (isset($source[$key]) && (is_int($source[$key]) || is_float($source[$key]))) {
                        $options[$target] = $source[$key];
                        continue 3;
                    }
                }
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $persistence
     * @return array<string, mixed>
     */
    private function summarizeComponentPersistence(array $persistence): array
    {
        return [
            'success' => (bool) ($persistence['success'] ?? true),
            'postType' => (string) ($persistence['postType'] ?? OxygenBlockRepository::POST_TYPE),
            'metaKeys' => is_array($persistence['metaKeys'] ?? null)
                ? array_values(array_map('strval', $persistence['metaKeys']))
                : ['_oxygen_data', '_breakdance_block_settings'],
            'candidates' => (int) ($persistence['candidates'] ?? 0),
            'created' => (int) ($persistence['created'] ?? 0),
            'updated' => (int) ($persistence['updated'] ?? 0),
            'skipped' => (int) ($persistence['skipped'] ?? 0),
            'createdBlocks' => is_array($persistence['createdBlocks'] ?? null) ? $persistence['createdBlocks'] : [],
            'updatedBlocks' => is_array($persistence['updatedBlocks'] ?? null) ? $persistence['updatedBlocks'] : [],
            'skippedCandidates' => is_array($persistence['skippedCandidates'] ?? null) ? $persistence['skippedCandidates'] : [],
            'errors' => $this->normalizeMessages($persistence['errors'] ?? []),
        ];
    }

    /**
     * @return array{attempted: bool, plans: int, replaced: int, replacements: list<array<string, mixed>>}
     */
    private function emptyComponentInstancesSummary(): array
    {
        return [
            'attempted' => false,
            'plans' => 0,
            'replaced' => 0,
            'replacements' => [],
        ];
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPersistence
     * @return array{tree: array<string, mixed>, summary: array<string, mixed>, replacements: list<array<string, mixed>>}
     */
    private function replaceComponentCandidatesInDocumentTree(
        array $tree,
        array $payload,
        array $componentPersistence
    ): array {
        $plans = $this->componentReplacementPlans($payload, $componentPersistence);
        $replacements = [];

        if ($plans !== [] && is_array($tree['root'] ?? null)) {
            $tree['root'] = $this->replaceComponentNodesInTree($tree['root'], $plans, $replacements);
        }

        return [
            'tree' => $tree,
            'summary' => [
                'attempted' => $plans !== [],
                'plans' => count($plans),
                'replaced' => count($replacements),
                'replacements' => $replacements,
            ],
            'replacements' => $replacements,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPersistence
     * @param array<string, mixed> $componentInstances
     * @return array<string, mixed>
     */
    private function mergeComponentCssIntoHostPayload(
        array $payload,
        array $componentPersistence,
        array $componentInstances
    ): array {
        $records = $this->componentCssRecordsForHostReplacements(
            $componentPersistence,
            is_array($componentInstances['replacements'] ?? null) ? $componentInstances['replacements'] : []
        );

        if ($records === []) {
            return $payload;
        }

        $routing = is_array($payload['styleRouting'] ?? null)
            ? $payload['styleRouting']
            : $this->styleRoutingFromLoosePayload($payload);
        $routing = (new StyleRoutingService())->mergeComponentCssIntoHostRouting($routing, $records);

        $payload['styleRouting'] = $routing;
        $payload['globalCss'] = (string) ($routing['globalCss'] ?? '');
        $payload['pageCss'] = (string) ($routing['pageCss'] ?? '');
        $payload['pageScopedCss'] = (string) ($routing['pageScopedCss'] ?? '');

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $documentTree
     * @return array<string, mixed>
     */
    private function payloadWithComponentizedDocumentTree(array $payload, array $documentTree): array
    {
        $payload['documentTree'] = $documentTree;

        if (array_key_exists('element', $payload)) {
            $payload['element'] = is_array($documentTree['root'] ?? null) ? $documentTree['root'] : $payload['element'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function styleRoutingFromLoosePayload(array $payload): array
    {
        $css = $this->joinCss([
            is_string($payload['globalCss'] ?? null) ? (string) $payload['globalCss'] : '',
            is_string($payload['fallbackCss'] ?? null) ? (string) $payload['fallbackCss'] : '',
            is_string($payload['pageCss'] ?? null) ? (string) $payload['pageCss'] : '',
            is_string($payload['pageScopedCss'] ?? null) ? (string) $payload['pageScopedCss'] : '',
        ]);

        return (new StyleRoutingService())->route($css, false);
    }

    /**
     * @param array<string, mixed> $componentPersistence
     * @param list<array<string, mixed>> $replacements
     * @return list<array<string, mixed>>
     */
    private function componentCssRecordsForHostReplacements(array $componentPersistence, array $replacements): array
    {
        $usedBySignature = [];
        foreach ($replacements as $replacement) {
            if (!is_array($replacement)) {
                continue;
            }

            $signature = is_string($replacement['signature'] ?? null) ? trim((string) $replacement['signature']) : '';
            $componentId = (int) ($replacement['componentId'] ?? 0);
            if ($signature !== '' && $componentId > 0) {
                $usedBySignature[$signature][$componentId] = true;
            }
        }

        if ($usedBySignature === []) {
            return [];
        }

        $records = [];
        foreach (['createdBlocks', 'updatedBlocks'] as $field) {
            foreach (is_array($componentPersistence[$field] ?? null) ? $componentPersistence[$field] : [] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $signature = is_string($block['signature'] ?? null) ? trim((string) $block['signature']) : '';
                $componentId = (int) ($block['postId'] ?? 0);
                if ($signature === '' || $componentId < 1 || !isset($usedBySignature[$signature][$componentId])) {
                    continue;
                }

                foreach (is_array($block['componentCss'] ?? null) ? $block['componentCss'] : [] as $componentCss) {
                    if (!is_array($componentCss)) {
                        continue;
                    }

                    $componentCss['componentId'] = $componentId;
                    if (!is_string($componentCss['componentName'] ?? null) || trim((string) $componentCss['componentName']) === '') {
                        $componentCss['componentName'] = is_string($block['suggestedName'] ?? null)
                            ? (string) $block['suggestedName']
                            : '';
                    }
                    $componentCss['signature'] = $signature;
                    $records[] = $componentCss;
                }
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $summary
     */
    private function mergeComponentInstancesSummary(
        array &$target,
        array $summary,
        string $section,
        string $recordId
    ): void {
        $target['attempted'] = (bool) ($target['attempted'] ?? false) || (bool) ($summary['attempted'] ?? false);
        $target['plans'] = max((int) ($target['plans'] ?? 0), (int) ($summary['plans'] ?? 0));

        $replacements = is_array($summary['replacements'] ?? null) ? $summary['replacements'] : [];
        foreach ($replacements as $replacement) {
            if (!is_array($replacement)) {
                continue;
            }

            $replacement['section'] = $section;
            $replacement['recordId'] = $recordId;
            $target['replacements'][] = $replacement;
        }

        $target['replaced'] = count(is_array($target['replacements'] ?? null) ? $target['replacements'] : []);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPersistence
     * @return list<array<string, mixed>>
     */
    private function componentReplacementPlans(array $payload, array $componentPersistence): array
    {
        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        $candidates = is_array($designDocument['componentCandidates'] ?? null)
            ? $designDocument['componentCandidates']
            : [];
        $blocksBySignature = [];

        foreach (['createdBlocks', 'updatedBlocks'] as $field) {
            foreach (is_array($componentPersistence[$field] ?? null) ? $componentPersistence[$field] : [] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $signature = is_string($block['signature'] ?? null) ? trim((string) $block['signature']) : '';
                $postId = (int) ($block['postId'] ?? 0);
                if ($signature !== '' && $postId > 0) {
                    $blocksBySignature[$signature][] = [
                        'postId' => $postId,
                        'suggestedName' => is_string($block['suggestedName'] ?? null) ? trim((string) $block['suggestedName']) : '',
                        'classes' => is_array($block['classes'] ?? null)
                            ? array_values(array_unique(array_map('strval', $block['classes'])))
                            : [],
                        'componentProperties' => is_array($block['componentProperties'] ?? null) ? $block['componentProperties'] : [],
                    ];
                }
            }
        }

        if ($blocksBySignature === []) {
            return [];
        }

        $plans = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $signature = is_string($candidate['signature'] ?? null) ? trim((string) $candidate['signature']) : '';
            if ($signature === '' || !isset($blocksBySignature[$signature])) {
                continue;
            }

            $block = $this->componentBlockForCandidate($candidate, $blocksBySignature[$signature]);
            if ($block === null) {
                continue;
            }

            $componentProperties = is_array($candidate['componentProperties'] ?? null)
                ? $candidate['componentProperties']
                : $block['componentProperties'];
            $componentTargets = is_array($candidate['componentTargets'] ?? null)
                ? $candidate['componentTargets']
                : [];
            if ($componentTargets === [] && is_array($componentProperties['targets'] ?? null)) {
                $componentTargets = $componentProperties['targets'];
            }
            $componentOverrideProperties = is_array($componentProperties['properties'] ?? null)
                ? $componentProperties['properties']
                : $componentProperties;
            unset($componentOverrideProperties['targets']);

            $plans[] = [
                'signature' => $signature,
                'componentId' => (int) $block['postId'],
                'suggestedName' => is_string($candidate['suggestedName'] ?? null) ? (string) $candidate['suggestedName'] : '',
                'classes' => is_array($candidate['classes'] ?? null)
                    ? array_values(array_unique(array_map('strval', $candidate['classes'])))
                    : [],
                'targets' => $componentTargets,
                'properties' => $componentOverrideProperties,
                'targetPaths' => $this->componentTargetPathsForCandidate($candidate, $componentTargets),
            ];
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function componentBlockForCandidate(array $candidate, array $blocks): ?array
    {
        $candidateName = is_string($candidate['suggestedName'] ?? null) ? trim((string) $candidate['suggestedName']) : '';
        $candidateClasses = is_array($candidate['classes'] ?? null)
            ? array_values(array_unique(array_map('strval', $candidate['classes'])))
            : [];

        foreach ($blocks as $block) {
            $blockName = is_string($block['suggestedName'] ?? null) ? trim((string) $block['suggestedName']) : '';
            if ($candidateName !== '' && $blockName === $candidateName) {
                return $block;
            }
        }

        foreach ($blocks as $block) {
            $blockClasses = is_array($block['classes'] ?? null) ? array_values(array_map('strval', $block['classes'])) : [];
            if ($candidateClasses !== [] && array_intersect($candidateClasses, $blockClasses) !== []) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, mixed> $targets
     * @return array<string, list<int>>
     */
    private function componentTargetPathsForCandidate(array $candidate, array $targets): array
    {
        $tree = is_array($candidate['documentTree'] ?? null) ? $candidate['documentTree'] : [];
        $rootChildren = is_array($tree['root']['children'] ?? null) ? $tree['root']['children'] : [];
        $componentRoot = is_array($rootChildren[0] ?? null) ? $rootChildren[0] : null;
        if ($componentRoot === null) {
            return [];
        }

        $paths = [];
        $errors = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $nodeId = $target['nodeId'] ?? null;
            $propertyKey = is_string($target['propertyKey'] ?? null) ? trim((string) $target['propertyKey']) : '';
            $controlPath = is_string($target['controlPath'] ?? null) ? trim((string) $target['controlPath']) : '';
            if (!is_int($nodeId) || $propertyKey === '' || $controlPath === '') {
                $errors[] = 'Editable component target is missing nodeId, propertyKey, or controlPath.';
                continue;
            }

            $path = $this->pathToTreeNodeId($componentRoot, $nodeId);
            if ($path === null) {
                $errors[] = sprintf(
                    'Editable component target %s nodeId %d must reference a node inside the component candidate tree.',
                    $propertyKey,
                    $nodeId
                );
                continue;
            }

            $targetNode = $this->treeNodeAtPath($componentRoot, $path);
            $value = $targetNode !== null
                ? $this->treeNodePropertyValue($targetNode, $controlPath)
                : ['exists' => false, 'value' => null];
            if (!$value['exists']) {
                $errors[] = sprintf(
                    'Editable component target %s controlPath %s must resolve inside nodeId %d properties.',
                    $propertyKey,
                    $controlPath,
                    $nodeId
                );
                continue;
            }

            $paths[$propertyKey] = $path;
        }

        if ($errors !== []) {
            $name = is_string($candidate['suggestedName'] ?? null) && trim((string) $candidate['suggestedName']) !== ''
                ? trim((string) $candidate['suggestedName'])
                : trim(is_scalar($candidate['signature'] ?? null) ? (string) $candidate['signature'] : 'component');
            throw new \RuntimeException(
                sprintf('Component candidate "%s" has unresolved editable property targets: %s', $name, implode(' ', $errors))
            );
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<int> $path
     * @return list<int>|null
     */
    private function pathToTreeNodeId(array $node, int $nodeId, array $path = []): ?array
    {
        if (($node['id'] ?? null) === $nodeId) {
            return $path;
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childPath = $this->pathToTreeNodeId($child, $nodeId, array_merge($path, [(int) $index]));
            if ($childPath !== null) {
                return $childPath;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $plans
     * @param list<array<string, mixed>> $replacements
     * @return array<string, mixed>
     */
    private function replaceComponentNodesInTree(array $node, array $plans, array &$replacements): array
    {
        $match = $this->matchingComponentReplacementPlan($node, $plans);
        if ($match !== null) {
            $componentNode = $this->buildComponentInstanceNode((int) ($node['id'] ?? 0), $match, $node);
            $replacements[] = [
                'nodeId' => (int) ($node['id'] ?? 0),
                'signature' => (string) $match['signature'],
                'componentId' => (int) $match['componentId'],
                'suggestedName' => (string) ($match['suggestedName'] ?? ''),
            ];

            return $componentNode;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $index => $child) {
                if (is_array($child)) {
                    $node['children'][$index] = $this->replaceComponentNodesInTree($child, $plans, $replacements);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $plans
     * @return array<string, mixed>|null
     */
    private function matchingComponentReplacementPlan(array $node, array $plans): ?array
    {
        if (($node['data']['type'] ?? null) === ElementTypes::COMPONENT) {
            return null;
        }

        $signature = $this->componentSignatureForTreeNode($node);
        if ($signature === '') {
            return null;
        }

        foreach ($plans as $plan) {
            if (!is_array($plan) || (string) ($plan['signature'] ?? '') !== $signature) {
                continue;
            }

            $planClasses = is_array($plan['classes'] ?? null) ? array_map('strval', $plan['classes']) : [];
            if ($planClasses === []) {
                return $plan;
            }

            if (array_intersect($this->classesForTreeNode($node), $planClasses) !== []) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function componentSignatureForTreeNode(array $node): string
    {
        $tag = $this->tagForTreeNode($node);
        if ($tag === '' || $tag === 'root') {
            return '';
        }

        $childTags = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $childTag = $this->tagForTreeNode($child);
                if ($childTag !== '') {
                    $childTags[] = $childTag;
                }
            }
        }

        if ($childTags === []) {
            return '';
        }

        return $tag . '[' . implode(',', $childTags) . ']';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function tagForTreeNode(array $node): string
    {
        foreach ([
            $node['data']['properties']['settings']['advanced']['tag'] ?? null,
            $node['data']['properties']['design']['tag'] ?? null,
        ] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                return strtolower(trim($tag));
            }
        }

        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type === 'root') {
            return 'root';
        }

        return self::COMPONENT_NODE_TYPE_TAGS[$type] ?? '';
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function classesForTreeNode(array $node): array
    {
        $classes = [];
        foreach ([
            $node['data']['properties']['settings']['advanced']['classes'] ?? null,
            $node['data']['properties']['meta']['classes'] ?? null,
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $className) {
                if (is_string($className) && trim($className) !== '') {
                    $classes[] = trim($className);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function buildComponentInstanceNode(int $nodeId, array $plan, array $sourceNode): array
    {
        $properties = is_array($plan['properties'] ?? null) ? $plan['properties'] : [];
        $targetPaths = is_array($plan['targetPaths'] ?? null) ? $plan['targetPaths'] : [];
        foreach (is_array($plan['targets'] ?? null) ? $plan['targets'] : [] as $target) {
            if (!is_array($target)) {
                continue;
            }

            $propertyKey = is_string($target['propertyKey'] ?? null) ? trim((string) $target['propertyKey']) : '';
            $controlPath = is_string($target['controlPath'] ?? null) ? trim((string) $target['controlPath']) : '';
            $path = is_array($targetPaths[$propertyKey] ?? null) ? $targetPaths[$propertyKey] : null;
            if ($propertyKey === '' || $controlPath === '' || $path === null) {
                throw new \RuntimeException(sprintf(
                    'Component instance target %s could not be resolved against the component candidate tree.',
                    $propertyKey !== '' ? $propertyKey : '(missing propertyKey)'
                ));
            }

            $matchedNode = $this->treeNodeAtPath($sourceNode, $path);
            if ($matchedNode === null) {
                throw new \RuntimeException(sprintf(
                    'Component instance target %s could not be resolved against the matched source tree.',
                    $propertyKey
                ));
            }
            $value = $this->treeNodePropertyValue($matchedNode, $controlPath);
            if ($value['exists']) {
                $properties[$propertyKey] = $value['value'];
            }
        }

        $componentNode = [
            'id' => $nodeId,
            'data' => [
                'type' => ElementTypes::COMPONENT,
                'properties' => [
                    'content' => [
                        'content' => [
                            'block' => [
                                'componentId' => (int) $plan['componentId'],
                                'targets' => is_array($plan['targets'] ?? null) ? $plan['targets'] : [],
                                'properties' => $properties,
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];

        $validation = (new OxygenSchemaValidator())->validateComponentInstance($componentNode);
        if (!$validation['valid']) {
            $messages = array_map(
                static fn (array $error): string => (string) $error['message'],
                $validation['errors']
            );
            throw new \RuntimeException('Generated component instance failed validation: ' . implode(' ', $messages));
        }

        return $componentNode;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<int> $path
     * @return array<string, mixed>|null
     */
    private function treeNodeAtPath(array $node, array $path): ?array
    {
        $cursor = $node;
        foreach ($path as $index) {
            if (!is_array($cursor['children'][$index] ?? null)) {
                return null;
            }

            $cursor = $cursor['children'][$index];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{exists: bool, value: mixed}
     */
    private function treeNodePropertyValue(array $node, string $controlPath): array
    {
        $cursor = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];
        foreach (explode('.', $controlPath) as $segment) {
            if ($segment === '' || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return [
                    'exists' => false,
                    'value' => null,
                ];
            }

            $cursor = $cursor[$segment];
        }

        return [
            'exists' => true,
            'value' => $cursor,
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
     * @return array<string, mixed>
     */
    private function emptyWindPressCacheResetResult(): array
    {
        return [
            'enabled' => false,
            'attempted' => false,
            'active' => false,
            'cacheFileDeleted' => false,
            'objectCacheFlushed' => false,
            'path' => '',
            'reason' => 'not_requested',
            'errors' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captureRollbackStores(int $postId, array $postResult): array
    {
        $stores = $this->captureDocumentPostStores($postId, $postResult, true);

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
     * @param array<string, mixed> $postResult
     * @return array<int, array<string, mixed>>
     */
    private function captureDocumentPostStores(int $postId, array $postResult, bool $includePageStyles): array
    {
        $previousPost = is_array($postResult['previousPost'] ?? null) ? $postResult['previousPost'] : null;
        $stores = [
            $this->capturePostStore($postId, $previousPost),
        ];

        $metaStores = [
            ['store' => 'page_document', 'key' => $this->getOxygenDataMetaKey()],
            ['store' => 'rollback_meta', 'key' => self::ROLLBACK_META_KEY],
            ['store' => 'import_manifest', 'key' => self::MANIFEST_META_KEY],
        ];

        if ($includePageStyles) {
            $metaStores[] = ['store' => 'page_styles', 'key' => PageStyleRepository::META_KEY];
        }

        foreach ($metaStores as $store) {
            $stores[] = $this->capturePostMetaStore($postId, $store['store'], $store['key']);
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
    private function capturePostStore(int $postId, ?array $previousPost, string $store = 'target_post'): array
    {
        $oldExists = $previousPost !== null;

        return [
            'owner' => 'oxygen-html-converter',
            'storeType' => 'post',
            'store' => $store,
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

        $skipCliCacheRefresh = PHP_SAPI === 'cli';
        if ($skipCliCacheRefresh && function_exists('apply_filters')) {
            $skipCliCacheRefresh = (bool) apply_filters('oxy_html_converter_skip_cli_cache_refresh', true, $postId);
        }

        if ($skipCliCacheRefresh) {
            return;
        }

        $cacheGenerator = function_exists('apply_filters')
            ? (string) apply_filters('oxy_html_converter_cache_generator', '\Breakdance\Render\generateCacheForPost')
            : '\Breakdance\Render\generateCacheForPost';
        if (is_callable($cacheGenerator)) {
            try {
                $cacheGenerator($postId);
            } catch (\Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('Oxygen HTML Converter cache refresh failed for post ' . $postId . ': ' . $e->getMessage());
                }
            }
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
