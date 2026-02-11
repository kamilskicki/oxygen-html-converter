<?php

namespace OxyHtmlConverter\Services;

/**
 * Service for transforming JavaScript to be compatible with Oxygen Builder's interaction system
 */
class JavaScriptTransformer
{
    /**
     * Transform JavaScript to make functions available on the window object
     *
     * Oxygen's Interactions system calls functions via window.functionName()
     * This method transforms standard function declarations:
     *   function toggleMenu() { ... }
     * Into window object assignments:
     *   window.toggleMenu = function() { ... }
     *
     * Also handles:
     * - Arrow functions assigned to const/let/var
     * - Function expressions assigned to variables
     * - Separates function definitions from initialization code
     * - Wraps ONLY initialization code in DOMContentLoaded (functions stay outside)
     *
     * @param string $js The original JavaScript code
     * @return string The transformed JavaScript code
     */
    public function transformJavaScriptForOxygen(string $js): string
    {
        $js = trim($js);

        if (empty($js)) {
            return $js;
        }

        // Check if code is already wrapped in DOMContentLoaded or window.onload
        $isAlreadyWrapped = preg_match('/^\s*(document\.addEventListener\s*\(\s*[\'"]DOMContentLoaded|window\.onload|jQuery\s*\(\s*function|\$\s*\(\s*function|\$\s*\(\s*document\s*\)\.ready)/', $js);

        // Extract function definitions and separate them from other code
        $functions = [];
        $otherCode = $js;

        // 1. ES6 Class Methods (extract before classes are potentially removed or modified)
        // SKIP this section - it was causing issues with arrow function callbacks like 
        // entries.forEach(entry => { ... }) being incorrectly matched as class methods.
        // Class methods within ES6 classes will still work in the original code context.
        // This section only extracts standalone method-like patterns which is too aggressive.

        // Standard function declarations: [async] function name() { ... }
        $offset = 0;
        while (preg_match('/\b(async\s+)?function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*\{/i', $otherCode, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $isAsync = !empty($matches[1][0]);
            $funcName = $matches[2][0];
            $originalParams = trim($matches[3][0]);
            $matchStart = $matches[0][1];
            $fullMatch = $matches[0][0];

            // Find the opening brace position
            $bracePos = $matchStart + strlen($fullMatch) - 1;

            // Find matching closing brace
            $endPos = $this->findMatchingBrace($otherCode, $bracePos);

            if ($endPos !== false) {
                $functionBody = substr($otherCode, $bracePos + 1, $endPos - $bracePos - 1);

                // Transform to Oxygen-compatible window function
                $functions[] = $this->createOxygenCompatibleFunction($funcName, $originalParams, $functionBody, $isAsync);

                // Remove function from other code
                $otherCode = substr($otherCode, 0, $matchStart) . substr($otherCode, $endPos + 1);
                $offset = 0; // Reset offset since we modified the string
            } else {
                $offset = $matchStart + strlen($fullMatch);
            }
        }

        // 3. Arrow functions and expressions: const name = [async] (...) => { ... } or const name = [async] function(...) { ... }
        $offset = 0;
        while (preg_match('/\b(?:const|let|var)\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(async\s+)?(?:function\s*\(([^)]*)\)|(\([^)]*\)|[a-zA-Z_][a-zA-Z0-9_]*)\s*=>)\s*(\{)?/i', $otherCode, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $funcName = $matches[1][0];
            $isAsync = !empty($matches[2][0]);
            $isFunctionExpr = !empty($matches[3][0]) || (isset($matches[3]) && $matches[3][1] !== -1);
            $originalParams = $isFunctionExpr ? trim($matches[3][0]) : trim($matches[4][0]);
            $hasBraces = !empty($matches[5][0]);
            $matchStart = $matches[0][1];
            $fullMatch = $matches[0][0];

            if ($hasBraces) {
                $bracePos = $matchStart + strlen($fullMatch) - 1;
                $endPos = $this->findMatchingBrace($otherCode, $bracePos);

                if ($endPos !== false) {
                    $functionBody = substr($otherCode, $bracePos + 1, $endPos - $bracePos - 1);
                    $functions[] = "window.{$funcName} = " . ($isAsync ? "async " : "") . ($isFunctionExpr ? "function({$originalParams})" : "{$originalParams} =>") . " {{$functionBody}}";
                    $otherCode = substr($otherCode, 0, $matchStart) . substr($otherCode, $endPos + 1);
                    $offset = 0;
                    continue;
                }
            } else {
                // Arrow function with implicit return (no braces)
                $remaining = substr($otherCode, $matchStart + strlen($fullMatch));
                if (preg_match('/^([^;\r\n]+)/', $remaining, $exprMatches)) {
                    $expression = trim($exprMatches[1]);
                    $functions[] = "window.{$funcName} = " . ($isAsync ? "async " : "") . "{$originalParams} => {$expression}";
                    $otherCode = substr($otherCode, 0, $matchStart) . substr($otherCode, $matchStart + strlen($fullMatch) + strlen($exprMatches[0]));
                    $offset = 0;
                    continue;
                }
            }
            $offset = $matchStart + strlen($fullMatch);
        }

        // Clean up other code (remove empty lines, trim)
        $otherCode = preg_replace('/^\s*[\r\n]+/m', '', $otherCode);
        $otherCode = trim($otherCode);

        // Build final output:
        // 1. Functions first (outside any wrapper, immediately available on window)
        // 2. Init code wrapped in DOMContentLoaded if needed
        $output = '';

        if (!empty($functions)) {
            $output .= "// Functions (available on window object)\n";
            $output .= implode("\n\n", $functions);
            $output .= "\n\n";
        }

        if (!empty($otherCode)) {
            // Don't add DOMContentLoaded wrapper — Oxygen already wraps
            // JavaScript Code elements in DOMContentLoaded automatically.
            // Adding our own causes double-wrapping where the inner listener
            // never fires (since the event already dispatched).
            if ($isAlreadyWrapped) {
                // Strip existing DOMContentLoaded wrapper since Oxygen adds one
                $otherCode = $this->stripDomContentLoadedWrapper($otherCode);
            }
            $output .= $otherCode;
        }

        return trim($output);
    }

    /**
     * Find the position of the matching closing brace
     *
     * @param string $code The code to search
     * @param int $openBracePos Position of the opening brace
     * @return int|false Position of closing brace or false if not found
     */
    private function findMatchingBrace(string $code, int $openBracePos)
    {
        $length = strlen($code);
        $depth = 1;
        $pos = $openBracePos + 1;
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = ''; // 'single' or 'multi'

        while ($pos < $length && $depth > 0) {
            $char = $code[$pos];
            $nextChar = $pos + 1 < $length ? $code[$pos + 1] : '';
            $prevChar = $pos > 0 ? $code[$pos - 1] : '';

            // Handle comments
            if (!$inString && !$inComment) {
                if ($char === '/' && $nextChar === '/') {
                    $inComment = true;
                    $commentType = 'single';
                    $pos++; // Skip second /
                } elseif ($char === '/' && $nextChar === '*') {
                    $inComment = true;
                    $commentType = 'multi';
                    $pos++; // Skip *
                }
            } elseif ($inComment) {
                if ($commentType === 'single' && ($char === "\n" || $char === "\r")) {
                    $inComment = false;
                    if ($char === "\r" && $nextChar === "\n") {
                        $pos++;
                    }
                } elseif ($commentType === 'multi' && $char === '*' && $nextChar === '/') {
                    $inComment = false;
                    $pos++; // Skip /
                }
            }

            // Handle string literals
            if (!$inComment) {
                if (!$inString && ($char === '"' || $char === "'" || $char === '`')) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                    $inString = false;
                } elseif (!$inString) {
                    if ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                    }
                }
            }

            $pos++;
        }

        return $depth === 0 ? $pos - 1 : false;
    }

