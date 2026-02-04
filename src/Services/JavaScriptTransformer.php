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
        $offset = 0;
        while (preg_match('/\b(static\s+)?([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*\{/i', $otherCode, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchText = $matches[0][0];
            $matchStart = $matches[0][1];
            
            // Check if this is likely a class method (must be preceded by 'class ... {' or another method)
            // For now, we'll use a heuristic: if it's not preceded by 'function', it might be a method
            $beforeMatch = substr($otherCode, 0, $matchStart);
            $isStandardFunc = preg_match('/function\s*$/i', $beforeMatch);
            $isReserved = in_array(strtolower($matches[2][0]), ['if', 'for', 'while', 'switch', 'catch', 'function']);

            if (!$isStandardFunc && !$isReserved) {
                $funcName = $matches[2][0];
                $originalParams = trim($matches[3][0]);
                $bracePos = $matchStart + strlen($matchText) - 1;
                $endPos = $this->findMatchingBrace($otherCode, $bracePos);

                if ($endPos !== null) {
                    $functionBody = substr($otherCode, $bracePos + 1, $endPos - $bracePos - 1);
                    $functions[] = $this->createOxygenCompatibleFunction($funcName, $originalParams, $functionBody);

                    // We don't remove class methods from the original code as the class might still be used
                    // but we need to move the offset to avoid infinite loop
                    $offset = $endPos + 1;
                    continue;
                }
            }
            $offset = $matchStart + strlen($matchText);
        }

        // 2. Standard function declarations: [async] function name() { ... }
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

            if ($endPos !== null) {
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

                if ($endPos !== null) {
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

        // Check if remaining code needs DOM ready wrapper
        $hasInitCode = !empty($otherCode) && preg_match('/lucide\.createIcons|\.querySelectorAll|\.getElementById|\.getElementsByClassName|\.querySelector\s*\(|\.addEventListener/', $otherCode);

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
            if ($hasInitCode && !$isAlreadyWrapped) {
                $output .= "// Initialization (runs when DOM is ready)\n";
                $output .= "document.addEventListener('DOMContentLoaded', function() {\n";
                $output .= "    " . str_replace("\n", "\n    ", $otherCode) . "\n";
                $output .= "});";
            } else {
                $output .= $otherCode;
            }
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
    private function findMatchingBrace(string $code, int $openBracePos): ?int
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

        return $depth === 0 ? $pos - 1 : null;
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