<?php

namespace OxyHtmlConverter;

/**
 * Handles AJAX endpoints for HTML conversion
 */
class Ajax
{
    public function __construct()
    {
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
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML input
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => 'No HTML provided'], 400);
            return;
        }

        // Get options
        $options = [
            'startingNodeId' => isset($_POST['startingNodeId']) ? intval($_POST['startingNodeId']) : 1,
            'wrapInContainer' => isset($_POST['wrapInContainer']) ? filter_var($_POST['wrapInContainer'], FILTER_VALIDATE_BOOLEAN) : false,
            'includeCssElement' => isset($_POST['includeCssElement']) ? filter_var($_POST['includeCssElement'], FILTER_VALIDATE_BOOLEAN) : true,
        ];

        try {
            $builder = new TreeBuilder();

            // Set starting node ID if provided
            if ($options['startingNodeId'] > 1) {
                $builder->setStartingNodeId($options['startingNodeId']);
            }

            $result = $builder->convert($html);

            if ($result['success']) {
                $rootElement = $result['element'];

                // Optionally wrap in container
                if ($options['wrapInContainer']) {
                    $rootElement = [
                        'id' => $options['startingNodeId'],
                        'data' => [
                            'type' => 'OxygenElements\\Container',
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
                                'type' => 'OxygenElements\\Container',
                                'properties' => [],
                            ],
                            'children' => [$result['cssElement'], $rootElement],
                        ];
                    }
                }

                // Include icon scripts if they exist
                if (!empty($result['iconScriptElements'])) {
                    foreach ($result['iconScriptElements'] as $iconElement) {
                        array_unshift($rootElement['children'], $iconElement);
                    }
                }

                wp_send_json_success([
                    'element' => $rootElement,
                    'cssElement' => $result['cssElement'],
                    'extractedCss' => $result['extractedCss'],
                    'customClasses' => $result['customClasses'],
                    'stats' => $result['stats'],
                    'json' => json_encode([
                        'element' => $rootElement,
                    ], JSON_PRETTY_PRINT),
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'] ?? 'Conversion failed',
                    'errors' => $result['errors'] ?? [],
                ], 400);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Oxygen HTML Converter Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            wp_send_json_error([
                'message' => 'Conversion error: ' . $e->getMessage(),
            ], 500);
        }
    }

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
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML inputs (expecting an array of HTML strings)
        $rawBatch = isset($_POST['batch']) ? $_POST['batch'] : [];
        if (empty($rawBatch) || !is_array($rawBatch)) {
            wp_send_json_error(['message' => 'No HTML batch provided'], 400);
            return;
        }

        // Sanitize batch array - ensure all items are strings
        $batch = array_values(array_filter($rawBatch, 'is_string'));

        $results = [];
        $builder = new TreeBuilder();
        $totalStats = [
            'elements' => 0,
            'tailwindClasses' => 0,
            'customClasses' => 0,
            'warnings' => [],
            'info' => [],
        ];

        try {
            foreach ($batch as $index => $html) {
                $html = wp_unslash($html);
                $result = $builder->convert($html);
                
                if ($result['success']) {
                    $results[] = [
                        'index' => $index,
                        'element' => $result['element'],
                        'stats' => $result['stats']
                    ];

                    // Aggregate stats
                    $totalStats['elements'] += $result['stats']['elements'];
                    $totalStats['tailwindClasses'] += $result['stats']['tailwindClasses'];
                    $totalStats['customClasses'] += $result['stats']['customClasses'];
                    $totalStats['warnings'] = array_merge($totalStats['warnings'], $result['stats']['warnings']);
                    $totalStats['info'] = array_merge($totalStats['info'], $result['stats']['info']);
                }
            }

            wp_send_json_success([
                'results' => $results,
                'totalStats' => $totalStats,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Batch conversion error: ' . $e->getMessage()], 500);
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
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }

        // Get HTML input
        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        if (empty($html)) {
            wp_send_json_error(['message' => 'No HTML provided'], 400);
            return;
        }

        try {
            $builder = new TreeBuilder();
            $result = $builder->convert($html);

            if ($result['success']) {
                // Generate preview summary
                $summary = $this->generatePreviewSummary($result['element']);

                wp_send_json_success([
                    'summary' => $summary,
                    'elementCount' => $result['stats']['elements'],
                    'tailwindClassCount' => $result['stats']['tailwindClasses'],
                    'customClassCount' => $result['stats']['customClasses'],
                    'customClasses' => $result['customClasses'],
                    'hasExtractedCss' => !empty($result['extractedCss']),
                    'warnings' => $result['stats']['warnings'],
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'] ?? 'Preview failed',
                ], 400);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Oxygen HTML Converter Preview Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            wp_send_json_error([
                'message' => 'Preview error: ' . $e->getMessage(),
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
}
