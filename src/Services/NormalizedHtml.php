<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use LogicException;

/**
 * Explicit output from the source normalization stage.
 */
final class NormalizedHtml
{
    /**
     * @param list<array<string, mixed>> $cssRules
     * @param list<DOMNode> $bodyNodes
     * @param list<string> $errors
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $decisions
     * @param list<array<string, mixed>> $headAssets
     * @param list<string> $frameworks
     */
    public function __construct(
        private readonly string $sourceHtml,
        private readonly DOMDocument $document,
        private readonly ?DOMElement $root,
        private readonly string $extractedCss,
        private readonly array $cssRules,
        private readonly array $bodyNodes,
        private readonly array $errors,
        private readonly string $normalizedHtml = '',
        private readonly array $issues = [],
        private readonly array $decisions = [],
        private readonly array $headAssets = [],
        private readonly array $frameworks = [],
        private readonly string $sourceHash = '',
        private readonly string $normalizedHash = ''
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->root instanceof DOMElement;
    }

    public function sourceHtml(): string
    {
        return $this->sourceHtml;
    }

    public function normalizedHtml(): string
    {
        return $this->normalizedHtml !== '' ? $this->normalizedHtml : $this->sourceHtml;
    }

    public function sourceHash(): string
    {
        return $this->sourceHash !== '' ? $this->sourceHash : hash('sha256', $this->sourceHtml);
    }

    public function normalizedHash(): string
    {
        return $this->normalizedHash !== ''
            ? $this->normalizedHash
            : hash('sha256', $this->normalizedHtml());
    }

    public function document(): DOMDocument
    {
        return $this->document;
    }

    public function root(): DOMElement
    {
        if (!$this->root instanceof DOMElement) {
            throw new LogicException('Normalized HTML root is not available for failed parsing output.');
        }

        return $this->root;
    }

    public function extractedCss(): string
    {
        return $this->extractedCss;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cssRules(): array
    {
        return $this->cssRules;
    }

    /**
     * @return list<DOMNode>
     */
    public function bodyNodes(): array
    {
        return $this->bodyNodes;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function headAssets(): array
    {
        return $this->headAssets;
    }

    /**
     * @return list<string>
     */
    public function frameworks(): array
    {
        return $this->frameworks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function headerDecisions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (array $decision): bool => ($decision['type'] ?? '') === 'header_role'
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizationReport(): array
    {
        return [
            'version' => 1,
            'sourceHash' => $this->sourceHash(),
            'normalizedHash' => $this->normalizedHash(),
            'issues' => $this->issues,
            'decisions' => $this->decisions,
            'headAssets' => $this->headAssets,
            'frameworks' => $this->frameworks,
            'summary' => [
                'issues' => count($this->issues),
                'decisions' => count($this->decisions),
                'placeholderLinks' => count(array_filter(
                    $this->issues,
                    static fn (array $issue): bool => ($issue['type'] ?? '') === 'placeholder_link'
                )),
                'temporaryMedia' => count(array_filter(
                    $this->issues,
                    static fn (array $issue): bool => ($issue['type'] ?? '') === 'temporary_media'
                )),
                'sourceArtifactsRemoved' => count(array_filter(
                    $this->issues,
                    static fn (array $issue): bool => ($issue['type'] ?? '') === 'source_artifact_attribute'
                )),
            ],
        ];
    }
}
