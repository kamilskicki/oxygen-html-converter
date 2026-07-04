<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Handles the preview endpoint's conversion flow.
 */
class PreviewRequestHandler
{
    public function __construct(
        private readonly TreeBuilderFactory $treeBuilderFactory,
        private readonly PreviewSummaryBuilder $summaryBuilder,
        private readonly ConversionAuditBuilder $auditBuilder,
        private readonly ?DesignDocumentBuilder $designDocumentBuilder = null,
        private readonly ?ImportPlanBuilder $importPlanBuilder = null
    )
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{success:bool,status:int,data:array<string, mixed>}
     */
    public function handle(string $html, array $options): array
    {
        $builder = $this->treeBuilderFactory->create($options, 'preview', $html);
        $result = $builder->convert($html);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'status' => 400,
                'data' => [
                    'message' => $result['error'] ?? 'Preview failed',
                ],
            ];
        }

        $designDocument = $this->getDesignDocumentBuilder()->build($html, $result);
        $importPlan = $this->getImportPlanBuilder()->build($result, $designDocument, $options);
        $resultWithAnalysis = array_merge($result, [
            'designDocument' => $designDocument,
            'importPlan' => $importPlan,
        ]);

        $payload = [
            'summary' => $this->summaryBuilder->build($result['element']),
            'elementCount' => $result['stats']['elements'],
            'tailwindClassCount' => $result['stats']['tailwindClasses'],
            'customClassCount' => $result['stats']['customClasses'],
            'customClasses' => $result['customClasses'],
            'hasExtractedCss' => !empty($result['extractedCss']),
            'hasGlobalCss' => !empty($result['globalCss']),
            'hasPageScopedCss' => !empty($result['pageScopedCss']),
            'styleRouting' => is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [],
            'warnings' => $result['stats']['warnings'],
            'errors' => $result['stats']['errors'] ?? [],
            'designDocument' => $designDocument,
            'importPlan' => $importPlan,
            'audit' => $this->auditBuilder->build($resultWithAnalysis, $options),
        ];

        return [
            'success' => true,
            'status' => 200,
            'data' => apply_filters('oxy_html_converter_preview_response', $payload, $resultWithAnalysis, $html),
        ];
    }

    private function getDesignDocumentBuilder(): DesignDocumentBuilder
    {
        return $this->designDocumentBuilder ?? new DesignDocumentBuilder();
    }

    private function getImportPlanBuilder(): ImportPlanBuilder
    {
        return $this->importPlanBuilder ?? new ImportPlanBuilder();
    }
}
