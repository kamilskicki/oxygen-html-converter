<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMElement;
use OxyHtmlConverter\StyleExtractor;

/**
 * Owns native CSS materialization, consumed-rule tracking, and mirror fallback CSS.
 */
final class NativeCssMaterializer
{
    private const JS_FINAL_STATE_CLASSES = ['visible', 'is-visible', 'done'];

    private const JS_BEHAVIOR_STATE_CLASSES = [
        'animate-on-scroll' => true,
        'reveal' => true,
        'scroll-reveal' => true,
        'fade-in' => true,
        'slide-up' => true,
        'slide-in' => true,
        'aos-animate' => true,
        'wow' => true,
    ];

    private const JS_FINAL_STATE_OVERRIDE_PROPERTIES = [
        'opacity' => true,
        'transform' => true,
        'visibility' => true,
        'width' => true,
        'height' => true,
        'max-height' => true,
        'clip-path' => true,
    ];

    private const NATIVE_CSS_MIRROR_PROPERTIES = [
        'align-items' => true,
        'background' => true,
        'background-color' => true,
        'background-image' => true,
        'background-position' => true,
        'background-repeat' => true,
        'background-size' => true,
        'border' => true,
        'border-bottom' => true,
        'border-bottom-width' => true,
        'border-color' => true,
        'border-left' => true,
        'border-left-width' => true,
        'border-radius' => true,
        'border-right' => true,
        'border-right-width' => true,
        'border-style' => true,
        'border-top' => true,
        'border-top-width' => true,
        'border-width' => true,
        'bottom' => true,
        'box-shadow' => true,
        'color' => true,
        'column-gap' => true,
        'display' => true,
        'filter' => true,
        'flex' => true,
        'flex-basis' => true,
        'flex-direction' => true,
        'flex-grow' => true,
        'flex-shrink' => true,
        'flex-wrap' => true,
        'font-family' => true,
        'font-size' => true,
        'font-style' => true,
        'font-weight' => true,
        'gap' => true,
        'grid-template-columns' => true,
        'grid-template-rows' => true,
        'height' => true,
        'justify-content' => true,
        'left' => true,
        'letter-spacing' => true,
        'line-height' => true,
        'margin' => true,
        'margin-bottom' => true,
        'margin-left' => true,
        'margin-right' => true,
        'margin-top' => true,
        'max-height' => true,
        'max-width' => true,
        'min-height' => true,
        'min-width' => true,
        'mix-blend-mode' => true,
        'object-fit' => true,
        'opacity' => true,
        'overflow' => true,
        'overflow-x' => true,
        'overflow-y' => true,
        'padding' => true,
        'padding-bottom' => true,
        'padding-left' => true,
        'padding-right' => true,
        'padding-top' => true,
        'position' => true,
        'right' => true,
        'row-gap' => true,
        'text-align' => true,
        'text-decoration' => true,
        'text-transform' => true,
        'top' => true,
        'transform' => true,
        'transition' => true,
        'width' => true,
        'z-index' => true,
    ];

    /** @var array<string, bool> */
    private array $consumedCssSelectors = [];

    /** @var array<string, list<string>> */
    private array $consumedCssDeclarations = [];

    /** @var list<string> */
    private array $nativeCssMirrorRules = [];

    /** @var array<string, string> */
    private array $classAliases = [];

    private bool $inlineStyles = true;

    private ?\Closure $debugLogger = null;

    public function __construct(
        private readonly StyleExtractor $styleExtractor,
        private readonly CssParser $cssParser,
        private readonly SelectorMatcher $selectorMatcher,
        private readonly OxygenSelectorImporter $selectorImporter,
        private readonly EnvironmentService $environment
    ) {
    }

    public function reset(): void
    {
        $this->consumedCssSelectors = [];
        $this->consumedCssDeclarations = [];
        $this->nativeCssMirrorRules = [];
    }

