<?php

namespace OxyHtmlConverter;

use OxyHtmlConverter\Services\CssParser;
use DOMElement;

/**
 * Extracts inline styles and maps them to Oxygen properties
 */
class StyleExtractor
{
    private CssParser $cssParser;

    /**
     * CSS property to Oxygen property path mapping
     */
    private const STYLE_MAP = [
        // Typography
        'font-family'      => ['typography', 'font_family'],
        'font-size'        => ['typography', 'font_size'],
        'font-weight'      => ['typography', 'font_weight'],
        'font-style'       => ['typography', 'style', 'font_style'],
        'line-height'      => ['typography', 'line_height'],
        'letter-spacing'   => ['typography', 'letter_spacing'],
        'text-align'       => ['typography', 'text_align'],
        'text-decoration'  => ['typography', 'style', 'text_decoration'],
        'text-transform'   => ['typography', 'text_transform'],
        'color'            => ['typography', 'color'],
        'text-overflow'    => ['typography', 'text_overflow'],
        'overflow-wrap'    => ['typography', 'overflow_wrap'],
        'text-wrap'        => ['typography', 'text_wrap'],

        // Spacing
        'margin-top'       => ['spacing', 'spacing', 'margin', 'top'],
        'margin-right'     => ['spacing', 'spacing', 'margin', 'right'],
        'margin-bottom'    => ['spacing', 'spacing', 'margin', 'bottom'],
        'margin-left'      => ['spacing', 'spacing', 'margin', 'left'],
        'padding-top'      => ['spacing', 'spacing', 'padding', 'top'],
        'padding-right'    => ['spacing', 'spacing', 'padding', 'right'],
        'padding-bottom'   => ['spacing', 'spacing', 'padding', 'bottom'],
        'padding-left'     => ['spacing', 'spacing', 'padding', 'left'],

        // Size
        'width'            => ['size', 'width'],
        'min-width'        => ['size', 'min_width'],
        'max-width'        => ['size', 'max_width'],
        'height'           => ['size', 'height'],
        'min-height'       => ['size', 'min_height'],
        'max-height'       => ['size', 'max_height'],
        'aspect-ratio'     => ['size', 'aspect_ratio'],

        // Layout
        'display'          => ['layout', 'display'],
        'flex-direction'   => ['layout', 'flex_direction'],
        'justify-content'  => ['layout', 'flex_align', 'primary_axis'],
        'align-items'      => ['layout', 'flex_align', 'cross_axis'],
        'align-content'    => ['layout', 'flex_align', 'content_axis'],
        'row-gap'          => ['layout', 'gap', 'row'],
        'column-gap'       => ['layout', 'gap', 'column'],
        'grid-auto-flow'   => ['layout', 'grid_auto_flow'],
        'justify-items'    => ['layout', 'grid_align', 'cross_axis'],
        'align-items-grid' => ['layout', 'grid_align', 'primary_axis'],
        'justify-self'     => ['grid_child', 'justify_self'],

        // Position
        'position'         => ['position', 'position'],
        'top'              => ['position', 'top'],
        'right'            => ['position', 'right'],
        'bottom'           => ['position', 'bottom'],
        'left'             => ['position', 'left'],
        'z-index'          => ['position', 'z_index'],

        // Background
        'background-color' => ['background', 'background_color'],

        // Border radius
        'border-top-left-radius'     => ['borders', 'border_radius', 'topLeft'],
        'border-top-right-radius'    => ['borders', 'border_radius', 'topRight'],
        'border-bottom-left-radius'  => ['borders', 'border_radius', 'bottomLeft'],
        'border-bottom-right-radius' => ['borders', 'border_radius', 'bottomRight'],

        // Effects
        'opacity'          => ['effects', 'opacity'],
        'cursor'           => ['effects', 'cursor'],
        'mix-blend-mode'   => ['effects', 'blend_mode'],
        'pointer-events'   => ['effects', 'pointer_events'],
        'object-fit'       => ['size', 'object_fit'],
        'box-sizing'       => ['size', 'box_sizing'],

        // Overflow
        'overflow'         => ['size', 'overflow'],
    ];

    private const BORDER_SIDES = ['top', 'right', 'bottom', 'left'];
    private const BORDER_STYLES = [
        'none' => true,
        'hidden' => true,
        'dotted' => true,
        'dashed' => true,
        'solid' => true,
        'double' => true,
        'groove' => true,
        'ridge' => true,
        'inset' => true,
        'outset' => true,
    ];

