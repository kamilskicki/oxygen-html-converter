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
        private readonly OutputValidator $outputValidator,
        private readonly ?DesignDocumentBuilder $designDocumentBuilder = null,
        private readonly ?ImportPlanBuilder $importPlanBuilder = null,
        private readonly ?OxygenTokenBindingService $tokenBindingService = null
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
                $designDocument = $this->getDesignDocumentBuilder()->build($html, $result);
                $result = $this->getTokenBindingService()->applyToConversionResult($result, [
                    'designDocument' => $designDocument,
                ]);
                $validationErrors = $this->validatePayload($result);
                $resultForPlan = $validationErrors !== []
                    ? array_merge($result, ['validationErrors' => $validationErrors])
                    : $result;
                $importPlan = $this->getImportPlanBuilder()->build($resultForPlan, $designDocument, $options);
                $resultWithAnalysis = array_merge($resultForPlan, [
                    'designDocument' => $designDocument,
                    'importPlan' => $importPlan,
                ]);

                if ($validationErrors !== []) {
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'message' => __('Converted output failed builder validation.', 'oxygen-html-converter'),
                        'errors' => $validationErrors,
                        'designDocument' => $designDocument,
                        'importPlan' => $importPlan,
                        'audit' => $this->auditBuilder->build($resultWithAnalysis, $options),
                    ];
                    $totalStats['warnings'][] = 'Item ' . $index . ' failed builder validation.';
                    $totalStats['errors'] = array_merge($totalStats['errors'], $validationErrors);
                    continue;
                }

                if (!empty($options['strictNative']) && ($importPlan['status'] ?? '') === 'blocked') {
                    $blockers = is_array($importPlan['blockers'] ?? null) ? $importPlan['blockers'] : [];
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'message' => __('Strict native import blocked fallback code or unsupported constructs.', 'oxygen-html-converter'),
                        'errors' => $blockers,
                        'designDocument' => $designDocument,
                        'importPlan' => $importPlan,
                        'audit' => $this->auditBuilder->build($resultWithAnalysis, $options),
                    ];
                    $totalStats['warnings'][] = 'Item ' . $index . ' was blocked by Strict Native mode.';
                    $totalStats['errors'] = array_merge($totalStats['errors'], $blockers);
                    continue;
                }

                $documentTree = $this->documentTree->build($result['element']);
                $results[] = [
                    'index' => $index,
                    'success' => true,
                    'element' => $result['element'],
                    'documentTree' => $documentTree,
                    'selectorPayload' => $result['selectorPayload'] ?? ['selectors' => [], 'collections' => []],
                    'globalCss' => (string) ($result['globalCss'] ?? ''),
                    'pageScopedCss' => (string) ($result['pageScopedCss'] ?? ''),
                    'styleRouting' => is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [],
                    'documentJson' => json_encode([
                        'tree_json_string' => wp_json_encode($documentTree),
                    ], JSON_PRETTY_PRINT),
                    'stats' => $result['stats'],
                    'tokenUsage' => is_array($result['tokenUsage'] ?? null) ? $result['tokenUsage'] : [],
                    'designDocument' => $designDocument,
                    'importPlan' => $importPlan,
                    'audit' => $this->auditBuilder->build($resultWithAnalysis, $options),
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
                'stats' => is_array($result['stats'] ?? null) ? $result['stats'] : [],
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

    private function getDesignDocumentBuilder(): DesignDocumentBuilder
    {
        return $this->designDocumentBuilder ?? new DesignDocumentBuilder();
    }

    private function getImportPlanBuilder(): ImportPlanBuilder
    {
        return $this->importPlanBuilder ?? new ImportPlanBuilder();
    }

    private function getTokenBindingService(): OxygenTokenBindingService
    {
        return $this->tokenBindingService ?? new OxygenTokenBindingService();
    }
}
