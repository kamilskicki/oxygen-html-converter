<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\Validation\OutputValidator;

/**
 * Handles the batch convert endpoint's conversion flow.
 */
class BatchConvertRequestHandler
{
    public function __construct(
        private readonly TreeBuilderFactory $treeBuilderFactory,
        private readonly OxygenDocumentTree $documentTree,
        private readonly ConversionAuditBuilder $auditBuilder,
        private readonly OutputValidator $outputValidator
    )
    {
    }

    /**
     * @param array<int, array{index:int, html:string}> $batch
     * @param array<string, mixed> $options
     * @return array{success:bool,status:int,data:array<string, mixed>}
     */
    public function handle(array $batch, array $options, int $skipped = 0): array
    {
        $results = [];
        $builder = $this->treeBuilderFactory->create($options, 'batch');
        $totalStats = [
            'elements' => 0,
            'tailwindClasses' => 0,
            'customClasses' => 0,
            'warnings' => [],
            'errors' => [],
            'info' => [],
        ];

        foreach ($batch as $batchItem) {
            $index = (int) $batchItem['index'];
            $html = wp_unslash((string) $batchItem['html']);
            $result = $builder->convert($html);

            if (!empty($result['success'])) {
                $validationErrors = $this->validatePayload($result);

                if ($validationErrors !== []) {
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

                $totalStats['elements'] += $result['stats']['elements'];
                $totalStats['tailwindClasses'] += $result['stats']['tailwindClasses'];
                $totalStats['customClasses'] += $result['stats']['customClasses'];
                $totalStats['warnings'] = array_merge($totalStats['warnings'], $result['stats']['warnings']);
                $totalStats['errors'] = array_merge($totalStats['errors'], $result['stats']['errors'] ?? []);
                $totalStats['info'] = array_merge($totalStats['info'], $result['stats']['info']);
                continue;
            }

            $results[] = [
                'index' => $index,
                'success' => false,
                'message' => $result['error'] ?? __('Batch conversion failed.', 'oxygen-html-converter'),
                'errors' => $result['errors'] ?? [],
                'audit' => $this->auditBuilder->build($result, $options),
            ];
            $totalStats['errors'] = array_merge($totalStats['errors'], $result['errors'] ?? []);
        }

        $response = [
            'results' => $results,
            'totalStats' => $totalStats,
        ];

        if ($skipped > 0) {
            $response['skippedItems'] = $skipped;
            $response['warning'] = "{$skipped} item(s) were skipped due to invalid type or size limits.";
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => apply_filters('oxy_html_converter_batch_response', $response, $results, $totalStats),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    private function validatePayload(array $result): array
    {
        $this->outputValidator->reset();

        $payload = [
            'success' => true,
            'element' => $result['element'],
            'cssElement' => $result['cssElement'],
            'headLinkElements' => $result['headLinkElements'],
            'headScriptElements' => $result['headScriptElements'],
            'iconScriptElements' => $result['iconScriptElements'],
            'stats' => $result['stats'],
        ];

        if ($this->outputValidator->validateConversionResult($payload)) {
            return [];
        }

        return $this->outputValidator->getErrors();
    }
}
