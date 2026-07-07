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
    private const DEFAULT_MIN_OCCURRENCES = 3;
    private const DEFAULT_MIN_CONFIDENCE = 0.75;
    private const DEFAULT_MIN_EDITABLE_PROPERTIES = 1;

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
            $structureKey = $this->componentStructureKey($signature, $this->componentRoleForClasses($this->classesForElement($node)));
            $this->structures[$structureKey][] = [
                'signature' => $signature,
                'class' => $node->getAttribute('class'),
                'editableFieldTypes' => $this->editableFieldTypesForElement($node),
                'advancedPatternTypes' => $this->advancedPatternTypesForElement($node),
            ];
        }

        foreach ($node->childNodes as $child) {
            $this->analyze($child);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidates(
        float $minConfidence = self::DEFAULT_MIN_CONFIDENCE,
        int $minOccurrences = self::DEFAULT_MIN_OCCURRENCES,
        int $minEditableProperties = self::DEFAULT_MIN_EDITABLE_PROPERTIES
    ): array
    {
        $candidates = [];
        $minOccurrences = max(1, $minOccurrences);
        $minConfidence = max(0.0, min(1.0, $minConfidence));
        $minEditableProperties = max(0, $minEditableProperties);

        foreach ($this->structures as $structureKey => $occurrences) {
            $count = count($occurrences);
            $confidence = max(0.0, min(1.0, $count / $minOccurrences));

            if ($count < $minOccurrences || $confidence < $minConfidence) {
                continue;
            }

            $signature = is_string($occurrences[0]['signature'] ?? null) ? (string) $occurrences[0]['signature'] : (string) $structureKey;
            $tagName = explode('[', $signature)[0];
            $classes = array_values(array_unique(array_filter(
                array_map('trim', array_map(
                    static fn ($occurrence): string => is_array($occurrence)
                        ? (string) ($occurrence['class'] ?? '')
                        : (string) $occurrence,
                    $occurrences
                )),
                static fn (string $className): bool => $className !== ''
            )));
            $editableFieldTypes = [];
            $advancedPatternTypes = [];
            foreach ($occurrences as $occurrence) {
                foreach (is_array($occurrence['editableFieldTypes'] ?? null) ? $occurrence['editableFieldTypes'] : [] as $fieldType) {
                    if (is_string($fieldType) && $fieldType !== '') {
                        $editableFieldTypes[] = $fieldType;
                    }
                }
                foreach (is_array($occurrence['advancedPatternTypes'] ?? null) ? $occurrence['advancedPatternTypes'] : [] as $patternType) {
                    if (is_string($patternType) && $patternType !== '') {
                        $advancedPatternTypes[] = $patternType;
                    }
                }
            }

            $editableFieldTypes = array_values(array_unique($editableFieldTypes));
            $advancedPatternTypes = array_values(array_unique($advancedPatternTypes));
            $editablePropertyCount = count($editableFieldTypes);
            $reasons = [];
            if ($editablePropertyCount < $minEditableProperties) {
                $reasons[] = 'insufficient_editable_properties';
            }

            $candidates[] = [
                'signature' => (string) $signature,
                'tag' => $tagName,
                'count' => $count,
                'occurrences' => $count,
                'confidence' => $confidence,
                'threshold' => [
                    'minOccurrences' => $minOccurrences,
                    'minConfidence' => $minConfidence,
                    'minEditableProperties' => $minEditableProperties,
                ],
                'eligible' => $reasons === [],
                'suggestedName' => $this->suggestComponentName($tagName, $classes),
                'classes' => array_slice($classes, 0, 8),
                'editableFieldTypes' => $editableFieldTypes,
                'editablePropertyCount' => $editablePropertyCount,
                'editablePropertiesSufficient' => $reasons === [],
                'advancedPatternTypes' => $advancedPatternTypes,
                'reason' => $reasons[0] ?? '',
                'reasons' => $reasons,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            return ($right['count'] <=> $left['count']) ?: strcmp($left['signature'], $right['signature']);
        });

        return $candidates;
    }

    /**
     * Generate a signature for an element based on its tag and direct children tags
     */
    private function getElementSignature(DOMElement $element): ?string
    {
        $tag = strtolower($element->tagName);
        
        // Only look at common container-like elements
        if (!in_array($tag, ['div', 'section', 'article', 'li', 'a'], true)) {
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
     * @return list<string>
     */
    private function classesForElement(DOMElement $element): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [],
            static fn (string $className): bool => $className !== ''
        ));
    }

    private function componentStructureKey(string $signature, string $role): string
    {
        return $role === '' ? $signature : $signature . '|role:' . $role;
    }

    /**
     * @param list<string> $classes
     */
    private function componentRoleForClasses(array $classes): string
    {
        $signature = strtolower(implode(' ', $classes));

        foreach ([
            'card' => ['card', 'tile', 'panel'],
            'testimonial' => ['testimonial', 'review'],
            'pricing' => ['pricing', 'price'],
            'feature' => ['feature', 'benefit'],
            'nav' => ['nav', 'menu'],
            'team' => ['team'],
            'logo' => ['logo'],
        ] as $role => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($signature, $needle)) {
                    return $role;
                }
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function editableFieldTypesForElement(DOMElement $element): array
    {
        $types = [];
        $elements = [$element];

        foreach ($element->getElementsByTagName('*') as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $elements[] = $child;
        }

        foreach ($elements as $child) {
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'label', 'blockquote', 'button'], true)
                && trim((string) $child->textContent) !== ''
            ) {
                $types[] = 'text';
            }

            if ($tag === 'a') {
                if (trim((string) $child->textContent) !== '') {
                    $types[] = 'text';
                }
                if (trim($child->getAttribute('href')) !== '') {
                    $types[] = 'link_url';
                }
            }

            if ($tag === 'img') {
                if (trim($child->getAttribute('src')) !== '') {
                    $types[] = 'image_src';
                }
                if (trim($child->getAttribute('alt')) !== '') {
                    $types[] = 'image_alt';
                }
            }

            if ($tag === 'svg' || $child->hasAttribute('data-lucide') || $child->hasAttribute('data-feather')) {
                $types[] = 'icon';
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>
     */
    private function advancedPatternTypesForElement(DOMElement $element): array
    {
        $types = [];
        $elements = [$element];

        foreach ($element->getElementsByTagName('*') as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        foreach ($elements as $child) {
            $tag = strtolower($child->tagName);
            $classSignature = strtolower(trim(
                $child->getAttribute('class') . ' '
                . $child->getAttribute('data-variant') . ' '
                . $child->getAttribute('data-state')
            ));

            if ($child->hasAttribute('data-variant') || str_contains($classSignature, 'variant-')) {
                $types[] = 'variants';
            }

            if ($child->hasAttribute('data-repeat') || $child->hasAttribute('data-repeater')) {
                $types[] = 'repeated_regions';
            }

            if (in_array($tag, ['ul', 'ol'], true)) {
                $types[] = 'lists';
            }

            if (in_array($tag, ['form', 'input', 'select', 'textarea'], true)) {
                $types[] = 'forms';
            }

            if ($child->hasAttribute('data-dynamic')
                || $child->hasAttribute('data-query')
                || preg_match('/(?:\{\{[^}]+\}\}|%%[^%]+%%)/', (string) $child->textContent) === 1
            ) {
                $types[] = 'dynamic_data';
            }

            if ($tag === 'style') {
                $types[] = 'component_scoped_css';
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Report findings to the ConversionReport
     */
    public function reportFindings(): void
    {
        foreach ($this->candidates(0.0, self::DEFAULT_MIN_OCCURRENCES) as $candidate) {
            if (empty($candidate['eligible'])) {
                continue;
            }

            $tagName = (string) $candidate['tag'];
            $this->report->addWarning(
                "Detected " . (int) $candidate['count'] . " repeated <{$tagName}> structures. " .
                "Consider creating a reusable Oxygen component or partial for these."
            );
        }
    }

    /**
     * @param list<string> $classes
     */
    private function suggestComponentName(string $tagName, array $classes): string
    {
        $signature = strtolower(implode(' ', $classes));

        foreach (['card', 'testimonial', 'review', 'feature', 'service', 'price', 'pricing', 'team', 'logo', 'nav', 'menu', 'item'] as $needle) {
            if (str_contains($signature, $needle)) {
                if ($needle === 'nav' || $needle === 'menu') {
                    return 'nav-item';
                }

                return $needle === 'price' ? 'pricing-card' : $needle;
            }
        }

        return $tagName . '-component';
    }
}
