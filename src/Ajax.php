<?php

declare(strict_types=1);

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\BatchConvertRequestHandler;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\ConvertRequestHandler;
use OxyHtmlConverter\Services\OxygenDocumentTree;
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
        ?RequestOptions $requestOptions = null,
        ?ConversionAuditBuilder $auditBuilder = null,
        ?OutputValidator $outputValidator = null,
        ?TreeBuilderFactory $treeBuilderFactory = null,
        ?ConvertRequestHandler $convertHandler = null,
        ?PreviewRequestHandler $previewHandler = null,
        ?BatchConvertRequestHandler $batchHandler = null
    )
    {
        $this->requestOptions = $requestOptions ?: new RequestOptions();
        $auditBuilder = $auditBuilder ?: new ConversionAuditBuilder();
        $outputValidator = $outputValidator ?: new OutputValidator();
        $treeBuilderFactory = $treeBuilderFactory ?: new TreeBuilderFactory();

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