    /**
     * @param array<string, string> $classAliases
     */
    public function configure(array $classAliases, bool $inlineStyles, ?callable $debugLogger = null): void
    {
        $this->classAliases = $classAliases;
        $this->inlineStyles = $inlineStyles;
        $this->debugLogger = $debugLogger !== null ? \Closure::fromCallable($debugLogger) : null;
    }

    /**
     * Apply CSS rules from style tags to an element.
     *
     * @param array<string, mixed> $element
     * @param list<array<string, mixed>> $cssRules
     */
    public function applyCssRules(array &$element, array $cssRules, DOMElement $node): void
    {
        if (empty($cssRules)) {
            $this->logDebug('No CSS rules to apply');
            return;
        }

        $elementId = $element['data']['properties']['settings']['advanced']['id'] ?? null;
        $elementClasses = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        $elementType = $element['data']['type'] ?? 'unknown';

        $this->logDebug(sprintf(
            'Applying CSS rules to element type=%s, id=%s, classes=%s',
            (string) $elementType,
            is_scalar($elementId) ? (string) $elementId : 'none',
            implode(',', is_array($elementClasses) ? $elementClasses : []) ?: 'none'
        ));

        $matchedCount = 0;
        $jsFinalStateBaseSelectors = $this->buildJsFinalStateBaseSelectorLookup($cssRules);
        $winningDeclarations = [];
        $sourceOrder = 0;

        foreach ($cssRules as $rule) {
            $sourceOrder++;
            $selector = trim((string) $rule['selector']);
            if ($selector === '') {
                continue;
            }

            if ($this->selectorStartsWithAliasedClass($selector)) {
                if ($this->selectorImporter->canImportCssRule($rule)) {
                    $this->markConsumedCssSelector($selector, $rule['media'] ?? null);
                }
                continue;
            }

            if (isset($rule['media']) && trim((string) $rule['media']) !== '') {
                continue;
            }

            if ($this->selectorMatcher->containsPseudo($selector)) {
                continue;
            }

            $matched = $this->selectorMatcher->matchesElement(
                $selector,
                $this->expandClassesForAliasMatching(is_array($elementClasses) ? $elementClasses : []),
                is_scalar($elementId) ? (string) $elementId : null,
                $node,
                $element
            );
            if ($matched) {
                $this->logDebug("Matched selector: $selector");
            }

            if ($matched && $this->environment->shouldUseWindPressMode()) {
                $matchedCount++;
                continue;
            }

            if (!$matched) {
                continue;
            }

            $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
            $declarations = $this->filterJsInitialStateDeclarations(
                $selector,
                $declarations,
                $jsFinalStateBaseSelectors
            );
            if ($declarations === []) {
                continue;
            }
            $this->mergeCascadeDeclarations(
                $winningDeclarations,
                $declarations,
                is_array($rule['importantDeclarations'] ?? null) ? $rule['importantDeclarations'] : [],
                $this->selectorSpecificity($selector),
                $sourceOrder
            );
            $this->trackConsumedCssDeclarations($selector, $declarations);
            $expandedDeclarations = $this->expandShorthandProperties($declarations);
            $materializedDeclarations = $this->filterNeutralFallbackDeclarations($expandedDeclarations);
            if ($this->styleExtractor->supportsDeclarationsFully($materializedDeclarations)) {
                $this->markConsumedCssSelector($selector);
            }
            $matchedCount++;
        }

        $inlineStyle = trim($node->getAttribute('style'));
        if ($inlineStyle !== '') {
            $this->mergeCascadeDeclarations(
                $winningDeclarations,
                $this->cssParser->parseDeclarations($inlineStyle),
                $this->cssParser->parseImportantDeclarations($inlineStyle),
                [1, 0, 0, 0],
                PHP_INT_MAX
            );
        }

        $resolvedDeclarations = [];
        foreach ($winningDeclarations as $property => $candidate) {
            $resolvedDeclarations[$property] = $candidate['value'];
        }
        $materializedDeclarations = $this->filterNeutralFallbackDeclarations($resolvedDeclarations);
        $convertedStyles = $this->styleExtractor->toOxygenProperties($materializedDeclarations);

        if ($convertedStyles !== []) {
            $this->logDebug(sprintf('Applying cascade-resolved styles: %s', json_encode($convertedStyles)));
            $element['data']['properties'] = $this->mergeAssociativeProperties(
                $element['data']['properties'],
                ['design' => $convertedStyles]
            );
        }

        $this->logDebug("Total rules matched: $matchedCount");
    }

