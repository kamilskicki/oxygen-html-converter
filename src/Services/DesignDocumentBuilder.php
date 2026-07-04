<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;

class DesignDocumentBuilder
{
    private const TOKEN_LIMIT = 16;

    public function __construct(
        private readonly ?TailwindDetector $tailwindDetector = null
    ) {
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function build(string $html, array $result): array
    {
        $document = $this->loadDocument($html);
        $cssText = $document instanceof DOMDocument ? $this->extractCssText($document) : '';
        $classTokens = $document instanceof DOMDocument ? $this->extractClassTokens($document) : [];
        $surface = $this->summarizeConvertedSurface($result['element'] ?? []);
        $sections = $document instanceof DOMDocument ? $this->buildSections($document) : [];
        $componentCandidates = $document instanceof DOMDocument ? $this->detectComponentCandidates($document) : [];
        $tokens = $this->buildTokens($cssText, $classTokens, $document);
        $classStrategy = $this->buildClassStrategy($result, $classTokens);

        $summary = [
            'sectionCount' => count($sections),
            'componentCandidatesCount' => count($componentCandidates),
            'colorTokenCount' => count($tokens['colors']),
            'fontTokenCount' => count($tokens['fonts']),
            'spacingTokenCount' => count($tokens['spacing']),
            'buttonVariantCount' => $this->countButtonVariants($document),
            'fallbackCss' => $surface['cssCodeBlocks'] > 0 || trim((string) ($result['extractedCss'] ?? '')) !== '',
            'htmlCodeBlocks' => $surface['htmlCodeBlocks'],
            'cssCodeBlocks' => $surface['cssCodeBlocks'],
        ];

        $designDocument = [
            'version' => 1,
            'source' => [
                'type' => 'html',
                'sizeBytes' => strlen($html),
                'hasFullDocument' => preg_match('/(?:<!doctype\s+html|<html\b)/i', $html) === 1,
            ],
            'summary' => $summary,
            'sections' => $sections,
            'tokens' => $tokens,
            'componentCandidates' => $componentCandidates,
            'classStrategy' => $classStrategy,
            'followUp' => $this->buildFollowUp($summary, $componentCandidates, $tokens, $classStrategy),
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('oxy_html_converter_design_document', $designDocument, $html, $result);

            if (is_array($filtered)) {
                return $filtered;
            }
        }

        return $designDocument;
    }

    private function loadDocument(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function extractCssText(DOMDocument $document): string
    {
        $parts = [];

        foreach ($document->getElementsByTagName('style') as $style) {
            $parts[] = $style->textContent;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('style')) {
                continue;
            }

            $parts[] = $element->getAttribute('style');
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function extractClassTokens(DOMDocument $document): array
    {
        $tokens = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('class')) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSections(DOMDocument $document): array
    {
        $body = $this->firstElementByTagName($document, 'body');
        $candidates = [];

        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                if (!$child instanceof DOMElement || !$this->isSectionContainer($child)) {
                    continue;
                }

                $candidates[] = $child;
            }
        }

        if ($candidates === []) {
            foreach (['header', 'nav', 'main', 'section', 'article', 'footer'] as $tagName) {
                foreach ($document->getElementsByTagName($tagName) as $element) {
                    if ($element instanceof DOMElement) {
                        $candidates[] = $element;
                    }
                }
            }
        }

        $sections = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $hash = spl_object_hash($candidate);

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $sections[] = $this->summarizeSection($candidate, count($sections) + 1);

            if (count($sections) >= 24) {
                break;
            }
        }

        return $sections;
    }