    public function __construct(?CssParser $cssParser = null)
    {
        $this->cssParser = $cssParser ?? new CssParser();
    }

    /**
     * Extract styles from DOM element
     */
    public function extract(DOMElement $node): array
    {
        $styles = [];

        // Get inline style attribute
        $styleAttr = $node->getAttribute('style');
        if ($styleAttr) {
            $styles = array_merge($styles, $this->parseInlineStyles($styleAttr));
        }

        // Get class attribute for reference (stored but not converted)
        $classAttr = $node->getAttribute('class');
        if ($classAttr) {
            $styles['_original_classes'] = $classAttr;
        }

        return $styles;
    }

    /**
     * Parse inline style string into array
     */
    public function parseInlineStyles(string $styleString): array
    {
        return $this->cssParser->parseDeclarations($styleString);
    }

    /**
     * Convert extracted styles to Oxygen properties format
     */
    public function toOxygenProperties(array $styles): array
    {
        $properties = [];

        foreach ($styles as $cssProp => $value) {
            if (!is_scalar($cssProp) || strpos((string) $cssProp, '_') === 0) {
                continue;
            }

            foreach (self::controlAssignmentsForDeclaration((string) $cssProp, $value) as $assignment) {
                $this->setNestedValue($properties, $assignment['path'], $assignment['value']);
            }
        }

        return $properties;
    }

    /**
     * Check whether every non-internal declaration can be represented
     * natively in the current Oxygen property map.
     */
    public function supportsDeclarationsFully(array $styles): bool
    {
        $supportedDeclarationCount = 0;

        foreach ($styles as $cssProp => $value) {
            if (!$this->supportsDeclaration((string) $cssProp)) {
                return false;
            }

            if (strpos((string) $cssProp, '_') !== 0) {
                $supportedDeclarationCount++;
            }
        }

        return $supportedDeclarationCount > 0;
    }

    public function supportsDeclaration(string $cssProp): bool
    {
        if (strpos($cssProp, '_') === 0) {
            return true;
        }

        return self::controlPathsForDeclaration($cssProp) !== [];
    }

