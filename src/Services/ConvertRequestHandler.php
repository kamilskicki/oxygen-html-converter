<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Handles the convert endpoint's conversion flow.
 */
class ConvertRequestHandler
{
    public function __construct(
        private readonly TreeBuilderFactory $treeBuilderFactory,
        private readonly ConvertPayloadBuilder $payloadBuilder
    )
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{success:bool,status:int,data:array<string, mixed>}
     */
    public function handle(string $html, array $options): array
    {
        $builder = $this->treeBuilderFactory->createForConvert($options, $html);
        $result = $builder->convert($html);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'status' => 400,
                'data' => [
                    'message' => $result['error'] ?? 'Conversion failed',
                    'errors' => $result['errors'] ?? [],
                ],
            ];
        }

        $response = $this->payloadBuilder->build($result, $options);
        if ($response['success']) {
            $response['data'] = apply_filters('oxy_html_converter_convert_response', $response['data'], $result, $options, $html);
        }

        return $response;
    }
}
