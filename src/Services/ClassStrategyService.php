<?php

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\Report\ConversionReport;

/**
 * Strategy service for handling CSS classes in different modes
 */
class ClassStrategyService
{
    private const SEMANTIC_DEDUPE_MIN_OCCURRENCES = 2;
    private const SEMANTIC_DEDUPE_MIN_CONFIDENCE = 0.9;

    private EnvironmentService $environment;
    private ConversionReport $report;
    private TailwindDetector $tailwindDetector;
    private TailwindPropertyMapper $tailwindPropertyMapper;
    /**
     * @var array<string, string>
     */
    private array $classAliases = [];

    public function __construct(
        EnvironmentService $environment,
        ConversionReport $report,
        TailwindDetector $tailwindDetector,
        TailwindPropertyMapper $tailwindPropertyMapper
    )
    {
        $this->environment = $environment;
        $this->report = $report;
        $this->tailwindDetector = $tailwindDetector;
        $this->tailwindPropertyMapper = $tailwindPropertyMapper;
    }

    /**
     * Process classes for an element based on the current mode
     *
     * @param array $classes Original class names
     * @param array &$element Reference to the Oxygen element structure
     */
    public function processClasses(array $classes, array &$element): void
    {
        if (empty($classes)) {
            return;
        }

        if ($this->environment->shouldUseWindPressMode()) {
            $this->processWindPressMode($classes, $element);
        } else {
            $this->processOxygenNativeMode($classes, $element);
        }
    }

    /**
     * @param array<string, string> $aliases
     */
    public function setClassAliases(array $aliases): void
    {
        $normalized = [];

        foreach ($aliases as $source => $semantic) {
            $source = $this->normalizeClassName((string) $source);
            $semantic = $this->normalizeClassName((string) $semantic);

            if ($source !== '' && $semantic !== '') {
                $normalized[$source] = $semantic;
            }
        }

        $this->classAliases = $normalized;
    }

    /**
     * @param array<int, array{selector:string, declarations:array<string, string>, media?:string}> $cssRules
     * @param list<string> $classTokens
     * @return array{aliases:array<string,string>,classMap:list<array<string,mixed>>,duplicateStylePatterns:list<array<string,mixed>>,skippedPatterns:list<array<string,mixed>>,selectorCountReduction:int}
     */
    public static function buildSemanticClassProfile(array $cssRules, array $classTokens): array
    {
        $usage = self::countClassUsage($classTokens);
        $declarationsByClass = [];
        $fullSignaturesByClass = [];

        foreach ($cssRules as $rule) {
            $selectorTarget = self::parseProfileSelectorTarget($rule['selector']);
            $className = $selectorTarget['className'];
            $declarations = self::normalizeProfileDeclarations($rule['declarations']);

            if ($className === '' || $declarations === [] || !isset($usage[$className])) {
                continue;
            }

            $media = isset($rule['media']) ? trim((string) $rule['media']) : '';
            $signatureKey = ($media === '' ? '' : 'media:' . $media . '|') . $selectorTarget['scope'];
            $fullSignaturesByClass[$className][$signatureKey] = array_merge(
                $fullSignaturesByClass[$className][$signatureKey] ?? [],
                $declarations
            );

            if ($signatureKey === 'base') {
                $declarationsByClass[$className] = array_merge($declarationsByClass[$className] ?? [], $declarations);
                ksort($declarationsByClass[$className]);
            }
        }

        $classesBySignature = [];
        foreach ($declarationsByClass as $className => $declarations) {
            $signature = self::styleSignature($declarations);
            if ($signature === '') {
                continue;
            }

            $classesBySignature[$signature][] = $className;
        }

        ksort($classesBySignature);
        $aliases = [];
        $classMap = [];
        $patterns = [];
        $skippedPatterns = [];
        $reservedSemanticNames = [];

        foreach ($classesBySignature as $signature => $classes) {
            $classes = array_values(array_unique($classes));
            sort($classes, SORT_STRING);

            if (count($classes) < 2) {
                continue;
            }

            $fullSignatures = array_values(array_unique(array_map(
                static fn (string $className): string => self::fullStyleSignature($fullSignaturesByClass[$className] ?? []),
                $classes
            )));
            if (count($fullSignatures) > 1) {
                $skippedPatterns[] = [
                    'styleSignature' => $signature,
                    'sourceClasses' => $classes,
                    'occurrences' => array_sum(array_map(static fn (string $className): int => $usage[$className] ?? 0, $classes)),
                    'threshold' => self::semanticDedupeThreshold(),
                    'reason' => 'state_or_responsive_mismatch',
                    'action' => 'keep_source_selectors',
                ];
                continue;
            }

            $semanticClass = self::uniqueSemanticClassName(self::semanticClassNameForClasses($classes), $signature, $reservedSemanticNames);
            $occurrences = array_sum(array_map(static fn (string $className): int => $usage[$className] ?? 0, $classes));
            $threshold = self::semanticDedupeThreshold();
            $confidence = $occurrences >= self::SEMANTIC_DEDUPE_MIN_OCCURRENCES ? 0.95 : 0.0;
            if ($confidence < self::SEMANTIC_DEDUPE_MIN_CONFIDENCE) {
                $skippedPatterns[] = [
                    'styleSignature' => $signature,
                    'sourceClasses' => $classes,
                    'occurrences' => $occurrences,
                    'threshold' => $threshold,
                    'reason' => 'below_confidence_threshold',
                    'action' => 'keep_source_selectors',
                ];
                continue;
            }

            foreach ($classes as $className) {
                $aliases[$className] = $semanticClass;
                $classMap[] = [
                    'sourceClass' => $className,
                    'semanticClass' => $semanticClass,
                    'role' => self::semanticRoleForClasses([$className]),
                    'styleSignature' => $signature,
                    'occurrences' => $usage[$className] ?? 0,
                    'selector' => '.' . $semanticClass,
                    'confidence' => $confidence,
                    'threshold' => $threshold,
                    'action' => 'dedupe_selector',
                ];
            }

            $patterns[] = [
                'styleSignature' => $signature,
                'semanticClass' => $semanticClass,
                'sourceClasses' => $classes,
                'occurrences' => $occurrences,
                'declarationCount' => substr_count($signature, ';') + 1,
                'confidence' => $confidence,
                'threshold' => $threshold,
                'action' => 'dedupe_selector',
            ];
        }

        usort($classMap, static fn (array $left, array $right): int => strcmp((string) $left['sourceClass'], (string) $right['sourceClass']));
        usort($patterns, static fn (array $left, array $right): int => strcmp((string) $left['semanticClass'], (string) $right['semanticClass']));
        usort($skippedPatterns, static fn (array $left, array $right): int => strcmp(implode(' ', $left['sourceClasses']), implode(' ', $right['sourceClasses'])));
        ksort($aliases);

        return [
            'aliases' => $aliases,
            'classMap' => $classMap,
            'duplicateStylePatterns' => $patterns,
            'skippedPatterns' => $skippedPatterns,
            'selectorCountReduction' => array_sum(array_map(static fn (array $pattern): int => max(0, count($pattern['sourceClasses']) - 1), $patterns)),
        ];
    }