    /**
     * @param mixed $value
     * @return list<array{path:list<string>, value:mixed}>
     */
    public static function controlAssignmentsForDeclaration(string $cssProp, $value): array
    {
        $cssProp = strtolower(trim($cssProp));
        if ($cssProp === '' || strpos($cssProp, '_') === 0) {
            return [];
        }

        if ($cssProp === 'margin' || $cssProp === 'padding') {
            $assignments = [];
            foreach (self::expandBoxValue((string) $value) as $side => $sideValue) {
                $assignments[] = [
                    'path' => ['spacing', 'spacing', $cssProp, $side],
                    'value' => $sideValue,
                ];
            }

            return $assignments;
        }

        if ($cssProp === 'gap') {
            return [
                ['path' => ['layout', 'gap', 'row'], 'value' => $value],
                ['path' => ['layout', 'gap', 'column'], 'value' => $value],
            ];
        }

        if ($cssProp === 'border-radius') {
            return [
                ['path' => ['borders', 'border_radius', 'all'], 'value' => $value],
                ['path' => ['borders', 'border_radius', 'topLeft'], 'value' => $value],
                ['path' => ['borders', 'border_radius', 'topRight'], 'value' => $value],
                ['path' => ['borders', 'border_radius', 'bottomLeft'], 'value' => $value],
                ['path' => ['borders', 'border_radius', 'bottomRight'], 'value' => $value],
                ['path' => ['borders', 'border_radius', 'editMode'], 'value' => 'all'],
            ];
        }

        if (isset(self::STYLE_MAP[$cssProp]) && str_contains($cssProp, 'radius')) {
            return [['path' => self::STYLE_MAP[$cssProp], 'value' => $value]];
        }

        if (in_array($cssProp, ['flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'order'], true)) {
            return self::flexChildAssignments($cssProp, $value);
        }

        if ($cssProp === 'grid-template-columns' || $cssProp === 'grid-template-rows') {
            return self::gridTemplateAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'grid-column' || $cssProp === 'grid-row') {
            return self::gridChildSpanAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'background' || str_starts_with($cssProp, 'background-')) {
            return self::backgroundAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'border' || str_starts_with($cssProp, 'border-')) {
            return self::borderAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'outline' || str_starts_with($cssProp, 'outline-')) {
            return self::outlineAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'box-shadow') {
            return self::shadowAssignments('box_shadow', (string) $value);
        }

        if ($cssProp === 'filter' || $cssProp === 'backdrop-filter') {
            return self::filterAssignments($cssProp, (string) $value);
        }

        if ($cssProp === 'transition') {
            return self::transitionAssignments((string) $value);
        }

        if ($cssProp === 'transform-origin') {
            return self::twoValueAssignments((string) $value, ['effects', 'transform_origin'], ['x', 'y']);
        }

        if ($cssProp === 'object-position') {
            return self::twoValueAssignments((string) $value, ['size', 'object_position'], ['x', 'y']);
        }

        $path = self::STYLE_MAP[$cssProp] ?? null;

        return is_array($path) ? [['path' => $path, 'value' => $value]] : [];
    }

    /**
     * @param mixed $value
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function flexChildAssignments(string $cssProp, $value): array
    {
        return match ($cssProp) {
            'flex-grow' => [['path' => ['flex_child', 'flex_grow'], 'value' => $value]],
            'flex-shrink' => [['path' => ['flex_child', 'flex_shrink'], 'value' => $value]],
            'flex-basis' => [['path' => ['flex_child', 'flex_basis'], 'value' => $value]],
            'align-self' => [
                ['path' => ['flex_child', 'align_self'], 'value' => $value],
                ['path' => ['grid_child', 'align_self'], 'value' => $value],
            ],
            'order' => [
                ['path' => ['flex_child', 'order'], 'value' => 'custom'],
                ['path' => ['flex_child', 'order_custom'], 'value' => $value],
                ['path' => ['grid_child', 'order'], 'value' => 'custom'],
                ['path' => ['grid_child', 'order_custom'], 'value' => $value],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function gridTemplateAssignments(string $cssProp, string $value): array
    {
        $axis = $cssProp === 'grid-template-columns' ? 'columns' : 'rows';
        $simpleKey = $axis === 'columns' ? 'simple_grid_template_columns' : 'simple_grid_template_rows';
        $advancedKey = $axis === 'columns' ? 'grid_template_columns' : 'grid_template_rows';

        if (preg_match('/^repeat\(\s*(\d+)\s*,/i', trim($value), $matches) === 1) {
            return [[
                'path' => ['layout', 'grid', $simpleKey],
                'value' => $matches[1],
            ]];
        }

        return [
            ['path' => ['layout', 'grid', 'enable_advanced_mode'], 'value' => true],
            ['path' => ['layout', $advancedKey, '0', 'size'], 'value' => $value],
        ];
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function gridChildSpanAssignments(string $cssProp, string $value): array
    {
        $start = $cssProp === 'grid-column' ? 'column_start' : 'row_start';
        $end = $cssProp === 'grid-column' ? 'column_end' : 'row_end';
        $parts = array_map('trim', explode('/', $value, 2));

        if (count($parts) === 2) {
            return [
                ['path' => ['grid_child', $start], 'value' => $parts[0]],
                ['path' => ['grid_child', $end], 'value' => $parts[1]],
            ];
        }

        return [['path' => ['grid_child', $start], 'value' => $value]];
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function backgroundAssignments(string $cssProp, string $value): array
    {
        if ($cssProp === 'background-color') {
            return [['path' => ['background', 'background_color'], 'value' => $value]];
        }

        if ($cssProp === 'background') {
            if (self::looksLikeColor($value)) {
                return [['path' => ['background', 'background_color'], 'value' => $value]];
            }

            return self::backgroundImageAssignments($value);
        }

        if ($cssProp === 'background-image') {
            return self::backgroundImageAssignments($value);
        }

        if ($cssProp === 'background-size') {
            return [['path' => ['background', 'backgrounds', '0', 'background_size'], 'value' => $value]];
        }

        if ($cssProp === 'background-position') {
            return self::twoValueAssignments($value, ['background', 'backgrounds', '0', 'background_position'], ['x', 'y']);
        }

        if ($cssProp === 'background-repeat') {
            return [['path' => ['background', 'backgrounds', '0', 'background_repeat'], 'value' => $value]];
        }

        if ($cssProp === 'background-attachment') {
            return [['path' => ['background', 'backgrounds', '0', 'background_attachment'], 'value' => $value]];
        }

        if ($cssProp === 'background-blend-mode') {
            return [['path' => ['background', 'backgrounds', '0', 'background_blend_mode'], 'value' => $value]];
        }

        return [];
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function backgroundImageAssignments(string $value): array
    {
        $assignments = [
            ['path' => ['background', 'backgrounds', '0', 'disabled'], 'value' => false],
        ];

        if (preg_match('/^(?:linear|radial|conic)-gradient\(/i', trim($value)) === 1) {
            $assignments[] = ['path' => ['background', 'backgrounds', '0', 'type'], 'value' => 'gradient'];
            $assignments[] = ['path' => ['background', 'backgrounds', '0', 'gradient', 'value'], 'value' => $value];
            return $assignments;
        }

        $assignments[] = ['path' => ['background', 'backgrounds', '0', 'type'], 'value' => 'image'];
        $assignments[] = ['path' => ['background', 'backgrounds', '0', 'image', 'url'], 'value' => self::extractCssUrl($value) ?? $value];

        return $assignments;
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function borderAssignments(string $cssProp, string $value): array
    {
        if ($cssProp === 'border-radius') {
            return [];
        }

        if ($cssProp === 'border') {
            return self::borderSideAssignments(self::BORDER_SIDES, self::parseBorderValue($value));
        }

        if (preg_match('/^border-(top|right|bottom|left)$/', $cssProp, $matches) === 1) {
            return self::borderSideAssignments([$matches[1]], self::parseBorderValue($value));
        }

        if (preg_match('/^border-(width|style|color)$/', $cssProp, $matches) === 1) {
            $parts = [$matches[1] => $value];
            return self::borderSideAssignments(self::BORDER_SIDES, $parts);
        }

        if (preg_match('/^border-(top|right|bottom|left)-(width|style|color)$/', $cssProp, $matches) === 1) {
            return self::borderSideAssignments([$matches[1]], [$matches[2] => $value]);
        }

        return [];
    }

    /**
     * @param list<string> $sides
     * @param array{width?:string,style?:string,color?:string} $parts
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function borderSideAssignments(array $sides, array $parts): array
    {
        $assignments = [];

        foreach ($sides as $side) {
            foreach ($parts as $key => $partValue) {
                $assignments[] = [
                    'path' => ['borders', 'borders', $side, $key],
                    'value' => $partValue,
                ];
            }
        }

        return $assignments;
    }

    /**
     * @return array{width?:string,style?:string,color?:string}
     */
    private static function parseBorderValue(string $value): array
    {
        $parts = [];
        $tokens = preg_split('/\s+/', trim($value)) ?: [];

        foreach ($tokens as $token) {
            $normalized = strtolower($token);
            if (!isset($parts['style']) && isset(self::BORDER_STYLES[$normalized])) {
                $parts['style'] = $normalized;
                continue;
            }

            if (!isset($parts['color']) && self::looksLikeColor($token)) {
                $parts['color'] = $token;
                continue;
            }

            if (!isset($parts['width'])) {
                $parts['width'] = $token;
            }
        }

        return $parts;
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function outlineAssignments(string $cssProp, string $value): array
    {
        if ($cssProp === 'outline') {
            $parts = self::parseBorderValue($value);
            $assignments = [];

            foreach (['width' => 'outline_width', 'style' => 'outline_style', 'color' => 'outline_color'] as $source => $target) {
                if (isset($parts[$source])) {
                    $assignments[] = ['path' => ['effects', $target], 'value' => $parts[$source]];
                }
            }

            return $assignments;
        }

        return match ($cssProp) {
            'outline-width' => [['path' => ['effects', 'outline_width'], 'value' => $value]],
            'outline-style' => [['path' => ['effects', 'outline_style'], 'value' => $value]],
            'outline-color' => [['path' => ['effects', 'outline_color'], 'value' => $value]],
            'outline-offset' => [['path' => ['effects', 'outline_offset'], 'value' => $value]],
            default => [],
        };
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function shadowAssignments(string $target, string $value): array
    {
        if ($value === 'none') {
            return [['path' => ['effects', $target], 'value' => []]];
        }

        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $position = in_array('inset', $tokens, true) ? 'inset' : 'outset';
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== 'inset'));
        $color = null;
        $lengths = [];

        foreach ($tokens as $token) {
            if ($color === null && self::looksLikeColor($token)) {
                $color = $token;
                continue;
            }

            $lengths[] = $token;
        }

        return [
            ['path' => ['effects', $target, '0', 'disabled'], 'value' => false],
            ['path' => ['effects', $target, '0', 'position'], 'value' => $position],
            ['path' => ['effects', $target, '0', 'x'], 'value' => $lengths[0] ?? '0'],
            ['path' => ['effects', $target, '0', 'y'], 'value' => $lengths[1] ?? '0'],
            ['path' => ['effects', $target, '0', 'blur'], 'value' => $lengths[2] ?? '0'],
            ['path' => ['effects', $target, '0', 'spread'], 'value' => $lengths[3] ?? '0'],
            ['path' => ['effects', $target, '0', 'color'], 'value' => $color ?? 'currentColor'],
        ];
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function filterAssignments(string $cssProp, string $value): array
    {
        $target = $cssProp === 'backdrop-filter' ? 'backdrop_filter' : 'filter';

        if (preg_match('/^([a-z-]+)\((.*)\)$/i', trim($value), $matches) !== 1) {
            return [];
        }

        $type = strtolower($matches[1]);
        $valueKey = $type === 'blur' ? 'blur_value' : ($type === 'hue-rotate' ? 'hue_value' : 'value');

        return [
            ['path' => ['effects', $target, '0', 'disabled'], 'value' => false],
            ['path' => ['effects', $target, '0', 'type'], 'value' => $type],
            ['path' => ['effects', $target, '0', $valueKey], 'value' => trim($matches[2])],
        ];
    }

    /**
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function transitionAssignments(string $value): array
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];

        return [
            ['path' => ['effects', 'transition', '0', 'disabled'], 'value' => false],
            ['path' => ['effects', 'transition', '0', 'property'], 'value' => $tokens[0] ?? 'all'],
            ['path' => ['effects', 'transition', '0', 'duration'], 'value' => $tokens[1] ?? '0s'],
            ['path' => ['effects', 'transition', '0', 'easing'], 'value' => $tokens[2] ?? 'ease'],
            ['path' => ['effects', 'transition', '0', 'delay'], 'value' => $tokens[3] ?? '0s'],
        ];
    }

    /**
     * @param list<string> $basePath
     * @param list<string> $keys
     * @return list<array{path:list<string>, value:mixed}>
     */
    private static function twoValueAssignments(string $value, array $basePath, array $keys): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $first = $parts[0] ?? '0';
        $second = $parts[1] ?? $first;

        return [
            ['path' => array_merge($basePath, [$keys[0]]), 'value' => $first],
            ['path' => array_merge($basePath, [$keys[1]]), 'value' => $second],
        ];
    }

    private static function looksLikeColor(string $value): bool
    {
        return preg_match('/^(#|rgb|hsl|var\(|currentColor\b|transparent\b|[a-z]+$)/i', trim($value)) === 1;
    }

    private static function extractCssUrl(string $value): ?string
    {
        if (preg_match('/url\((["\']?)(.*?)\1\)/i', trim($value), $matches) !== 1) {
            return null;
        }

        return trim($matches[2]);
    }

    /**
     * @return list<list<string>>
     */
    public static function controlPathsForDeclaration(string $cssProp): array
    {
        return array_values(array_map(
            static fn (array $assignment): array => $assignment['path'],
            self::controlAssignmentsForDeclaration($cssProp, '')
        ));
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $path
     * @param mixed $value
     */
    public static function setControlPathValue(array &$properties, array $path, $value): void
    {
        $current = &$properties;
        foreach ($path as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }

    /**
     * Set a nested array value by path
     */
    private function setNestedValue(array &$array, array $path, $value): void
    {
        self::setControlPathValue($array, $path, $value);
    }

    /**
     * Extract and convert styles in one step
     */
    public function extractAndConvert(DOMElement $node): array
    {
        $styles = $this->extract($node);
        return $this->toOxygenProperties($styles);
    }

    /**
     * Parse shorthand margin/padding values
     */
    public function parseShorthandSpacing(string $value): array
    {
        return self::expandBoxValue($value);
    }

    /**
     * Convert color value to standard format
     */
    public function normalizeColor(string $color): string
    {
        $color = trim($color);

        // Already hex or rgb/rgba
        if (preg_match('/^#|^rgb/i', $color)) {
            return $color;
        }

        // Named colors - return as-is (browser will handle)
        return $color;
    }

    /**
     * Get original CSS classes from extracted styles
     */
    public function getOriginalClasses(array $styles): array
    {
        if (isset($styles['_original_classes'])) {
            return array_filter(array_map('trim', explode(' ', $styles['_original_classes'])));
        }
        return [];
    }

    /**
     * @return array{top:string,right:string,bottom:string,left:string}
     */
    private static function expandBoxValue(string $value): array
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
}
