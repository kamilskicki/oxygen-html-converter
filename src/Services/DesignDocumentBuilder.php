<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use OxyHtmlConverter\ElementTypes;

class DesignDocumentBuilder
{
    private const TOKEN_LIMIT = 16;
    private const COMPONENT_MIN_OCCURRENCES = 3;
    private const COMPONENT_MIN_CONFIDENCE = 0.75;
    private const COMPONENT_MIN_EDITABLE_PROPERTIES = 1;

    /**
     * @var array<string, string>
     */
    private const COMPONENT_NODE_TYPE_TAGS = [
        ElementTypes::CONTAINER => 'div',
        ElementTypes::CONTAINER_LINK => 'a',
        ElementTypes::TEXT => 'p',
        ElementTypes::TEXT_LINK => 'a',
        ElementTypes::RICH_TEXT => 'div',
        ElementTypes::IMAGE => 'img',
        ElementTypes::SVG_ICON => 'svg',
        ElementTypes::HTML5_VIDEO => 'video',
        ElementTypes::HTML_CODE => 'html',
        ElementTypes::CSS_CODE => 'style',
        ElementTypes::JAVASCRIPT_CODE => 'script',
    ];

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
        $surface = $this->summarizeConversionResultSurface($result);
        $sections = $document instanceof DOMDocument ? $this->buildSections($document) : [];
        $convertedRoot = is_array($result['element'] ?? null) ? $result['element'] : [];
        $componentCandidates = $document instanceof DOMDocument ? $this->detectComponentCandidates($document, $convertedRoot) : [];
        $tokens = $this->buildTokens($cssText, $classTokens, $document);
        $semanticClassProfile = ClassStrategyService::buildSemanticClassProfile((new CssParser())->parse($cssText), $classTokens);
        $classApplications = $document instanceof DOMDocument
            ? $this->buildSemanticClassApplications($document, $semanticClassProfile['aliases'])
            : [];
        $classStrategy = array_merge($this->buildClassStrategy($result, $classTokens), $semanticClassProfile);
        $oxygenGlobalSettings = (new OxygenGlobalSettingsInferenceService())->infer($tokens, $cssText);
        $selectorPayload = is_array($result['selectorPayload'] ?? null) ? $result['selectorPayload'] : [];
        $selectors = is_array($selectorPayload['selectors'] ?? null) ? $selectorPayload['selectors'] : [];
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $unsupportedItems = is_array($stats['unsupportedItems'] ?? null) ? $stats['unsupportedItems'] : [];