    private function isSectionContainer(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), ['header', 'nav', 'main', 'section', 'article', 'footer', 'div'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeSection(DOMElement $element, int $index): array
    {
        $classes = array_slice($this->classesForElement($element), 0, 8);
        $role = $this->inferSectionRole($element, $classes);

        return [
            'index' => $index,
            'tag' => strtolower($element->tagName),
            'id' => $element->getAttribute('id'),
            'classes' => $classes,
            'role' => $role,
            'suggestedName' => $this->suggestSectionName($role, $index),
            'heading' => $this->firstHeadingText($element),
            'nodeCount' => $this->countDescendantElements($element),
            'buttonCount' => $this->countDescendantsByTagNames($element, ['a', 'button']),
            'imageCount' => $this->countDescendantsByTagNames($element, ['img', 'picture', 'svg', 'video']),
        ];
    }

    /**
     * @param list<string> $classes
     */
    private function inferSectionRole(DOMElement $element, array $classes): string
    {
        $tagName = strtolower($element->tagName);

        if (in_array($tagName, ['nav', 'footer'], true)) {
            return $tagName;
        }

        $signature = strtolower(trim($element->getAttribute('id') . ' ' . implode(' ', $classes)));

        foreach ([
            'hero' => ['hero', 'masthead', 'above-fold'],
            'cta' => ['cta', 'call-to-action'],
            'pricing' => ['pricing', 'price'],
            'testimonial' => ['testimonial', 'review'],
            'gallery' => ['gallery', 'portfolio'],
            'features' => ['feature', 'benefit'],
        ] as $role => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($signature, $needle)) {
                    return $role;
                }
            }
        }

        if ($tagName === 'header') {
            return 'hero';
        }

