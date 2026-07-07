<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMNode;

/**
 * Intermediate representation shared by pipeline stages before Oxygen serialization.
 */
final class ConversionIr
{
    /**
     * @param list<DOMNode> $bodyNodes
     * @param list<array<string, mixed>> $cssRules
     * @param list<string> $sourceClassTokens
     * @param array<string, mixed> $semanticClassProfile
     * @param array<string, string> $classAliases
     * @param array{toggles: list<array<string, mixed>>, smoothScroll: bool} $javaScriptPatterns
     * @param array<string, array<string, mixed>> $detectedIconLibraries
     * @param list<array<string, mixed>> $componentCandidates
     */
    public function __construct(
        private readonly DOMDocument $document,
        private readonly array $bodyNodes,
        private readonly string $extractedCss,
        private readonly array $cssRules,
        private readonly array $sourceClassTokens,
        private readonly array $semanticClassProfile,
        private readonly array $classAliases,
        private readonly array $javaScriptPatterns,
        private readonly array $detectedIconLibraries,
        private readonly array $componentCandidates
    ) {
    }

    public function document(): DOMDocument
    {
        return $this->document;
    }

    /**
     * @return list<DOMNode>
     */
    public function bodyNodes(): array
    {
        return $this->bodyNodes;
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
     * @return list<string>
     */
    public function sourceClassTokens(): array
    {
        return $this->sourceClassTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function semanticClassProfile(): array
    {
        return $this->semanticClassProfile;
    }

    /**
     * @return array<string, string>
     */
    public function classAliases(): array
    {
        return $this->classAliases;
    }

    /**
     * @return array{toggles: list<array<string, mixed>>, smoothScroll: bool}
     */
    public function javaScriptPatterns(): array
    {
        return $this->javaScriptPatterns;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function detectedIconLibraries(): array
    {
        return $this->detectedIconLibraries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function componentCandidates(): array
    {
        return $this->componentCandidates;
    }
}
