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
                'message' => $label . ' is required.',
            ];
        }

        if (strlen($rawPayload) > $maxBytes) {
            return [
                'success' => false,
                'status' => self::JSON_LIMIT_STATUS,
                'message' => $label . ' is too large. Maximum ' . $this->formatBytes($maxBytes) . ' allowed.',
            ];
        }

        try {
            $payload = json_decode($rawPayload, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ((int) $e->getCode() === JSON_ERROR_DEPTH) {
                return [
                    'success' => false,
                    'status' => self::JSON_LIMIT_STATUS,
                    'message' => $label . ' exceeds maximum JSON depth of ' . $maxDepth . '.',
                ];
            }

            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid ' . strtolower($label) . ' JSON.',
            ];
        }

        if (!is_array($payload)) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid ' . strtolower($label) . ' shape.',
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
    private function validateSelectorPayloadLimits(array $payload, string $label = 'Selector payload'): ?array
    {
        $selectors = is_array($payload['selectors'] ?? null) ? $payload['selectors'] : [];
        if (count($selectors) > self::SELECTOR_MAX_RECORDS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => $label . ' contains too many selectors. Maximum ' . self::SELECTOR_MAX_RECORDS . ' allowed.',
            ];
        }

        $collections = is_array($payload['collections'] ?? null) ? $payload['collections'] : [];
        if (count($collections) > self::SELECTOR_MAX_COLLECTIONS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => $label . ' contains too many collections. Maximum ' . self::SELECTOR_MAX_COLLECTIONS . ' allowed.',
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
                        'message' => $label . ' selector ' . $field . ' is too long at index ' . (int) $index . '.',
                    ];
                }
            }

            if (is_array($selector['properties'] ?? null)) {
                $shapeError = $this->validateNestedShapeLimits(
                    $selector['properties'],
                    self::SELECTOR_MAX_PROPERTY_ITEMS - $propertiesItemCount,
                    self::SELECTOR_MAX_PROPERTY_VALUE_BYTES,
                    self::SELECTOR_MAX_PROPERTY_KEY_BYTES,
                    $label . ' selector properties'
                );
                if ($shapeError !== null) {
                    return $shapeError;
                }

                $propertiesItemCount += $this->countNestedItems($selector['properties'], self::SELECTOR_MAX_PROPERTY_ITEMS);
                if ($propertiesItemCount > self::SELECTOR_MAX_PROPERTY_ITEMS) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => $label . ' selector properties contain too many nested items. Maximum '
                            . self::SELECTOR_MAX_PROPERTY_ITEMS . ' allowed.',
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
        foreach ($this->extractImportCssPayloads($payload) as $label => $css) {
            if (strlen($css) > self::IMPORT_CSS_MAX_BYTES) {
                return [
                    'status' => self::JSON_LIMIT_STATUS,
                    'message' => $label . ' is too large. Maximum ' . $this->formatBytes(self::IMPORT_CSS_MAX_BYTES) . ' allowed.',
                ];
            }
        }

        $shapeError = $this->validateNestedShapeLimits(
            $payload,
            self::IMPORT_MAX_TOTAL_ITEMS,
            self::IMPORT_CSS_MAX_BYTES,
            256,
            'Import payload'
        );
        if ($shapeError !== null) {
            return $shapeError;
        }

        if (is_array($payload['selectorPayload'] ?? null)) {
            $selectorError = $this->validateSelectorPayloadLimits($payload['selectorPayload'], 'Import selector payload');
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

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractImportCssPayloads(array $payload): array
    {
        $cssPayloads = [];

        foreach (['globalCss' => 'Global CSS payload', 'pageScopedCss' => 'Page scoped CSS payload'] as $field => $label) {
            if (is_string($payload[$field] ?? null)) {
                $cssPayloads[$label] = (string) $payload[$field];
            }
        }

        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        foreach (['globalCss' => 'Global routed CSS payload', 'pageScopedCss' => 'Page scoped routed CSS payload'] as $field => $label) {
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
                'message' => 'Import document tree exceeds maximum depth of ' . self::IMPORT_TREE_MAX_DEPTH . '.',
            ];
        }

        $nodeCount++;
        if ($nodeCount > self::IMPORT_TREE_MAX_NODES) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => 'Import document tree contains too many nodes. Maximum ' . self::IMPORT_TREE_MAX_NODES . ' allowed.',
            ];
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if (count($children) > self::IMPORT_TREE_MAX_CHILDREN) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => 'Import document tree node contains too many children. Maximum '
                    . self::IMPORT_TREE_MAX_CHILDREN . ' allowed.',
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
            'Brand library payload'
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
                'message' => 'Brand library payload contains too many color tokens. Maximum '
                    . self::BRAND_MAX_COLOR_TOKENS . ' allowed.',
            ];
        }

        $typographyCount = max(
            $this->countArrayAtPath($payload, ['designDocument', 'tokens', 'fonts']),
            $this->countArrayAtPath($payload, ['importPlan', 'tokens', 'fonts'])
        );
        if ($typographyCount > self::BRAND_MAX_TYPOGRAPHY_TOKENS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => 'Brand library payload contains too many typography tokens. Maximum '
                    . self::BRAND_MAX_TYPOGRAPHY_TOKENS . ' allowed.',
            ];
        }

        $componentCount = max(
            $this->countArrayAtPath($payload, ['designDocument', 'componentCandidates']),
            $this->countArrayAtPath($payload, ['importPlan', 'components'])
        );
        if ($componentCount > self::BRAND_MAX_COMPONENTS) {
            return [
                'status' => self::JSON_LIMIT_STATUS,
                'message' => 'Brand library payload contains too many components. Maximum '
                    . self::BRAND_MAX_COMPONENTS . ' allowed.',
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
                        'message' => $label . ' contains too many nested items. Maximum ' . $maxItems . ' allowed.',
                    ];
                }

                if (is_string($key) && strlen($key) > $maxKeyBytes) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => $label . ' contains a key longer than ' . $maxKeyBytes . ' bytes.',
                    ];
                }

                if (is_string($child) && strlen($child) > $maxStringBytes) {
                    return [
                        'status' => self::JSON_LIMIT_STATUS,
                        'message' => $label . ' contains a string longer than ' . $this->formatBytes($maxStringBytes) . '.',
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
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML input
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML must remain intact for conversion.
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => 'No HTML provided'], 400);
            return;
        }

        // Size limit for single conversion (1MB)
        if (strlen($html) > 1048576) {
            wp_send_json_error([
                'message' => 'HTML content too large. Maximum 1MB allowed for single conversion.',
            ], 400);
            return;
        }

        // Get options
        $options = $this->requestOptions->normalizeConvert($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_convert_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizeConvert(array_merge($options, $filteredOptions));
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
                'message' => $this->getClientErrorMessage('Conversion failed', $e),
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
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML inputs (expecting an array of HTML strings)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML arrays must remain intact for conversion.
        $rawBatch = isset($_POST['batch']) ? wp_unslash($_POST['batch']) : [];
        if (empty($rawBatch) || !is_array($rawBatch)) {
            wp_send_json_error(['message' => 'No HTML batch provided'], 400);
            return;
        }

        // Safety limits
        if (count($rawBatch) > self::MAX_BATCH_ITEMS) {
            wp_send_json_error([
                'message' => 'Batch too large. Maximum ' . self::MAX_BATCH_ITEMS . ' items allowed.',
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
                    'message' => 'Batch total size exceeds limit. Maximum ' . (self::MAX_BATCH_SIZE / 1048576) . 'MB allowed.',
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

        try {
            $response = $this->batchHandler->handle($batch, $options, $skipped);
            wp_send_json_success($response['data']);
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_batch_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage('Batch conversion failed', $e),
            ], 500);
        }
    }

    /**
     * Save generated Oxygen selector records before pasting elements that reference them.
     */
    public function handleSaveSelectors(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        if (!$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON selector payload is validated below.
        $rawPayload = isset($_POST['selectorPayload']) ? wp_unslash($_POST['selectorPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            'Selector payload',
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
                'message' => $this->getClientErrorMessage('Failed to save selectors', $e),
            ], 500);
        }
    }

    /**
     * Create or update a draft WordPress page with generated Oxygen data.
     */
    public function handleImportPage(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Import payload JSON is validated below.
        $rawPayload = isset($_POST['importPayload']) ? wp_unslash($_POST['importPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            'Import payload',
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

        if ($this->importPayloadMutatesGlobalDesign($payload) && !$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error([
                'message' => 'Permission denied for global design import mutations.',
            ], 403);
            return;
        }

        try {
            $result = $this->pageImporter->import($payload);

            if (!empty($result['success'])) {
                unset($result['success'], $result['status']);
                wp_send_json_success($result);
                return;
            }

            wp_send_json_error([
                'message' => $result['message'] ?? 'Page import failed.',
                'errors' => $result['errors'] ?? [],
            ], (int) ($result['status'] ?? 500));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_import_page_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage('Page import failed', $e),
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importPayloadMutatesGlobalDesign(array $payload): bool
    {
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
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
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
                'message' => $result['message'] ?? 'Rollback failed.',
                'errors' => $result['errors'] ?? [],
            ], (int) ($result['status'] ?? 500));
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_rollback_import_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage('Rollback failed', $e),
            ], 500);
        }
    }

    /**
     * Save detected design tokens and reusable component candidates into the plugin brand library.
     */
    public function handleSaveBrandLibrary(): void
    {
        if (!check_ajax_referer('oxy_html_converter', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        if (!$this->currentUserCanMutateGlobalDesign()) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Brand library JSON is normalized below.
        $rawPayload = isset($_POST['brandPayload']) ? wp_unslash($_POST['brandPayload']) : '';
        $decodedPayload = $this->decodeBoundedJsonPayload(
            $rawPayload,
            'Brand library payload',
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
                'message' => $this->getClientErrorMessage('Failed to save brand library', $e),
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
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        // Check permissions
        if (!current_user_can($this->getRequiredCapability())) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML input
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML must remain intact for conversion.
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => 'No HTML provided'], 400);
            return;
        }

        // Size limit for preview (1MB)
        if (strlen($html) > 1048576) {
            wp_send_json_error([
                'message' => 'HTML content too large. Maximum 1MB allowed.',
            ], 400);
            return;
        }

        $options = $this->requestOptions->normalizePreview($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_preview_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizePreview(array_merge($options, $filteredOptions));
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
                'message' => $this->getClientErrorMessage('Preview failed', $e),
            ], 500);
        }
    }

}
