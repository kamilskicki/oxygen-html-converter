<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Validation\OutputValidator;

/**
 * Builds convert-endpoint payloads while preserving the existing wire format.
 */
class ConvertPayloadBuilder
{
    public function __construct(
        private readonly OxygenDocumentTree $documentTree,
        private readonly ConversionAuditBuilder $auditBuilder,
        private readonly OutputValidator $outputValidator
    )
    {
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @return array{success:bool,status:int,data:array<string, mixed>}
     */
    public function build(array $result, array $options): array
    {
        $rootElement = $this->buildRootElement($result, $options);
        $this->reindexElementTree($rootElement, (int) ($options['startingNodeId'] ?? 1));
        $validationErrors = $this->validatePayload($rootElement, $result);

        if ($validationErrors !== []) {
            $audit = $this->auditBuilder->build(
                array_merge($result, ['validationErrors' => $validationErrors]),
                $options
            );

            return [
                'success' => false,
                'status' => 422,
                'data' => [
                    'message' => __('Converted output failed builder validation. Try Safe Mode or a different preset.', 'oxygen-html-converter'),
                    'errors' => $validationErrors,
                    'audit' => $audit,
                ],
            ];
        }

        $documentTree = $this->documentTree->build($rootElement);
        $cssElement = $this->findFirstElementOfType($rootElement, ElementTypes::CSS_CODE) ?? $result['cssElement'];
        $audit = $this->auditBuilder->build($result, $options);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'element' => $rootElement,
                'documentTree' => $documentTree,
                'cssElement' => $cssElement,
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
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRootElement(array $result, array $options): array
    {
        $rootElement = $result['element'];

        if (!empty($options['wrapInContainer'])) {
            $rootElement = [
                'id' => (int) $options['startingNodeId'],
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => [$rootElement],
            ];
        }

        if (!empty($options['includeCssElement']) && !empty($result['cssElement'])) {
            if (!empty($options['wrapInContainer'])) {
                array_unshift($rootElement['children'], $result['cssElement']);
            } else {
                $rootElement = [
                    'id' => (int) $options['startingNodeId'],
                    'data' => [
                        'type' => ElementTypes::CONTAINER,
                        'properties' => [],
                    ],
                    'children' => [$result['cssElement'], $rootElement],
                ];
            }
        }

        $prependChildren = [];
        foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
            if (empty($result[$key]) || !is_array($result[$key])) {
                continue;
            }

            foreach ($result[$key] as $candidate) {
                if (is_array($candidate)) {
                    $prependChildren[] = $candidate;
                }
            }
        }

        if ($prependChildren !== []) {
            $existingChildren = isset($rootElement['children']) && is_array($rootElement['children'])
                ? $rootElement['children']
                : [];
            $rootElement['children'] = array_merge($prependChildren, $existingChildren);
        }

        return $rootElement;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function reindexElementTree(array &$element, int $nextId): int
    {
        if ($nextId < 1) {
            $nextId = 1;
        }

        $element['id'] = $nextId++;

        if (empty($element['children']) || !is_array($element['children'])) {
            return $nextId;
        }

        foreach ($element['children'] as &$child) {
            if (is_array($child)) {
                $nextId = $this->reindexElementTree($child, $nextId);
            }
        }
        unset($child);

        return $nextId;
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>|null
     */
    private function findFirstElementOfType(array $element, string $type): ?array
    {
        if (($element['data']['type'] ?? null) === $type) {
            return $element;
        }

        if (empty($element['children']) || !is_array($element['children'])) {
            return null;
        }

        foreach ($element['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $match = $this->findFirstElementOfType($child, $type);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rootElement
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    private function validatePayload(array $rootElement, array $result): array
    {
        $this->outputValidator->reset();

        $payload = [
            'success' => true,
            'element' => $rootElement,
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
