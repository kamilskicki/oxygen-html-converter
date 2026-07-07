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
            $data = [
                'message' => $result['error'] ?? 'Conversion failed',
                'errors' => $result['errors'] ?? [],
            ];
            if (isset($result['stats']) && is_array($result['stats'])) {
                $data['stats'] = $result['stats'];
            }

            return [
                'success' => false,
                'status' => 400,
                'data' => $data,
            ];
        }

        $response = $this->payloadBuilder->build($result, $options, $html);
        if ($response['success']) {
            $response['data'] = apply_filters('oxy_html_converter_convert_response', $response['data'], $result, $options, $html);
        }

        return $response;
    }
}
