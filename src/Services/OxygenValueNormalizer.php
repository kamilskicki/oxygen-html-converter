<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Normalizes CSS values into the Oxygen 6 control value shapes from PRD/01.
 */
class OxygenValueNormalizer
{
    private const MEASUREMENT_UNITS = 'px|rem|em|%|vw|vh|vmin|vmax|deg|ch|fr|s|ms';
    private const TRACK_UNITS = 'px|rem|em|%|vw|vh|vmin|vmax|ch|fr';
    private const CUSTOM_FUNCTIONS = 'calc|clamp|min|max|var';
    private const BREAKPOINT_PATTERN = '/^(?:breakpoint_|custom_breakpoint_)/';
    private const NAMED_COLORS = [
        'black' => true,
        'silver' => true,
        'gray' => true,
        'grey' => true,
        'white' => true,
        'maroon' => true,
        'red' => true,
        'purple' => true,
        'fuchsia' => true,
        'green' => true,
        'lime' => true,
        'olive' => true,
        'yellow' => true,
        'navy' => true,
        'blue' => true,
        'teal' => true,
        'aqua' => true,
        'orange' => true,
        'transparent' => true,
        'currentcolor' => true,
    ];
    private const ENUM_VALUES = [
        'layout.display' => ['block', 'inline-block', 'inline', 'flex', 'inline-flex', 'grid', 'inline-grid', 'none'],
        'layout.visibility' => ['visible', 'hidden', 'collapse'],
        'layout.flex_direction' => ['row', 'row-reverse', 'column', 'column-reverse'],
        'layout.flex_align.primary_axis' => ['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'start', 'end', 'left', 'right', 'normal'],
        'layout.flex_align.cross_axis' => ['stretch', 'flex-start', 'flex-end', 'center', 'baseline', 'start', 'end', 'normal'],
        'layout.grid_auto_flow' => ['row', 'column', 'dense', 'row dense', 'column dense'],
        'layout.grid_align.primary_axis' => ['stretch', 'start', 'end', 'center', 'baseline', 'normal'],
        'layout.grid_align.cross_axis' => ['stretch', 'start', 'end', 'center', 'baseline', 'normal'],
        'layout.grid_justify_content' => ['start', 'end', 'center', 'stretch', 'space-around', 'space-between', 'space-evenly', 'normal'],
        'layout.grid_align_content' => ['start', 'end', 'center', 'stretch', 'space-around', 'space-between', 'space-evenly', 'normal'],
        'position.position' => ['static', 'relative', 'absolute', 'fixed', 'sticky'],
        'size.overflow' => ['visible', 'hidden', 'clip', 'scroll', 'auto'],
        'size.object_fit' => ['fill', 'contain', 'cover', 'none', 'scale-down'],
        'size.box_sizing' => ['content-box', 'border-box'],
        'typography.text_align' => ['left', 'right', 'center', 'justify', 'start', 'end'],
        'typography.style.text_decoration' => ['none', 'underline', 'overline', 'line-through'],
        'typography.style.font_style' => ['normal', 'italic', 'oblique'],
        'typography.text_transform' => ['none', 'capitalize', 'uppercase', 'lowercase'],
        'typography.text_overflow' => ['clip', 'ellipsis'],
        'typography.overflow_wrap' => ['normal', 'break-word', 'anywhere'],
        'typography.text_wrap' => ['wrap', 'nowrap', 'balance', 'pretty', 'stable'],
        'flex_child.order' => ['custom'],
        'flex_child.align_self' => ['auto', 'normal', 'stretch', 'center', 'start', 'end', 'self-start', 'self-end', 'flex-start', 'flex-end', 'baseline'],
        'grid_child.order' => ['custom'],
        'grid_child.align_self' => ['auto', 'normal', 'stretch', 'center', 'start', 'end', 'self-start', 'self-end', 'flex-start', 'flex-end', 'baseline'],
        'grid_child.justify_self' => ['auto', 'normal', 'stretch', 'center', 'start', 'end', 'self-start', 'self-end', 'flex-start', 'flex-end', 'baseline', 'left', 'right'],
        'background.backgrounds.*.background_size' => ['auto', 'cover', 'contain'],
        'background.backgrounds.*.background_repeat' => ['repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'space', 'round'],
        'background.backgrounds.*.background_attachment' => ['scroll', 'fixed', 'local'],
        'background.backgrounds.*.background_blend_mode' => ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'],
        'borders.borders.*.style' => ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'],
        'effects.outline_style' => ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'],
        'effects.cursor' => ['auto', 'default', 'pointer', 'wait', 'text', 'move', 'not-allowed', 'grab', 'grabbing', 'help', 'zoom-in', 'zoom-out', 'inherit'],
        'effects.blend_mode' => ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'],
        'effects.pointer_events' => ['auto', 'none'],
        'effects.filter.*.type' => ['blur', 'brightness', 'contrast', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia'],
        'effects.backdrop_filter.*.type' => ['blur', 'brightness', 'contrast', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia'],
    ];

    /**
     * @param list<string> $path
     * @return mixed|null Null means the value is not safe/valid for this Oxygen path.
     */
    public function normalizeForPath(array $path, $value, ?string $cssProperty = null)
    {
        $path = $this->withoutBreakpointSegments($path);
        if ($path === []) {
            return null;
        }

        $leaf = (string) end($path);
        if ($leaf === 'editMode') {
            return $this->normalizeRawString($value, true);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (($path[0] ?? '') === 'typography' && ($path[1] ?? '') === 'line_height') {
            return $this->normalizeLineHeight((string) $value);
        }

        if ($this->isMeasurementPath($path, $cssProperty)) {
            return $this->normalizeMeasurement((string) $value);
        }

        if ($this->isColorPath($path, $cssProperty)) {
            return $this->normalizeColor((string) $value);
        }

        if (($path[0] ?? '') === 'typography' && ($path[1] ?? '') === 'font_weight') {
            return $this->normalizeFontWeight((string) $value);
        }

        if (($path[0] ?? '') === 'position' && ($path[1] ?? '') === 'z_index') {
            return $this->normalizeIntegerOrCssVariable((string) $value);
        }

        if (($path[0] ?? '') === 'size' && ($path[1] ?? '') === 'aspect_ratio') {
            return $this->normalizeAspectRatio((string) $value);
        }

        if (($path[0] ?? '') === 'effects' && ($path[1] ?? '') === 'opacity') {
            return $this->normalizeOpacity((string) $value);
        }

        if (($path[0] ?? '') === 'flex_child' && in_array(($path[1] ?? ''), ['flex_grow', 'flex_shrink'], true)) {
            return $this->normalizeNonNegativeNumberOrCssVariable((string) $value);
        }

        if (in_array(($path[0] ?? ''), ['flex_child', 'grid_child'], true) && ($path[1] ?? '') === 'order_custom') {
            return $this->normalizeIntegerOrCssVariable((string) $value);
        }

        if (($path[0] ?? '') === 'grid_child' && in_array(($path[1] ?? ''), ['column_start', 'column_end', 'row_start', 'row_end'], true)) {
            return $this->normalizeGridLineValue((string) $value);
        }

        $enum = $this->enumValuesForPath($path);
        if ($enum !== null) {
            $value = $this->normalizeRawString($value, false);
            if (!is_string($value)) {
                return null;
            }

            $normalizedValue = strtolower($value);
            return in_array($normalizedValue, $enum, true) ? $normalizedValue : null;
        }

        return $this->normalizeRawString($value, $this->allowsRawFunction($path));
    }

    /**
     * @return array{number:int|float|null, unit:string, style:string}|null
     */
    public function normalizeMeasurement(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return [
                'number' => null,
                'unit' => 'custom',
                'style' => $value,
            ];
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        $keyword = strtolower($value);
        if (in_array($keyword, ['auto', 'none'], true)) {
            return [
                'number' => null,
                'unit' => $keyword,
                'style' => $keyword,
            ];
        }

        if (preg_match('/^(-?(?:\d+|\d*\.\d+))(' . self::MEASUREMENT_UNITS . ')?$/i', $value, $matches) === 1) {
            $number = (float) $matches[1];
            if ($number == (int) $number) {
                $number = (int) $number;
            }

            $unit = strtolower($matches[2] ?? 'px');

            return [
                'number' => $number,
                'unit' => $unit,
                'style' => $number . $unit,
            ];
        }

        if ($this->isAllowedCustomMeasurement($value)) {
            return [
                'number' => null,
                'unit' => 'custom',
                'style' => $value,
            ];
        }

        return null;
    }

    public function normalizeColor(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $matches) === 1) {
            return '#' . strtoupper($matches[1]) . 'FF';
        }

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $matches) === 1) {
            $hex = strtoupper($matches[1]);

            return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . 'FF';
        }

        if (preg_match('/^#([0-9a-fA-F]{8})$/', $value, $matches) === 1) {
            return '#' . strtoupper($matches[1]);
        }

        if (preg_match('/^rgba?\(([^)]+)\)$/i', $value, $matches) === 1) {
            return $this->normalizeRgbColor($matches[1]);
        }

        if (preg_match('/^hsla?\(([^)]+)\)$/i', $value, $matches) === 1) {
            return $this->normalizeHslColor($matches[1]);
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (preg_match('/^[a-zA-Z]+$/', $value) === 1 && isset(self::NAMED_COLORS[strtolower($value)])) {
            return strtolower($value) === 'currentcolor' ? 'currentColor' : strtolower($value);
        }

        return null;
    }

    public function normalizeFontWeight(string $value): int|string|null
    {
        $value = strtolower(trim($value));
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
            return null;
        }

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

        if (preg_match('/^[1-9]00$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public function normalizeOpacity(string $value): int|string|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $opacity = (float) $value;
        if ($opacity >= 0 && $opacity <= 1) {
            return (int) round($opacity * 100);
        }

        if ($opacity >= 0 && $opacity <= 100) {
            return (int) round($opacity);
        }

        return null;
    }

    /**
     * @return array{number:int|float|null, unit:string, style:string}|null
     */
    private function normalizeLineHeight(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^(?:\d+|\d*\.\d+)$/', $value) === 1) {
            $number = (float) $value;
            if ($number == (int) $number) {
                $number = (int) $number;
            }

            return [
                'number' => $number,
                'unit' => 'custom',
                'style' => $value,
            ];
        }

        return $this->normalizeMeasurement($value);
    }

    /**
     * @param list<string> $path
     */
    public function isMeasurementPath(array $path, ?string $cssProperty = null): bool
    {
        $path = $this->withoutBreakpointSegments($path);
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';
        $leaf = (string) end($path);

        if ($root === 'typography') {
            if (in_array($section, ['font_size', 'line_height', 'letter_spacing', 'text_indent'], true)) {
                return true;
            }

            return $section === 'stroke' && $leaf === 'stroke_width';
        }

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

        if ($root === 'layout') {
            if ($section === 'gap') {
                return true;
            }

            return in_array($section, ['grid_template_columns', 'grid_template_rows', 'grid_auto_columns', 'grid_auto_rows'], true)
                && $leaf === 'size';
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

        if ($root === 'background') {
            return $section === 'backgrounds'
                && in_array($leaf, ['width', 'height'], true);
        }

        if ($root !== 'effects') {
            return false;
        }

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

        return false;
    }

    /**
     * @param list<string> $path
     */
    private function isColorPath(array $path, ?string $cssProperty = null): bool
    {
        $path = $this->withoutBreakpointSegments($path);
        $leaf = (string) end($path);

        return in_array($cssProperty, ['color', 'background-color', 'border-color', 'outline-color'], true)
            || $leaf === 'color'
            || $leaf === 'background_color'
            || str_ends_with($leaf, '_color');
    }

    /**
     * @param list<string> $path
     */
    private function allowsRawFunction(array $path): bool
    {
        $path = $this->withoutBreakpointSegments($path);
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';
        $leaf = (string) end($path);

        if ($root === 'background' && $section === 'backgrounds') {
            return in_array($leaf, ['value', 'image', 'url'], true);
        }

        if ($root === 'typography' && $section === 'font_family') {
            return true;
        }

        return false;
    }

    /**
     * @return string|int|float|null
     */
    private function normalizeRawString($value, bool $allowFunction)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (!$allowFunction && preg_match('/^(?:' . self::CUSTOM_FUNCTIONS . ')\(/i', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function isAllowedCustomMeasurement(string $value): bool
    {
        $value = trim($value);
        if ($this->containsUnsafeSyntax($value) || !$this->isBalancedFunctionValue($value)) {
            return false;
        }

        if (preg_match('/^(?:' . self::CUSTOM_FUNCTIONS . ')\(.+\)$/i', $value) === 1) {
            return true;
        }

        if ($this->isGridTrackFunction($value)) {
            return true;
        }

        $tokens = $this->splitTopLevelWhitespace($value);
        if (count($tokens) < 2) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!$this->isTrackListToken($token)) {
                return false;
            }
        }

        return true;
    }

    private function containsUnsafeSyntax(string $value): bool
    {
        return preg_match('/[;{}<>]|javascript\s*:/i', $value) === 1;
    }

    private function isOxygenVariableSentinel(string $value): bool
    {
        return preg_match('/^\{var-[A-Za-z0-9_-]+\}$/', trim($value)) === 1;
    }

    private function normalizeNonNegativeNumberOrCssVariable(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(?:\d+|\d*\.\d+)$/', $value) !== 1 || (float) $value < 0) {
            return null;
        }

        return $value;
    }

    public function normalizeIntegerOrCssVariable(string $value): int|string|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeGridLineValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isOxygenVariableSentinel($value)) {
            return $value;
        }

        if ($this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (strtolower($value) === 'auto') {
            return 'auto';
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^span\s+([1-9]\d*)$/i', $value, $matches) === 1) {
            return 'span ' . $matches[1];
        }

        return null;
    }

    public function normalizeAspectRatio(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (in_array(strtolower($value), ['auto', 'custom'], true)) {
            return strtolower($value);
        }

        if (preg_match('/^\d+(?:\.\d+)?(?:\s*\/\s*\d+(?:\.\d+)?)?$/', $value) === 1) {
            return preg_replace('/\s+/', ' ', $value) ?? $value;
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        return null;
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function withoutBreakpointSegments(array $path): array
    {
        return array_values(array_filter(
            $path,
            static fn(string $segment): bool => preg_match(self::BREAKPOINT_PATTERN, $segment) !== 1
        ));
    }

    /**
     * @param list<string> $path
     * @return list<string>|null
     */
    private function enumValuesForPath(array $path): ?array
    {
        $pathKey = implode('.', $path);
        if (isset(self::ENUM_VALUES[$pathKey])) {
            return self::ENUM_VALUES[$pathKey];
        }

        if (preg_match('/^borders\.borders\.[^.]+\.style$/', $pathKey) === 1) {
            return self::ENUM_VALUES['borders.borders.*.style'];
        }

        if (preg_match('/^background\.backgrounds\.[^.]+\.background_size$/', $pathKey) === 1) {
            return self::ENUM_VALUES['background.backgrounds.*.background_size'];
        }

        if (preg_match('/^background\.backgrounds\.[^.]+\.background_repeat$/', $pathKey) === 1) {
            return self::ENUM_VALUES['background.backgrounds.*.background_repeat'];
        }

        if (preg_match('/^background\.backgrounds\.[^.]+\.background_attachment$/', $pathKey) === 1) {
            return self::ENUM_VALUES['background.backgrounds.*.background_attachment'];
        }

        if (preg_match('/^background\.backgrounds\.[^.]+\.background_blend_mode$/', $pathKey) === 1) {
            return self::ENUM_VALUES['background.backgrounds.*.background_blend_mode'];
        }

        if (preg_match('/^effects\.filter\.[^.]+\.type$/', $pathKey) === 1) {
            return self::ENUM_VALUES['effects.filter.*.type'];
        }

        if (preg_match('/^effects\.backdrop_filter\.[^.]+\.type$/', $pathKey) === 1) {
            return self::ENUM_VALUES['effects.backdrop_filter.*.type'];
        }

        return null;
    }

    private function normalizeRgbColor(string $body): ?string
    {
        $parts = array_map('trim', explode(',', $body));
        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }

        for ($i = 0; $i < 3; $i++) {
            if (!is_numeric($parts[$i])) {
                return null;
            }

            $channel = (float) $parts[$i];
            if ($channel < 0 || $channel > 255) {
                return null;
            }
        }

        if (isset($parts[3])) {
            if (!is_numeric($parts[3])) {
                return null;
            }

            $alpha = (float) $parts[3];
            if ($alpha < 0 || $alpha > 1) {
                return null;
            }
        }

        return strtolower(preg_replace('/\s+/', '', 'rgb' . (isset($parts[3]) ? 'a' : '') . '(' . implode(',', $parts) . ')') ?? '');
    }

    private function normalizeHslColor(string $body): ?string
    {
        $parts = array_map('trim', explode(',', $body));
        if (count($parts) < 3 || count($parts) > 4 || !is_numeric($parts[0])) {
            return null;
        }

        foreach ([1, 2] as $index) {
            if (preg_match('/^(?:100|\d{1,2})(?:\.\d+)?%$/', $parts[$index]) !== 1) {
                return null;
            }
        }

        if (isset($parts[3])) {
            if (!is_numeric($parts[3])) {
                return null;
            }

            $alpha = (float) $parts[3];
            if ($alpha < 0 || $alpha > 1) {
                return null;
            }
        }

        return strtolower(preg_replace('/\s+/', '', 'hsl' . (isset($parts[3]) ? 'a' : '') . '(' . implode(',', $parts) . ')') ?? '');
    }

    private function isBalancedFunctionValue(string $value): bool
    {
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '(') {
                $depth++;
            } elseif ($value[$i] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    private function isTrackListToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        if (preg_match('/^(?:auto|min-content|max-content)$/i', $token) === 1) {
            return true;
        }

        if (preg_match('/^0(?:\.0+)?$/', $token) === 1) {
            return true;
        }

        if (preg_match('/^(?:\d+|\d*\.\d+)(' . self::TRACK_UNITS . ')$/i', $token) === 1) {
            return true;
        }

        if (preg_match('/^(?:' . self::CUSTOM_FUNCTIONS . ')\(.+\)$/i', $token) === 1) {
            return $this->isBalancedFunctionValue($token);
        }

        return $this->isGridTrackFunction($token);
    }

    private function isGridTrackFunction(string $value): bool
    {
        $parts = $this->extractFunctionParts($value);
        if ($parts === null) {
            return false;
        }

        [$name, $body] = $parts;
        if ($name === 'fit-content') {
            $args = $this->splitTopLevelComma($body);
            return count($args) === 1 && $this->isTrackListToken($args[0]);
        }

        if ($name === 'minmax') {
            $args = $this->splitTopLevelComma($body);
            return count($args) === 2
                && $this->isTrackListToken($args[0])
                && $this->isTrackListToken($args[1]);
        }

        if ($name === 'repeat') {
            $args = $this->splitTopLevelComma($body);
            return count($args) === 2
                && $this->isValidRepeatCount($args[0])
                && $this->isValidTrackList($args[1]);
        }

        return false;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function extractFunctionParts(string $value): ?array
    {
        $value = trim($value);
        if (!$this->isBalancedFunctionValue($value)) {
            return null;
        }

        if (preg_match('/^([a-z-]+)\((.*)\)$/i', $value, $matches) !== 1) {
            return null;
        }

        return [strtolower($matches[1]), trim($matches[2])];
    }

    private function isValidRepeatCount(string $value): bool
    {
        $value = strtolower(trim($value));
        return preg_match('/^[1-9]\d*$/', $value) === 1
            || in_array($value, ['auto-fill', 'auto-fit'], true);
    }

    private function isValidTrackList(string $value): bool
    {
        $tokens = $this->splitTopLevelWhitespace($value);
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!$this->isTrackListToken($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelComma(string $value): array
    {
        return $this->splitTopLevel($value, ',');
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelWhitespace(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if (ctype_space($char) && $depth === 0) {
                if (trim($current) !== '') {
                    $parts[] = trim($current);
                    $current = '';
                }
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
}
