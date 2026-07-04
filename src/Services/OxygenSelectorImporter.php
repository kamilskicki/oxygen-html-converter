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

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $selectorsByClass = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $declarationsByClass = [];

    public function __construct(?OxygenValueNormalizer $valueNormalizer = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new OxygenValueNormalizer();
    }

    public function reset(): void
    {
        $this->selectorsByClass = [];
        $this->declarationsByClass = [];
    }

    /**
     * @param array<int, array{selector:string, declarations:array<string, string>}> $cssRules
     */
    public function setCssRules(array $cssRules): void
    {
        $this->declarationsByClass = [];

        foreach ($cssRules as $rule) {
            $selector = trim($rule['selector']);
            $className = $this->extractSimpleClassSelector($selector);
            if ($className === null || $rule['declarations'] === []) {
                continue;
            }

            $this->declarationsByClass[$className] = array_merge(
                $this->declarationsByClass[$className] ?? [],
                $this->normalizeDeclarations($rule['declarations'])
            );
        }
    }

    /**
     * @param array<int, mixed> $classes
     * @param array<string, mixed> $element
     */
    public function syncElementClasses(array $classes, array &$element): void
    {
        $selectorIds = [];

        foreach ($this->normalizeClasses($classes) as $className) {
            if (!$this->isNativeSelectorClassName($className)) {
                continue;
            }

            $selector = $this->ensureSelector($className);
            $selectorIds[] = $selector['id'];
        }

        $selectorIds = array_values(array_unique($selectorIds));
        if ($selectorIds === []) {
            unset($element['data']['properties']['meta']['classes']);
            return;
        }

        $element['data']['properties']['meta'] = $element['data']['properties']['meta'] ?? [];
        $element['data']['properties']['meta']['classes'] = $selectorIds;
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
            'children' => [],
            'properties' => $this->buildSelectorProperties($declarations) ?: new \stdClass(),
        ];

        $this->selectorsByClass[$className] = $selector;

        return $selector;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, mixed>
     */
    private function buildSelectorProperties(array $declarations): array
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

        return $base === [] ? [] : ['breakpoint_base' => $base];
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
            $normalizedValue = $this->valueNormalizer->normalizeForPath(
                $assignment['path'],
                $assignment['value'],
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
     * @return int|string|array<string, mixed>
     */
    private function convertSelectorValue(string $property, array $path, string $value)
    {
        if (end($path) === 'editMode') {
            return $value;
        }

        if ($this->isMeasurementPath($property, $path)) {
            return $this->convertMeasurement($value);
        }

        if ($property === 'font-weight') {
            return $this->convertFontWeight($value);
        }

        if ($property === 'opacity') {
            $opacity = is_numeric($value) ? (float) $value : null;
            if ($opacity !== null && $opacity >= 0 && $opacity <= 1) {
                return (int) round($opacity * 100);
            }
        }

        $leaf = end($path);
        if (
            in_array($property, ['color', 'background-color', 'border-color', 'outline-color'], true)
            || $leaf === 'color'
            || $leaf === 'background_color'
            || (is_string($leaf) && str_ends_with($leaf, '_color'))
        ) {
            return $this->normalizeColor($value);
        }

        return $value;
    }

    /**
     * @param array<int, string> $path
     */
    private function isMeasurementPath(string $property, array $path): bool
    {
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';
        $leaf = end($path);

        if ($root === 'position') {
            return in_array($section, ['top', 'right', 'bottom', 'left'], true);
        }

        if ($root === 'size') {
            return in_array($section, [
                'width',
                'height',
                'max_width',
                'max_height',
                'min_width',
                'min_height',
            ], true) || $section === 'object_position';
        }

        if ($root === 'spacing') {
            return true;
        }

        if ($root === 'layout' && $section === 'gap') {
            return true;
        }

        if ($root === 'layout' && in_array($section, ['grid_template_columns', 'grid_template_rows'], true)) {
            return $leaf === 'size';
        }

        if ($root === 'flex_child' && $section === 'flex_basis') {
            return true;
        }

        if ($root === 'borders' && $section === 'border_radius') {
            return $leaf !== 'editMode';
        }

        if ($root === 'borders' && $section === 'borders') {
            return $leaf === 'width';
        }

        if ($root === 'effects') {
            if (in_array($section, ['outline_width', 'outline_offset'], true)) {
                return true;
            }

            if ($section === 'transform_origin') {
                return true;
            }

            if ($section === 'transition') {
                return in_array($leaf, ['duration', 'delay'], true);
            }

            if ($section === 'box_shadow') {
                return in_array($leaf, ['x', 'y', 'blur', 'spread'], true);
            }

            if (in_array($section, ['filter', 'backdrop_filter'], true)) {
                return in_array($leaf, ['blur_value', 'hue_value', 'value'], true);
            }
        }

        return in_array($property, [
            'font-size',
            'line-height',
            'letter-spacing',
            'text-indent',
            'flex-basis',
            'border-width',
            'border-top-width',
            'border-right-width',
            'border-bottom-width',
            'border-left-width',
        ], true);
    }

    /**
     * @return string|array{number:int|float|null, unit:string, style:string}
     */
    private function convertMeasurement(string $value)
    {
        $value = trim($value);
        $keyword = strtolower($value);
        if ($keyword === 'auto' || $keyword === 'none') {
            return [
                'number' => null,
                'unit' => $keyword,
                'style' => $keyword,
            ];
        }

        if (preg_match('/^(-?\d*\.?\d+)\s*(px|rem|em|%|vw|vh|vmin|vmax|deg|ch)?$/', $value, $matches) !== 1) {
            return $value;
        }

        $number = (float) $matches[1];
        if ($number == (int) $number) {
            $number = (int) $number;
        }

        $unit = $matches[2] ?? 'px';

        return [
            'number' => $number,
            'unit' => $unit,
            'style' => $number . $unit,
        ];
    }

    private function convertFontWeight(string $value): int|string
    {
        $value = strtolower(trim($value));
        $keywords = [
            'thin' => 100,
            'extralight' => 200,
            'light' => 300,
            'normal' => 400,
            'regular' => 400,
            'medium' => 500,
            'semibold' => 600,
            'bold' => 700,
            'extrabold' => 800,
            'black' => 900,
        ];

        if (isset($keywords[$value])) {
            return $keywords[$value];
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    private function normalizeColor(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return strtoupper($value) . 'FF';
        }

        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $value, $matches) === 1) {
            return '#' . strtoupper($matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3]) . 'FF';
        }

        return $value;
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

    private function extractSimpleClassSelector(string $selector): ?string
    {
        if (preg_match('/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', trim($selector), $matches) !== 1) {
            return null;
        }

        return $matches[1];
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