        $summary = [
            'sectionCount' => count($sections),
            'componentCandidatesCount' => count($componentCandidates),
            'colorTokenCount' => count($tokens['colors']),
            'fontTokenCount' => count($tokens['fonts']),
            'spacingTokenCount' => count($tokens['spacing']),
            'imageTokenCount' => count($tokens['images']),
            'measurementTokenCount' => count($tokens['measurements']),
            'numberTokenCount' => count($tokens['numbers']),
            'semanticClassCount' => count($semanticClassProfile['classMap']),
            'duplicateStylePatternCount' => count($semanticClassProfile['duplicateStylePatterns']),
            'classApplicationCount' => count($classApplications),
            'buttonVariantCount' => $this->countButtonVariants($document),
            'fallbackCss' => $surface['cssCodeBlocks'] > 0 || trim((string) ($result['extractedCss'] ?? '')) !== '',
            'totalNodes' => $surface['totalNodes'],
            'codeBlocksTotal' => $surface['htmlCodeBlocks'] + $surface['cssCodeBlocks'] + $surface['javascriptCodeBlocks'],
            'htmlCodeBlocks' => $surface['htmlCodeBlocks'],
            'cssCodeBlocks' => $surface['cssCodeBlocks'],
            'javascriptCodeBlocks' => $surface['javascriptCodeBlocks'],
            'componentNodes' => $surface['componentNodes'],
            'assetNodes' => $surface['assetNodes'],
            'imageNodes' => $surface['imageNodes'],
            'videoNodes' => $surface['videoNodes'],
            'classAssignments' => $surface['classAssignments'],
            'selectorCount' => count($selectors),
            'unsupportedCount' => count($unsupportedItems),
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

        if ($semanticClassProfile['classMap'] !== [] || $classApplications !== []) {
            $designDocument['designProfile'] = [
                'version' => 1,
                'semanticClasses' => $semanticClassProfile['classMap'],
                'duplicateStylePatterns' => $semanticClassProfile['duplicateStylePatterns'],
                'skippedStylePatterns' => $semanticClassProfile['skippedPatterns'],
                'elementApplications' => $classApplications,
            ];
        }

        if ($oxygenGlobalSettings !== []) {
            $designDocument['oxygenGlobalSettings'] = $oxygenGlobalSettings;
        }

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
    private function detectComponentCandidates(DOMDocument $document, array $convertedRoot): array
    {
        $structures = [];

        if ($convertedRoot !== []) {
            $this->collectComponentStructuresFromTree($convertedRoot, $structures);
        }

        $candidates = array_values(array_filter(
            $structures,
            static fn (array $structure): bool => $structure['count'] >= self::COMPONENT_MIN_OCCURRENCES
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ($right['count'] <=> $left['count']) ?: strcmp($left['signature'], $right['signature']);
        });

        return array_map(function (array $candidate): array {
            $count = (int) $candidate['count'];

            $record = [
                'signature' => $candidate['signature'],
                'tag' => $candidate['tag'],
                'role' => (string) ($candidate['role'] ?? ''),
                'count' => $count,
                'occurrences' => $count,
                'confidence' => 1.0,
                'threshold' => [
                    'minOccurrences' => self::COMPONENT_MIN_OCCURRENCES,
                    'minConfidence' => self::COMPONENT_MIN_CONFIDENCE,
                    'minEditableProperties' => self::COMPONENT_MIN_EDITABLE_PROPERTIES,
                ],
                'suggestedName' => $this->suggestComponentName($candidate['tag'], $candidate['classes']),
                'classes' => array_slice($candidate['classes'], 0, 8),
                'instances' => array_values(is_array($candidate['instances'] ?? null) ? $candidate['instances'] : []),
            ];

            $record['documentTree'] = $candidate['documentTree'];
            $record['componentProperties'] = (new OxygenBlockRepository())->buildComponentPropertiesFromTree(
                $record['documentTree'],
                $record
            );
            $record['editablePropertyCount'] = count($record['componentProperties']['targets']);
            $record['editablePropertiesSufficient'] = $record['editablePropertyCount'] >= self::COMPONENT_MIN_EDITABLE_PROPERTIES;
            $record['eligible'] = $record['editablePropertiesSufficient'];
            $record['reason'] = $record['eligible'] ? '' : 'insufficient_editable_properties';
            $record['reasons'] = $record['eligible'] ? [] : ['insufficient_editable_properties'];

            return $record;
        }, array_slice($candidates, 0, 12));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $structures
     */
    private function collectComponentStructuresFromTree(array $node, array &$structures, array $path = []): void
    {
        if ($this->treeNodeCanBecomeReusableComponent($node)) {
            $signature = $this->componentSignatureForTreeNode($node);
            if ($signature !== '') {
                $classes = array_slice($this->classesForTreeNode($node), 0, 5);
                $role = $this->componentRoleForClasses($classes);
                $structureKey = $this->componentStructureKey($signature, $role);
                $tag = explode('[', $signature)[0];
                if (!isset($structures[$structureKey])) {
                    $structures[$structureKey] = [
                        'signature' => $signature,
                        'tag' => $tag,
                        'role' => $role,
                        'count' => 0,
                        'classes' => [],
                        'documentTree' => $this->componentDocumentTreeFromNode($node),
                        'instances' => [],
                    ];
                }

                $structures[$structureKey]['count']++;
                $structures[$structureKey]['classes'] = array_values(array_unique(array_merge(
                    is_array($structures[$structureKey]['classes'] ?? null) ? $structures[$structureKey]['classes'] : [],
                    $classes
                )));
                $nodeId = $node['id'] ?? null;
                $structures[$structureKey]['instances'][] = [
                    'nodeId' => is_int($nodeId) ? $nodeId : 0,
                    'tag' => $tag,
                    'classes' => $classes,
                    'path' => implode('.', $path),
                ];
            }
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $this->collectComponentStructuresFromTree($child, $structures, array_merge($path, [(int) $index]));
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function componentDocumentTreeFromNode(array $node): array
    {
        return [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [
                    $node,
                ],
            ],
            '_nextNodeId' => $this->nextNodeIdForTree($node),
            'exportedLookupTable' => [],
            'status' => 'exported',
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function componentSignatureForTreeNode(array $node): string
    {
        $tag = $this->tagForTreeNode($node);
        if ($tag === '' || $tag === 'root') {
            return '';
        }

        $childTags = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $childTag = $this->tagForTreeNode($child);
            if ($childTag !== '') {
                $childTags[] = $childTag;
            }
        }

        if ($childTags === []) {
            return '';
        }

        return $tag . '[' . implode(',', $childTags) . ']';
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
     * @param array<string, mixed> $node
     */
    private function tagForTreeNode(array $node): string
    {
        foreach ([
            $node['data']['properties']['settings']['advanced']['tag'] ?? null,
            $node['data']['properties']['design']['tag'] ?? null,
        ] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                return strtolower(trim($tag));
            }
        }

        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type === 'root') {
            return 'root';
        }

        return self::COMPONENT_NODE_TYPE_TAGS[$type] ?? '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeNodeCanBecomeReusableComponent(array $node): bool
    {
        return in_array($this->tagForTreeNode($node), ['article', 'aside', 'div', 'li', 'section'], true);
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function classesForTreeNode(array $node): array
    {
        $classes = [];
        foreach ([
            $node['data']['properties']['settings']['advanced']['classes'] ?? null,
            $node['data']['properties']['meta']['classes'] ?? null,
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $className) {
                if (is_string($className) && trim($className) !== '') {
                    $classes[] = trim($className);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nextNodeIdForTree(array $node): int
    {
        $maxId = $this->maxNodeId($node);

        return max(1, $maxId + 1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function maxNodeId(array $node): int
    {
        $max = is_int($node['id'] ?? null) ? (int) $node['id'] : 0;

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $max = max($max, $this->maxNodeId($child));
            }
        }

        return $max;
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

        return 'reusable-' . $tagName;
    }

    /**
     * @param list<string> $classTokens
     *
     * @return array{colors: list<array<string, mixed>>, fonts: list<array<string, mixed>>, spacing: list<array<string, mixed>>, images: list<array<string, mixed>>, measurements: list<array<string, mixed>>, numbers: list<array<string, mixed>>}
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
            'images' => $this->buildTokenList(
                $this->extractImageValues($cssText, $document),
                fn (string $value): string => 'image-' . $this->slugForImageUrl($value)
            ),
            'measurements' => $this->buildTokenList(
                $this->extractMeasurementValues($cssText),
                static fn (string $value): string => 'measure-' . strtolower(str_replace(['.', '%'], ['-', 'pct'], $value))
            ),
            'numbers' => $this->buildTokenList(
                $this->extractNumberValues($cssText),
                static fn (string $value): string => 'number-' . strtolower(str_replace(['.', '+'], ['-', 'plus'], ltrim($value, '+')))
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
     * @return list<string>
     */
    private function extractImageValues(string $cssText, ?DOMDocument $document): array
    {
        $values = [];

        if (preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/i', $cssText, $matches)) {
            foreach ($matches[2] as $url) {
                $url = trim($url);
                if ($this->isSupportedImageTokenUrl($url)) {
                    $values[] = $url;
                }
            }
        }

        if ($document instanceof DOMDocument) {
            foreach ($document->getElementsByTagName('img') as $image) {
                if ($image instanceof DOMElement && $this->isSupportedImageTokenUrl($image->getAttribute('src'))) {
                    $values[] = trim($image->getAttribute('src'));
                }
            }

            foreach ($document->getElementsByTagName('source') as $source) {
                if (!$source instanceof DOMElement) {
                    continue;
                }

                foreach ($this->extractSrcsetUrls($source->getAttribute('srcset')) as $url) {
                    if ($this->isSupportedImageTokenUrl($url)) {
                        $values[] = $url;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function extractMeasurementValues(string $cssText): array
    {
        $values = [];
        $properties = [
            'font-size',
            'line-height',
            'letter-spacing',
            'text-indent',
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-right-radius',
            'border-bottom-left-radius',
            'width',
            'max-width',
            'min-width',
            'height',
            'max-height',
            'min-height',
            'top',
            'right',
            'bottom',
            'left',
        ];

        $pattern = '/(?:' . implode('|', array_map('preg_quote', $properties)) . ')\s*:\s*([^;}{]+)/i';
        if (!preg_match_all($pattern, $cssText, $matches)) {
            return [];
        }

        foreach ($matches[1] as $declarationValue) {
            if (!preg_match_all('/-?\d*\.?\d+(?:px|rem|em|vw|vh|%|ch|fr)/i', $declarationValue, $valueMatches)) {
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
     * @return list<string>
     */
    private function extractNumberValues(string $cssText): array
    {
        $values = [];
        $properties = [
            'opacity',
            'z-index',
            'font-weight',
            'flex-grow',
            'flex-shrink',
            'order',
            'orphans',
            'widows',
        ];

        $pattern = '/(?:' . implode('|', array_map('preg_quote', $properties)) . ')\s*:\s*([+-]?\d*\.?\d+)\s*(?:!important)?\s*(?:;|$)/i';
        if (!preg_match_all($pattern, $cssText, $matches)) {
            return [];
        }

        foreach ($matches[1] as $number) {
            $normalized = $this->normalizeNumberTokenValue($number);
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        return $values;
    }

    private function normalizeNumberTokenValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        if ($number == (int) $number) {
            return (string) (int) $number;
        }

        $normalized = rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');

        return $normalized === '-0' ? '0' : $normalized;
    }

    /**
     * @return list<string>
     */
    private function extractSrcsetUrls(string $srcset): array
    {
        $urls = [];

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate)) ?: [];
            $url = trim((string) ($parts[0] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function isSupportedImageTokenUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s<>{}]/', $url) === 1) {
            return false;
        }

        if (preg_match('/^(?:javascript|vbscript|data):/i', $url) === 1) {
            return false;
        }

        return preg_match('#^https?://#i', $url) === 1
            || preg_match('#^/(?!/)#', $url) === 1
            || preg_match('#^[A-Za-z0-9_.~/-]+\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?\#].*)?$#i', $url) === 1;
    }

    private function slugForImageUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $base) ?? '');
        $slug = trim($slug, '-');

        return $slug === '' ? substr(sha1($url), 0, 8) : $slug;
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
            $value = (string) $value;
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

        if ($tailwindClassCount >= 20 && $tailwindClassCount > $customClassCount && $this->isWindPressRecommendationEnabled()) {
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

    private function isWindPressRecommendationEnabled(): bool
    {
        if (!function_exists('apply_filters')) {
            return false;
        }

        $flags = (array) apply_filters('oxy_html_converter_feature_flags', [
            'windpress_integration' => false,
            'windpress_class_mode' => false,
        ]);

        return !empty($flags['windpress_integration'])
            && !empty($flags['windpress_class_mode']);
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

    /**
     * @param array<string, string> $aliases
     * @return list<array<string, mixed>>
     */
    private function buildSemanticClassApplications(DOMDocument $document, array $aliases): array
    {
        if ($aliases === []) {
            return [];
        }

        $applications = [];
        $index = 0;

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('class')) {
                continue;
            }

            $sourceClasses = [];
            $appliedClasses = [];
            foreach ($this->classesForElement($element) as $className) {
                if (!isset($aliases[$className])) {
                    continue;
                }

                $sourceClasses[] = $className;
                $appliedClasses[] = $aliases[$className];
            }

            if ($sourceClasses === []) {
                continue;
            }

            $index++;
            $applications[] = [
                'index' => $index,
                'tag' => strtolower($element->tagName),
                'id' => $element->getAttribute('id'),
                'sourceClasses' => array_values(array_unique($sourceClasses)),
                'appliedClasses' => array_values(array_unique($appliedClasses)),
            ];
        }

        return $applications;
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
     * @param array<string, mixed> $result
     *
     * @return array{htmlCodeBlocks: int, cssCodeBlocks: int, javascriptCodeBlocks: int, componentNodes: int, assetNodes: int, imageNodes: int, videoNodes: int, classAssignments: int, totalNodes: int}
     */
    private function summarizeConversionResultSurface(array $result): array
    {
        $summary = [
            'htmlCodeBlocks' => 0,
            'cssCodeBlocks' => 0,
            'javascriptCodeBlocks' => 0,
            'componentNodes' => 0,
            'assetNodes' => 0,
            'imageNodes' => 0,
            'videoNodes' => 0,
            'classAssignments' => 0,
            'totalNodes' => 0,
        ];

        $this->walkConvertedElement($result['element'] ?? null, $summary);

        foreach (['cssElement', 'headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
            $value = $result[$key] ?? null;
            if ($key === 'cssElement') {
                $this->walkConvertedElement($value, $summary);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $node) {
                $this->walkConvertedElement($node, $summary);
            }
        }

        return $summary;
    }

    /**
     * @param mixed $element
     * @param array{htmlCodeBlocks: int, cssCodeBlocks: int, javascriptCodeBlocks: int, componentNodes: int, assetNodes: int, imageNodes: int, videoNodes: int, classAssignments: int, totalNodes: int} $summary
     */
    private function walkConvertedElement(mixed $element, array &$summary): void
    {
        if (!is_array($element)) {
            return;
        }

        $summary['totalNodes']++;
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $type = (string) ($data['type'] ?? $element['type'] ?? '');

        if (str_ends_with($type, 'HtmlCode')) {
            $summary['htmlCodeBlocks']++;
        }

        if (str_ends_with($type, 'CssCode')) {
            $summary['cssCodeBlocks']++;
        }

        if (str_ends_with($type, 'JavaScriptCode')) {
            $summary['javascriptCodeBlocks']++;
        }

        if ($type === ElementTypes::COMPONENT || str_ends_with($type, 'Component')) {
            $summary['componentNodes']++;
        }

        if ($type === ElementTypes::IMAGE || str_ends_with($type, 'Image')) {
            $summary['imageNodes']++;
            $summary['assetNodes']++;
        }

        if ($type === ElementTypes::HTML5_VIDEO || str_ends_with($type, 'Html5Video')) {
            $summary['videoNodes']++;
            $summary['assetNodes']++;
        }

        $classes = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (is_array($classes)) {
            $summary['classAssignments'] += count(array_filter($classes, 'is_string'));
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

        if (
            ($tokens['colors'] ?? []) !== []
            || ($tokens['fonts'] ?? []) !== []
            || ($tokens['spacing'] ?? []) !== []
            || ($tokens['images'] ?? []) !== []
            || ($tokens['measurements'] ?? []) !== []
            || ($tokens['numbers'] ?? []) !== []
        ) {
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
