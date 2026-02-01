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

        // Remove comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css);

        // Basic regex to find rules: selector { declarations }
        preg_match_all('/([^{]+)\{([^}]+)\}/', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $selectors = explode(',', $match[1]);
            $declarationsRaw = $match[2];

            $declarations = $this->parseDeclarations($declarationsRaw);

            foreach ($selectors as $selector) {
                $selector = trim($selector);
                if ($selector) {
                    $rules[] = [
                        'selector' => $selector,
                        'declarations' => $declarations
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Parse declaration block into key-value pairs
     */
    private function parseDeclarations(string $declarationsRaw): array
    {
        $declarations = [];
        $parts = explode(';', $declarationsRaw);

        foreach ($parts as $part) {
            $part = trim($part);
            if (!$part) {
                continue;
            }

            $bits = explode(':', $part, 2);
            if (count($bits) === 2) {
                $prop = trim($bits[0]);
                $val = trim($bits[1]);

                // Remove !important if present
                $val = trim(str_replace('!important', '', $val));

                if ($prop && $val) {
                    $declarations[$prop] = $val;
                }
            }
        }

        return $declarations;
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
