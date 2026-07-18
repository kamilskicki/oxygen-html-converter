<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\StyleExtractor;

/**
 * Builds Oxygen selector records and attaches them to converted elements.
 */
class OxygenSelectorImporter
{
    private const COLLECTION = 'Imported HTML';

    private OxygenValueNormalizer $valueNormalizer;
    private OxygenTokenBindingService $tokenBindingService;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $selectorsByClass = [];

    /**
     * @var array<string, array<string, array<string, string>>>
     */
    private array $declarationsByClass = [];

    /**
     * @var array<string, array<string, array{name:string,pseudo:bool,declarations:array<string, array<string, string>>}>>
     */
    private array $childrenByClass = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $tokenReferences = [];

    /**
     * @var array<string, string>
     */
    private array $classAliases = [];

    public function __construct(?OxygenValueNormalizer $valueNormalizer = null, ?OxygenTokenBindingService $tokenBindingService = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new OxygenValueNormalizer();
        $this->tokenBindingService = $tokenBindingService ?? new OxygenTokenBindingService();
    }

    public function reset(): void
    {
        $this->selectorsByClass = [];
        $this->declarationsByClass = [];
        $this->childrenByClass = [];
        $this->classAliases = [];
    }

    /**
     * @param list<array<string, mixed>> $references
     */
    public function setTokenReferences(array $references): void
    {
        $this->tokenReferences = array_values(array_filter($references, static fn ($reference): bool => is_array($reference)));
    }

    /**
     * @param array<string, string> $aliases
     */
    public function setClassAliases(array $aliases): void
    {
        $this->classAliases = [];

        foreach ($aliases as $source => $semantic) {
            $source = trim((string) $source);
            $semantic = trim((string) $semantic);

            if ($this->isNativeSelectorClassName($source) && $this->isNativeSelectorClassName($semantic)) {
                $this->classAliases[$source] = $semantic;
            }
        }
    }

    /**
     * @param array<int, array{selector:string, declarations:array<string, string>, media?:string}> $cssRules
     */
    public function setCssRules(array $cssRules): void
    {
        $this->declarationsByClass = [];
        $this->childrenByClass = [];

        foreach ($cssRules as $rule) {
            if (!$this->canImportCssRule($rule)) {
                continue;
            }

            $selector = trim($rule['selector']);
            $selectorTarget = $this->parseSelectorTarget($selector);
            $breakpointKey = $this->breakpointKeyForMedia($rule['media'] ?? null);

            $className = $this->semanticClassFor($selectorTarget['className']);
            $declarations = $this->normalizeDeclarations($rule['declarations']);

            if ($selectorTarget['childName'] === null) {
                $this->declarationsByClass[$className][$breakpointKey] = array_merge(
                    $this->declarationsByClass[$className][$breakpointKey] ?? [],
                    $declarations
                );
                continue;
            }

            $childKey = $selectorTarget['childName'];
            $this->childrenByClass[$className][$childKey] = $this->childrenByClass[$className][$childKey] ?? [
                'name' => $selectorTarget['childName'],
                'pseudo' => $selectorTarget['pseudo'],
                'declarations' => [],
            ];
            $this->childrenByClass[$className][$childKey]['pseudo'] = $this->childrenByClass[$className][$childKey]['pseudo'] || $selectorTarget['pseudo'];
            $this->childrenByClass[$className][$childKey]['declarations'][$breakpointKey] = array_merge(
                $this->childrenByClass[$className][$childKey]['declarations'][$breakpointKey] ?? [],
                $declarations
            );
        }
    }