    /**
     * WindPress Mode: Store all classes as-is
     */
    private function processWindPressMode(array $classes, array &$element): void
    {
        foreach ($classes as $className) {
            if ($this->tailwindDetector->isTailwindClass($className)) {
                $this->report->incrementTailwindClassCount();
            } else {
                $this->report->incrementCustomClassCount();
            }
        }

        $this->setElementClasses($element, $classes);
    }

    /**
     * Oxygen Native Mode: Separate Tailwind and custom classes
     */
    private function processOxygenNativeMode(array $classes, array &$element): void
    {
        $customClasses = [];
        $preservedTailwindClasses = [];
        $mappedProperties = [];
        $preservedUnsupportedTailwind = false;

        foreach ($classes as $className) {
            $mappedClassProperties = $this->tailwindPropertyMapper->mapClass($className);
            if ($mappedClassProperties !== []) {
                $this->report->incrementTailwindClassCount();
                $mappedProperties = $this->mergeAssociativeProperties($mappedProperties, $mappedClassProperties);
                continue;
            }

            if ($this->tailwindDetector->isTailwindClass($className)) {
                $this->report->incrementTailwindClassCount();

                $preservedTailwindClasses[] = $className;
                $preservedUnsupportedTailwind = true;
            } else {
                $customClasses[] = $this->semanticClassFor($className);
                $this->report->incrementCustomClassCount();
            }
        }

        if ($mappedProperties !== []) {
            $element['data']['properties'] = $this->mergeAssociativeProperties(
                $element['data']['properties'] ?? [],
                ['design' => $mappedProperties]
            );
        }

        if ($preservedUnsupportedTailwind) {
            $this->report->addWarning('Native mode preserved unsupported Tailwind utilities as classes to maintain parity.');
        }

        $this->setElementClasses($element, array_merge($customClasses, $preservedTailwindClasses));
    }

    private function semanticClassFor(string $className): string
    {
        $normalized = $this->normalizeClassName($className);

        return $this->classAliases[$normalized] ?? $normalized;
    }