    /**
     * Strip DOMContentLoaded / window.onload wrapper from code
     *
     * Since Oxygen wraps JS Code elements in DOMContentLoaded automatically,
     * we need to strip any existing wrapper to avoid double-wrapping.
     */
    private function stripDomContentLoadedWrapper(string $code): string
    {
        $code = trim($code);

        // Match: document.addEventListener('DOMContentLoaded', function() { ... });
        if (preg_match('/^\s*document\.addEventListener\s*\(\s*[\'"]DOMContentLoaded[\'"]\s*,\s*function\s*\([^)]*\)\s*\{/', $code, $matches)) {
            $bracePos = strpos($code, '{', strpos($code, 'function'));
            if ($bracePos !== false) {
                $endPos = $this->findMatchingBrace($code, $bracePos);
                if ($endPos !== false) {
                    // Extract the inner body
                    $inner = substr($code, $bracePos + 1, $endPos - $bracePos - 1);
                    return trim($inner);
                }
            }
        }

        // Match: window.onload = function() { ... };
        if (preg_match('/^\s*window\.onload\s*=\s*function\s*\([^)]*\)\s*\{/', $code, $matches)) {
            $bracePos = strpos($code, '{', strpos($code, 'function'));
            if ($bracePos !== false) {
                $endPos = $this->findMatchingBrace($code, $bracePos);
                if ($endPos !== false) {
                    $inner = substr($code, $bracePos + 1, $endPos - $bracePos - 1);
                    return trim($inner);
                }
            }
        }

        return $code;
    }

