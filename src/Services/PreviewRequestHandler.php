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
        private readonly ConversionAuditBuilder $auditBuilder
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

        $payload = [
            'summary' => $this->summaryBuilder->build($result['element']),
            'elementCount' => $result['stats']['elements'],
            'tailwindClassCount' => $result['stats']['tailwindClasses'],
            'customClassCount' => $result['stats']['customClasses'],
            'customClasses' => $result['customClasses'],
            'hasExtractedCss' => !empty($result['extractedCss']),
            'warnings' => $result['stats']['warnings'],
            'errors' => $result['stats']['errors'] ?? [],
            'audit' => $this->auditBuilder->build($result, $options),
        ];

        return [
            'success' => true,
            'status' => 200,
            'data' => apply_filters('oxy_html_converter_preview_response', $payload, $result, $html),
        ];
    }
}
