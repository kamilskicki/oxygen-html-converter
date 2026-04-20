<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\RequestOptions;
use OxyHtmlConverter\Validation\OutputValidator;

/**
 * Handles AJAX endpoints for HTML conversion
 */
class Ajax
{
    private OxygenDocumentTree $documentTree;
    private RequestOptions $requestOptions;
    private ConversionAuditBuilder $auditBuilder;
    private OutputValidator $outputValidator;

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

    public function __construct(
        ?OxygenDocumentTree $documentTree = null,
        ?RequestOptions $requestOptions = null,
        ?ConversionAuditBuilder $auditBuilder = null,
        ?OutputValidator $outputValidator = null
    )
    {
        $this->documentTree = $documentTree ?: new OxygenDocumentTree();
        $this->requestOptions = $requestOptions ?: new RequestOptions();
        $this->auditBuilder = $auditBuilder ?: new ConversionAuditBuilder();
        $this->outputValidator = $outputValidator ?: new OutputValidator();

        add_action('wp_ajax_oxy_html_convert', [$this, 'handleConvert']);
        add_action('wp_ajax_oxy_html_convert_preview', [$this, 'handlePreview']);
        add_action('wp_ajax_oxy_html_convert_batch', [$this, 'handleBatchConvert']);
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
            $builder = apply_filters('oxy_html_converter_tree_builder', new TreeBuilder(), $options, $html);
            if (!($builder instanceof TreeBuilder)) {
                $builder = new TreeBuilder();
            }

            // Set starting node ID if provided
            if ($options['startingNodeId'] > 1) {
                $builder->setStartingNodeId($options['startingNodeId']);
            }

            // Configure builder options
            $builder->setInlineStyles($options['inlineStyles']);
            $builder->setSafeMode($options['safeMode']);
            $builder->setDebugMode($options['debugMode']);
            $builder->enableValidation();

            $result = $builder->convert($html);

            if ($result['success']) {
                $rootElement = $result['element'];

                // Optionally wrap in container
                if ($options['wrapInContainer']) {
                    $rootElement = [
                        'id' => $options['startingNodeId'],
                        'data' => [
                            'type' => ElementTypes::CONTAINER,
                            'properties' => [],
                        ],
                        'children' => [$rootElement],
                    ];
                }

                // Optionally include CSS element as first child
                if ($options['includeCssElement'] && !empty($result['cssElement'])) {
                    // If we wrapped in container, add CSS element as first child
                    if ($options['wrapInContainer']) {
                        array_unshift($rootElement['children'], $result['cssElement']);
                    } else {
                        // Otherwise, wrap both in a container
                        $rootElement = [
                            'id' => $options['startingNodeId'],
                            'data' => [
                                'type' => ElementTypes::CONTAINER,
                                'properties' => [],
                            ],
                            'children' => [$result['cssElement'], $rootElement],
                        ];
                    }
                }

                $prependChildren = [];

                if (!empty($result['headLinkElements']) && is_array($result['headLinkElements'])) {
                    foreach ($result['headLinkElements'] as $linkElement) {
                        if (is_array($linkElement)) {
                            $prependChildren[] = $linkElement;
                        }
                    }
                }

                if (!empty($result['headScriptElements']) && is_array($result['headScriptElements'])) {
                    foreach ($result['headScriptElements'] as $scriptElement) {
                        if (is_array($scriptElement)) {
                            $prependChildren[] = $scriptElement;
                        }
                    }
                }

                if (!empty($result['iconScriptElements']) && is_array($result['iconScriptElements'])) {
                    foreach ($result['iconScriptElements'] as $iconElement) {
                        if (is_array($iconElement)) {
                            $prependChildren[] = $iconElement;
                        }
                    }
                }

                if ($prependChildren) {
                    $existingChildren = isset($rootElement['children']) && is_array($rootElement['children'])
                        ? $rootElement['children']
                        : [];
                    $rootElement['children'] = array_merge($prependChildren, $existingChildren);
                }

                $validationErrors = $this->validateResponsePayload([
                    'success' => true,
                    'element' => $rootElement,
                    'cssElement' => $result['cssElement'],
                    'headLinkElements' => $result['headLinkElements'],
                    'headScriptElements' => $result['headScriptElements'],
                    'iconScriptElements' => $result['iconScriptElements'],
                    'stats' => $result['stats'],
                ]);

                if ($validationErrors) {
                    $audit = $this->auditBuilder->build(
                        array_merge($result, ['validationErrors' => $validationErrors]),
                        $options
                    );

                    wp_send_json_error([
                        'message' => __('Converted output failed builder validation. Try Safe Mode or a different preset.', 'oxygen-html-converter'),
                        'errors' => $validationErrors,
                        'audit' => $audit,
                    ], 422);
                    return;
                }

                $documentTree = $this->documentTree->build($rootElement);
                $audit = $this->auditBuilder->build($result, $options);

                $payload = [
                    'element' => $rootElement,
                    'documentTree' => $documentTree,
                    'cssElement' => $result['cssElement'],
                    'extractedCss' => $result['extractedCss'],
                    'customClasses' => $result['customClasses'],
                    'stats' => $result['stats'],
                    'json' => json_encode([
                        'element' => $rootElement,
                    ], JSON_PRETTY_PRINT),
                    'documentJson' => json_encode([
                        'tree_json_string' => wp_json_encode($documentTree),
                    ], JSON_PRETTY_PRINT),
                    'audit' => $audit,
                ];

                $payload = apply_filters('oxy_html_converter_convert_response', $payload, $result, $options, $html);
                wp_send_json_success($payload);
            } else {
                wp_send_json_error([
                    'message' => $result['error'] ?? 'Conversion failed',
                    'errors' => $result['errors'] ?? [],
                ], 400);
            }
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

        $results = [];
        $builder = new TreeBuilder();
        $options = $this->requestOptions->normalizeBatch($_POST);
        $filteredOptions = apply_filters('oxy_html_converter_batch_options', $options, $_POST);
        if (is_array($filteredOptions)) {
            $options = $this->requestOptions->normalizeBatch(array_merge($options, $filteredOptions));
        }

        $builder->setInlineStyles($options['inlineStyles']);
        $builder->setSafeMode($options['safeMode']);
        $builder->setDebugMode($options['debugMode']);
        $builder->enableValidation();
        $totalStats = [
            'elements' => 0,
            'tailwindClasses' => 0,
            'customClasses' => 0,
            'warnings' => [],
            'errors' => [],
            'info' => [],
        ];

        try {
            foreach ($batch as $batchItem) {
                $index = (int) $batchItem['index'];
                $html = wp_unslash((string) $batchItem['html']);
                $result = $builder->convert($html);

                if ($result['success']) {
                    $validationErrors = $this->validateResponsePayload([
                        'success' => true,
                        'element' => $result['element'],
                        'cssElement' => $result['cssElement'],
                        'headLinkElements' => $result['headLinkElements'],
                        'headScriptElements' => $result['headScriptElements'],
                        'iconScriptElements' => $result['iconScriptElements'],
                        'stats' => $result['stats'],
                    ]);

                    if ($validationErrors) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'message' => __('Converted output failed builder validation.', 'oxygen-html-converter'),
                            'errors' => $validationErrors,
                            'audit' => $this->auditBuilder->build(
                                array_merge($result, ['validationErrors' => $validationErrors]),
                                $options
                            ),
                        ];
                        $totalStats['warnings'][] = 'Item ' . $index . ' failed builder validation.';
                        $totalStats['errors'] = array_merge($totalStats['errors'], $validationErrors);
                        continue;
                    }

                    $documentTree = $this->documentTree->build($result['element']);

                    $results[] = [
                        'index' => $index,
                        'success' => true,
                        'element' => $result['element'],
                        'documentTree' => $documentTree,
                        'documentJson' => json_encode([
                            'tree_json_string' => wp_json_encode($documentTree),
                        ], JSON_PRETTY_PRINT),
                        'stats' => $result['stats'],
                        'audit' => $this->auditBuilder->build($result, $options),
                    ];

                    // Aggregate stats
                    $totalStats['elements'] += $result['stats']['elements'];
                    $totalStats['tailwindClasses'] += $result['stats']['tailwindClasses'];
                    $totalStats['customClasses'] += $result['stats']['customClasses'];
                    $totalStats['warnings'] = array_merge($totalStats['warnings'], $result['stats']['warnings']);
                    $totalStats['errors'] = array_merge($totalStats['errors'], $result['stats']['errors'] ?? []);
                    $totalStats['info'] = array_merge($totalStats['info'], $result['stats']['info']);
                } else {
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'message' => $result['error'] ?? __('Batch conversion failed.', 'oxygen-html-converter'),
                        'errors' => $result['errors'] ?? [],
                        'audit' => $this->auditBuilder->build($result, $options),
                    ];
                    $totalStats['errors'] = array_merge($totalStats['errors'], $result['errors'] ?? []);
                }
            }

            $response = [
                'results' => $results,
                'totalStats' => $totalStats,
            ];

            // Include skipped count if any items were skipped
            if ($skipped > 0) {
                $response['skippedItems'] = $skipped;
                $response['warning'] = "{$skipped} item(s) were skipped due to invalid type or size limits.";
            }

            $response = apply_filters('oxy_html_converter_batch_response', $response, $results, $totalStats);
            wp_send_json_success($response);
        } catch (\Throwable $e) {
            do_action('oxy_html_converter_batch_exception', $e);
            wp_send_json_error([
                'message' => $this->getClientErrorMessage('Batch conversion failed', $e),
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
            $builder = new TreeBuilder();
            $builder->setInlineStyles($options['inlineStyles']);
            $builder->setSafeMode($options['safeMode']);
            $builder->setDebugMode($options['debugMode']);
            $builder->enableValidation();
            $result = $builder->convert($html);

            if ($result['success']) {
                // Generate preview summary
                $summary = $this->generatePreviewSummary($result['element']);

                $payload = [
                    'summary' => $summary,
                    'elementCount' => $result['stats']['elements'],
                    'tailwindClassCount' => $result['stats']['tailwindClasses'],
                    'customClassCount' => $result['stats']['customClasses'],
                    'customClasses' => $result['customClasses'],
                    'hasExtractedCss' => !empty($result['extractedCss']),
                    'warnings' => $result['stats']['warnings'],
                    'errors' => $result['stats']['errors'] ?? [],
                    'audit' => $this->auditBuilder->build($result, $options),
                ];

                $payload = apply_filters('oxy_html_converter_preview_response', $payload, $result, $html);
                wp_send_json_success($payload);
            } else {
                wp_send_json_error([
                    'message' => $result['error'] ?? 'Preview failed',
                ], 400);
            }
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

    /**
     * Generate preview summary from element tree
     */
    private function generatePreviewSummary(array $element, array &$counts = null): array
    {
        if ($counts === null) {
            $counts = [
                'total' => 0,
                'byType' => [],
            ];
        }

        $counts['total']++;

        // Extract type name
        $type = $element['data']['type'] ?? 'Unknown';
        $typeName = substr($type, strrpos($type, '\\') + 1);

        if (!isset($counts['byType'][$typeName])) {
            $counts['byType'][$typeName] = 0;
        }
        $counts['byType'][$typeName]++;

        // Process children
        if (!empty($element['children'])) {
            foreach ($element['children'] as $child) {
                $this->generatePreviewSummary($child, $counts);
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function validateResponsePayload(array $payload): array
    {
        $this->outputValidator->reset();

        if ($this->outputValidator->validateConversionResult($payload)) {
            return [];
        }

        return $this->outputValidator->getErrors();
    }
}