    /**
     * Strip JavaScript blocks that were converted to native Oxygen features.
     *
     * Removes:
     * - IntersectionObserver block for .animate-on-scroll
     * - Smooth scroll addEventListener block for a[href^="#"]
     * - Mobile menu toggle addEventListener blocks (navToggle, mobileMenuClose, mobileLinks)
     *
     * @param string $js         The JavaScript code
     * @param bool   $removeObserver   Remove IntersectionObserver block
     * @param bool   $removeSmoothScroll  Remove smooth scroll block
     * @param array  $removeToggleIds  Element IDs whose addEventListener blocks should be removed
     * @return string  Cleaned JavaScript
     */
    public function stripConvertedPatterns(
        string $js,
        bool $removeObserver = false,
        bool $removeSmoothScroll = false,
        array $removeToggleIds = []
    ): string {
        if (empty(trim($js))) {
            return $js;
        }

        // Remove IntersectionObserver block for scroll animations
        if ($removeObserver) {
            $js = $this->removeIntersectionObserverBlock($js);
        }

        // Remove smooth scroll for anchor links
        if ($removeSmoothScroll) {
            $js = $this->removeSmoothScrollBlock($js);
        }

        // Remove addEventListener blocks for specific element IDs
        foreach ($removeToggleIds as $id) {
            $js = $this->removeEventListenerBlockForId($js, $id);
        }

        // Remove querySelectorAll(...).forEach addEventListener blocks for mobile-link etc.
        if (!empty($removeToggleIds)) {
            $js = $this->removeForEachEventListenerBlocks($js);
        }

        // Remove variable declarations for removed element IDs
        foreach ($removeToggleIds as $id) {
            $js = $this->removeVariableDeclaration($js, $id);
        }

        // Clean up excessive blank lines
        $js = preg_replace('/\n{3,}/', "\n\n", $js);

        return trim($js);
    }

    /**
     * Remove IntersectionObserver block that adds 'visible' class to .animate-on-scroll elements.
     */
    private function removeIntersectionObserverBlock(string $js): string
    {
        // Remove the observerOptions const
        $js = preg_replace(
            '/\s*(?:const|let|var)\s+observerOptions\s*=\s*\{[^}]*\};\s*/s',
            "\n",
            $js
        );

        // Remove: const observer = new IntersectionObserver(...);
        // This is a multi-level block, use regex for the common pattern
        $js = preg_replace(
            '/\s*(?:const|let|var)\s+observer\s*=\s*new\s+IntersectionObserver\s*\([^;]*;\s*/s',
            "\n",
            $js
        );

        // Remove: document.querySelectorAll('.animate-on-scroll').forEach(el => { observer.observe(el); });
        $js = preg_replace(
            '/\s*document\.querySelectorAll\s*\(\s*[\'"]\.animate-on-scroll[\'"]\s*\)\.forEach\s*\([^;]*;\s*/s',
            "\n",
            $js
        );

        return $js;
    }