    /**
     * Persist the cascade-resolved style for one element as an Oxygen selector.
     * Oxygen's native elements do not render arbitrary `properties.design`
     * values on the frontend, while selectors are compiled into the global
     * Oxygen stylesheet. A declaration hash keeps the selector stable across
     * imports and prevents node-id collisions between documents.
     *
     * @param array<string, string> $declarations
     * @param array<string, mixed> $element
     */
    public function attachResolvedStyleSelector(array $declarations, array &$element): ?string
    {
        $declarations = $this->normalizeDeclarations($declarations);
        if ($declarations === []) {
            return null;
        }

        ksort($declarations);
        $signature = json_encode($declarations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($signature)) {
            return null;
        }

        $className = 'ohc-style-' . substr(hash('sha256', $signature), 0, 12);
        $this->declarationsByClass[$className]['breakpoint_base'] = $declarations;

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

        return $className;
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function canImportCssRule(array $rule): bool
    {
        $selector = is_string($rule['selector'] ?? null) ? trim($rule['selector']) : '';
        $declarations = is_array($rule['declarations'] ?? null) ? $this->normalizeDeclarations($rule['declarations']) : [];

        return $selector !== ''
            && $this->parseSelectorTarget($selector) !== null
            && $this->breakpointKeyForMedia($rule['media'] ?? null) !== null
            && $declarations !== [];
    }

    /**
     * @param array<int, mixed> $classes
     * @param array<string, mixed> $element
     */
    public function syncElementClasses(array $classes, array &$element): void
    {
        $selectorIds = [];
        $selectorIdsByClass = [];

        foreach ($this->normalizeClasses($classes) as $className) {
            $className = $this->semanticClassFor($className);
            if (!$this->isNativeSelectorClassName($className)) {
                continue;
            }

            $selector = $this->ensureSelector($className);
            $selectorIds[] = $selector['id'];
            $selectorIdsByClass[$className] = $selector['id'];
        }

        $selectorIds = array_values(array_unique($selectorIds));
        if ($selectorIds === []) {
            unset($element['data']['properties']['meta']['classes']);
            unset($element['data']['properties']['meta']['classes_conditions']);
            return;
        }

        $classConditions = $this->remapClassConditions(
            $element['data']['properties']['meta']['classes_conditions'] ?? [],
            $selectorIdsByClass
        );

        $element['data']['properties']['meta'] = $element['data']['properties']['meta'] ?? [];
        $element['data']['properties']['meta']['classes'] = $selectorIds;
        if ($classConditions !== []) {
            $element['data']['properties']['meta']['classes_conditions'] = $classConditions;
        } else {
            unset($element['data']['properties']['meta']['classes_conditions']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $selectors = array_values($this->selectorsByClass);
        $collections = $selectors === [] ? [] : [self::COLLECTION];

        return [
            'selectors' => $selectors,
            'collections' => $collections,
            'persistence' => [
                'requiresTreeJsonString' => true,
                'requiresOxygenSelectorPersistence' => $selectors !== [],
                'requiresBreakdanceClassesJsonString' => false,
                'persistsBreakdanceClassesJsonString' => $selectors !== [],
                'oxygenSelectorsOptionName' => 'oxygen_oxy_selectors_json_string',
                'oxygenSelectorCollectionsOptionName' => 'oxygen_oxy_selectors_collections_json_string',
                'breakdanceClassesOptionName' => 'breakdance_classes_json_string',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureSelector(string $className): array
    {
        if (isset($this->selectorsByClass[$className])) {
            return $this->selectorsByClass[$className];
        }

        $declarations = $this->declarationsByClass[$className] ?? [];
        $selector = [
            'id' => $this->deterministicUuid($className),
            'name' => $className,
            'selector' => '.' . $className,
            'type' => 'class',
            'collection' => self::COLLECTION,
            'locked' => false,
            'children' => $this->buildSelectorChildren($className),
            'properties' => $this->buildSelectorProperties($declarations) ?: new \stdClass(),
        ];

        $this->selectorsByClass[$className] = $selector;

        return $selector;
    }

    /**
     * @param array<string, array<string, string>> $declarationsByBreakpoint
     * @return array<string, mixed>
     */
    private function buildSelectorProperties(array $declarationsByBreakpoint): array
    {
        $properties = [];

        foreach ($declarationsByBreakpoint as $breakpointKey => $declarations) {
            $base = $this->buildSelectorBreakpointProperties($declarations);
            if ($base !== []) {
                $properties[$breakpointKey] = $base;
            }
        }

        return $properties;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSelectorChildren(string $className): array
    {
        $children = [];

        foreach ($this->childrenByClass[$className] ?? [] as $child) {
            $properties = $this->buildSelectorProperties($child['declarations']);
            if ($properties === []) {
                continue;
            }

            $record = [
                'id' => $this->deterministicUuid($className . ':' . $child['name']),
                'name' => $child['name'],
                'locked' => false,
                'properties' => $properties,
            ];

            if ($child['pseudo']) {
                $record['pseudo'] = true;
            }

            $children[] = $record;
        }

        return $children;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, mixed>
     */
    private function buildSelectorBreakpointProperties(array $declarations): array
    {
        $base = [];
        $customCss = [];

        foreach ($this->expandSpacingDeclarations($declarations) as $property => $value) {
            $property = strtolower(trim((string) $property));
            $value = trim((string) $value);
            if ($property === '' || $value === '' || strpos($property, '_') === 0) {
                continue;
            }

            $handled = $this->routeSelectorProperty($base, $property, $value);
            if (!$handled) {
                $customCss[$property] = $value;
            }
        }

        if ($customCss !== []) {
            $base['custom_css']['custom_css'] = $this->buildCustomCssBlock($customCss);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $base
     */
    private function routeSelectorProperty(array &$base, string $property, string $value): bool
    {
        $assignments = StyleExtractor::controlAssignmentsForDeclaration($property, $value);
        if ($assignments === []) {
            return false;
        }

        $converted = [];
        foreach ($assignments as $assignment) {
            $bound = $this->tokenReferences === []
                ? [
                    'value' => $assignment['value'],
                    'reference' => null,
                ]
                : $this->tokenBindingService->bindControlValue(
                    $assignment['path'],
                    $assignment['value'],
                    $property,
                    $this->tokenReferences,
                    'selector'
                );

            $normalizedValue = $this->valueNormalizer->normalizeForPath(
                $assignment['path'],
                $bound['value'],
                $property
            );

            if ($normalizedValue === null) {
                return false;
            }

            $converted[] = [
                'path' => $assignment['path'],
                'value' => $normalizedValue,
            ];
        }

        foreach ($converted as $assignment) {
            $this->setNestedValue($base, $assignment['path'], $assignment['value']);
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function expandSpacingDeclarations(array $declarations): array
    {
        $expanded = [];

        foreach ($this->normalizeDeclarations($declarations) as $property => $value) {
            if ($property === 'padding' || $property === 'margin') {
                $sides = $this->expandBoxValue($value);
                foreach ($sides as $side => $sideValue) {
                    $expanded[$property . '-' . $side] = $sideValue;
                }
                continue;
            }

            $expanded[$property] = $value;
        }

        return $expanded;
    }

    /**
     * @return array{top:string,right:string,bottom:string,left:string}
     */
    private function expandBoxValue(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return match (count($parts)) {
            1 => ['top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0]],
            2 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1]],
            3 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1]],
            default => [
                'top' => $parts[0] ?? '0',
                'right' => $parts[1] ?? ($parts[0] ?? '0'),
                'bottom' => $parts[2] ?? ($parts[0] ?? '0'),
                'left' => $parts[3] ?? ($parts[1] ?? ($parts[0] ?? '0')),
            ],
        };
    }

    /**
     * @param array<string, string> $declarations
     */
    private function buildCustomCssBlock(array $declarations): string
    {
        $lines = [':selector {'];
        foreach ($declarations as $property => $value) {
            $lines[] = '  ' . $property . ': ' . $value . ';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $array
     * @param array<int, string> $path
     * @param mixed $value
     */
    private function setNestedValue(array &$array, array $path, $value): void
    {
        $current = &$array;
        foreach ($path as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    /**
     * @param array<int|string, mixed> $declarations
     * @return array<string, string>
     */
    private function normalizeDeclarations(array $declarations): array
    {
        $normalized = [];

        foreach ($declarations as $property => $value) {
            if (!is_scalar($property) || !is_scalar($value)) {
                continue;
            }

            $property = strtolower(trim((string) $property));
            $value = trim((string) $value);
            if ($property === '' || $value === '') {
                continue;
            }

            $normalized[$property] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $classes
     * @return array<int, string>
     */
    private function normalizeClasses(array $classes): array
    {
        $normalized = [];

        foreach ($classes as $className) {
            if (!is_scalar($className)) {
                continue;
            }

            $className = trim((string) $className);
            if ($className !== '') {
                $normalized[] = $className;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function semanticClassFor(string $className): string
    {
        return $this->classAliases[$className] ?? $className;
    }

    /**
     * @param mixed $conditions
     * @param array<string, string> $selectorIdsByClass
     * @return array<string, mixed>
     */
    private function remapClassConditions($conditions, array $selectorIdsByClass): array
    {
        if (!is_array($conditions)) {
            return [];
        }

        $remapped = [];
        $knownSelectorIds = array_fill_keys(array_values($selectorIdsByClass), true);

        foreach ($conditions as $conditionKey => $condition) {
            if (!is_string($conditionKey) || !is_array($condition)) {
                continue;
            }

            $normalizedClassKey = ltrim($conditionKey, '.');
            $selectorId = $selectorIdsByClass[$normalizedClassKey] ?? null;

            if ($selectorId === null && isset($knownSelectorIds[$conditionKey])) {
                $selectorId = $conditionKey;
            }

            if ($selectorId === null || !$this->isValidClassCondition($condition)) {
                continue;
            }

            $remapped[$selectorId] = $condition;
        }

        return $remapped;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function isValidClassCondition(array $condition): bool
    {
        return isset($condition['ruleGroups']) && is_array($condition['ruleGroups']);
    }

    private function extractSimpleClassSelector(string $selector): ?string
    {
        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', trim($selector), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array{className:string, childName:?string, pseudo:bool}|null
     */
    private function parseSelectorTarget(string $selector): ?array
    {
        $selector = trim($selector);
        $className = $this->extractSimpleClassSelector($selector);
        if ($className !== null) {
            return [
                'className' => $className,
                'childName' => null,
                'pseudo' => false,
            ];
        }

        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)(.+)$/', $selector, $matches) !== 1) {
            return null;
        }

        $className = $matches[1];
        $tail = $matches[2];
        if (!$this->isSupportedNestedSelectorTail($tail)) {
            return null;
        }

        $childName = null;
        $pseudo = false;

        if (preg_match('/^(:[A-Za-z-]+(?:\([^)]*\))?)(.*)$/', $tail, $pseudoMatches) === 1) {
            $pseudoName = strtolower($pseudoMatches[1]);
            if (!$this->isSupportedPseudoSelector($pseudoName)) {
                return null;
            }

            $remainder = trim($pseudoMatches[2]);
            $childName = '&' . $pseudoName . ($remainder === '' ? '' : ' ' . $remainder);
            $pseudo = true;
        } elseif (preg_match('/^\s*>\s*(.+)$/', $tail, $childMatches) === 1) {
            $childName = '& > ' . trim($childMatches[1]);
        } elseif (preg_match('/^\s+(.+)$/', $tail, $descendantMatches) === 1) {
            $childName = '& ' . trim($descendantMatches[1]);
        } elseif (preg_match('/^\.[A-Za-z_-][A-Za-z0-9_-]*$/', $tail) === 1) {
            $childName = '&' . $tail;
        }

        if ($childName === null || !$this->isSupportedChildSelectorName($childName)) {
            return null;
        }

        return [
            'className' => $className,
            'childName' => $childName,
            'pseudo' => $pseudo || $this->selectorNameContainsPseudo($childName),
        ];
    }

    private function isSupportedNestedSelectorTail(string $tail): bool
    {
        if (preg_match('/[{};,~+]/', $tail) === 1) {
            return false;
        }

        if (strpos($tail, '[') !== false || strpos($tail, ']') !== false) {
            return false;
        }

        return true;
    }

    private function isSupportedChildSelectorName(string $name): bool
    {
        if (preg_match('/[{};,~+]/', $name) === 1) {
            return false;
        }

        if (strpos($name, '[') !== false || strpos($name, ']') !== false) {
            return false;
        }

        return strpos($name, '&') === 0 && trim($name) !== '&';
    }

    private function isSupportedPseudoSelector(string $pseudo): bool
    {
        return in_array($pseudo, [
            ':hover',
            ':focus',
            ':active',
            ':visited',
            ':disabled',
            ':checked',
            ':focus-visible',
            ':focus-within',
        ], true);
    }

    private function selectorNameContainsPseudo(string $selector): bool
    {
        return preg_match('/::?[A-Za-z-]+(?:\([^)]*\))?/', $selector) === 1;
    }

    private function breakpointKeyForMedia(?string $media): ?string
    {
        $media = trim((string) $media);
        if ($media === '') {
            return 'breakpoint_base';
        }

        if (preg_match('/^\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', $media, $matches) !== 1) {
            return null;
        }

        $maxWidth = (int) round((float) $matches[1]);

        return match ($maxWidth) {
            1119 => 'breakpoint_tablet_landscape',
            1023 => 'breakpoint_tablet_portrait',
            767 => 'breakpoint_phone_landscape',
            479 => 'breakpoint_phone_portrait',
            default => null,
        };
    }

    private function isNativeSelectorClassName(string $className): bool
    {
        return preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $className) === 1;
    }

    private function deterministicUuid(string $className): string
    {
        $hash = sha1('oxy-html-converter-selector:' . $className);
        $variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return substr($hash, 0, 8)
            . '-' . substr($hash, 8, 4)
            . '-5' . substr($hash, 13, 3)
            . '-' . $variant . substr($hash, 17, 3)
            . '-' . substr($hash, 20, 12);
    }

}
