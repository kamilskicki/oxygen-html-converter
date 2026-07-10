<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\BatchConvertRequestHandler;
use OxyHtmlConverter\Services\BrandLibraryRepository;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\ConvertRequestHandler;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\OxygenSelectorRepository;
use OxyHtmlConverter\Services\PreviewRequestHandler;
use OxyHtmlConverter\Services\PreviewSummaryBuilder;
use OxyHtmlConverter\Services\RequestOptions;
use OxyHtmlConverter\Services\TreeBuilderFactory;
use OxyHtmlConverter\Validation\OutputValidator;

/**
 * Handles AJAX endpoints for HTML conversion
 */
class Ajax
{
    private RequestOptions $requestOptions;
    private ConvertRequestHandler $convertHandler;
    private PreviewRequestHandler $previewHandler;
    private BatchConvertRequestHandler $batchHandler;
    private OxygenSelectorRepository $selectorRepository;
    private OxygenPageImporter $pageImporter;
    private BrandLibraryRepository $brandLibraryRepository;

    private const SELECTOR_JSON_MAX_BYTES = 524288;
    private const SELECTOR_JSON_MAX_DEPTH = 32;
    private const SELECTOR_MAX_RECORDS = 1000;
    private const SELECTOR_MAX_COLLECTIONS = 100;
    private const SELECTOR_MAX_STRING_BYTES = 256;
    private const SELECTOR_MAX_PROPERTY_ITEMS = 10000;
    private const SELECTOR_MAX_PROPERTY_KEY_BYTES = 128;
    private const SELECTOR_MAX_PROPERTY_VALUE_BYTES = 4096;

    private const IMPORT_JSON_MAX_BYTES = 2097152;
    private const IMPORT_JSON_MAX_DEPTH = 64;
    private const IMPORT_TREE_MAX_NODES = 5000;
    private const IMPORT_TREE_MAX_DEPTH = 80;
    private const IMPORT_TREE_MAX_CHILDREN = 500;
    private const IMPORT_CSS_MAX_BYTES = 262144;
    private const IMPORT_MAX_TOTAL_ITEMS = 50000;

    private const BRAND_JSON_MAX_BYTES = 524288;
    private const BRAND_JSON_MAX_DEPTH = 32;
    private const BRAND_MAX_COLOR_TOKENS = 512;
    private const BRAND_MAX_TYPOGRAPHY_TOKENS = 256;
    private const BRAND_MAX_COMPONENTS = 500;
    private const BRAND_MAX_STRING_BYTES = 4096;
    private const BRAND_MAX_TOTAL_ITEMS = 10000;
    private const JSON_LIMIT_STATUS = 413;

    /**
     * Capability required to use converter endpoints.
     */
    private function getRequiredCapability(): string
    {
        $capability = 'manage_options';

        /**
         * Filter required capability for converter AJAX endpoints.
         *
         * @param string $capability Default capability (`manage_options`).
         */
        return (string) apply_filters('oxy_html_converter_required_capability', $capability);
    }

    /**
     * Should detailed exception messages be exposed to clients.
     */
    private function shouldExposeDetailedErrors(): bool
    {
        $default = defined('WP_DEBUG') && WP_DEBUG;

        /**
         * Filter whether detailed server error messages should be returned to clients.
         *
         * @param bool $default Defaults to true only when WP_DEBUG is enabled.
         */
        return (bool) apply_filters('oxy_html_converter_expose_error_details', $default);
    }

    /**
     * Build a client-safe error message.
     */
    private function getClientErrorMessage(string $defaultMessage, \Throwable $e): string
    {
        if ($this->shouldExposeDetailedErrors()) {
            return $defaultMessage . ': ' . $e->getMessage();
        }

        return $defaultMessage;
    }

    private function currentUserCanMutateGlobalDesign(): bool
    {
        return current_user_can($this->getRequiredCapability()) && current_user_can('edit_theme_options');
    }

    /**
     * @return array{success: true, payload: array<string, mixed>}|array{success: false, status: int, message: string}
     */
    private function decodeBoundedJsonPayload($rawPayload, string $label, int $maxBytes, int $maxDepth): array
    {
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            return [
                'success' => false,
                'status' => 400,
                'message' => sprintf(
                /* translators: %s: payload label. */
                __('%s is required.', 'oxygen-html-converter'),
                $label
            ),
            ];
        }