    /**
     * Remove smooth scroll anchor link block.
     */
    private function removeSmoothScrollBlock(string $js): string
    {
        // Remove: document.querySelectorAll('a[href^="#"]').forEach(anchor => { ... });
        $pattern = '/document\.querySelectorAll\s*\(\s*[\'"]a\[href\^=[\'"]?#[\'"]?\][\'"]\s*\)\.forEach/';
        if (preg_match($pattern, $js, $m, PREG_OFFSET_CAPTURE)) {
            $startPos = $m[0][1];

            // Find the start of the full statement (may have preceding whitespace/comment)
            $lineStart = strrpos(substr($js, 0, $startPos), "\n");
            $lineStart = $lineStart !== false ? $lineStart : 0;

            // Find the forEach callback block and its closing
            $remaining = substr($js, $startPos);
            // Find the outermost opening brace of the forEach callback
            $bracePos = strpos($remaining, '{');
            if ($bracePos !== false) {
                $absoluteBracePos = $startPos + $bracePos;
                $endPos = $this->findMatchingBrace($js, $absoluteBracePos);
                if ($endPos !== false) {
                    // Skip past closing ); of forEach
                    $afterBlock = substr($js, $endPos + 1, 10);
                    $extraChars = 0;
                    if (preg_match('/^\s*\)\s*;?/', $afterBlock, $am)) {
                        $extraChars = strlen($am[0]);
                    }
                    $js = substr($js, 0, $lineStart) . substr($js, $endPos + 1 + $extraChars);
                }
            }
        }

        return $js;
    }

    /**
     * Remove addEventListener block for a variable that references getElementById('id').
     */
    private function removeEventListenerBlockForId(string $js, string $elementId): string
    {
        // Find variable name: const varName = document.getElementById('elementId');
        $varName = null;
        if (preg_match('/(?:const|let|var)\s+(\w+)\s*=\s*document\.getElementById\s*\(\s*[\'"]' . preg_quote($elementId, '/') . '[\'"]\s*\)/', $js, $m)) {
            $varName = $m[1];
        }

        if (!$varName) {
            return $js;
        }

        // Remove: varName.addEventListener('event', ... { ... });
        $pattern = '/' . preg_quote($varName, '/') . '\.addEventListener\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(?:function\s*\([^)]*\)|[^)]*=>)\s*\{/';
        while (preg_match($pattern, $js, $m, PREG_OFFSET_CAPTURE)) {
            $matchStart = $m[0][1];
            $bracePos = $matchStart + strlen($m[0][0]) - 1;
            $endPos = $this->findMatchingBrace($js, $bracePos);

            if ($endPos !== false) {
                // Skip past closing ); of addEventListener
                $afterBlock = substr($js, $endPos + 1, 10);
                $extraChars = 0;
                if (preg_match('/^\s*\)\s*;?/', $afterBlock, $am)) {
                    $extraChars = strlen($am[0]);
                }

                // Find start of line
                $lineStart = strrpos(substr($js, 0, $matchStart), "\n");
                $lineStart = $lineStart !== false ? $lineStart : $matchStart;

                $js = substr($js, 0, $lineStart) . substr($js, $endPos + 1 + $extraChars);
            } else {
                break; // Avoid infinite loop
            }
        }

        return $js;
    }

    /**
     * Remove querySelectorAll(...).forEach addEventListener blocks (e.g., mobileLinks).
     */
    private function removeForEachEventListenerBlocks(string $js): string
    {
        // Match: document.querySelectorAll('.mobile-link').forEach(... => { ...addEventListener... })
        $pattern = '/(?:const|let|var)\s+\w+\s*=\s*document\.querySelectorAll\s*\(\s*[\'"]\.mobile-link[\'"]\s*\)\s*;?\s*/';
        $js = preg_replace($pattern, '', $js);

        // Remove: mobileLinks.forEach(link => { link.addEventListener('click', () => { ... }) });
        $forEachPattern = '/\w+\.forEach\s*\(\s*\w+\s*=>\s*\{\s*\w+\.addEventListener/';
        if (preg_match($forEachPattern, $js, $m, PREG_OFFSET_CAPTURE)) {
            $startPos = $m[0][1];
            $lineStart = strrpos(substr($js, 0, $startPos), "\n");
            $lineStart = $lineStart !== false ? $lineStart : $startPos;

            // Find the outer forEach block opening brace
            $outerBracePos = strpos($js, '{', $startPos);
            if ($outerBracePos !== false) {
                $outerEnd = $this->findMatchingBrace($js, $outerBracePos);
                if ($outerEnd !== false) {
                    $afterBlock = substr($js, $outerEnd + 1, 10);
                    $extraChars = 0;
                    if (preg_match('/^\s*\)\s*;?/', $afterBlock, $am)) {
                        $extraChars = strlen($am[0]);
                    }
                    $js = substr($js, 0, $lineStart) . substr($js, $outerEnd + 1 + $extraChars);
                }
            }
        }

        return $js;
    }

