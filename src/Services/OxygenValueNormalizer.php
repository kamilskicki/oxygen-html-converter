<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Normalizes CSS values into the Oxygen 6 control value shapes from PRD/01.
 */
class OxygenValueNormalizer
{
    private const MEASUREMENT_UNITS = 'px|rem|em|%|vw|vh|vmin|vmax|deg|ch|fr|s|ms';
    private const CUSTOM_FUNCTIONS = 'calc|clamp|min|max|var';

    /**
     * @param list<string> $path
     * @return mixed|null Null means the value is not safe/valid for this Oxygen path.
     */
    public function normalizeForPath(array $path, $value, ?string $cssProperty = null)
    {
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

        if ($this->isMeasurementPath($path, $cssProperty)) {
            return $this->normalizeMeasurement((string) $value);
        }

        if ($this->isColorPath($path, $cssProperty)) {
            return $this->normalizeColor((string) $value);
        }

        if (($path[0] ?? '') === 'typography' && ($path[1] ?? '') === 'font_weight') {
            return $this->normalizeFontWeight((string) $value);
        }

        if (($path[0] ?? '') === 'effects' && ($path[1] ?? '') === 'opacity') {
            return $this->normalizeOpacity((string) $value);
        }

        return $this->normalizeRawString($value, $this->allowsRawFunction($path));
    }

    /**
     * @return array{number:int|float|null, unit:string, style:string}|null
     */
    public function normalizeMeasurement(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
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
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
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

        if (preg_match('/^(?:rgba?|hsla?)\([^)]+\)$/i', $value) === 1) {
            return strtolower(preg_replace('/\s+/', '', $value) ?? $value);
        }

        if (preg_match('/^var\([^;{}<>]+\)$/i', $value) === 1) {
            return $value;
        }

        if (preg_match('/^[a-zA-Z]+$/', $value) === 1) {
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

        return $value;
    }

    public function normalizeOpacity(string $value): int|string|null
    {
        $value = trim($value);
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
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
     * @param list<string> $path
     */
    public function isMeasurementPath(array $path, ?string $cssProperty = null): bool
    {
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
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';
        $leaf = (string) end($path);

        if ($root === 'background' && $section === 'backgrounds') {
            return in_array($leaf, ['value', 'image'], true);
        }

        return false;
    }

    /**
     * @return string|int|float|bool|null
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
        if ($value === '' || $this->containsUnsafeSyntax($value)) {
            return null;
        }

        if (!$allowFunction && preg_match('/^(?:' . self::CUSTOM_FUNCTIONS . ')\(/i', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function isAllowedCustomMeasurement(string $value): bool
    {
        if ($this->containsUnsafeSyntax($value)) {
            return false;
        }

        if (preg_match('/^(?:' . self::CUSTOM_FUNCTIONS . ')\(.+\)$/i', $value) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9_.,%()\/+\-\s]+$/', $value) === 1
            && preg_match('/\d/', $value) === 1;
    }

    private function containsUnsafeSyntax(string $value): bool
    {
        return preg_match('/[;{}<>]|javascript\s*:/i', $value) === 1;
    }
}
