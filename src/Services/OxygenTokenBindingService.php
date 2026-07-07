<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class OxygenTokenBindingService
{
    private OxygenVariableRepository $variableRepository;

    public function __construct(?OxygenVariableRepository $variableRepository = null)
    {
        $this->variableRepository = $variableRepository ?? new OxygenVariableRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function buildReferences(array $payload): array
    {
        $references = $this->variableRepository->buildTokenReferencesFromPayload($payload);

        return array_map(
            static function (array $reference): array {
                $reference['bindingRequired'] = ($reference['group'] ?? '') !== 'images';

                return $reference;
            },
            $references['items']
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyToConversionResult(array $result, array $payload): array
    {
        $references = $this->buildReferences($payload);
        $references = $this->markBindingRequirements($references, $result);
        $usage = $this->initializeUsage($references);

        if ($references !== []) {
            if (isset($result['element']) && is_array($result['element'])) {
                $this->bindElementTree($result['element'], $references, $usage);
            }

            if (isset($result['cssElement']) && is_array($result['cssElement'])) {
                $this->bindElementTree($result['cssElement'], $references, $usage);
            }

            foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $elementListKey) {
                if (!isset($result[$elementListKey]) || !is_array($result[$elementListKey])) {
                    continue;
                }

                foreach ($result[$elementListKey] as &$element) {
                    if (is_array($element)) {
                        $this->bindElementTree($element, $references, $usage);
                    }
                }
                unset($element);
            }

            if (isset($result['selectorPayload']) && is_array($result['selectorPayload'])) {
                $this->bindSelectorPayload($result['selectorPayload'], $references, $usage);
            }

            foreach (['extractedCss', 'globalCss', 'pageScopedCss'] as $cssKey) {
                if (!is_string($result[$cssKey] ?? null) || trim((string) $result[$cssKey]) === '') {
                    continue;
                }

                $result[$cssKey] = $this->bindCssText((string) $result[$cssKey], $references, $usage, $cssKey, 'element');
            }

            if (isset($result['styleRouting']) && is_array($result['styleRouting'])) {
                foreach (['globalCss', 'pageCss', 'pageScopedCss'] as $cssKey) {
                    if (!is_string($result['styleRouting'][$cssKey] ?? null) || trim((string) $result['styleRouting'][$cssKey]) === '') {
                        continue;
                    }

                    $result['styleRouting'][$cssKey] = $this->bindCssText(
                        (string) $result['styleRouting'][$cssKey],
                        $references,
                        $usage,
                        'styleRouting.' . $cssKey,
                        'element'
                    );
                }
            }
        }

        $tokenUsage = $this->summarizeUsage($references, $usage);
        $result['tokenUsage'] = $tokenUsage;
        $result['stats'] = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        $result['stats']['tokenUsage'] = $tokenUsage;

        if ($tokenUsage['bindingCount'] > 0) {
            $result['stats']['info'] = is_array($result['stats']['info'] ?? null) ? $result['stats']['info'] : [];
            $message = 'Bound ' . $tokenUsage['bound'] . ' supported design token(s) into Oxygen controls or CSS variables.';
            if (!in_array($message, $result['stats']['info'], true)) {
                $result['stats']['info'][] = $message;
            }
        }

        if ($tokenUsage['orphanCount'] > 0) {
            $result['stats']['warnings'] = is_array($result['stats']['warnings'] ?? null) ? $result['stats']['warnings'] : [];
            $message = 'Token binding left ' . $tokenUsage['orphanCount'] . ' supported token variable(s) unused.';
            if (!in_array($message, $result['stats']['warnings'], true)) {
                $result['stats']['warnings'][] = $message;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $path
     * @param mixed $value
     * @param list<array<string, mixed>> $references
     * @return array{value:mixed, reference:array<string, mixed>|null}
     */
    public function bindControlValue(array $path, $value, string $cssProperty, array $references, string $mode): array
    {
        $groups = $this->groupsForPath($path, $cssProperty);
        if ($groups === []) {
            return [
                'value' => $value,
                'reference' => null,
            ];
        }

        $reference = $this->matchReference($references, $groups, $value);
        if ($reference === null) {
            return [
                'value' => $value,
                'reference' => null,
            ];
        }

        return [
            'value' => $mode === 'selector'
                ? (string) $reference['selectorReference']
                : (string) $reference['cssReference'],
            'reference' => $reference,
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @param list<array<string, mixed>> $references
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     */
    private function bindElementTree(array &$element, array $references, array &$usage): void
    {
        $elementId = (string) ($element['id'] ?? 'unknown');
        $properties = &$element['data']['properties'];

        if (is_array($properties['design'] ?? null)) {
            $this->bindPropertyMap(
                $properties['design'],
                [],
                $references,
                $usage,
                'element:' . $elementId . '.design',
                'element'
            );
        }

        if (is_string($properties['content']['content']['css_code'] ?? null)) {
            $properties['content']['content']['css_code'] = $this->bindCssText(
                (string) $properties['content']['content']['css_code'],
                $references,
                $usage,
                'element:' . $elementId . '.content.css_code',
                'element'
            );
        }
        unset($properties);

        if (!isset($element['children']) || !is_array($element['children'])) {
            return;
        }

        foreach ($element['children'] as &$child) {
            if (is_array($child)) {
                $this->bindElementTree($child, $references, $usage);
            }
        }
        unset($child);
    }

    /**
     * @param list<array<string, mixed>> $references
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function markBindingRequirements(array $references, array $result): array
    {
        foreach ($references as &$reference) {
            if (($reference['group'] ?? '') !== 'images') {
                $reference['bindingRequired'] = true;
                continue;
            }

            $reference['bindingRequired'] = $this->imageReferenceHasBindableOccurrence($reference, $result);
        }
        unset($reference);

        return $references;
    }

    /**
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $result
     */
    private function imageReferenceHasBindableOccurrence(array $reference, array $result): bool
    {
        foreach (['extractedCss', 'globalCss', 'pageScopedCss'] as $cssKey) {
            if (is_string($result[$cssKey] ?? null) && $this->cssTextContainsImageReference((string) $result[$cssKey], $reference)) {
                return true;
            }
        }

        if (isset($result['styleRouting']) && is_array($result['styleRouting'])) {
            foreach (['globalCss', 'pageCss', 'pageScopedCss'] as $cssKey) {
                if (is_string($result['styleRouting'][$cssKey] ?? null) && $this->cssTextContainsImageReference((string) $result['styleRouting'][$cssKey], $reference)) {
                    return true;
                }
            }
        }

        foreach (['element', 'cssElement'] as $elementKey) {
            if (isset($result[$elementKey]) && is_array($result[$elementKey]) && $this->arrayContainsBindableImageReference($result[$elementKey], $reference, [])) {
                return true;
            }
        }

        foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $elementListKey) {
            if (!isset($result[$elementListKey]) || !is_array($result[$elementListKey])) {
                continue;
            }

            foreach ($result[$elementListKey] as $element) {
                if (is_array($element) && $this->arrayContainsBindableImageReference($element, $reference, [])) {
                    return true;
                }
            }
        }

        return isset($result['selectorPayload'])
            && is_array($result['selectorPayload'])
            && $this->arrayContainsBindableImageReference($result['selectorPayload'], $reference, []);
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function cssTextContainsImageReference(string $css, array $reference): bool
    {
        foreach ($this->rawReplacementValues($reference) as $rawValue) {
            $pattern = '/url\(\s*([\'"]?)' . preg_quote($rawValue, '/') . '\1\s*\)/i';
            if (preg_match($pattern, $css) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $reference
     * @param list<string> $path
     */
    private function arrayContainsBindableImageReference($value, array $reference, array $path): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($this->arrayContainsBindableImageReference($child, $reference, array_merge($path, [(string) $key]))) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        if ($this->isCssCodePath($path)) {
            return $this->cssTextContainsImageReference($value, $reference);
        }

        if (!$this->isBackgroundImageUrlPath($path)) {
            return false;
        }

        return $this->matchReference([$reference], ['images'], $value) !== null;
    }

    /**
     * @param array<string, mixed> $selectorPayload
     * @param list<array<string, mixed>> $references
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     */
    private function bindSelectorPayload(array &$selectorPayload, array $references, array &$usage): void
    {
        if (!isset($selectorPayload['selectors']) || !is_array($selectorPayload['selectors'])) {
            return;
        }

        foreach ($selectorPayload['selectors'] as &$selector) {
            if (!is_array($selector)) {
                continue;
            }

            $selectorName = (string) ($selector['name'] ?? $selector['id'] ?? 'selector');
            if (is_array($selector['properties'] ?? null)) {
                $this->bindPropertyMap(
                    $selector['properties'],
                    [],
                    $references,
                    $usage,
                    'selector:' . $selectorName . '.properties',
                    'selector'
                );
            }

            if (!isset($selector['children']) || !is_array($selector['children'])) {
                continue;
            }

            foreach ($selector['children'] as &$child) {
                if (!is_array($child) || !is_array($child['properties'] ?? null)) {
                    continue;
                }

                $childName = (string) ($child['name'] ?? $child['id'] ?? 'child');
                $this->bindPropertyMap(
                    $child['properties'],
                    [],
                    $references,
                    $usage,
                    'selector:' . $selectorName . '.children.' . $childName,
                    'selector'
                );
            }
            unset($child);
        }
        unset($selector);
    }

    /**
     * @param array<string|int, mixed> $properties
     * @param list<string> $path
     * @param list<array<string, mixed>> $references
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     */
    private function bindPropertyMap(
        array &$properties,
        array $path,
        array $references,
        array &$usage,
        string $destinationPrefix,
        string $mode
    ): void {
        foreach ($properties as $key => &$value) {
            $logicalKey = (string) $key;
            $nextPath = array_merge($path, [$logicalKey]);
            $destination = $destinationPrefix . '.' . implode('.', $nextPath);

            if (is_array($value)) {
                if ($this->isMeasurementObject($value)) {
                    $groups = $this->groupsForPath($nextPath, null);
                    $reference = $this->matchReference(
                        $references,
                        $groups === [] ? ['spacing', 'measurements'] : $groups,
                        (string) $value['style']
                    );
                    if ($reference !== null) {
                        $value['number'] = null;
                        $value['unit'] = 'custom';
                        $value['style'] = $mode === 'selector'
                            ? (string) $reference['selectorReference']
                            : (string) $reference['cssReference'];
                        $this->recordUsage($usage, $reference, $destination, (string) $value['style'], $mode);
                    }
                    continue;
                }

                $this->bindPropertyMap($value, $nextPath, $references, $usage, $destinationPrefix, $mode);
                continue;
            }

            if (is_string($value) && $this->isCustomCssPath($nextPath)) {
                $value = $this->bindCssText($value, $references, $usage, $destination, $mode);
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $groups = $this->groupsForPath($nextPath, null);
            if ($groups === []) {
                continue;
            }

            $reference = $this->matchReference($references, $groups, $value);
            if ($reference === null) {
                continue;
            }

            $value = $mode === 'selector'
                ? (string) $reference['selectorReference']
                : (string) $reference['cssReference'];
            $this->recordUsage($usage, $reference, $destination, (string) $value, $mode);
        }
        unset($value);
    }

    /**
     * @param list<array<string, mixed>> $references
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     */
    private function bindCssText(string $css, array $references, array &$usage, string $destination, string $mode): string
    {
        $updated = $css;

        foreach ($references as $reference) {
            $replacement = $mode === 'selector'
                ? (string) $reference['selectorReference']
                : (string) $reference['cssReference'];
            [$updated, $changed] = $this->replaceReferenceInCssText($updated, $reference, $replacement);

            if ($changed) {
                $this->recordUsage($usage, $reference, $destination, $replacement, $mode);
            }
        }

        return $updated;
    }

    /**
     * @param list<array<string, mixed>> $references
     * @param list<string> $groups
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function matchReference(array $references, array $groups, $value): ?array
    {
        $candidates = $this->candidateValues($value);
        if ($candidates === []) {
            return null;
        }

        foreach ($references as $reference) {
            if (!in_array((string) ($reference['group'] ?? ''), $groups, true)) {
                continue;
            }

            if (($reference['group'] ?? '') === 'fonts' && is_scalar($value) && $this->fontValueContainsReference((string) $value, $reference)) {
                return $reference;
            }

            foreach ($this->referenceValues($reference) as $referenceValue) {
                if (isset($candidates[$this->valueKey($referenceValue)])) {
                    return $reference;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function groupsForPath(array $path, ?string $cssProperty): array
    {
        $path = $this->withoutBreakpointSegments($path);
        $leaf = (string) end($path);
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';

        if ($cssProperty !== null) {
            $cssProperty = strtolower(trim($cssProperty));
            if (in_array($cssProperty, ['color', 'background-color', 'border-color', 'outline-color'], true)) {
                return ['colors'];
            }

            if ($cssProperty === 'font-family') {
                return ['fonts'];
            }

            if ($cssProperty === 'background-image') {
                return ['images'];
            }

            if (
                str_starts_with($cssProperty, 'padding')
                || str_starts_with($cssProperty, 'margin')
                || in_array($cssProperty, ['gap', 'row-gap', 'column-gap'], true)
            ) {
                return ['spacing'];
            }

            if (in_array($cssProperty, [
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
            ], true)) {
                return ['measurements'];
            }

            if (in_array($cssProperty, ['opacity', 'z-index', 'font-weight', 'flex-grow', 'flex-shrink', 'order'], true)) {
                return ['numbers'];
            }
        }

        if ($root === 'typography' && $section === 'font_family') {
            return ['fonts'];
        }

        if ($this->isMeasurementPath($path)) {
            return $this->measurementGroupsForPath($path);
        }

        if ($leaf === 'color' || $leaf === 'background_color' || str_ends_with($leaf, '_color')) {
            return ['colors'];
        }

        if ($leaf === 'url' && in_array('backgrounds', $path, true) && in_array('image', $path, true)) {
            return ['images'];
        }

        if (in_array($leaf, ['z_index', 'opacity', 'flex_grow', 'flex_shrink', 'order_custom'], true)) {
            return ['numbers'];
        }

        return [];
    }

    /**
     * @param list<string> $path
     */
    private function isMeasurementPath(array $path): bool
    {
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';
        $leaf = (string) end($path);

        if ($root === 'spacing') {
            return true;
        }

        if ($root === 'layout' && $section === 'gap') {
            return true;
        }

        if ($root === 'typography') {
            return in_array($section, ['font_size', 'line_height', 'letter_spacing', 'text_indent'], true);
        }

        if ($root === 'position') {
            return in_array($section, ['top', 'right', 'bottom', 'left'], true);
        }

        if ($root === 'size') {
            return in_array($section, ['width', 'height', 'max_width', 'max_height', 'min_width', 'min_height'], true);
        }

        if ($root === 'borders' && $section === 'border_radius') {
            return $leaf !== 'editMode';
        }

        if ($root === 'borders' && $section === 'borders') {
            return $leaf === 'width';
        }

        if ($root === 'effects') {
            return in_array($leaf, ['outline_width', 'outline_offset', 'duration', 'delay', 'x', 'y', 'blur', 'spread'], true);
        }

        return false;
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function measurementGroupsForPath(array $path): array
    {
        $root = $path[0] ?? '';
        $section = $path[1] ?? '';

        if ($root === 'spacing' || ($root === 'layout' && $section === 'gap')) {
            return ['spacing'];
        }

        return ['measurements'];
    }

    /**
     * @param mixed $value
     * @return array<string, true>
     */
    private function candidateValues($value): array
    {
        if (is_int($value) || is_float($value)) {
            return [$this->valueKey((string) $value) => true];
        }

        if (!is_scalar($value)) {
            return [];
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $values = [$value, trim($value, " \t\n\r\0\x0B\"'")];
        $url = $this->extractCssUrl($value);
        if ($url !== null) {
            $values[] = $url;
        }

        foreach ($this->colorVariants($value) as $variant) {
            $values[] = $variant;
        }

        $keys = [];
        foreach ($values as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $keys[$this->valueKey($candidate)] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $reference
     * @return list<string>
     */
    private function referenceValues(array $reference): array
    {
        $values = [];

        foreach (['sourceValue', 'normalizedValue'] as $field) {
            if (is_scalar($reference[$field] ?? null)) {
                $values[] = trim((string) $reference[$field]);
            }
        }

        foreach ($this->colorVariants((string) ($reference['normalizedValue'] ?? '')) as $variant) {
            $values[] = $variant;
        }

        if (($reference['group'] ?? '') === 'fonts') {
            foreach ($values as $value) {
                $values[] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param array<string, mixed> $reference
     * @return list<string>
     */
    private function rawReplacementValues(array $reference): array
    {
        $values = $this->referenceValues($reference);
        usort($values, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $values;
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceReferenceInCssText(string $css, array $reference, string $replacement): array
    {
        $parts = preg_split('/(\/\*.*?\*\/)/s', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return [$css, false];
        }

        $changed = false;

        foreach ($parts as $index => $part) {
            if ($part === '' || str_starts_with($part, '/*')) {
                continue;
            }

            [$parts[$index], $partChanged] = $this->replaceReferenceInCssSegment($part, $reference, $replacement);
            $changed = $changed || $partChanged;
        }

        return [implode('', $parts), $changed];
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceReferenceInCssSegment(string $css, array $reference, string $replacement): array
    {
        $group = (string) ($reference['group'] ?? '');

        return match ($group) {
            'images' => $this->replaceImageReferenceInCssSegment($css, $reference, $replacement),
            'fonts' => $this->replaceFontReferenceInCssSegment($css, $reference, $replacement),
            'numbers' => $this->replaceNumberReferenceInCssSegment($css, $reference, $replacement),
            'colors' => $this->replaceTokenReferenceInCssSegment($css, $reference, $replacement, '/(?<![A-Za-z0-9_-])%s(?![A-Za-z0-9_-])/i'),
            'spacing', 'measurements' => $this->replaceTokenReferenceInCssSegment($css, $reference, $replacement, '/(?<![A-Za-z0-9_.-])%s(?![A-Za-z0-9_.%-])/i'),
            default => [$css, false],
        };
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceImageReferenceInCssSegment(string $css, array $reference, string $replacement): array
    {
        $updated = $css;
        $changed = false;

        foreach ($this->rawReplacementValues($reference) as $rawValue) {
            $pattern = '/url\(\s*([\'"]?)' . preg_quote($rawValue, '/') . '\1\s*\)/i';
            $next = preg_replace($pattern, $replacement, $updated);
            if (is_string($next) && $next !== $updated) {
                $updated = $next;
                $changed = true;
            }
        }

        return [$updated, $changed];
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceFontReferenceInCssSegment(string $css, array $reference, string $replacement): array
    {
        $changed = false;
        $next = preg_replace_callback(
            '/(font-family\s*:\s*)([^;{}]+)(?=;|})/i',
            function (array $matches) use ($reference, $replacement, &$changed): string {
                $value = $matches[2];
                foreach ($this->rawReplacementValues($reference) as $rawValue) {
                    $pattern = '/(?<![A-Za-z0-9_-])(["\']?)' . preg_quote($rawValue, '/') . '\1(?![A-Za-z0-9_-])/i';
                    $updatedValue = preg_replace($pattern, $replacement, $value);
                    if (is_string($updatedValue) && $updatedValue !== $value) {
                        $value = $updatedValue;
                        $changed = true;
                    }
                }

                return $matches[1] . $value;
            },
            $css
        );

        return [is_string($next) ? $next : $css, $changed];
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceNumberReferenceInCssSegment(string $css, array $reference, string $replacement): array
    {
        $updated = $css;
        $changed = false;

        foreach ($this->rawReplacementValues($reference) as $rawValue) {
            $pattern = '/((?:opacity|z-index|font-weight|flex-grow|flex-shrink|order)\s*:\s*)'
                . preg_quote($rawValue, '/')
                . '(\s*(?:!important)?\s*)(?=;|})/i';
            $next = preg_replace($pattern, '$1' . $replacement . '$2', $updated);
            if (is_string($next) && $next !== $updated) {
                $updated = $next;
                $changed = true;
            }
        }

        return [$updated, $changed];
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{0:string,1:bool}
     */
    private function replaceTokenReferenceInCssSegment(string $css, array $reference, string $replacement, string $patternTemplate): array
    {
        $updated = $css;
        $changed = false;

        foreach ($this->rawReplacementValues($reference) as $rawValue) {
            $pattern = sprintf($patternTemplate, preg_quote($rawValue, '/'));
            $next = preg_replace($pattern, $replacement, $updated);
            if (is_string($next) && $next !== $updated) {
                $updated = $next;
                $changed = true;
            }
        }

        return [$updated, $changed];
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function fontValueContainsReference(string $value, array $reference): bool
    {
        foreach ($this->rawReplacementValues($reference) as $rawValue) {
            $pattern = '/(?<![A-Za-z0-9_-])(["\']?)' . preg_quote($rawValue, '/') . '\1(?![A-Za-z0-9_-])/i';
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function colorVariants(string $value): array
    {
        $value = trim($value);
        $variants = [];

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $matches) === 1) {
            $hex = strtoupper($matches[1]);
            $variants[] = '#' . $hex;
            $variants[] = '#' . $hex . 'FF';
        }

        if (preg_match('/^#([0-9a-f]{8})$/i', $value, $matches) === 1) {
            $hex = strtoupper($matches[1]);
            $variants[] = '#' . $hex;
            if (str_ends_with($hex, 'FF')) {
                $variants[] = '#' . substr($hex, 0, 6);
            }
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $matches) === 1) {
            $hex = strtoupper($matches[1]);
            $expanded = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            $variants[] = '#' . $hex;
            $variants[] = '#' . $expanded;
            $variants[] = '#' . $expanded . 'FF';
        }

        return $variants;
    }

    private function valueKey(string $value): string
    {
        return strtolower(trim($value, " \t\n\r\0\x0B\"'"));
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function withoutBreakpointSegments(array $path): array
    {
        return array_values(array_filter(
            $path,
            static fn (string $segment): bool => preg_match('/^(?:breakpoint_|custom_breakpoint_)/', $segment) !== 1
        ));
    }

    private function extractCssUrl(string $value): ?string
    {
        if (preg_match('/url\(\s*(["\']?)(.*?)\1\s*\)/i', trim($value), $matches) !== 1) {
            return null;
        }

        return trim($matches[2]);
    }

    /**
     * @param mixed $value
     */
    private function isMeasurementObject($value): bool
    {
        return is_array($value)
            && array_key_exists('number', $value)
            && array_key_exists('unit', $value)
            && array_key_exists('style', $value)
            && is_string($value['style'] ?? null);
    }

    /**
     * @param list<string> $path
     */
    private function isCustomCssPath(array $path): bool
    {
        return count($path) >= 2
            && $path[count($path) - 2] === 'custom_css'
            && $path[count($path) - 1] === 'custom_css';
    }

    /**
     * @param list<string> $path
     */
    private function isCssCodePath(array $path): bool
    {
        return count($path) >= 1 && $path[count($path) - 1] === 'css_code';
    }

    /**
     * @param list<string> $path
     */
    private function isBackgroundImageUrlPath(array $path): bool
    {
        return count($path) >= 3
            && $path[count($path) - 1] === 'url'
            && in_array('backgrounds', $path, true)
            && in_array('image', $path, true);
    }

    /**
     * @param list<array<string, mixed>> $references
     * @return array<string, array{count:int, bindings:list<array<string, mixed>>}>
     */
    private function initializeUsage(array $references): array
    {
        $usage = [];

        foreach ($references as $reference) {
            $key = $this->referenceKey($reference);
            $usage[$key] = [
                'count' => 0,
                'bindings' => [],
            ];
        }

        return $usage;
    }

    /**
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     * @param array<string, mixed> $reference
     */
    private function recordUsage(array &$usage, array $reference, string $destination, string $replacement, string $mode): void
    {
        $key = $this->referenceKey($reference);
        if (!isset($usage[$key])) {
            $usage[$key] = [
                'count' => 0,
                'bindings' => [],
            ];
        }

        $binding = [
            'group' => (string) ($reference['group'] ?? ''),
            'cssVariableName' => (string) ($reference['cssVariableName'] ?? ''),
            'variableId' => (string) ($reference['variableId'] ?? ''),
            'replacement' => $replacement,
            'destination' => $destination,
            'mode' => $mode,
        ];

        if (!in_array($binding, $usage[$key]['bindings'], true)) {
            $usage[$key]['bindings'][] = $binding;
            $usage[$key]['count']++;
        }
    }

    /**
     * @param list<array<string, mixed>> $references
     * @param array<string, array{count:int, bindings:list<array<string, mixed>>}> $usage
     * @return array<string, mixed>
     */
    private function summarizeUsage(array $references, array $usage): array
    {
        $bindings = [];
        $orphans = [];
        $bound = 0;
        $bindingRequired = 0;

        foreach ($references as $reference) {
            if (!empty($reference['bindingRequired'])) {
                $bindingRequired++;
            }

            $key = $this->referenceKey($reference);
            $entry = $usage[$key] ?? ['count' => 0, 'bindings' => []];
            if ($entry['count'] > 0) {
                $bound++;
                $bindings = array_merge($bindings, $entry['bindings']);
                continue;
            }

            if (!empty($reference['bindingRequired']) && (int) ($reference['uses'] ?? 0) > 0) {
                $orphans[] = [
                    'group' => (string) ($reference['group'] ?? ''),
                    'cssVariableName' => (string) ($reference['cssVariableName'] ?? ''),
                    'variableId' => (string) ($reference['variableId'] ?? ''),
                    'value' => (string) ($reference['normalizedValue'] ?? ''),
                    'uses' => (int) ($reference['uses'] ?? 0),
                ];
            }
        }

        return [
            'totalSupported' => count($references),
            'bindingRequired' => $bindingRequired,
            'bound' => $bound,
            'bindingCount' => count($bindings),
            'orphanCount' => count($orphans),
            'orphans' => $orphans,
            'bindings' => $bindings,
        ];
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function referenceKey(array $reference): string
    {
        return (string) ($reference['group'] ?? '') . ':' . (string) ($reference['cssVariableName'] ?? '');
    }
}
