<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use OxyHtmlConverter\Report\ConversionReport;

/**
 * Builds the conversion intermediate representation from normalized source HTML.
 */
final class ConversionIrBuilder
{
    public function __construct(
        private readonly InteractionDetector $interactionDetector,
        private readonly IconDetector $iconDetector,
        private readonly ConversionReport $report
    ) {
    }

    public function build(NormalizedHtml $normalized): ConversionIr
    {
        $sourceClassTokens = $this->extractClassTokensFromDocument($normalized->document());
        $semanticClassProfile = ClassStrategyService::buildSemanticClassProfile(
            $normalized->cssRules(),
            $sourceClassTokens
        );
        $classAliases = array_map('strval', $semanticClassProfile['aliases']);
        $javaScriptPatterns = $this->analyzeJavaScriptPatterns($normalized->document());
        $detectedIconLibraries = $this->iconDetector->detectIconLibraries($normalized->document());

        $componentDetector = new ComponentDetector($this->report);
        foreach ($normalized->bodyNodes() as $node) {
            $componentDetector->analyze($node);
        }
        $componentCandidates = $componentDetector->candidates();
        $componentDetector->reportFindings();

        return new ConversionIr(
            $normalized->document(),
            $normalized->bodyNodes(),
            $normalized->extractedCss(),
            $normalized->cssRules(),
            $sourceClassTokens,
            $semanticClassProfile,
            $classAliases,
            $javaScriptPatterns,
            $detectedIconLibraries,
            $componentCandidates
        );
    }

    /**
     * @return list<string>
     */
    private function extractClassTokensFromDocument(DOMDocument $document): array
    {
        $tokens = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('class')) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $className) {
                $className = trim($className);
                if ($className !== '') {
                    $tokens[] = $className;
                }
            }
        }

        return $tokens;
    }

    /**
     * @return array{toggles: list<array<string, mixed>>, smoothScroll: bool}
     */
    private function analyzeJavaScriptPatterns(DOMDocument $document): array
    {
        $allJs = '';
        $scriptTags = $document->getElementsByTagName('script');

        foreach ($scriptTags as $script) {
            if (!$script->getAttribute('src')) {
                $allJs .= $script->textContent . "\n";
            }
        }

        $toggles = $this->interactionDetector->detectTogglePatterns($allJs);
        $smoothScroll = $this->interactionDetector->detectSmoothScrollPattern($allJs);

        if (!empty($toggles)) {
            $this->report->addInfo(
                'Detected ' . count($toggles) . ' toggle interaction(s) from JavaScript — preserving original handlers for frontend parity.'
            );
        }
        if ($smoothScroll) {
            $this->report->addInfo(
                'Detected smooth scroll pattern — converted to native Oxygen scroll_to interactions on anchor links.'
            );
        }

        return [
            'toggles' => $toggles,
            'smoothScroll' => $smoothScroll,
        ];
    }
}
