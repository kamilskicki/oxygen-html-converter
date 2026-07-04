<?php

namespace OxyHtmlConverter\Services;

/**
 * Parses CSS content into rules and selectors
 */
class CssParser
{
    /**
     * Parse CSS content into an array of rules
     *
     * @param string $css
     * @return array Array of [selector, declarations]
     */
    public function parse(string $css): array
    {
        $rules = [];

        // Brace-depth-aware parser to handle nested @keyframes, @media, @property blocks
        $len = strlen($css);
        $depth = 0;
        $selector = '';
        $block = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $isAtRule = false;

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
                if ($depth === 1 && !$isAtRule) {
                    $block .= $char;
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
                if ($depth === 1 && !$isAtRule) {
                    $block .= $char;
                }
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    // Starting a new top-level block
                    $isAtRule = (strpos(trim($selector), '@') === 0);
                }
                $depth++;
                if ($depth === 1 && !$isAtRule) {
                    // Opening brace for a normal rule — don't add to block
                    continue;
                }
                if ($depth > 1 && !$isAtRule) {
                    $block .= $char;
                }
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    if (!$isAtRule) {
                        // Emit the normal rule
                        $selectors = explode(',', $selector);
                        $declarations = $this->parseDeclarations($block);

                        foreach ($selectors as $sel) {
                            $sel = trim($sel);
                            if ($sel) {
                                $rules[] = [
                                    'selector' => $sel,
                                    'declarations' => $declarations,
                                ];
                            }
                        }
                    }
                    // Reset for next rule (at-rules are skipped)
                    $selector = '';
                    $block = '';
                    $isAtRule = false;
                    continue;
                }
                if (!$isAtRule) {
                    $block .= $char;
                }
                continue;
            }

            // Accumulate characters
            if ($depth === 0) {
                $selector .= $char;
            } elseif ($depth >= 1 && !$isAtRule) {
                $block .= $char;
            }
        }

        return $rules;
    }

    /**
     * Parse declaration block into key-value pairs
     */
    public function parseDeclarations(string $declarationsRaw): array
    {
        $declarations = [];

        foreach ($this->parseDeclarationList($declarationsRaw) as $declaration) {
            $declarations[$declaration['property']] = $declaration['value'];
        }

        return $declarations;
    }

    /**
     * Parse declaration block into ordered property/value records.
     *
     * @return array<int, array{property: string, value: string}>
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
            $value = $this->stripImportant($value);

            if ($property !== '' && $value !== '') {
                $declarations[] = [
                    'property' => $property,
                    'value' => $value,
                ];
            }
        }

        return $declarations;
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
                && $this->splitPropertyValue($current) === null
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