        if (strlen($rawPayload) > $maxBytes) {
            return [
                'success' => false,
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                /* translators: 1: payload label, 2: maximum size. */
                __('%1$s is too large. Maximum %2$s allowed.', 'oxygen-html-converter'),
                $label,
                $this->formatBytes($maxBytes)
            ),
            ];
        }

        try {
            $payload = json_decode($rawPayload, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ((int) $e->getCode() === JSON_ERROR_DEPTH) {
                return [
                    'success' => false,
                    'status' => self::JSON_LIMIT_STATUS,
                    'message' => sprintf(
                        /* translators: 1: payload label, 2: maximum JSON depth. */
                        __('%1$s exceeds maximum JSON depth of %2$d.', 'oxygen-html-converter'),
                        $label,
                        $maxDepth
                    ),
                ];
            }

            return [
                'success' => false,
                'status' => 400,
                'message' => sprintf(
                /* translators: %s: payload label. */
                __('Invalid %s JSON.', 'oxygen-html-converter'),
                strtolower($label)
            ),
            ];
        }

        if (!is_array($payload)) {
            return [
                'success' => false,
                'status' => 400,
                'message' => sprintf(
                /* translators: %s: payload label. */
                __('Invalid %s shape.', 'oxygen-html-converter'),
                strtolower($label)
            ),
            ];
        }

        return [
            'success' => true,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function validateSelectorPayloadLimits(array $payload, string $label = ''): ?array
    {
        $label = $label !== '' ? $label : __('Selector payload', 'oxygen-html-converter');
        $selectors = is_array($payload['selectors'] ?? null) ? $payload['selectors'] : [];
        if (count($selectors) > self::SELECTOR_MAX_RECORDS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: 1: payload label, 2: maximum selector count. */
                    __('%1$s contains too many selectors. Maximum %2$d allowed.', 'oxygen-html-converter'),
                    $label,
                    self::SELECTOR_MAX_RECORDS
                ),
            ];
        }

        $collections = is_array($payload['collections'] ?? null) ? $payload['collections'] : [];
        if (count($collections) > self::SELECTOR_MAX_COLLECTIONS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: 1: payload label, 2: maximum collection count. */
                    __('%1$s contains too many collections. Maximum %2$d allowed.', 'oxygen-html-converter'),
                    $label,
                    self::SELECTOR_MAX_COLLECTIONS
                ),
            ];
        }

        $propertiesItemCount = 0;
        foreach ($selectors as $index => $selector) {
            if (!is_array($selector)) {
                continue;
            }

            foreach (['id', 'name', 'collection'] as $field) {
                if (is_string($selector[$field] ?? null) && strlen((string) $selector[$field]) > self::SELECTOR_MAX_STRING_BYTES) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => sprintf(
                            /* translators: 1: payload label, 2: selector field name, 3: selector index. */
                            __('%1$s selector %2$s is too long at index %3$d.', 'oxygen-html-converter'),
                            $label,
                            $field,
                            (int) $index
                        ),
                    ];
                }
            }

            if (is_array($selector['properties'] ?? null)) {
                $shapeError = $this->validateNestedShapeLimits(
                    $selector['properties'],
                    self::SELECTOR_MAX_PROPERTY_ITEMS - $propertiesItemCount,
                    self::SELECTOR_MAX_PROPERTY_VALUE_BYTES,
                    self::SELECTOR_MAX_PROPERTY_KEY_BYTES,
                    /* translators: %s: payload label. */
                    sprintf(__('%s selector properties', 'oxygen-html-converter'), $label)
                );
                if ($shapeError !== null) {
                    return $shapeError;
                }

                $propertiesItemCount += $this->countNestedItems($selector['properties'], self::SELECTOR_MAX_PROPERTY_ITEMS);
                if ($propertiesItemCount > self::SELECTOR_MAX_PROPERTY_ITEMS) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => sprintf(
                            /* translators: 1: payload label, 2: maximum nested property count. */
                            __('%1$s selector properties contain too many nested items. Maximum %2$d allowed.', 'oxygen-html-converter'),
                            $label,
                            self::SELECTOR_MAX_PROPERTY_ITEMS
                        ),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function validateImportPayloadLimits(array $payload): ?array
    {
        $cssPayloads = $this->extractImportCssPayloads($payload);
        if (isset($payload['siteKitManifest']) && is_array($payload['siteKitManifest'])) {
            foreach ($this->extractImportCssPayloads($payload['siteKitManifest']) as $label => $css) {
                /* translators: %s: CSS payload label. */
                $cssPayloads[sprintf(__('Site-kit %s', 'oxygen-html-converter'), lcfirst($label))] = $css;
            }
        }

        foreach ($cssPayloads as $label => $css) {
            if (strlen($css) > self::IMPORT_CSS_MAX_BYTES) {
                return [
                    'status' => self::JSON_LIMIT_STATUS,
                    'message' => sprintf(
                    /* translators: 1: payload label, 2: maximum size. */
                    __('%1$s is too large. Maximum %2$s allowed.', 'oxygen-html-converter'),
                    $label,
                    $this->formatBytes(self::IMPORT_CSS_MAX_BYTES)
                ),
                ];
            }
        }

        $shapeError = $this->validateNestedShapeLimits(
            $payload,
            self::IMPORT_MAX_TOTAL_ITEMS,
            self::IMPORT_CSS_MAX_BYTES,
            256,
            __('Import payload', 'oxygen-html-converter')
        );
        if ($shapeError !== null) {
            return $shapeError;
        }

        if (is_array($payload['selectorPayload'] ?? null)) {
            $selectorError = $this->validateSelectorPayloadLimits($payload['selectorPayload'], __('Import selector payload', 'oxygen-html-converter'));
            if ($selectorError !== null) {
                return $selectorError;
            }
        }

        foreach ($this->siteKitSelectorPayloadsForLimits($payload) as $selectorPayload) {
            $selectorError = $this->validateSelectorPayloadLimits($selectorPayload, __('Site-kit selector payload', 'oxygen-html-converter'));
            if ($selectorError !== null) {
                return $selectorError;
            }
        }

        $treeRoot = $this->resolveImportTreeRoot($payload);
        if ($treeRoot !== null) {
            $nodeCount = 0;
            $treeError = $this->validateDocumentTreeLimits($treeRoot, 1, $nodeCount);
            if ($treeError !== null) {
                return $treeError;
            }
        }

        foreach ($this->siteKitDocumentTreeRoots($payload) as $treeRoot) {
            $nodeCount = 0;
            $treeError = $this->validateDocumentTreeLimits($treeRoot, 1, $nodeCount);
            if ($treeError !== null) {
                return $treeError;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractImportCssPayloads(array $payload): array
    {
        $cssPayloads = [];

        foreach ([
            'globalCss' => __('Global CSS payload', 'oxygen-html-converter'),
            'fallbackCss' => __('Fallback CSS payload', 'oxygen-html-converter'),
            'pageCss' => __('Page CSS payload', 'oxygen-html-converter'),
            'pageScopedCss' => __('Page scoped CSS payload', 'oxygen-html-converter'),
        ] as $field => $label) {
            if (is_string($payload[$field] ?? null)) {
                $cssPayloads[$label] = (string) $payload[$field];
            }
        }

        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        foreach ([
            'globalCss' => __('Global routed CSS payload', 'oxygen-html-converter'),
            'pageCss' => __('Page routed CSS payload', 'oxygen-html-converter'),
            'pageScopedCss' => __('Page scoped routed CSS payload', 'oxygen-html-converter'),
        ] as $field => $label) {
            if (is_string($routing[$field] ?? null)) {
                $cssPayloads[$label] = (string) $routing[$field];
            }
        }

        return $cssPayloads;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveImportTreeRoot(array $payload): ?array
    {
        if (isset($payload['documentTree']) && is_array($payload['documentTree'])) {
            $documentTree = $payload['documentTree'];
            if (isset($documentTree['root']) && is_array($documentTree['root'])) {
                return $documentTree['root'];
            }

            return $documentTree;
        }

        if (isset($payload['element']) && is_array($payload['element'])) {
            return $payload['element'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{status: int, message: string}|null
     */
    private function validateDocumentTreeLimits(array $node, int $depth, int &$nodeCount): ?array
    {
        if ($depth > self::IMPORT_TREE_MAX_DEPTH) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum tree depth. */
                    __('Import document tree exceeds maximum depth of %d.', 'oxygen-html-converter'),
                    self::IMPORT_TREE_MAX_DEPTH
                ),
            ];
        }

        $nodeCount++;
        if ($nodeCount > self::IMPORT_TREE_MAX_NODES) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum node count. */
                    __('Import document tree contains too many nodes. Maximum %d allowed.', 'oxygen-html-converter'),
                    self::IMPORT_TREE_MAX_NODES
                ),
            ];
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if (count($children) > self::IMPORT_TREE_MAX_CHILDREN) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum child node count. */
                    __('Import document tree node contains too many children. Maximum %d allowed.', 'oxygen-html-converter'),
                    self::IMPORT_TREE_MAX_CHILDREN
                ),
            ];
        }

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $error = $this->validateDocumentTreeLimits($child, $depth + 1, $nodeCount);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function validateBrandLibraryPayloadLimits(array $payload): ?array
    {
        $shapeError = $this->validateNestedShapeLimits(
            $payload,
            self::BRAND_MAX_TOTAL_ITEMS,
            self::BRAND_MAX_STRING_BYTES,
            256,
            __('Brand library payload', 'oxygen-html-converter')
        );
        if ($shapeError !== null) {
            return $shapeError;
        }

        $colorCount = max(
            $this->countArrayAtPath($payload, ['designDocument', 'tokens', 'colors']),
            $this->countArrayAtPath($payload, ['importPlan', 'tokens', 'colors'])
        );
        if ($colorCount > self::BRAND_MAX_COLOR_TOKENS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum color token count. */
                    __('Brand library payload contains too many color tokens. Maximum %d allowed.', 'oxygen-html-converter'),
                    self::BRAND_MAX_COLOR_TOKENS
                ),
            ];
        }

        $typographyCount = max(
            $this->countArrayAtPath($payload, ['designDocument', 'tokens', 'fonts']),
            $this->countArrayAtPath($payload, ['importPlan', 'tokens', 'fonts'])
        );
        if ($typographyCount > self::BRAND_MAX_TYPOGRAPHY_TOKENS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum typography token count. */
                    __('Brand library payload contains too many typography tokens. Maximum %d allowed.', 'oxygen-html-converter'),
                    self::BRAND_MAX_TYPOGRAPHY_TOKENS
                ),
            ];
        }

        $componentCount = max(
            $this->countArrayAtPath($payload, ['designDocument', 'componentCandidates']),
            $this->countArrayAtPath($payload, ['importPlan', 'components'])
        );
        if ($componentCount > self::BRAND_MAX_COMPONENTS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => sprintf(
                    /* translators: %d: maximum component count. */
                    __('Brand library payload contains too many components. Maximum %d allowed.', 'oxygen-html-converter'),
                    self::BRAND_MAX_COMPONENTS
                ),
            ];
        }

        return null;
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function validateNestedShapeLimits(
        $value,
        int $maxItems,
        int $maxStringBytes,
        int $maxKeyBytes,
        string $label
    ): ?array {
        if (!is_array($value)) {
            return null;
        }

        $itemCount = 0;
        $stack = [$value];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $child) {
                $itemCount++;
                if ($itemCount > $maxItems) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => sprintf(
                            /* translators: 1: payload label, 2: maximum nested item count. */
                            __('%1$s contains too many nested items. Maximum %2$d allowed.', 'oxygen-html-converter'),
                            $label,
                            $maxItems
                        ),
                    ];
                }

                if (is_string($key) && strlen($key) > $maxKeyBytes) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => sprintf(
                            /* translators: 1: payload label, 2: maximum key length in bytes. */
                            __('%1$s contains a key longer than %2$d bytes.', 'oxygen-html-converter'),
                            $label,
                            $maxKeyBytes
                        ),
                    ];
                }

                if (is_string($child) && strlen($child) > $maxStringBytes) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => sprintf(
                            /* translators: 1: payload label, 2: maximum string size. */
                            __('%1$s contains a string longer than %2$s.', 'oxygen-html-converter'),
                            $label,
                            $this->formatBytes($maxStringBytes)
                        ),
                    ];
                }

                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return null;
    }

    private function countNestedItems($value, int $limit): int
    {
        if (!is_array($value)) {
            return 0;
        }

        $count = 0;
        $stack = [$value];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $child) {
                $count++;
                if ($count > $limit) {
                    return $count;
                }

                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<int, string> $path
     */
    private function countArrayAtPath(array $payload, array $path): int
    {
        $current = $payload;

        foreach ($path as $key) {
            if (!is_array($current) || !isset($current[$key]) || !is_array($current[$key])) {
                return 0;
            }

            $current = $current[$key];
        }

        return count($current);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes % 1048576 === 0) {
            return (int) ($bytes / 1048576) . ' MiB';
        }

        if ($bytes % 1024 === 0) {
            return (int) ($bytes / 1024) . ' KiB';
        }

        return $bytes . ' bytes';
    }

    public function __construct(
        ?RequestOptions $requestOptions = null,
        ?ConversionAuditBuilder $auditBuilder = null,
        ?OutputValidator $outputValidator = null,
        ?TreeBuilderFactory $treeBuilderFactory = null,
        ?ConvertRequestHandler $convertHandler = null,
        ?PreviewRequestHandler $previewHandler = null,
        ?BatchConvertRequestHandler $batchHandler = null,
        ?OxygenSelectorRepository $selectorRepository = null,
        ?OxygenPageImporter $pageImporter = null,
        ?BrandLibraryRepository $brandLibraryRepository = null
    )
    {
        $this->requestOptions = $requestOptions ?: new RequestOptions();
        $auditBuilder = $auditBuilder ?: new ConversionAuditBuilder();
        $outputValidator = $outputValidator ?: new OutputValidator();
        $treeBuilderFactory = $treeBuilderFactory ?: new TreeBuilderFactory();
        $this->selectorRepository = $selectorRepository ?: new OxygenSelectorRepository();
        $this->pageImporter = $pageImporter ?: new OxygenPageImporter(new OxygenDocumentTree(), $this->selectorRepository);
        $this->brandLibraryRepository = $brandLibraryRepository ?: new BrandLibraryRepository();

        $this->convertHandler = $convertHandler ?: new ConvertRequestHandler(
            $treeBuilderFactory,
            new ConvertPayloadBuilder(new OxygenDocumentTree(), $auditBuilder, $outputValidator)
        );
        $this->previewHandler = $previewHandler ?: new PreviewRequestHandler(
            $treeBuilderFactory,
            new PreviewSummaryBuilder(),
            $auditBuilder
        );
        $this->batchHandler = $batchHandler ?: new BatchConvertRequestHandler(
            $treeBuilderFactory,
            new OxygenDocumentTree(),
            $auditBuilder,
            $outputValidator
        );

        add_action('wp_ajax_oxy_html_convert', [$this, 'handleConvert']);
        add_action('wp_ajax_oxy_html_convert_preview', [$this, 'handlePreview']);
        add_action('wp_ajax_oxy_html_convert_batch', [$this, 'handleBatchConvert']);
        add_action('wp_ajax_oxy_html_save_selectors', [$this, 'handleSaveSelectors']);
        add_action('wp_ajax_oxy_html_import_page', [$this, 'handleImportPage']);
        add_action('wp_ajax_oxy_html_rollback_import', [$this, 'handleRollbackImport']);
        add_action('wp_ajax_oxy_html_save_brand_library', [$this, 'handleSaveBrandLibrary']);
    }

    /**
     * Handle HTML to Oxygen JSON conversion
     */
    public function handleConvert(): void
    {
        // Verify nonce
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // Get HTML input
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML must remain intact for conversion.
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => __('No HTML provided', 'oxygen-html-converter')], 400);
            return;
        }

        // Size limit for single conversion (1MB)
        if (strlen($html) > 1048576) {
            wp_send_json_error([
                'message' => __('HTML content too large. Maximum 1MB allowed for single conversion.', 'oxygen-html-converter'),
            ], 400);
            return;
        }

        // Get options
        $options = $this->requestOptions->normalizeConvert($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_convert_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizeConvert(array_merge($options, $filteredOptions));
        }
        if (!$this->currentUserCanUseExecutableCode($options)) {
            wp_send_json_error([
                'message' => __('Executable code fallback requires unfiltered HTML permission.', 'oxygen-html-converter'),
            ], 403);
            return;
        }

        try {
            $response = $this->convertHandler->handle($html, $options);

            if ($response['success']) {
                wp_send_json_success($response['data']);
                return;
            }

            wp_send_json_error($response['data'], $response['status']);
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_conversion_exception', $e, $options);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Oxygen HTML Converter Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Conversion failed', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * Maximum number of items in a batch
     */
    private const MAX_BATCH_ITEMS = 50;

    /**
     * Maximum size per HTML item in bytes (500KB)
     */
    private const MAX_ITEM_SIZE = 512000;

    /**
     * Maximum total batch size in bytes (5MB)
     */
    private const MAX_BATCH_SIZE = 5242880;

    /**
     * Handle batch HTML to Oxygen JSON conversion
     */
    public function handleBatchConvert(): void
    {
        // Verify nonce
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // Get HTML inputs (expecting an array of HTML strings)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML arrays must remain intact for conversion.
        $rawBatch = isset($_POST['batch']) ? wp_unslash($_POST['batch']) : [];
        if (empty($rawBatch) || !is_array($rawBatch)) {
            wp_send_json_error(['message' => __('No HTML batch provided', 'oxygen-html-converter')], 400);
            return;
        }

        // Safety limits
        if (count($rawBatch) > self::MAX_BATCH_ITEMS) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %d: maximum batch item count. */
                    __('Batch too large. Maximum %d items allowed.', 'oxygen-html-converter'),
                    self::MAX_BATCH_ITEMS
                ),
            ], 400);
            return;
        }

        // Sanitize batch array - ensure all items are strings and within size limits
        $batch = [];
        $totalSize = 0;
        $skipped = 0;

        foreach ($rawBatch as $index => $item) {
            if (!is_string($item)) {
                $skipped++;
                continue;
            }

            $itemSize = strlen($item);

            // Skip items that are too large
            if ($itemSize > self::MAX_ITEM_SIZE) {
                $skipped++;
                continue;
            }

            // Check total batch size
            if ($totalSize + $itemSize > self::MAX_BATCH_SIZE) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %d: maximum batch size in megabytes. */
                        __('Batch total size exceeds limit. Maximum %dMB allowed.', 'oxygen-html-converter'),
                        self::MAX_BATCH_SIZE / 1048576
                    ),
                    'processedItems' => count($batch),
                ], 400);
                return;
            }

            $batch[] = [
                'index' => $index,
                'html' => $item,
            ];
            $totalSize += $itemSize;
        }

        $options = $this->requestOptions->normalizeBatch($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_batch_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizeBatch(array_merge($options, $filteredOptions));
        }
        if (!$this->currentUserCanUseExecutableCode($options)) {
            wp_send_json_error([
                'message' => __('Executable code fallback requires unfiltered HTML permission.', 'oxygen-html-converter'),
            ], 403);
            return;
        }

        try {
            $response = $this->batchHandler->handle($batch, $options, $skipped);
            wp_send_json_success($response['data']);
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_batch_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Batch conversion failed', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * Save generated Oxygen selector records before pasting elements that reference them.
     */
    public function handleSaveSelectors(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        if (!$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON selector payload is validated below.
        $rawPayload = isset($_POST['selectorPayload']) ? wp_unslash($_POST['selectorPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            __('Selector payload', 'oxygen-html-converter'),
            self::SELECTOR_JSON_MAX_BYTES,
            self::SELECTOR_JSON_MAX_DEPTH
        );
        if (empty($decodedPayload['success'])) {
            wp_send_json_error(['message' => $decodedPayload['message']], (int) $decodedPayload['status']);
            return;
        }

        $payload = $decodedPayload['payload'];
        $limitError = $this->validateSelectorPayloadLimits($payload);
        if ($limitError !== null) {
            wp_send_json_error(['message' => $limitError['message']], $limitError['status']);
            return;
        }

        try {
            wp_send_json_success($this->selectorRepository->savePayload($payload));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_save_selectors_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Failed to save selectors', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * Create or update a draft WordPress page with generated Oxygen data.
     */
    public function handleImportPage(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Import payload JSON is validated below.
        $rawPayload = isset($_POST['importPayload']) ? wp_unslash($_POST['importPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            __('Import payload', 'oxygen-html-converter'),
            self::IMPORT_JSON_MAX_BYTES,
            self::IMPORT_JSON_MAX_DEPTH
        );
        if (empty($decodedPayload['success'])) {
            wp_send_json_error(['message' => $decodedPayload['message']], (int) $decodedPayload['status']);
            return;
        }

        $payload = $decodedPayload['payload'];
        $limitError = $this->validateImportPayloadLimits($payload);
        if ($limitError !== null) {
            wp_send_json_error(['message' => $limitError['message']], $limitError['status']);
            return;
        }

        if ($this->importPayloadContainsExecutableCode($payload) && !$this->currentUserCanUseExecutableImport($payload)) {
            wp_send_json_error([
                'message' => __('Executable code import requires explicit approval and unfiltered HTML permission.', 'oxygen-html-converter'),
            ], 403);
            return;
        }

        if ($this->importPayloadMutatesGlobalDesign($payload) && !$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error([
                'message' => __('Permission denied for global design import mutations.', 'oxygen-html-converter'),
            ], 403);
            return;
        }

        try {
            $result = $this->importPayloadIsStandaloneSiteKit($payload)
                ? $this->pageImporter->importSiteKit($this->resolveStandaloneSiteKitManifest($payload))
                : $this->pageImporter->import($payload);

            if (!empty($result['success'])) {
                unset($result['success'], $result['status']);
                wp_send_json_success($result);
                return;
            }

            wp_send_json_error([
                'message' => $result['message'] ?? __('Page import failed.', 'oxygen-html-converter'),
                'errors' => $result['errors'] ?? [],
            ], (int) ($result['status'] ?? 500));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_import_page_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Page import failed', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importPayloadMutatesGlobalDesign(array $payload): bool
    {
        if ($this->importPayloadIsStandaloneSiteKit($payload)) {
            return true;
        }

        $selectorPayload = is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : [];
        if ($this->arrayPathHasItems($selectorPayload, ['selectors']) || $this->arrayPathHasItems($selectorPayload, ['collections'])) {
            return true;
        }

        if ($this->stringPathHasContent($payload, ['globalCss']) || $this->stringPathHasContent($payload, ['styleRouting', 'globalCss'])) {
            return true;
        }

        foreach ([
            ['oxygenGlobalSettings'],
            ['globalSettings'],
            ['designDocument', 'oxygenGlobalSettings'],
            ['designDocument', 'globalSettings'],
            ['designDocument', 'tokens'],
            ['importPlan', 'tokens'],
            ['variables'],
            ['oxygenVariables'],
        ] as $path) {
            if ($this->arrayPathHasItems($payload, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importPayloadIsStandaloneSiteKit(array $payload): bool
    {
        if (isset($payload['documentTree']) || isset($payload['element'])) {
            return false;
        }

        if (isset($payload['siteKitManifest']) && is_array($payload['siteKitManifest'])) {
            return true;
        }

        foreach (['pages', 'templates', 'headers', 'footers', 'parts'] as $section) {
            if (isset($payload[$section]) && is_array($payload[$section])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveStandaloneSiteKitManifest(array $payload): array
    {
        if (isset($payload['siteKitManifest']) && is_array($payload['siteKitManifest'])) {
            return $payload['siteKitManifest'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function arrayPathHasItems(array $payload, array $path): bool
    {
        $current = $payload;

        foreach ($path as $key) {
            if (!is_array($current) || !isset($current[$key]) || !is_array($current[$key])) {
                return false;
            }

            $current = $current[$key];
        }

        return $current !== [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function stringPathHasContent(array $payload, array $path): bool
    {
        $current = $payload;

        foreach ($path as $index => $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }

            if ($index === count($path) - 1) {
                return is_string($current[$key]) && trim($current[$key]) !== '';
            }

            $current = $current[$key];
        }

        return false;
    }

    /**
     * Restore the previous Oxygen data payload saved before an import update.
     */
    public function handleRollbackImport(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        $postId = isset($_POST['postId']) ? (int) $_POST['postId'] : 0;

        try {
            $result = $this->pageImporter->rollback($postId);

            if (!empty($result['success'])) {
                unset($result['success'], $result['status']);
                wp_send_json_success($result);
                return;
            }

            wp_send_json_error([
                'message' => $result['message'] ?? __('Rollback failed.', 'oxygen-html-converter'),
                'errors' => $result['errors'] ?? [],
            ], (int) ($result['status'] ?? 500));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_rollback_import_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Rollback failed', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * Save detected design tokens and reusable component candidates into the plugin brand library.
     */
    public function handleSaveBrandLibrary(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        if (!$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Brand library JSON is normalized below.
        $rawPayload = isset($_POST['brandPayload']) ? wp_unslash($_POST['brandPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            __('Brand library payload', 'oxygen-html-converter'),
            self::BRAND_JSON_MAX_BYTES,
            self::BRAND_JSON_MAX_DEPTH
        );
        if (empty($decodedPayload['success'])) {
            wp_send_json_error(['message' => $decodedPayload['message']], (int) $decodedPayload['status']);
            return;
        }

        $payload = $decodedPayload['payload'];
        $limitError = $this->validateBrandLibraryPayloadLimits($payload);
        if ($limitError !== null) {
            wp_send_json_error(['message' => $limitError['message']], $limitError['status']);
            return;
        }

        try {
            wp_send_json_success($this->brandLibraryRepository->saveFromPayload($payload));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_save_brand_library_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Failed to save brand library', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * Handle preview request (returns simplified structure)
     */
    public function handlePreview(): void
    {
        // Verify nonce
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'oxygen-html-converter')], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => __('Permission denied', 'oxygen-html-converter')], 403);
            return;
        }

        // Get HTML input
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML must remain intact for conversion.
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => __('No HTML provided', 'oxygen-html-converter')], 400);
            return;
        }

        // Size limit for preview (1MB)
        if (strlen($html) > 1048576) {
            wp_send_json_error([
                'message' => __('HTML content too large. Maximum 1MB allowed.', 'oxygen-html-converter'),
            ], 400);
            return;
        }

        $options = $this->requestOptions->normalizePreview($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_preview_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizePreview(array_merge($options, $filteredOptions));
        }
        if (!$this->currentUserCanUseExecutableCode($options)) {
            wp_send_json_error([
                'message' => __('Executable code fallback requires unfiltered HTML permission.', 'oxygen-html-converter'),
            ], 403);
            return;
        }

        try {
            $response = $this->previewHandler->handle($html, $options);

            if ($response['success']) {
                wp_send_json_success($response['data']);
                return;
            }

            wp_send_json_error($response['data'], $response['status']);
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_preview_exception', $e);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Oxygen HTML Converter Preview Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            wp_send_json_error([
                'message' => $this->getClientErrorMessage(__('Preview failed', 'oxygen-html-converter'), $e),
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function currentUserCanUseExecutableCode(array $options): bool
    {
        return empty($options['allowExecutableCode']) || current_user_can('unfiltered_html');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function currentUserCanUseExecutableImport(array $payload): bool
    {
        $safeMode = $this->requestOptions->parseBool(
            $this->readBooleanPostField('safeMode') ?? ($payload['safeMode'] ?? ($payload['options']['safeMode'] ?? true)),
            true
        );
        $strictNative = $this->requestOptions->parseBool(
            $this->readBooleanPostField('strictNative') ?? ($payload['strictNative'] ?? ($payload['options']['strictNative'] ?? false)),
            false
        );
        $allowExecutableCode = $this->requestOptions->parseBool(
            $this->readBooleanPostField('allowExecutableCode') ?? ($payload['allowExecutableCode'] ?? ($payload['options']['allowExecutableCode'] ?? false)),
            false
        );

        return !$safeMode && !$strictNative && $allowExecutableCode && current_user_can('unfiltered_html');
    }

    /**
     * @return mixed|null
     */
    private function readBooleanPostField(string $field)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified before this helper is called.
        if (!isset($_POST[$field])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified and this boolean is normalized by RequestOptions::parseBool().
        return wp_unslash($_POST[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importPayloadContainsExecutableCode(array $payload): bool
    {
        if ($this->importPayloadIsStandaloneSiteKit($payload)
            && $this->siteKitManifestContainsExecutableCode($this->resolveStandaloneSiteKitManifest($payload))
        ) {
            return true;
        }

        $treeRoot = $this->resolveImportTreeRoot($payload);
        if ($treeRoot !== null && $this->elementNodeContainsExecutableCode($treeRoot)) {
            return true;
        }

        foreach (['cssElement'] as $field) {
            if (isset($payload[$field]) && is_array($payload[$field]) && $this->elementNodeContainsExecutableCode($payload[$field])) {
                return true;
            }
        }

        foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $field) {
            $elements = is_array($payload[$field] ?? null) ? $payload[$field] : [];
            foreach ($elements as $element) {
                if (is_array($element) && $this->elementNodeContainsExecutableCode($element)) {
                    return true;
                }
            }
        }

        if ($this->payloadCssContainsExecutableCode($payload)) {
            return true;
        }

        $selectorPayload = is_array($payload['selectorPayload'] ?? null) ? $payload['selectorPayload'] : [];
        if ($selectorPayload !== [] && $this->arrayContainsExecutableStyleValue($selectorPayload)) {
            return true;
        }

        if ($this->globalSettingsPayloadContainsExecutableCode($payload)) {
            return true;
        }

        if ($this->brandLibraryPayloadContainsExecutableCode($payload)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function siteKitManifestContainsExecutableCode(array $manifest): bool
    {
        foreach ($this->siteKitDocumentTreeRoots($manifest) as $treeRoot) {
            if ($this->elementNodeContainsExecutableCode($treeRoot)) {
                return true;
            }
        }

        if ($this->payloadCssContainsExecutableCode($manifest)) {
            return true;
        }

        $selectorPayload = is_array($manifest['selectorPayload'] ?? null) ? $manifest['selectorPayload'] : [];
        if ($selectorPayload === []) {
            $selectorPayload = [
                'selectors' => is_array($manifest['selectors'] ?? null) ? $manifest['selectors'] : [],
                'collections' => is_array($manifest['collections'] ?? null) ? $manifest['collections'] : [],
            ];
        }

        if ($selectorPayload !== [] && $this->arrayContainsExecutableStyleValue($selectorPayload)) {
            return true;
        }

        if ($this->globalSettingsPayloadContainsExecutableCode($manifest)) {
            return true;
        }

        return $this->brandLibraryPayloadContainsExecutableCode($this->siteKitBrandPayload($manifest));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function siteKitDocumentTreeRoots(array $payload): array
    {
        $manifest = isset($payload['siteKitManifest']) && is_array($payload['siteKitManifest'])
            ? $payload['siteKitManifest']
            : $payload;
        $roots = [];

        foreach (['pages', 'templates', 'headers', 'footers', 'parts'] as $section) {
            $records = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $root = $this->siteKitRecordTreeRoot($record);
                if ($root !== null) {
                    $roots[] = $root;
                }
            }
        }

        return $roots;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function siteKitRecordTreeRoot(array $record): ?array
    {
        foreach (['documentTree', 'tree'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $tree = $record[$field];
                return isset($tree['root']) && is_array($tree['root']) ? $tree['root'] : $tree;
            }
        }

        $oxygenData = is_array($record['_oxygen_data'] ?? null) ? $record['_oxygen_data'] : [];
        if (!is_string($oxygenData['tree_json_string'] ?? null)) {
            return null;
        }

        $tree = json_decode((string) $oxygenData['tree_json_string'], true);
        if (!is_array($tree)) {
            return null;
        }

        return isset($tree['root']) && is_array($tree['root']) ? $tree['root'] : $tree;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function siteKitSelectorPayloadsForLimits(array $payload): array
    {
        $payloads = [];
        $manifests = [];

        if (isset($payload['siteKitManifest']) && is_array($payload['siteKitManifest'])) {
            $manifests[] = $payload['siteKitManifest'];
        }

        if ($this->importPayloadIsStandaloneSiteKit($payload)) {
            $manifests[] = $this->resolveStandaloneSiteKitManifest($payload);
        }

        foreach ($manifests as $manifest) {
            $selectorPayload = $this->siteKitSelectorPayloadForScanning($manifest);
            if ($selectorPayload !== []) {
                $payloads[] = $selectorPayload;
            }
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function siteKitSelectorPayloadForScanning(array $manifest): array
    {
        if (is_array($manifest['selectorPayload'] ?? null)) {
            return $manifest['selectorPayload'];
        }

        $selectors = is_array($manifest['selectors'] ?? null) ? $manifest['selectors'] : [];
        $collections = is_array($manifest['collections'] ?? null) ? $manifest['collections'] : [];
        if ($selectors === [] && $collections === []) {
            return [];
        }

        return [
            'selectors' => $selectors,
            'collections' => $collections,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function siteKitBrandPayload(array $manifest): array
    {
        $designDocument = is_array($manifest['designDocument'] ?? null) ? $manifest['designDocument'] : [];
        $importPlan = is_array($manifest['importPlan'] ?? null) ? $manifest['importPlan'] : [];

        foreach (['tokens', 'variables', 'oxygenVariables'] as $key) {
            if (is_array($manifest[$key] ?? null)) {
                $designDocument['tokens'] = $manifest[$key];
                break;
            }
        }

        return [
            'designDocument' => $designDocument,
            'importPlan' => $importPlan,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function elementNodeContainsExecutableCode(array $node): bool
    {
        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type === 'OxygenElements\\JavaScriptCode' || str_ends_with($type, '\\JavaScriptCode')) {
            return true;
        }

        if ($this->nodeSettingsContainExecutableCode($node)) {
            return true;
        }

        if ($type === 'OxygenElements\\HtmlCode' || str_ends_with($type, '\\HtmlCode')) {
            $html = $node['data']['properties']['content']['content']['html_code'] ?? '';
            if (is_string($html) && $this->htmlCodeContainsExecutableMarkers($html)) {
                return true;
            }
        }

        if ($type === 'OxygenElements\\CssCode' || str_ends_with($type, '\\CssCode')) {
            $css = $node['data']['properties']['content']['content']['css_code'] ?? '';
            if (is_string($css) && $this->cssCodeContainsExecutableMarkers($css)) {
                return true;
            }
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            if (is_array($child) && $this->elementNodeContainsExecutableCode($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeSettingsContainExecutableCode(array $node): bool
    {
        $settings = is_array($node['data']['properties']['settings'] ?? null) ? $node['data']['properties']['settings'] : [];
        $advanced = is_array($settings['advanced'] ?? null) ? $settings['advanced'] : [];
        $attributes = is_array($advanced['attributes'] ?? null) ? $advanced['attributes'] : [];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $name = is_scalar($attribute['name'] ?? null) ? strtolower(trim((string) $attribute['name'])) : '';
            $value = is_scalar($attribute['value'] ?? null) ? (string) $attribute['value'] : '';
            if ($this->attributeNameIsExecutable($name) || $this->stringContainsExecutableUrl($value)) {
                return true;
            }
        }

        $interactionSettings = is_array($settings['interactions'] ?? null) ? $settings['interactions'] : [];
        $interactions = is_array($interactionSettings['interactions'] ?? null) ? $interactionSettings['interactions'] : [];

        return $this->interactionListContainsExecutableCode($interactions);
    }

    private function attributeNameIsExecutable(string $name): bool
    {
        return $name !== '' && (
            strpos($name, 'on') === 0
            || strpos($name, 'data-oxy-at-') === 0
            || strpos($name, 'x-') === 0
            || strpos($name, 'v-') === 0
            || strpos($name, 'ng-') === 0
            || strpos($name, 'hx-on') === 0
            || strpos($name, 'bind:') === 0
            || strpos($name, ':') === 0
            || strpos($name, '@') === 0
            || in_array($name, ['srcdoc', 'formaction', 'ping'], true)
        );
    }

    /**
     * @param array<int, mixed> $interactions
     */
    private function interactionListContainsExecutableCode(array $interactions): bool
    {
        foreach ($interactions as $interaction) {
            if (!is_array($interaction)) {
                continue;
            }

            $actions = is_array($interaction['actions'] ?? null) ? $interaction['actions'] : [];
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $name = is_scalar($action['name'] ?? null) ? strtolower((string) $action['name']) : '';
                if ($name === 'javascript_function') {
                    return true;
                }
            }
        }

        return false;
    }

    private function htmlCodeContainsExecutableMarkers(string $html): bool
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $urlDecoded = rawurldecode($decoded);
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $urlDecoded);
        if (!is_string($normalized)) {
            return true;
        }

        $pattern = '/<\s*(?:script|iframe|object|embed|svg|form|input|textarea|select)\b|\son[a-z0-9_-]+\s*=|(?:javascript|vbscript)\s*:|data:(?:text\/html|image\/svg\+xml)(?:[;,\s]|$)|srcdoc\s*=/i';
        foreach ([$html, $decoded, $urlDecoded, $normalized] as $candidate) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadCssContainsExecutableCode(array $payload): bool
    {
        foreach (['globalCss', 'fallbackCss', 'pageCss', 'pageScopedCss'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && $this->cssCodeContainsExecutableMarkers($payload[$field])) {
                return true;
            }
        }

        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        foreach (['globalCss', 'pageCss', 'pageScopedCss'] as $field) {
            if (isset($routing[$field]) && is_string($routing[$field]) && $this->cssCodeContainsExecutableMarkers($routing[$field])) {
                return true;
            }
        }

        $routes = is_array($routing['routes'] ?? null) ? $routing['routes'] : [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            foreach (['css', 'content'] as $field) {
                if (isset($route[$field]) && is_string($route[$field]) && $this->cssCodeContainsExecutableMarkers($route[$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function globalSettingsPayloadContainsExecutableCode(array $payload): bool
    {
        foreach ($this->globalSettingsCandidates($payload) as $candidate) {
            $settings = is_array($candidate['settings'] ?? null) ? $candidate['settings'] : $candidate;
            $code = is_array($settings['code'] ?? null) ? $settings['code'] : [];

            $scripts = is_array($code['scripts'] ?? null) ? $code['scripts'] : [];
            foreach ($scripts as $script) {
                if (is_array($script) && is_string($script['code'] ?? null) && trim($script['code']) !== '') {
                    return true;
                }
            }

            $stylesheets = is_array($code['stylesheets'] ?? null) ? $code['stylesheets'] : [];
            foreach ($stylesheets as $stylesheet) {
                if (is_array($stylesheet)
                    && is_string($stylesheet['code'] ?? null)
                    && $this->cssCodeContainsExecutableMarkers($stylesheet['code'])
                ) {
                    return true;
                }
            }

            if ($this->arrayContainsExecutableStyleValue($settings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function globalSettingsCandidates(array $payload): array
    {
        $candidates = [];

        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $candidates[] = $payload[$key];
            }
        }

        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($designDocument[$key]) && is_array($designDocument[$key])) {
                $candidates[] = $designDocument[$key];
            }
        }

        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        foreach (['oxygenGlobalSettings', 'globalSettings'] as $key) {
            if (isset($importPlan[$key]) && is_array($importPlan[$key])) {
                $candidates[] = $importPlan[$key];
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function brandLibraryPayloadContainsExecutableCode(array $payload): bool
    {
        $importPlan = is_array($payload['importPlan'] ?? null) ? $payload['importPlan'] : [];
        $designDocument = is_array($payload['designDocument'] ?? null) ? $payload['designDocument'] : [];

        foreach ([$importPlan, $designDocument] as $source) {
            $tokens = is_array($source['tokens'] ?? null) ? $source['tokens'] : [];
            if ($tokens !== [] && $this->arrayContainsExecutableStyleValue($tokens)) {
                return true;
            }

            $components = is_array($source['components'] ?? null) ? $source['components'] : [];
            if ($components !== [] && $this->arrayContainsExecutableStyleValue($components)) {
                return true;
            }
        }

        $componentCandidates = is_array($designDocument['componentCandidates'] ?? null) ? $designDocument['componentCandidates'] : [];
        if ($componentCandidates !== [] && $this->arrayContainsExecutableStyleValue($componentCandidates)) {
            return true;
        }

        foreach ([$importPlan, $designDocument] as $source) {
            $designProfile = is_array($source['designProfile'] ?? null) ? $source['designProfile'] : [];
            if ($designProfile !== [] && $this->arrayContainsExecutableStyleValue($designProfile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     */
    private function arrayContainsExecutableStyleValue(array $value, int $depth = 0): bool
    {
        if ($depth > 50) {
            return true;
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                if ($this->htmlCodeContainsExecutableMarkers($item)
                    || $this->cssCodeContainsExecutableMarkers($item)
                    || $this->stringContainsExecutableUrl($item)
                ) {
                    return true;
                }

                continue;
            }

            if (is_array($item) && $this->arrayContainsExecutableStyleValue($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function cssCodeContainsExecutableMarkers(string $css): bool
    {
        $decoded = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $urlDecoded = rawurldecode($decoded);
        $cssDecoded = preg_replace_callback(
            '/\\\\([0-9a-f]{1,6})\s?/i',
            static function (array $matches): string {
                $codepoint = hexdec($matches[1]);
                return $codepoint >= 0 && $codepoint <= 127 ? chr($codepoint) : '';
            },
            $urlDecoded
        );
        if (!is_string($cssDecoded)) {
            return true;
        }

        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $cssDecoded);
        if (!is_string($normalized)) {
            return true;
        }

        $pattern = '/expression\s*\(|(?:javascript|vbscript)\s*:|data:(?:text\/html|image\/svg\+xml)(?:[;,\s]|$)/i';
        foreach ([$css, $decoded, $urlDecoded, $cssDecoded, $normalized] as $candidate) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function stringContainsExecutableUrl(string $value): bool
    {
        $decoded = strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $probe = rawurldecode($decoded);
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $probe);
        if (!is_string($probe)) {
            return true;
        }

        return preg_match('/(?:javascript|vbscript)\s*:|data:(?:text\/html|image\/svg\+xml)(?:[;,]|$)/i', $probe) === 1;
    }
}
