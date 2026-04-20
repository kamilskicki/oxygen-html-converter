<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Builds the preview summary tree returned by the preview AJAX endpoint.
 */
class PreviewSummaryBuilder
{
    /**
     * @param array<string, mixed> $element
     * @return array{total:int, byType:array<string, int>}
     */
    public function build(array $element): array
    {
        $counts = [
            'total' => 0,
            'byType' => [],
        ];

        $this->summarize($element, $counts);

        return $counts;
    }

    /**
     * @param array<string, mixed> $element
     * @param array{total:int, byType:array<string, int>} $counts
     */
    private function summarize(array $element, array &$counts): void
    {
        $counts['total']++;

        $type = (string) ($element['data']['type'] ?? 'Unknown');
        $typeName = substr($type, strrpos($type, '\\') + 1);

        if (!isset($counts['byType'][$typeName])) {
            $counts['byType'][$typeName] = 0;
        }
        $counts['byType'][$typeName]++;

        foreach (($element['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->summarize($child, $counts);
            }
        }
    }
}