    /**
     * @param array<string,array{value:string,important:bool,specificity:array{int,int,int,int},sourceOrder:int}> $winners
     * @param array<string,string> $declarations
     * @param array<string,bool> $importantDeclarations
     * @param array{int,int,int,int} $specificity
     */
    private function mergeCascadeDeclarations(
        array &$winners,
        array $declarations,
        array $importantDeclarations,
        array $specificity,
        int $sourceOrder
    ): void {
        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            if ($property === '' || !is_scalar($value)) {
                continue;
            }

            $expanded = $this->expandShorthandProperties([$property => (string) $value]);
            foreach ($expanded as $expandedProperty => $expandedValue) {
                $candidate = [
                    'value' => (string) $expandedValue,
                    'important' => !empty($importantDeclarations[$property]),
                    'specificity' => $specificity,
                    'sourceOrder' => $sourceOrder,
                ];

                if (!isset($winners[$expandedProperty]) || $this->cascadeCandidateWins($candidate, $winners[$expandedProperty])) {
                    $winners[$expandedProperty] = $candidate;
                }
            }
        }
    }

    /**
     * @param array{value:string,important:bool,specificity:array{int,int,int,int},sourceOrder:int} $candidate
     * @param array{value:string,important:bool,specificity:array{int,int,int,int},sourceOrder:int} $current
     */
    private function cascadeCandidateWins(array $candidate, array $current): bool
    {
        if ($candidate['important'] !== $current['important']) {
            return $candidate['important'];
        }

        for ($index = 0; $index < 4; $index++) {
            if ($candidate['specificity'][$index] === $current['specificity'][$index]) {
                continue;
            }

            return $candidate['specificity'][$index] > $current['specificity'][$index];
        }

        return $candidate['sourceOrder'] >= $current['sourceOrder'];
    }

    /**
     * Calculate author-selector specificity as [inline, ids, class-like, types].
     *
     * @return array{int,int,int,int}
     */
    private function selectorSpecificity(string $selector): array
    {
        $withoutWhere = preg_replace('/:where\([^)]*\)/i', '', $selector);
        $selector = is_string($withoutWhere) ? $withoutWhere : $selector;

        $idCount = preg_match_all('/#[A-Za-z_][A-Za-z0-9_-]*/', $selector);
        $classCount = preg_match_all('/\.[A-Za-z_][A-Za-z0-9_-]*/', $selector);
        $attributeCount = preg_match_all('/\[[^\]]+\]/', $selector);
        $pseudoClassCount = preg_match_all('/:(?!:)[A-Za-z_-][A-Za-z0-9_-]*/', $selector);
        $pseudoElementCount = preg_match_all('/::[A-Za-z_-][A-Za-z0-9_-]*/', $selector);

        $typeCount = 0;
        if (preg_match_all('/(?:^|[\s>+~,(])([A-Za-z][A-Za-z0-9_-]*|\*)/', $selector, $matches) > 0) {
            foreach ($matches[1] as $type) {
                if ($type !== '*') {
                    $typeCount++;
                }
            }
        }

        return [
            0,
            (int) $idCount,
            (int) $classCount + (int) $attributeCount + (int) $pseudoClassCount,
            $typeCount + (int) $pseudoElementCount,
        ];
    }

    /**
     * @param list<array{selector:string, declarations:array<string, string>, media?:string}> $cssRules
     */
    public function markSemanticAliasCssRulesConsumed(array $cssRules): void
    {
        foreach ($cssRules as $rule) {
            $selector = trim((string) $rule['selector']);
            if (
                $selector === ''
                || !$this->selectorStartsWithAliasedClass($selector)
                || !$this->selectorImporter->canImportCssRule($rule)
            ) {
                continue;
            }

            $this->markConsumedCssSelector($selector, $rule['media'] ?? null);
        }
    }

    /**
     * @param list<array{selector:string, declarations:array<string, string>, media?:string}> $cssRules
     * @param list<string> $sourceClassTokens
     */
    public function markImportableSelectorCssRulesConsumed(array $cssRules, array $sourceClassTokens): void
    {
        if (!$this->inlineStyles || $this->environment->shouldUseWindPressMode()) {
            return;
        }

        $sourceClassLookup = array_fill_keys($sourceClassTokens, true);

        foreach ($cssRules as $rule) {
            $selector = trim((string) $rule['selector']);
            if ($selector === '' || !$this->selectorImporter->canImportCssRule($rule)) {
                continue;
            }

            $className = $this->classNameFromSelectorPrefix($selector);
            if ($className === null || !isset($sourceClassLookup[$className])) {
                continue;
            }

            $expandedDeclarations = $this->expandShorthandProperties($rule['declarations']);
            $materializedDeclarations = $this->filterNeutralFallbackDeclarations($expandedDeclarations);
            if (!$this->styleExtractor->supportsDeclarationsFully($materializedDeclarations)) {
                continue;
            }

            $this->markConsumedCssSelector($selector, $rule['media'] ?? null);
        }
    }

    public function cleanupConsumedCssRules(string $css): string
    {
        if (empty($this->consumedCssSelectors) && empty($this->consumedCssDeclarations)) {
            return $css;
        }

        $fullyConsumed = array_fill_keys(array_map('strval', array_keys($this->consumedCssSelectors)), true);
        $partiallyConsumed = $this->consumedCssDeclarations;

        $callback = null;
        $callback = function (
            string $selector,
            string $selectorRaw,
            string $block,
            ?string $mediaContext = null
        ) use (&$callback, $fullyConsumed, $partiallyConsumed): ?string {
            if ($this->cssBlockContainsNestedRule($block)) {
                if (preg_match('/^@media\b/i', $selector) === 1 && $callback !== null) {
                    $cleanedBlock = $this->rewriteCssRuleBlocks($block, $callback, $selector);

                    return trim($cleanedBlock) === '' ? '' : $selectorRaw . '{' . $cleanedBlock . '}';
                }

                return null;
            }

            $consumptionKey = $this->cssConsumptionKey($selector, $mediaContext);
            if (isset($fullyConsumed[$consumptionKey])) {
                return '';
            }

            if (!isset($partiallyConsumed[$consumptionKey])) {
                return null;
            }

            $remainingBlock = $this->removeConsumedDeclarationsFromCssBlock($block, $partiallyConsumed[$consumptionKey]);
            if (trim($remainingBlock) === '') {
                return '';
            }

            return $selectorRaw . '{' . $remainingBlock . '}';
        };

        return $this->rewriteCssRuleBlocks($css, $callback);
    }

    /**
     * @param list<array<string, mixed>> $cssRules
     */
    public function buildJsFinalStateOverrideCss(array $cssRules): string
    {
        $selectorRules = $this->indexCssRulesBySelector($cssRules);
        $overrides = [];

        foreach ($selectorRules as $selector => $rule) {
            if ($this->isBehaviorOnlyStateSelector($selector)) {
                continue;
            }

            foreach (self::JS_FINAL_STATE_CLASSES as $stateClass) {
                $finalSelector = $this->selectorWithFinalStateClass($selector, $stateClass);
                if ($finalSelector === null || !isset($selectorRules[$finalSelector])) {
                    continue;
                }

                $baseDeclarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
                $finalDeclarations = is_array($selectorRules[$finalSelector]['declarations'] ?? null)
                    ? $selectorRules[$finalSelector]['declarations']
                    : [];
                if (!$this->hasJsInitialStateDeclaration($selector, $baseDeclarations, $finalDeclarations, $stateClass)) {
                    continue;
                }

                $overrideDeclarations = $this->finalStateOverrideDeclarations($finalDeclarations);
                if ($overrideDeclarations === []) {
                    continue;
                }

                if ($stateClass === 'done') {
                    $overrideDeclarations['pointer-events'] = 'none';
                }

                $overrides[$selector] = array_merge($overrides[$selector] ?? [], $overrideDeclarations);
            }
        }

        if ($overrides === []) {
            return '';
        }

        $css = ["/* Override: JS-dependent final states promoted for safe-mode import. */"];
        foreach ($overrides as $selector => $declarations) {
            $parts = [];
            foreach ($declarations as $property => $value) {
                $parts[] = $property . ': ' . $value . ' !important;';
            }
            $css[] = $selector . ' { ' . implode(' ', $parts) . ' }';
        }

        return implode("\n", $css) . "\n";
    }

    /**
     * @param array<string, mixed> $element
     */
    public function appendNativeCssMirrorFallback(array &$element): void
    {
        $design = $element['data']['properties']['design'] ?? [];
        $declarations = is_array($design) ? $this->collectNativeCssMirrorDeclarations($design) : [];

        if ($declarations !== []) {
            $className = 'ohc-native-' . (int) ($element['id'] ?? 0);
            $this->appendInternalElementClass($element, $className);
            $this->nativeCssMirrorRules[] = '.' . $className . '{' . $this->formatNativeCssMirrorDeclarations($declarations) . '}';
        }

        if (!isset($element['children']) || !is_array($element['children'])) {
            return;
        }

        foreach ($element['children'] as &$child) {
            if (is_array($child)) {
                $this->appendNativeCssMirrorFallback($child);
            }
        }
        unset($child);
    }

    /**
     * @return list<string>
     */
    public function nativeCssMirrorRules(): array
    {
        return $this->nativeCssMirrorRules;
    }

    private function classNameFromSelectorPrefix(string $selector): ?string
    {
        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)/', trim($selector), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param list<array<string, mixed>> $cssRules
     * @return array<string, array<string, string>>
     */
    private function buildJsFinalStateBaseSelectorLookup(array $cssRules): array
    {
        $selectorRules = $this->indexCssRulesBySelector($cssRules);
        $lookup = [];

        foreach ($selectorRules as $selector => $rule) {
            foreach (self::JS_FINAL_STATE_CLASSES as $stateClass) {
                $finalSelector = $this->selectorWithFinalStateClass($selector, $stateClass);
                if ($finalSelector === null || !isset($selectorRules[$finalSelector])) {
                    continue;
                }

                $baseDeclarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
                $finalDeclarations = is_array($selectorRules[$finalSelector]['declarations'] ?? null)
                    ? $selectorRules[$finalSelector]['declarations']
                    : [];
                if ($this->hasJsInitialStateDeclaration($selector, $baseDeclarations, $finalDeclarations, $stateClass)) {
                    $lookup[$selector] = array_merge($lookup[$selector] ?? [], $finalDeclarations);
                }
            }
        }

        return $lookup;
    }

    /**
     * @param list<array<string, mixed>> $cssRules
     * @return array<string, array<string, mixed>>
     */
    private function indexCssRulesBySelector(array $cssRules): array
    {
        $indexed = [];

        foreach ($cssRules as $rule) {
            $selector = trim((string) ($rule['selector'] ?? ''));
            if ($selector !== '' && !isset($rule['media'])) {
                $indexed[$selector] = $rule;
            }
        }

        return $indexed;
    }

    private function selectorWithFinalStateClass(string $selector, string $stateClass): ?string
    {
        $selector = trim($selector);
        if ($selector === '' || $this->selectorMatcher->containsPseudo($selector)) {
            return null;
        }

        $result = preg_replace(
            '/(\.[A-Za-z_-][A-Za-z0-9_-]*)(?!.*\.[A-Za-z_-][A-Za-z0-9_-]*)/',
            '$1.' . $stateClass,
            $selector,
            1
        );

        return is_string($result) && $result !== $selector ? $result : null;
    }

    private function isBehaviorOnlyStateSelector(string $selector): bool
    {
        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', trim($selector), $matches) !== 1) {
            return false;
        }

        return isset(self::JS_BEHAVIOR_STATE_CLASSES[$matches[1]]);
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, string> $finalDeclarations
     */
    private function hasJsInitialStateDeclaration(
        string $selector,
        array $declarations,
        array $finalDeclarations,
        string $stateClass
    ): bool
    {
        foreach ($declarations as $property => $value) {
            if ($this->isJsHiddenStateDeclaration((string) $property, (string) $value, $finalDeclarations)) {
                return true;
            }
        }

        return $stateClass === 'done'
            && $this->isLikelyBlockingOverlaySelector($selector)
            && isset($finalDeclarations['transform']);
    }

    private function isLikelyBlockingOverlaySelector(string $selector): bool
    {
        return preg_match('/\.[A-Za-z0-9_-]*(?:loader|loading|preloader|splash)[A-Za-z0-9_-]*\b/i', $selector) === 1;
    }

    /**
     * @param array<string, array<string, string>> $jsFinalStateBaseSelectors
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function filterJsInitialStateDeclarations(
        string $selector,
        array $declarations,
        array $jsFinalStateBaseSelectors
    ): array {
        if (!isset($jsFinalStateBaseSelectors[$selector])) {
            return $declarations;
        }

        $finalDeclarations = $jsFinalStateBaseSelectors[$selector];

        return array_filter(
            $declarations,
            fn (string $value, string $property): bool => !$this->isJsInitialStateDeclaration(
                $property,
                $value,
                $finalDeclarations
            ),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array<string, string> $finalDeclarations
     */
    private function isJsInitialStateDeclaration(string $property, string $value, array $finalDeclarations): bool
    {
        if ($this->isJsHiddenStateDeclaration($property, $value, $finalDeclarations)) {
            return true;
        }

        $property = strtolower(trim($property));

        if (in_array($property, ['transform', 'transition', 'transition-delay', 'animation', 'animation-delay'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, string> $finalDeclarations
     */
    private function isJsHiddenStateDeclaration(string $property, string $value, array $finalDeclarations): bool
    {
        $property = strtolower(trim($property));
        $value = strtolower(trim($value));

        if ($property === 'opacity' && $this->isCssZero($value)) {
            return true;
        }

        if ($property === 'visibility' && $value === 'hidden') {
            return true;
        }

        if (
            in_array($property, ['width', 'height', 'max-height'], true)
            && $this->isCssZero($value)
            && isset($finalDeclarations[$property])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function finalStateOverrideDeclarations(array $declarations): array
    {
        $overrides = [];

        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            if (!isset(self::JS_FINAL_STATE_OVERRIDE_PROPERTIES[$property])) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $overrides[$property] = $value;
            }
        }

        return $overrides;
    }

    private function isCssZero(string $value): bool
    {
        return preg_match('/^0(?:\.0+)?(?:[a-z%]+)?$/i', trim($value)) === 1;
    }

    private function selectorStartsWithAliasedClass(string $selector): bool
    {
        return preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)/', trim($selector), $matches) === 1
            && isset($this->classAliases[$matches[1]]);
    }

    /**
     * @param array<int, mixed> $classes
     * @return list<string>
     */
    private function expandClassesForAliasMatching(array $classes): array
    {
        $expanded = [];

        foreach ($classes as $className) {
            if (is_scalar($className) && trim((string) $className) !== '') {
                $expanded[] = trim((string) $className);
            }
        }

        foreach ($this->classAliases as $source => $semantic) {
            if (in_array($semantic, $expanded, true) && !in_array($source, $expanded, true)) {
                $expanded[] = $source;
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Expand shorthand CSS properties into longhand equivalents.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function expandShorthandProperties(array $declarations): array
    {
        $expanded = [];

        foreach ($declarations as $property => $value) {
            if ($property === 'margin' || $property === 'padding') {
                $sides = $this->styleExtractor->parseShorthandSpacing($value);
                if (!empty($sides)) {
                    $expanded[$property . '-top'] = $sides['top'];
                    $expanded[$property . '-right'] = $sides['right'];
                    $expanded[$property . '-bottom'] = $sides['bottom'];
                    $expanded[$property . '-left'] = $sides['left'];
                } else {
                    $expanded[$property] = $value;
                }
            } elseif ($property === 'border' && preg_match('/^(\S+)\s+(\S+)\s+(.+)$/', $value, $m)) {
                $expanded['border-width'] = $m[1];
                $expanded['border-style'] = $m[2];
                $expanded['border-color'] = $m[3];
            } elseif ($property === 'background' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|[a-zA-Z]+)$/', trim($value))) {
                $expanded['background-color'] = trim($value);
            } else {
                $expanded[$property] = $value;
            }
        }

        return $expanded;
    }

    /**
     * Skip neutral fallback declarations that are useful in CSS utility rules
     * but should not be materialized into native Oxygen properties.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function filterNeutralFallbackDeclarations(array $declarations): array
    {
        $filtered = [];

        foreach ($declarations as $property => $value) {
            if ($property === 'color' && trim((string) $value) === 'inherit') {
                continue;
            }

            $filtered[$property] = $value;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeAssociativeProperties(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $merged)
                && is_array($merged[$key])
                && is_array($value)
                && $this->isAssocArray($merged[$key])
                && $this->isAssocArray($value)
            ) {
                $merged[$key] = $this->mergeAssociativeProperties($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function isAssocArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param callable(string, string, string, ?string): ?string $callback
     */
    private function rewriteCssRuleBlocks(string $css, callable $callback, ?string $mediaContext = null): string
    {
        $len = strlen($css);
        $result = '';
        $ruleStart = 0;
        $inString = false;
        $stringChar = '';
        $inComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $css[$i];
            $next = $css[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isCssCharacterEscaped($css, $i)) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inComment = true;
                $i++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($char !== '{') {
                continue;
            }

            $blockEnd = $this->findMatchingCssBlockEnd($css, $i);
            if ($blockEnd === null) {
                break;
            }

            $selectorRaw = substr($css, $ruleStart, $i - $ruleStart);
            $selector = $this->normalizeCssSelectorForCleanup($selectorRaw);
            $block = substr($css, $i + 1, $blockEnd - $i - 1);
            $replacement = $callback($selector, $selectorRaw, $block, $mediaContext);

            if ($replacement === null) {
                $result .= substr($css, $ruleStart, $blockEnd - $ruleStart + 1);
            } else {
                $result .= $replacement;
            }

            $i = $blockEnd;
            $ruleStart = $blockEnd + 1;
        }

        return $result . substr($css, $ruleStart);
    }

    private function findMatchingCssBlockEnd(string $css, int $blockStart): ?int
    {
        $len = strlen($css);
        $depth = 1;
        $inString = false;
        $stringChar = '';
        $inComment = false;

        for ($i = $blockStart + 1; $i < $len; $i++) {
            $char = $css[$i];
            $next = $css[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isCssCharacterEscaped($css, $i)) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inComment = true;
                $i++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function normalizeCssSelectorForCleanup(string $selectorRaw): string
    {
        $withoutComments = preg_replace('!/\*.*?\*/!s', '', $selectorRaw);
        return trim(is_string($withoutComments) ? $withoutComments : $selectorRaw);
    }

    private function markConsumedCssSelector(string $selector, mixed $media = null): void
    {
        $key = $this->cssConsumptionKey($selector, $media);
        if ($key !== '') {
            $this->consumedCssSelectors[$key] = true;
        }
    }

    private function cssConsumptionKey(string $selector, mixed $media = null): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }

        $mediaKey = $this->normalizeCssMediaForConsumption($media);
        if ($mediaKey === '') {
            return $selector;
        }

        return '@media ' . $mediaKey . '||' . $selector;
    }

    private function normalizeCssMediaForConsumption(mixed $media): string
    {
        $media = trim((string) $media);
        if ($media === '') {
            return '';
        }

        if (stripos($media, '@media') === 0) {
            $media = trim(substr($media, 6));
        }

        $normalized = preg_replace('/\s+/', ' ', $media);

        return trim(is_string($normalized) ? $normalized : $media);
    }

    private function cssBlockContainsNestedRule(string $block): bool
    {
        $len = strlen($block);
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $parenDepth = 0;
        $bracketDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $block[$i];
            $next = $block[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isCssCharacterEscaped($block, $i)) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inComment = true;
                $i++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                continue;
            }

            if ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
                continue;
            }

            if ($char === '[') {
                $bracketDepth++;
                continue;
            }

            if ($char === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
                continue;
            }

            if ($char === '{' && $parenDepth === 0 && $bracketDepth === 0) {
                return true;
            }
        }

        return false;
    }

    private function isCssCharacterEscaped(string $css, int $offset): bool
    {
        $slashes = 0;
        for ($i = $offset - 1; $i >= 0 && $css[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function trackConsumedCssDeclarations(string $selector, array $declarations): void
    {
        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            if ($property === '' || !$this->styleExtractor->supportsDeclaration($property, $value)) {
                continue;
            }

            $key = $this->cssConsumptionKey($selector);
            $this->consumedCssDeclarations[$key] = $this->consumedCssDeclarations[$key] ?? [];
            $this->consumedCssDeclarations[$key][] = $property;
        }

        $key = $this->cssConsumptionKey($selector);
        if (isset($this->consumedCssDeclarations[$key])) {
            $this->consumedCssDeclarations[$key] = array_values(array_unique($this->consumedCssDeclarations[$key]));
        }
    }

    /**
     * @param list<string> $properties
     */
    private function removeConsumedDeclarationsFromCssBlock(string $block, array $properties): string
    {
        $properties = array_fill_keys(array_map('strtolower', $properties), true);
        $remaining = [];

        foreach ($this->cssParser->parseDeclarationList($block) as $declaration) {
            $property = strtolower($declaration['property']);

            if (isset($properties[$property])) {
                continue;
            }

            $remaining[] = $declaration['property'] . ': ' . $declaration['value'];
        }

        return $remaining === [] ? '' : ' ' . implode('; ', $remaining) . '; ';
    }

    /**
     * @param array<string, mixed> $element
     */
    private function appendInternalElementClass(array &$element, string $className): void
    {
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];

        $classes = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (!is_array($classes)) {
            $classes = [];
        }

        if (!in_array($className, $classes, true)) {
            $classes[] = $className;
        }

        $element['data']['properties']['settings']['advanced']['classes'] = array_values($classes);
    }

    /**
     * @param array<string, mixed> $design
     * @return array<string, string>
     */
    private function collectNativeCssMirrorDeclarations(array $design): array
    {
        $declarations = [];
        $this->collectNativeCssMirrorDeclarationsRecursive($design, $declarations);

        return $declarations;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $declarations
     */
    private function collectNativeCssMirrorDeclarationsRecursive(array $properties, array &$declarations): void
    {
        foreach ($properties as $property => $value) {
            if (is_array($value)) {
                $this->collectNativeCssMirrorDeclarationsRecursive($value, $declarations);
                continue;
            }

            $property = strtolower(trim((string) $property));
            if (
                $property === ''
                || strpos($property, '-') === false
                || !isset(self::NATIVE_CSS_MIRROR_PROPERTIES[$property])
            ) {
                continue;
            }

            $normalizedValue = $this->normalizeNativeCssMirrorValue($value);
            if ($normalizedValue === null) {
                continue;
            }

            $declarations[$property] = $normalizedValue;
        }
    }

    private function normalizeNativeCssMirrorValue(mixed $value): ?string
    {
        if (is_bool($value) || $value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s*!important\s*$/i', '', $value);
        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/[{}<>;]/', $value) === 1) {
            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, string> $declarations
     */
    private function formatNativeCssMirrorDeclarations(array $declarations): string
    {
        $parts = [];
        foreach ($declarations as $property => $value) {
            $parts[] = $property . ':' . $value . ' !important;';
        }

        return implode('', $parts);
    }

    private function logDebug(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }
}