    /**
     * Remove variable declaration for an element ID if it's now unused.
     */
    private function removeVariableDeclaration(string $js, string $elementId): string
    {
        // Find: const varName = document.getElementById('elementId');
        if (preg_match('/(?:const|let|var)\s+(\w+)\s*=\s*document\.getElementById\s*\(\s*[\'"]' . preg_quote($elementId, '/') . '[\'"]\s*\)\s*;?/', $js, $m)) {
            $varName = $m[1];

            // Check if variable is still used elsewhere in the code (beyond the declaration)
            $codeWithoutDeclaration = str_replace($m[0], '', $js);
            if (strpos($codeWithoutDeclaration, $varName) === false) {
                // Variable is no longer used, remove the declaration
                $js = preg_replace(
                    '/\s*(?:const|let|var)\s+' . preg_quote($varName, '/') . '\s*=\s*document\.getElementById\s*\(\s*[\'"]' . preg_quote($elementId, '/') . '[\'"]\s*\)\s*;?\s*/',
                    "\n",
                    $js
                );
            }
        }

        return $js;
    }

    /**
     * Create an Oxygen-compatible function wrapper
     *
     * Oxygen's javascript_function action calls: window.funcName(event, target, action)
     * Original functions may have different signatures like: function setActiveDna(index)
     *
     * This method creates a wrapper that:
     * 1. Accepts the Oxygen signature (event, target, action)
     * 2. Extracts original arguments from data-arg-{funcname} attributes on the target
     * 3. Calls the original function body with those arguments available
     *
     * @param string $funcName Function name
     * @param string $originalParams Original parameter list (e.g., "index" or "a, b")
     * @param string $functionBody The function body code
     * @return string The transformed function code
     */
    private function createOxygenCompatibleFunction(string $funcName, string $originalParams, string $functionBody, bool $isAsync = false): string
    {
        $asyncPrefix = $isAsync ? 'async ' : '';
        
        // Parse original parameters
        $params = array_filter(array_map('trim', explode(',', $originalParams)));

        // If no parameters, simple transformation
        if (empty($params)) {
            return "window.{$funcName} = {$asyncPrefix}function(event, target, action) {{$functionBody}}";
        }

        // Build argument extraction code
        // Data attribute: data-arg-{funcname} → dataset.arg{Funcname}
        $funcNameLower = strtolower($funcName);
        // Convert data-arg-setactivedna to dataset.argSetactivedna (camelCase after 'arg')
        $datasetKey = 'arg' . ucfirst($funcNameLower);

        $argExtraction = "\n    // Extract original arguments from data-arg-{$funcNameLower} attribute (set by converter)\n";
        $argExtraction .= "    var _rawArgs = target ? (target.dataset['{$datasetKey}'] || target.getAttribute('data-arg-{$funcNameLower}') || '') : '';\n";

        // Create variable assignments for each original parameter
        foreach ($params as $index => $param) {
            if ($index === 0) {
                // First param gets the raw value (or parsed if numeric)
                $argExtraction .= "    var {$param} = _rawArgs;\n";
                $argExtraction .= "    if (_rawArgs !== '' && !isNaN(_rawArgs)) { {$param} = parseInt(_rawArgs, 10); }\n";
            } else {
                // Multiple params would need comma-split
                $argExtraction .= "    // Note: Multiple params - split by comma if needed\n";
                $argExtraction .= "    var _argParts = _rawArgs.split(',');\n";
                $argExtraction .= "    var {$param} = _argParts[{$index}] ? _argParts[{$index}].trim() : undefined;\n";
                break; // Only add split logic once
            }
        }

        // Handle multiple params after split
        if (count($params) > 1) {
            foreach ($params as $index => $param) {
                if ($index > 0) {
                    $argExtraction .= "    if (_argParts[{$index}]) { {$param} = _argParts[{$index}].trim(); if (!isNaN({$param})) { {$param} = parseInt({$param}, 10); } }\n";
                }
            }
        }

        return "window.{$funcName} = {$asyncPrefix}function(event, target, action) {\n{$argExtraction}{$functionBody}}";
    }
}