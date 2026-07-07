<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenGlobalSettingsInferenceService
{
    /**
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    public function infer(array $tokens, string $cssText = ''): array
    {
        $settings = [];

        $colors = $this->inferColors($tokens, $cssText);
        if ($colors !== []) {
            $settings['colors'] = $colors;
        }

        $typography = $this->inferTypography($tokens, $cssText);
        if ($typography !== []) {
            $settings['typography'] = $typography;
        }

        $containers = $this->inferContainers($tokens, $cssText);
        if ($containers !== []) {
            $settings['containers'] = $containers;
        }

        $other = $this->inferOther($cssText);
        if ($other !== []) {
            $settings['other'] = $other;
        }

        $code = $this->inferCode($cssText);
        if ($settings !== [] || $code !== []) {
            $settings['code'] = [
                'stylesheets' => is_array($code['stylesheets'] ?? null) ? $code['stylesheets'] : [],
                'scripts' => [],
            ];
        }

        return $settings === [] ? [] : ['settings' => $this->completeSettings($settings)];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function completeSettings(array $settings): array
    {
        $settings['colors'] = is_array($settings['colors'] ?? null) ? $settings['colors'] : [];
        $settings['colors']['palette'] = is_array($settings['colors']['palette'] ?? null) ? $settings['colors']['palette'] : [];
        $settings['colors']['palette']['colors'] = is_array($settings['colors']['palette']['colors'] ?? null)
            ? $settings['colors']['palette']['colors']
            : [];
        $settings['colors']['palette']['gradients'] = is_array($settings['colors']['palette']['gradients'] ?? null)
            ? $settings['colors']['palette']['gradients']
            : [];

        $settings['typography'] = is_array($settings['typography'] ?? null) ? $settings['typography'] : [];
        $settings['typography']['global_typography'] = is_array($settings['typography']['global_typography'] ?? null)
            ? $settings['typography']['global_typography']
            : [];
        $settings['typography']['global_typography']['typography_presets'] = is_array($settings['typography']['global_typography']['typography_presets'] ?? null)
            ? $settings['typography']['global_typography']['typography_presets']
            : [];

        $settings['containers'] = is_array($settings['containers'] ?? null) ? $settings['containers'] : [];
        $settings['containers']['sections'] = is_array($settings['containers']['sections'] ?? null)
            ? $settings['containers']['sections']
            : [];

        $settings['code'] = is_array($settings['code'] ?? null) ? $settings['code'] : [];
        $settings['code']['stylesheets'] = is_array($settings['code']['stylesheets'] ?? null) ? $settings['code']['stylesheets'] : [];
        $settings['code']['scripts'] = is_array($settings['code']['scripts'] ?? null) ? $settings['code']['scripts'] : [];

        $settings['other'] = is_array($settings['other'] ?? null) ? $settings['other'] : [];

        return $settings;
    }

    /**
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function inferColors(array $tokens, string $cssText): array
    {
        $paletteColors = [];
        $paletteColorNames = [];
        $gradients = [];
        $gradientNames = [];
        $gradientValues = [];
        $colorTokens = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];

        foreach ($colorTokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $value = $this->scalarValue($token['value'] ?? null);
            $suggestedName = $this->scalarValue($token['suggestedName'] ?? null);

            if ($value === '' || $suggestedName === '') {
                continue;
            }

            $colorValue = $this->normalizeGlobalColorValue($value);
            if ($colorValue !== '') {
                $name = $this->normalizeCssVariableName($suggestedName);
                if (!isset($paletteColorNames[$name])) {
                    $paletteColorNames[$name] = true;
                    $paletteColors[] = [
                        'label' => $this->labelForName($name),
                        'cssVariableName' => $name,
                        'value' => $colorValue,
                    ];
                }
                continue;
            }

            if ($this->isGradientValue($value)) {
                $this->appendGradient($gradients, $gradientNames, $gradientValues, $suggestedName, $value);
            }
        }

        foreach ($this->extractGradientRecords($cssText) as $gradient) {
            $this->appendGradient(
                $gradients,
                $gradientNames,
                $gradientValues,
                $gradient['suggestedName'],
                $gradient['value']
            );
        }

        $palette = [];
        if ($paletteColors !== []) {
            $palette['colors'] = $paletteColors;
        }

        if ($gradients !== []) {
            $palette['gradients'] = $gradients;
        }

        return $palette === [] ? [] : ['palette' => $palette];
    }

    /**
     * @param list<array<string, mixed>> $gradients
     * @param array<string, bool> $gradientNames
     * @param array<string, bool> $gradientValues
     */
    private function appendGradient(array &$gradients, array &$gradientNames, array &$gradientValues, string $suggestedName, string $value): void
    {
        $value = trim($value);
        if (!$this->isGradientValue($value)) {
            return;
        }

        $name = $this->normalizeCssVariableName($suggestedName);
        $valueKey = strtolower(preg_replace('/\s+/', '', $value) ?? $value);

        if (isset($gradientNames[$name]) || isset($gradientValues[$valueKey])) {
            return;
        }

        $gradientNames[$name] = true;
        $gradientValues[$valueKey] = true;
        $gradients[] = [
            'label' => $this->labelForName($name),
            'cssVariableName' => $name,
            'value' => [
                'value' => $value,
                'svgValue' => $this->buildGradientSvgValue($value),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function inferTypography(array $tokens, string $cssText): array
    {
        $fontTokens = is_array($tokens['fonts'] ?? null) ? $tokens['fonts'] : [];
        $fontValues = $this->tokenValues($fontTokens);
        $fontValues = array_values(array_filter($fontValues, fn (string $font): bool => !$this->isIconFontFamily($font)));

        $bodyFont = $this->extractFontFamilyForSelectors($cssText, ['body', 'html']) ?? ($fontValues[0] ?? '');
        $headingFont = $this->extractFontFamilyForSelectors($cssText, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
            ?? ($bodyFont !== '' ? $bodyFont : ($fontValues[0] ?? ''));
        $baseSize = $this->extractMeasurementForSelectors($cssText, ['body', 'html', ':root'], ['font-size'])
            ?? $this->selectMeasurementToken($tokens, ['base', 'body', 'font-size', 'font'], null, false);
        $ratio = $this->selectRatioToken($tokens);

        $typography = [];
        if ($bodyFont !== '') {
            $typography['body_font'] = $bodyFont;
        }

        if ($headingFont !== '') {
            $typography['heading_font'] = $headingFont;
        }

        if ($baseSize !== null) {
            $typography['base_size'] = $baseSize;
        }

        if ($ratio !== null) {
            $typography['ratio'] = $ratio;
        }

        $presetTypography = [];
        if ($bodyFont !== '') {
            $presetTypography['fontFamily'] = $bodyFont;
        }

        if ($baseSize !== null) {
            $presetTypography['fontSize'] = $baseSize;
        }

        if ($presetTypography !== []) {
            $typography['global_typography'] = [
                'typography_presets' => [[
                    'preset' => [
                        'label' => 'Body',
                        'id' => 'ohc-body',
                    ],
                    'custom' => [
                        'customTypography' => $presetTypography,
                    ],
                ]],
            ];
        }

        return $typography;
    }

    /**
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function inferContainers(array $tokens, string $cssText): array
    {
        $containerWidth = $this->extractLargestMeasurementForProperties($cssText, ['max-width', 'width'], 320.0)
            ?? $this->selectMeasurementToken($tokens, ['container', 'max-width', 'width'], 320.0);
        $verticalPadding = $this->extractPaddingMeasurement($cssText, 'vertical')
            ?? $this->selectSpacingToken($tokens, ['section-y', 'vertical', 'padding-y', 'space-y']);
        $horizontalPadding = $this->extractPaddingMeasurement($cssText, 'horizontal')
            ?? $this->selectSpacingToken($tokens, ['page-x', 'horizontal', 'padding-x', 'space-x']);
        $columnGap = $this->extractFirstMeasurementForProperties($cssText, ['column-gap', 'gap'])
            ?? $this->selectSpacingToken($tokens, ['column-gap', 'gap']);

        $containers = [];
        $sections = [];

        if ($containerWidth !== null) {
            $sections['container_width'] = $containerWidth;
        }

        if ($verticalPadding !== null) {
            $sections['vertical_padding'] = $verticalPadding;
        }

        if ($horizontalPadding !== null) {
            $sections['horizontal_padding'] = $horizontalPadding;
        }

        if ($sections !== []) {
            $containers['sections'] = $sections;
        }

        if ($columnGap !== null) {
            $containers['column_gap'] = $columnGap;
        }

        return $containers;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferOther(string $cssText): array
    {
        $transitionDuration = $this->extractFirstMeasurementForProperties($cssText, ['transition-duration']);

        return $transitionDuration === null ? [] : ['transition_duration' => $transitionDuration];
    }

    /**
     * @return array<string, mixed>
     */
    private function inferCode(string $cssText): array
    {
        $customProperties = $this->extractRootCustomProperties($cssText);
        if ($customProperties === []) {
            return [];
        }

        $declarations = [];
        foreach ($customProperties as $property => $value) {
            if ($this->isUnsafeCssValue($value)) {
                continue;
            }

            $declarations[] = $property . ': ' . $value . ';';
        }

        if ($declarations === []) {
            return [];
        }

        return [
            'stylesheets' => [[
                'name' => 'Imported root custom properties',
                'code' => ':root { ' . implode(' ', $declarations) . ' }',
            ]],
        ];
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<string>
     */
    private function tokenValues(array $tokens): array
    {
        $values = [];

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $value = $this->scalarValue($token['value'] ?? null);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param mixed $value
     */
    private function scalarValue($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeGlobalColorValue(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1) {
            return strtoupper($value);
        }

        if (preg_match('/^(?:rgba?|hsla?)\([^)]+\)$/i', $value) === 1) {
            return strtolower(preg_replace('/\s+/', '', $value) ?? $value);
        }

        return '';
    }

    private function isGradientValue(string $value): bool
    {
        return preg_match('/^(?:linear|radial|conic)-gradient\(.+\)$/i', trim($value)) === 1;
    }

    /**
     * @return list<array{suggestedName:string,value:string}>
     */
    private function extractGradientRecords(string $cssText): array
    {
        $records = [];

        foreach ($this->extractRootCustomProperties($cssText) as $property => $value) {
            if ($this->isGradientValue($value)) {
                $records[] = [
                    'suggestedName' => ltrim($property, '-'),
                    'value' => $value,
                ];
            }
        }

        foreach ($this->extractGradientValues($cssText) as $value) {
            $records[] = [
                'suggestedName' => 'gradient-' . substr(sha1(strtolower($value)), 0, 8),
                'value' => $value,
            ];
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    private function extractGradientValues(string $cssText): array
    {
        $values = [];

        if (!preg_match_all('/(?:linear|radial|conic)-gradient\(/i', $cssText, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];
            $open = strpos($cssText, '(', $start);
            if ($open === false) {
                continue;
            }

            $end = $this->findMatchingParenthesis($cssText, $open);
            if ($end === null) {
                continue;
            }

            $values[] = trim(substr($cssText, $start, $end - $start + 1));
        }

        return array_values(array_unique($values));
    }

    private function findMatchingParenthesis(string $text, int $openIndex): ?int
    {
        $depth = 0;
        $length = strlen($text);
        $inString = false;
        $stringChar = '';

        for ($i = $openIndex; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($char === $stringChar && !$this->isEscaped($text, $i)) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractRootCustomProperties(string $cssText): array
    {
        if (trim($cssText) === '') {
            return [];
        }

        $properties = [];

        foreach ((new CssParser())->parse($cssText) as $rule) {
            $selector = strtolower(trim((string) ($rule['selector'] ?? '')));
            if (!in_array($selector, [':root', 'html'], true)) {
                continue;
            }

            $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
            foreach ($declarations as $property => $value) {
                $property = trim((string) $property);
                $value = trim((string) $value);

                if (preg_match('/^--[A-Za-z_][A-Za-z0-9_-]*$/', $property) === 1 && $value !== '') {
                    $properties[$property] = $value;
                }
            }
        }

        return $properties;
    }

    /**
     * @param list<string> $selectors
     */
    private function extractFontFamilyForSelectors(string $cssText, array $selectors): ?string
    {
        foreach ($this->parseRules($cssText) as $rule) {
            if (!$this->selectorMatches($rule['selector'], $selectors)) {
                continue;
            }

            $fontFamily = $rule['declarations']['font-family'] ?? null;
            if (!is_string($fontFamily)) {
                continue;
            }

            $family = $this->firstFontFamily($fontFamily);
            if ($family !== '') {
                return $family;
            }
        }

        return null;
    }

    /**
     * @param list<string> $selectors
     * @param list<string> $properties
     * @return array<string, mixed>|null
     */
    private function extractMeasurementForSelectors(string $cssText, array $selectors, array $properties): ?array
    {
        foreach ($this->parseRules($cssText) as $rule) {
            if (!$this->selectorMatches($rule['selector'], $selectors)) {
                continue;
            }

            foreach ($properties as $property) {
                $measurement = $this->measurementFromCssValue((string) ($rule['declarations'][$property] ?? ''));
                if ($measurement !== null) {
                    return $measurement;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $properties
     * @return array<string, mixed>|null
     */
    private function extractFirstMeasurementForProperties(string $cssText, array $properties): ?array
    {
        foreach ($this->parseRules($cssText) as $rule) {
            foreach ($properties as $property) {
                $measurement = $this->measurementFromCssValue((string) ($rule['declarations'][$property] ?? ''));
                if ($measurement !== null) {
                    return $measurement;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $properties
     * @return array<string, mixed>|null
     */
    private function extractLargestMeasurementForProperties(string $cssText, array $properties, ?float $minimum = null): ?array
    {
        $best = null;
        $bestNumber = null;

        foreach ($this->parseRules($cssText) as $rule) {
            foreach ($properties as $property) {
                $measurement = $this->measurementFromCssValue((string) ($rule['declarations'][$property] ?? ''));
                $number = $this->measurementNumber($measurement);

                if ($measurement === null || $number === null || ($minimum !== null && $number < $minimum)) {
                    continue;
                }

                if ($bestNumber === null || $number > $bestNumber) {
                    $best = $measurement;
                    $bestNumber = $number;
                }
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPaddingMeasurement(string $cssText, string $axis): ?array
    {
        $best = null;
        $bestNumber = null;

        foreach ($this->parseRules($cssText) as $rule) {
            foreach ($this->paddingValues($rule['declarations'], $axis) as $value) {
                $measurement = $this->parseMeasurement($value);
                $number = $this->measurementNumber($measurement);

                if ($measurement === null || $number === null) {
                    continue;
                }

                if ($bestNumber === null || $number > $bestNumber) {
                    $best = $measurement;
                    $bestNumber = $number;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string, string> $declarations
     * @return list<string>
     */
    private function paddingValues(array $declarations, string $axis): array
    {
        $values = [];
        $sideProperties = $axis === 'vertical'
            ? ['padding-top', 'padding-bottom']
            : ['padding-left', 'padding-right'];

        foreach ($sideProperties as $property) {
            if (is_string($declarations[$property] ?? null)) {
                $values[] = $declarations[$property];
            }
        }

        if (is_string($declarations['padding'] ?? null)) {
            $parts = preg_split('/\s+/', trim($declarations['padding'])) ?: [];
            if ($axis === 'vertical') {
                $values[] = (string) ($parts[0] ?? '');
            } else {
                $values[] = (string) ($parts[1] ?? $parts[0] ?? '');
            }
        }

        return array_values(array_filter($values, static fn (string $value): bool => trim($value) !== ''));
    }

    /**
     * @param array<string, mixed> $tokens
     * @param list<string> $nameNeedles
     * @return array<string, mixed>|null
     */
    private function selectMeasurementToken(array $tokens, array $nameNeedles, ?float $minimum = null, bool $allowFallback = true): ?array
    {
        $measurements = is_array($tokens['measurements'] ?? null) ? $tokens['measurements'] : [];
        $named = $this->selectTokenMeasurementByName($measurements, $nameNeedles, $minimum);

        if ($named !== null) {
            return $named;
        }

        if (!$allowFallback) {
            return null;
        }

        $best = null;
        $bestNumber = null;
        foreach ($measurements as $token) {
            if (!is_array($token)) {
                continue;
            }

            $measurement = $this->parseMeasurement($this->scalarValue($token['value'] ?? null));
            $number = $this->measurementNumber($measurement);
            if ($measurement === null || $number === null || ($minimum !== null && $number < $minimum)) {
                continue;
            }

            if ($bestNumber === null || $number > $bestNumber) {
                $best = $measurement;
                $bestNumber = $number;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $tokens
     * @param list<string> $nameNeedles
     * @return array<string, mixed>|null
     */
    private function selectSpacingToken(array $tokens, array $nameNeedles): ?array
    {
        $spacing = is_array($tokens['spacing'] ?? null) ? $tokens['spacing'] : [];
        $named = $this->selectTokenMeasurementByName($spacing, $nameNeedles);

        if ($named !== null) {
            return $named;
        }

        foreach ($spacing as $token) {
            if (!is_array($token)) {
                continue;
            }

            $measurement = $this->parseMeasurement($this->scalarValue($token['value'] ?? null));
            if ($measurement !== null) {
                return $measurement;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param list<string> $nameNeedles
     * @return array<string, mixed>|null
     */
    private function selectTokenMeasurementByName(array $tokens, array $nameNeedles, ?float $minimum = null): ?array
    {
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $name = strtolower($this->scalarValue($token['suggestedName'] ?? null));
            if ($name === '') {
                continue;
            }

            foreach ($nameNeedles as $needle) {
                if (!str_contains($name, strtolower($needle))) {
                    continue;
                }

                $measurement = $this->parseMeasurement($this->scalarValue($token['value'] ?? null));
                $number = $this->measurementNumber($measurement);
                if ($measurement !== null && ($minimum === null || ($number !== null && $number >= $minimum))) {
                    return $measurement;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tokens
     * @return int|float|null
     */
    private function selectRatioToken(array $tokens)
    {
        $numbers = is_array($tokens['numbers'] ?? null) ? $tokens['numbers'] : [];

        foreach ($numbers as $token) {
            if (!is_array($token)) {
                continue;
            }

            $name = strtolower($this->scalarValue($token['suggestedName'] ?? null));
            $value = $this->scalarValue($token['value'] ?? null);
            if (!is_numeric($value)) {
                continue;
            }

            $number = (float) $value;
            if ($number <= 0 || $number > 3) {
                continue;
            }

            if (str_contains($name, 'ratio') || str_contains($name, 'scale')) {
                return $this->normalizeNumber($number);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function measurementFromCssValue(string $value): ?array
    {
        if (!preg_match('/(?:^|\s)(-?\d*\.?\d+(?:px|rem|em|vw|vh|%|ch|fr|ms|s))(?:\s|$)/i', trim($value), $matches)) {
            return null;
        }

        return $this->parseMeasurement($matches[1]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseMeasurement(string $value): ?array
    {
        $style = trim($value);
        if ($style === '') {
            return null;
        }

        if (preg_match('/^(-?\d*\.?\d+)([a-z%]+)$/i', $style, $matches) === 1) {
            $number = (float) $matches[1];

            return [
                'number' => $this->normalizeNumber($number),
                'unit' => strtolower($matches[2]),
                'style' => strtolower($style),
            ];
        }

        if (preg_match('/^(?:calc|clamp|min|max|var)\(.+\)$/i', $style) === 1) {
            return [
                'number' => null,
                'unit' => 'custom',
                'style' => $style,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $measurement
     */
    private function measurementNumber(?array $measurement): ?float
    {
        if ($measurement === null || !(is_int($measurement['number'] ?? null) || is_float($measurement['number'] ?? null))) {
            return null;
        }

        return (float) $measurement['number'];
    }

    /**
     * @return int|float
     */
    private function normalizeNumber(float $number)
    {
        return $number == (int) $number ? (int) $number : $number;
    }

    /**
     * @return list<array{selector:string,declarations:array<string,string>}>
     */
    private function parseRules(string $cssText): array
    {
        $rules = [];

        foreach ((new CssParser())->parse($cssText) as $rule) {
            $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
            $normalized = [];
            foreach ($declarations as $property => $value) {
                $normalized[strtolower((string) $property)] = (string) $value;
            }

            $rules[] = [
                'selector' => strtolower(trim((string) ($rule['selector'] ?? ''))),
                'declarations' => $normalized,
            ];
        }

        return $rules;
    }

    /**
     * @param list<string> $targets
     */
    private function selectorMatches(string $selector, array $targets): bool
    {
        $selector = strtolower(trim($selector));

        foreach ($targets as $target) {
            if ($selector === strtolower($target)) {
                return true;
            }
        }

        return false;
    }

    private function firstFontFamily(string $fontFamily): string
    {
        foreach (explode(',', $fontFamily) as $family) {
            $family = trim($family, " \t\n\r\0\x0B\"'");
            if ($family !== '' && !$this->isGenericFontFamily($family) && !$this->isIconFontFamily($family)) {
                return $family;
            }
        }

        return '';
    }

    private function isGenericFontFamily(string $family): bool
    {
        return in_array(strtolower($family), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'], true);
    }

    private function isIconFontFamily(string $family): bool
    {
        $family = strtolower($family);

        return str_contains($family, 'material symbols')
            || str_contains($family, 'material icons')
            || str_contains($family, 'font awesome')
            || str_contains($family, 'dashicons');
    }

    private function normalizeCssVariableName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = ltrim($name, '-');
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'global-setting';
        }

        return str_starts_with($name, 'ohc-') ? $name : 'ohc-' . $name;
    }

    private function labelForName(string $name): string
    {
        $label = preg_replace('/^ohc-/', '', $name) ?? $name;
        $label = str_replace('-', ' ', $label);

        return ucwords($label);
    }

    private function buildGradientSvgValue(string $gradient): string
    {
        $colors = $this->extractGradientColors($gradient);
        if (count($colors) < 2) {
            $colors = ['#000000', '#FFFFFF'];
        }

        $stopCount = max(1, count($colors) - 1);
        $stops = [];
        foreach (array_values($colors) as $index => $color) {
            $offset = (string) round(($index / $stopCount) * 100, 2);
            $stops[] = '<stop offset="' . $offset . '%" stop-color="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"/>';
        }

        if (preg_match('/^radial-gradient/i', $gradient) === 1) {
            return '<symbol id="%%GRADIENTID%%" viewBox="0 0 1 1"><radialGradient id="g">'
                . implode('', $stops)
                . '</radialGradient><rect width="1" height="1" fill="url(#g)"/></symbol>';
        }

        return '<symbol id="%%GRADIENTID%%" viewBox="0 0 1 1"><linearGradient id="g" x1="0" x2="1" y1="0" y2="1">'
            . implode('', $stops)
            . '</linearGradient><rect width="1" height="1" fill="url(#g)"/></symbol>';
    }

    /**
     * @return list<string>
     */
    private function extractGradientColors(string $gradient): array
    {
        if (!preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\)|hsla?\([^)]+\)/', $gradient, $matches)) {
            return [];
        }

        return array_slice(array_values(array_unique($matches[0])), 0, 8);
    }

    private function isUnsafeCssValue(string $value): bool
    {
        return preg_match('/<\/?\s*script\b|javascript\s*:/i', $value) === 1;
    }

    private function isEscaped(string $text, int $index): bool
    {
        $slashes = 0;
        for ($i = $index - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }
}
