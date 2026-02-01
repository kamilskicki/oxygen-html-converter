<?php

namespace OxyHtmlConverter\Services;

use DOMNode;
use DOMElement;
use OxyHtmlConverter\Report\ConversionReport;

/**
 * Detects repeated HTML structures that could be Oxygen components/partials
 */
class ComponentDetector
{
    private ConversionReport $report;
    private array $structures = [];

    public function __construct(ConversionReport $report)
    {
        $this->report = $report;
    }

    /**
     * Analyze a node and its children for repeated structures
     */
    public function analyze(DOMNode $node): void
    {
        if (!($node instanceof DOMElement)) {
            return;
        }

        $signature = $this->getElementSignature($node);
        if ($signature) {
            $this->structures[$signature][] = $node->getAttribute('class');
        }

        foreach ($node->childNodes as $child) {
            $this->analyze($child);
        }
    }

    /**
     * Generate a signature for an element based on its tag and direct children tags
     */
    private function getElementSignature(DOMElement $element): ?string
    {
        $tag = strtolower($element->tagName);
        
        // Only look at common container-like elements
        if (!in_array($tag, ['div', 'section', 'article', 'li', 'a'])) {
            return null;
        }

        $childTags = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $childTags[] = strtolower($child->tagName);
            }
        }

        if (empty($childTags)) {
            return null;
        }

        return $tag . '[' . implode(',', $childTags) . ']';
    }

    /**
     * Report findings to the ConversionReport
     */
    public function reportFindings(): void
    {
        foreach ($this->structures as $signature => $occurrences) {
            if (count($occurrences) >= 3) {
                $tagName = explode('[', $signature)[0];
                $this->report->addWarning(
                    "Detected " . count($occurrences) . " repeated <{$tagName}> structures. " .
                    "Consider creating a reusable Oxygen component or partial for these."
                );
            }
        }
    }
}