    private function normalizeClassName(string $className): string
    {
        $className = trim($className);

        return preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $className) === 1 ? $className : '';
    }

    /**
     * @param list<string> $classTokens
     * @return array<string, int>
     */
    private static function countClassUsage(array $classTokens): array
    {
        $usage = [];

        foreach ($classTokens as $className) {
            $className = trim((string) $className);
            if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $className) !== 1) {
                continue;
            }

            $usage[$className] = ($usage[$className] ?? 0) + 1;
        }

        ksort($usage);

        return $usage;
    }

    /**
     * @return array{minOccurrences:int,minConfidence:float}
     */
    private static function semanticDedupeThreshold(): array
    {
        return [
            'minOccurrences' => self::SEMANTIC_DEDUPE_MIN_OCCURRENCES,
            'minConfidence' => self::SEMANTIC_DEDUPE_MIN_CONFIDENCE,
        ];
    }

    /**
     * @return array{className:string,scope:string}
     */
    private static function parseProfileSelectorTarget(string $selector): array
    {
        $selector = trim($selector);
        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', $selector, $matches) === 1) {
            return [
                'className' => $matches[1],
                'scope' => 'base',
            ];
        }

        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)(.+)$/', $selector, $matches) === 1) {
            return [
                'className' => $matches[1],
                'scope' => 'child:' . trim($matches[2]),
            ];
        }

        return [
            'className' => '',
            'scope' => '',
        ];
    }

    /**
     * @param array<int|string, mixed> $declarations
     * @return array<string, string>
     */
    private static function normalizeProfileDeclarations(array $declarations): array
    {
        $normalized = [];

        foreach ($declarations as $property => $value) {
            if (!is_scalar($property) || !is_scalar($value)) {
                continue;
            }

            $property = strtolower(trim((string) $property));
            $value = strtolower(preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value));

            if ($property !== '' && $value !== '' && !str_starts_with($property, '_')) {
            foreach (self::expandProfileDeclaration($property, $value) as $expandedProperty => $expandedValue) {
                $normalized[$expandedProperty] = $expandedValue;
            }
        }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function expandProfileDeclaration(string $property, string $value): array
    {
        if ($property === 'padding' || $property === 'margin') {
            $parts = preg_split('/\s+/', $value) ?: [];
            $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

            if ($parts === []) {
                return [];
            }

            $sides = match (count($parts)) {
                1 => ['top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0]],
                2 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1]],
                3 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1]],
                default => [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[2],
                    'left' => $parts[3],
                ],
            };

            $expanded = [];
            foreach ($sides as $side => $sideValue) {
                $expanded[$property . '-' . $side] = $sideValue;
            }

            return $expanded;
        }

        return [$property => $value];
    }

    /**
     * @param array<string, string> $declarations
     */
    private static function styleSignature(array $declarations): string
    {
        $parts = [];

        foreach ($declarations as $property => $value) {
            $parts[] = $property . ':' . $value;
        }

        return implode(';', $parts);
    }

    /**
     * @param array<string, array<string, string>> $scopedDeclarations
     */
    private static function fullStyleSignature(array $scopedDeclarations): string
    {
        ksort($scopedDeclarations);
        $parts = [];

        foreach ($scopedDeclarations as $scope => $declarations) {
            ksort($declarations);
            $parts[] = $scope . '{' . self::styleSignature($declarations) . '}';
        }

        return implode('|', $parts);
    }

    /**
     * @param list<string> $classes
     */
    private static function semanticClassNameForClasses(array $classes): string
    {
        $role = self::semanticRoleForClasses($classes);

        return 'ohc-' . ($role !== '' ? $role : self::slugClassName($classes[0] ?? 'component'));
    }

    /**
     * @param list<string> $classes
     */
    private static function semanticRoleForClasses(array $classes): string
    {
        $signature = strtolower(implode(' ', $classes));

        foreach ([
            'card' => ['card', 'tile', 'panel'],
            'button' => ['button', 'btn', 'cta'],
            'hero' => ['hero', 'masthead'],
            'feature' => ['feature', 'benefit'],
            'pricing' => ['pricing', 'price'],
            'testimonial' => ['testimonial', 'review'],
            'nav' => ['nav', 'menu'],
            'container' => ['container', 'wrapper'],
            'section' => ['section', 'block'],
        ] as $role => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($signature, $needle)) {
                    return $role;
                }
            }
        }

        return '';
    }

    private static function slugClassName(string $className): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $className) ?? '');
        $slug = trim($slug, '-');

        return $slug === '' ? 'component' : $slug;
    }

    /**
     * @param array<string, bool> $reserved
     */
    private static function uniqueSemanticClassName(string $candidate, string $signature, array &$reserved): string
    {
        if (!isset($reserved[$candidate])) {
            $reserved[$candidate] = true;
            return $candidate;
        }

        $candidate .= '-' . substr(sha1($signature), 0, 6);
        $reserved[$candidate] = true;

        return $candidate;
    }

    /**
     * Set classes in the Oxygen element structure
     */
    private function setElementClasses(array &$element, array $classes): void
    {
        $classes = array_values(array_unique(array_filter($classes, static fn ($className): bool => is_string($className) && trim($className) !== '')));

        if (!isset($element['data']['properties']['settings'])) {
            $element['data']['properties']['settings'] = [];
        }
        if (!isset($element['data']['properties']['settings']['advanced'])) {
            $element['data']['properties']['settings']['advanced'] = [];
        }

        if ($classes === []) {
            unset($element['data']['properties']['settings']['advanced']['classes']);
            return;
        }

        $element['data']['properties']['settings']['advanced']['classes'] = $classes;
    }

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
}