        return 'section';
    }

    private function suggestSectionName(string $role, int $index): string
    {
        if ($role !== 'section') {
            return $role;
        }

        return 'section-' . $index;
    }

    private function firstHeadingText(DOMElement $element): string
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tagName) {
            foreach ($element->getElementsByTagName($tagName) as $heading) {
                return $this->normalizeText($heading->textContent);
            }
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectComponentCandidates(DOMDocument $document): array
    {
        $structures = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$this->canBecomeReusableComponent($element)) {
                continue;
            }

            $childTags = $this->directChildElementTags($element);

            if (count($childTags) < 2) {
                continue;
            }

            $signature = strtolower($element->tagName) . '[' . implode(',', $childTags) . ']';

            if (!isset($structures[$signature])) {
                $structures[$signature] = [
                    'signature' => $signature,
                    'tag' => strtolower($element->tagName),
                    'count' => 0,
                    'classes' => [],
                ];
            }

            $structures[$signature]['count']++;
            $structures[$signature]['classes'] = array_values(array_unique(array_merge(
                $structures[$signature]['classes'],
                array_slice($this->classesForElement($element), 0, 5)
            )));
        }

        $candidates = array_values(array_filter(
            $structures,
            static fn (array $structure): bool => $structure['count'] >= 3
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ($right['count'] <=> $left['count']) ?: strcmp($left['signature'], $right['signature']);
        });

        return array_map(function (array $candidate): array {
            return [
                'signature' => $candidate['signature'],
                'tag' => $candidate['tag'],
                'count' => $candidate['count'],
                'suggestedName' => $this->suggestComponentName($candidate['tag'], $candidate['classes']),
                'classes' => array_slice($candidate['classes'], 0, 8),
            ];
        }, array_slice($candidates, 0, 12));
    }

    private function canBecomeReusableComponent(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), ['article', 'aside', 'div', 'li', 'section'], true);
    }

    /**
     * @return list<string>
     */
    private function directChildElementTags(DOMElement $element): array
    {
        $tags = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tags[] = strtolower($child->tagName);
            }
        }

        return $tags;
    }

    /**
     * @param list<string> $classes
     */
    private function suggestComponentName(string $tagName, array $classes): string
    {
        $signature = strtolower(implode(' ', $classes));

        foreach (['card', 'testimonial', 'review', 'feature', 'service', 'price', 'pricing', 'team', 'logo', 'item'] as $needle) {
            if (str_contains($signature, $needle)) {
                return $needle === 'price' ? 'pricing-card' : $needle;
            }
        }

        return 'reusable-' . $tagName;
    }

    /**
     * @param list<string> $classTokens
     *
     * @return array{colors: list<array<string, mixed>>, fonts: list<array<string, mixed>>, spacing: list<array<string, mixed>>}
     */
    private function buildTokens(string $cssText, array $classTokens, ?DOMDocument $document): array
    {
        return [
            'colors' => $this->buildTokenList(
                $this->extractColorValues($cssText, $classTokens),
                fn (string $value): string => 'color-' . strtolower(ltrim(substr($value, 0, 7), '#'))
            ),
            'fonts' => $this->buildTokenList(
                $this->extractFontValues($cssText, $document),
                static fn (string $value): string => 'font-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? 'family')
            ),
            'spacing' => $this->buildTokenList(
                $this->extractSpacingValues($cssText),
                static fn (string $value): string => 'space-' . strtolower(str_replace(['.', '%'], ['-', 'pct'], $value))
            ),
        ];
    }

    /**
     * @param list<string> $classTokens
     *
     * @return list<string>
     */
    private function extractColorValues(string $cssText, array $classTokens): array
    {
        $values = [];

        if (preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\)|hsla?\([^)]+\)/', $cssText, $matches)) {
            foreach ($matches[0] as $match) {
                $values[] = $this->normalizeColorValue($match);
            }
        }

        foreach ($classTokens as $token) {
            if (!preg_match_all('/\[(#[0-9a-fA-F]{3,8}|rgba?\([^\]]+\)|hsla?\([^\]]+\))\]/', $token, $matches)) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $values[] = $this->normalizeColorValue($match);
            }
        }

        return array_values(array_filter($values));
    }

    private function normalizeColorValue(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, '#')) {
            return strtoupper($value);
        }

        return strtolower(preg_replace('/\s+/', '', $value) ?? $value);
    }

    /**
     * @return list<string>
     */
    private function extractFontValues(string $cssText, ?DOMDocument $document): array
    {
        $values = [];

        if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $cssText, $matches)) {
            foreach ($matches[1] as $familyGroup) {
                foreach (explode(',', $familyGroup) as $family) {
                    $family = trim($family, " \t\n\r\0\x0B\"'");

                    if ($family !== '' && !$this->isGenericFontFamily($family)) {
                        $values[] = $family;
                    }
                }
            }
        }

        if ($document instanceof DOMDocument) {
            foreach ($document->getElementsByTagName('link') as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                foreach ($this->extractGoogleFontFamilies($link->getAttribute('href')) as $family) {
                    $values[] = $family;
                }
            }
        }

        return $values;
    }

    private function isGenericFontFamily(string $family): bool
    {
        return in_array(strtolower($family), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'], true);
    }

    /**
     * @return list<string>
     */
    private function extractGoogleFontFamilies(string $href): array
    {
        if (!str_contains($href, 'fonts.googleapis.com')) {
            return [];
        }

        $families = [];
        $decoded = html_entity_decode($href, ENT_QUOTES);

        if (!preg_match_all('/[?&]family=([^&]+)/', $decoded, $matches)) {
            return [];
        }

        foreach ($matches[1] as $family) {
            $family = urldecode(str_replace('+', ' ', preg_replace('/:.+$/', '', $family) ?? $family));

            if ($family !== '') {
                $families[] = $family;
            }
        }

        return $families;
    }

    /**
     * @return list<string>
     */
    private function extractSpacingValues(string $cssText): array
    {
        $values = [];

        if (!preg_match_all('/(?:margin|padding|gap|column-gap|row-gap)(?:-[a-z]+)?\s*:\s*([^;}{]+)/i', $cssText, $matches)) {
            return [];
        }

        foreach ($matches[1] as $declarationValue) {
            if (!preg_match_all('/-?\d*\.?\d+(?:px|rem|em|vw|vh|%|ch)/i', $declarationValue, $valueMatches)) {
                continue;
            }

            foreach ($valueMatches[0] as $value) {
                $normalized = strtolower($value);

                if ($normalized !== '0px' && $normalized !== '0rem' && $normalized !== '0em') {
                    $values[] = $normalized;
                }
            }
        }

        return $values;
    }

    /**
     * @param list<string> $values
     * @param callable(string): string $nameBuilder
     *
     * @return list<array<string, mixed>>
     */
    private function buildTokenList(array $values, callable $nameBuilder): array
    {
        $counts = [];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        uksort($counts, static function (string $left, string $right) use ($counts): int {
            return ($counts[$right] <=> $counts[$left]) ?: strcmp($left, $right);
        });

        $tokens = [];

        foreach (array_slice($counts, 0, self::TOKEN_LIMIT, true) as $value => $uses) {
            $tokens[] = [
                'value' => $value,
                'uses' => $uses,
                'suggestedName' => $nameBuilder($value),
            ];
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $classTokens
     *
     * @return array<string, mixed>
     */
    private function buildClassStrategy(array $result, array $classTokens): array
    {
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $customClassCount = count(is_array($result['customClasses'] ?? null) ? $result['customClasses'] : []);
        $tailwindClassCount = (int) ($stats['tailwindClasses'] ?? $this->countTailwindClasses($classTokens));
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $selectorCount = count(is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : []);

        if ($tailwindClassCount >= 20 && $tailwindClassCount > $customClassCount) {
            $recommendation = 'windpress';
        } elseif ($selectorCount > 0 && $customClassCount >= $tailwindClassCount) {
            $recommendation = 'native';
        } else {
            $recommendation = 'hybrid';
        }

        return [
            'nativeSelectorCount' => $selectorCount,
            'customClassCount' => $customClassCount,
            'tailwindClassCount' => $tailwindClassCount,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param list<string> $classTokens
     */
    private function countTailwindClasses(array $classTokens): int
    {
        $detector = $this->tailwindDetector ?? new TailwindDetector();
        $count = 0;

        foreach ($classTokens as $token) {
            if ($detector->isTailwindClass($token)) {
                $count++;
            }
        }

        return $count;
    }

    private function countButtonVariants(?DOMDocument $document): int
    {
        if (!$document instanceof DOMDocument) {
            return 0;
        }

        $variants = [];

        foreach (['a', 'button'] as $tagName) {
            foreach ($document->getElementsByTagName($tagName) as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $classes = $this->classesForElement($element);

                if ($classes === []) {
                    continue;
                }

                $variants[implode(' ', $classes)] = true;
            }
        }

        return count($variants);
    }

    /**
     * @param mixed $element
     *
     * @return array{htmlCodeBlocks: int, cssCodeBlocks: int, totalNodes: int}
     */
    private function summarizeConvertedSurface(mixed $element): array
    {
        $summary = [
            'htmlCodeBlocks' => 0,
            'cssCodeBlocks' => 0,
            'totalNodes' => 0,
        ];

        $this->walkConvertedElement($element, $summary);

        return $summary;
    }

    /**
     * @param mixed $element
     * @param array{htmlCodeBlocks: int, cssCodeBlocks: int, totalNodes: int} $summary
     */
    private function walkConvertedElement(mixed $element, array &$summary): void
    {
        if (!is_array($element)) {
            return;
        }

        $summary['totalNodes']++;
        $type = (string) ($element['type'] ?? '');

        if (str_ends_with($type, 'HtmlCode')) {
            $summary['htmlCodeBlocks']++;
        }

        if (str_ends_with($type, 'CssCode')) {
            $summary['cssCodeBlocks']++;
        }

        $children = $element['children'] ?? [];

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->walkConvertedElement($child, $summary);
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $componentCandidates
     * @param array<string, list<array<string, mixed>>> $tokens
     * @param array<string, mixed> $classStrategy
     *
     * @return list<string>
     */
    private function buildFollowUp(array $summary, array $componentCandidates, array $tokens, array $classStrategy): array
    {
        $items = [];

        if ($componentCandidates !== []) {
            $items[] = 'Review reusable component candidates before saving them into the brand library.';
        }

        if (($tokens['colors'] ?? []) !== [] || ($tokens['fonts'] ?? []) !== [] || ($tokens['spacing'] ?? []) !== []) {
            $items[] = 'Map detected design tokens to Oxygen global colors, fonts, spacing, and selector variables.';
        }

        if (($summary['fallbackCss'] ?? false) === true || ($summary['htmlCodeBlocks'] ?? 0) > 0) {
            $items[] = 'Inspect fallback CSS or HTML code blocks before treating the page as perfectly native.';
        }

        if (($classStrategy['recommendation'] ?? '') === 'windpress') {
            $items[] = 'Keep WindPress as the fast path for Tailwind-heavy drafts, then promote repeated patterns into native selectors.';
        }

        return $items;
    }

    private function firstElementByTagName(DOMDocument $document, string $tagName): ?DOMElement
    {
        foreach ($document->getElementsByTagName($tagName) as $element) {
            if ($element instanceof DOMElement) {
                return $element;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function classesForElement(DOMElement $element): array
    {
        if (!$element->hasAttribute('class')) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [],
            static fn (string $className): bool => $className !== ''
        ));
    }

    private function countDescendantElements(DOMElement $element): int
    {
        return $element->getElementsByTagName('*')->length + 1;
    }

    /**
     * @param list<string> $tagNames
     */
    private function countDescendantsByTagNames(DOMElement $element, array $tagNames): int
    {
        $count = 0;

        foreach ($tagNames as $tagName) {
            $count += $element->getElementsByTagName($tagName)->length;
        }

        return $count;
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
