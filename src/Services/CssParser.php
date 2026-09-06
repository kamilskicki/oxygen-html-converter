<?php

namespace OxyHtmlConverter\Services;

/**
 * Parses CSS content into rules and selectors
 */
class CssParser
{
    private const MAX_COMBINED_MEDIA_ALTERNATIVES = 64;
    private const MAX_COMBINED_MEDIA_BYTES = 8192;

    /**
     * Parse CSS content into an array of rules
     *
     * @param string $css
     * @return array Array of [selector, declarations]
     */
    public function parse(string $css): array
    {
        return $this->parseRuleBlocks($css);
    }

    /**
     * @return array<int, array{selector:string, declarations:array<string, string>, media?:string}>
     */
    private function parseRuleBlocks(string $css, ?string $media = null): array
    {
        $rules = [];
        $len = strlen($css);
        $ruleStart = 0;
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $parenDepth = 0;
        $bracketDepth = 0;

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

            // Handle string literals (skip braces inside quotes)
            if ($inString) {
                if ($char === $stringChar && !$this->isEscaped($css, $i)) {
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

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif ($char === '[') {
                $bracketDepth++;
            } elseif ($char === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
            }

            if ($parenDepth !== 0 || $bracketDepth !== 0) {
                continue;
            }

            // Statement at-rules (@charset, @import, @layer) end without a block.
            if ($char === ';') {
                $ruleStart = $i + 1;
                continue;
            }

            if ($char !== '{') {
                continue;
            }

            $blockEnd = $this->findMatchingBlockEnd($css, $i);
            if ($blockEnd === null) {
                break;
            }

            $selector = $this->normalizeSelectorPrelude(substr($css, $ruleStart, $i - $ruleStart));
            $block = substr($css, $i + 1, $blockEnd - $i - 1);
            $ruleStart = $blockEnd + 1;
            $i = $blockEnd;

            if ($selector === '') {
                continue;
            }

            if (preg_match('/^@media\s+(.+)$/i', $selector, $matches) === 1) {
                $combinedMedia = $this->combineMediaConditions($media, trim($matches[1]));
                if ($combinedMedia === null) {
                    // Keep complex media groups in the original CSS owner.
                    continue;
                }
                foreach ($this->parseRuleBlocks($block, $combinedMedia) as $rule) {
                    $rules[] = $rule;
                }
                continue;
            }

            if (strpos($selector, '@') === 0) {
                continue;
            }

            $declarationMetadata = $this->parseDeclarationsWithImportance($block);
            $declarations = $declarationMetadata['declarations'];
            foreach ($this->splitTopLevelList($selector) as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }

                $rule = [
                    'selector' => $sel,
                    'declarations' => $declarations,
                ];

                if ($declarationMetadata['important'] !== []) {
                    $rule['importantDeclarations'] = $declarationMetadata['important'];
                }

                if ($media !== null && $media !== '') {
                    $rule['media'] = $media;
                }

                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function normalizeSelectorPrelude(string $selector): string
    {
        $normalized = '';
        $quote = '';
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($char === '\\') {
                $normalized .= $char . ($selector[++$i] ?? '');
                continue;
            }
            if ($quote !== '') {
                $normalized .= $char;
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '/' && ($selector[$i + 1] ?? '') === '*') {
                $end = strpos($selector, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }
            $normalized .= $char;
        }

        return trim($normalized);
    }

    /**
     * Split selector/media lists without splitting functional arguments or strings.
     *
     * @return list<string>
     */
    private function splitTopLevelList(string $input): array
    {
        $parts = [];
        $start = 0;
        $quote = '';
        $depth = 0;
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($input, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($input, $start));

        return $parts;
    }

    private function combineMediaConditions(?string $parent, string $child): ?string
    {
        if ($parent === null || trim($parent) === '') {
            return $child;
        }

        $conditions = [];
        $parents = $this->splitTopLevelList($parent);
        $children = $this->splitTopLevelList($child);
        if (count($parents) * count($children) > self::MAX_COMBINED_MEDIA_ALTERNATIVES) {
            return null;
        }
        $bytes = 0;
        foreach ($parents as $parentCondition) {
            foreach ($children as $childCondition) {
                $condition = $parentCondition . ' and ' . $childCondition;
                $bytes += strlen($condition) + 2;
                if ($bytes > self::MAX_COMBINED_MEDIA_BYTES) {
                    return null;
                }
                $conditions[] = $condition;
            }
        }

        return implode(', ', $conditions);
    }

    /**
     * Parse declaration block into key-value pairs
     */
    public function parseDeclarations(string $declarationsRaw): array
    {
        return $this->parseDeclarationsWithImportance($declarationsRaw)['declarations'];
    }

    /**
     * Return the declarations whose winning value in the block is marked !important.
     *
     * @return array<string, bool>
     */
    public function parseImportantDeclarations(string $declarationsRaw): array
    {
        return $this->parseDeclarationsWithImportance($declarationsRaw)['important'];
    }

    /**
     * Parse declaration block into ordered property/value records.
     *
     * @return array<int, array{property: string, value: string, important: bool}>
     */
    public function parseDeclarationList(string $declarationsRaw): array
    {
        $declarations = [];

        foreach ($this->splitDeclarations($declarationsRaw) as $part) {
            $parsed = $this->splitPropertyValue($part);
            if ($parsed === null) {
                continue;
            }

            [$property, $value] = $parsed;
            $important = preg_match('/\s*!\s*important\s*$/i', $value) === 1;
            $value = $this->stripImportant($value);

            if ($property !== '' && $value !== '') {
                $declarations[] = [
                    'property' => $property,
                    'value' => $value,
                    'important' => $important,
                ];
            }
        }

        return $declarations;
    }

    /**
     * Resolve duplicate declarations inside one block while retaining importance.
     *
     * @return array{declarations:array<string,string>, important:array<string,bool>}
     */
    private function parseDeclarationsWithImportance(string $declarationsRaw): array
    {
        $declarations = [];
        $important = [];

        foreach ($this->splitDeclarations($declarationsRaw) as $part) {
            $parsed = $this->splitPropertyValue($part);
            if ($parsed === null) {
                continue;
            }

            [$property, $rawValue] = $parsed;
            // Custom property names are case-sensitive; ordinary properties are not.
            $property = str_starts_with($property, '--') ? $property : strtolower($property);
            $isImportant = preg_match('/\s*!\s*important\s*$/i', $rawValue) === 1;
            $value = $this->stripImportant($rawValue);

            if ($property === '' || $value === '') {
                continue;
            }

            if (($important[$property] ?? false) && !$isImportant) {
                continue;
            }

            $declarations[$property] = $value;
            if ($isImportant) {
                $important[$property] = true;
            } else {
                unset($important[$property]);
            }
        }

        return [
            'declarations' => $declarations,
            'important' => $important,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitDeclarations(string $declarationsRaw): array
    {
        $parts = [];
        $current = '';
        $len = strlen($declarationsRaw);
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $declarationsRaw[$i];
            $next = $declarationsRaw[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar && !$this->isEscaped($declarationsRaw, $i)) {
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
                $current .= $char;
                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                $current .= $char;
                continue;
            }

            if ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
                $current .= $char;
                continue;
            }

            if ($char === '[') {
                $bracketDepth++;
                $current .= $char;
                continue;
            }

            if ($char === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
                $current .= $char;
                continue;
            }

            if (
                $char === '{'
                && $parenDepth === 0
                && $bracketDepth === 0
                && $braceDepth === 0
                && preg_match('/^\s*--[^\s:]+\s*:/', $current) !== 1
            ) {
                $current = '';
                $this->skipNestedBlock($declarationsRaw, $i);
                continue;
            }

            if ($char === '{') {
                $braceDepth++;
                $current .= $char;
                continue;
            }

            if ($char === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                $current .= $char;
                continue;
            }

            if ($char === ';' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $part = trim($current);
                if ($part !== '') {
                    $parts[] = $part;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $part = trim($current);
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * @return array{string, string}|null
     */
    private function splitPropertyValue(string $declaration): ?array
    {
        $len = strlen($declaration);
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $declaration[$i];
            $next = $declaration[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isEscaped($declaration, $i)) {
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

            if ($char === '{') {
                $braceDepth++;
                continue;
            }

            if ($char === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                continue;
            }

            if ($char === ':' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $property = trim(substr($declaration, 0, $i));
                $value = trim(substr($declaration, $i + 1));

                return [$property, $value];
            }
        }

        return null;
    }

    private function skipNestedBlock(string $input, int &$offset): void
    {
        $len = strlen($input);
        $depth = 1;
        $inString = false;
        $stringChar = '';
        $inComment = false;

        for ($i = $offset + 1; $i < $len; $i++) {
            $char = $input[$i];
            $next = $input[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isEscaped($input, $i)) {
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
                    $offset = $i;
                    return;
                }
            }
        }

        $offset = $len - 1;
    }

    private function findMatchingBlockEnd(string $input, int $blockStart): ?int
    {
        $len = strlen($input);
        $depth = 1;
        $inString = false;
        $stringChar = '';
        $inComment = false;

        for ($i = $blockStart + 1; $i < $len; $i++) {
            $char = $input[$i];
            $next = $input[$i + 1] ?? '';

            if ($inComment) {
                if ($char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === $stringChar && !$this->isEscaped($input, $i)) {
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

    private function stripImportant(string $value): string
    {
        return trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));
    }

    private function isEscaped(string $input, int $offset): bool
    {
        $slashes = 0;
        for ($i = $offset - 1; $i >= 0 && $input[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    /**
     * Map CSS rules to specific elements based on ID or Class
     * This is a simplified mapping for Oxygen
     */
    public function mapRulesToElements(array $rules, array &$elements): void
    {
        foreach ($rules as $rule) {
            $selector = $rule['selector'];
            $declarations = $rule['declarations'];

            // Simple ID selector mapping: #id
            if (preg_match('/^#([a-zA-Z0-9_\-]+)$/', $selector, $matches)) {
                $id = $matches[1];
                if (isset($elements[$id])) {
                    $elements[$id]['css_rules'] = array_merge($elements[$id]['css_rules'] ?? [], $declarations);
                }
            }
            // Simple class selector mapping: .class
            // Note: In Oxygen, we usually prefer to map these to the element if it's the only one, 
            // or let the ClassStrategyService handle it. For now, we just store it.
        }
    }
}
