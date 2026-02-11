<?php

namespace OxyHtmlConverter\Services;

/**
 * Service to detect and process interactive attributes (event handlers)
 */
class InteractionDetector
{
    private ?FrameworkDetector $frameworkDetector;

    /** Pre-detected toggle interactions from JS analysis: elementId => interaction */
    private array $detectedToggles = [];

    /** Whether smooth scroll pattern was detected in JS */
    private bool $smoothScrollDetected = false;

    /** Script blocks that were consumed (converted to native interactions) */
    private array $consumedScriptBlocks = [];

    public function __construct(?FrameworkDetector $frameworkDetector = null)
    {
        $this->frameworkDetector = $frameworkDetector;
    }

    /**
     * Map HTML event handlers to Oxygen trigger types
     */
    private const EVENT_TO_TRIGGER_MAP = [
        'onclick' => 'click',
        'ondblclick' => 'dblclick',
        'onmouseenter' => 'mouse_enter',
        'onmouseleave' => 'mouse_leave',
        'onmouseover' => 'mouseover',
        'onmouseout' => 'mouseout',
        'onfocus' => 'focus',
        'onblur' => 'blur',
        'onchange' => 'change',
        'oninput' => 'input',
        'onsubmit' => 'submit',
        'onkeydown' => 'keydown',
        'onkeyup' => 'keyup',
        'onkeypress' => 'keypress',
        'onscroll' => 'scroll',
        'ontouchstart' => 'touchstart',
        'ontouchend' => 'touchend',
    ];

    /**
     * List of attribute patterns to preserve as regular attributes
     */
    private const PRESERVE_PATTERNS = [
        '/^data-/',      // All data attributes
        '/^aria-/',      // All ARIA attributes
        '/^role$/',      // Role attribute
        '/^tabindex$/',  // Tab index
        '/^title$/',     // Title attribute
        '/^lang$/',      // Language attribute
        '/^dir$/',       // Direction attribute
        '/^draggable$/', // Draggable attribute
        '/^contenteditable$/', // Content editable
    ];

    /**
     * Specific attributes by name to preserve
     */
    private const PRESERVE_NAMES = ['target', 'rel', 'download', 'ping', 'referrerpolicy', 'type', 'name', 'value', 'placeholder', 'autocomplete', 'autofocus', 'disabled', 'readonly', 'required', 'pattern', 'min', 'max', 'step', 'minlength', 'maxlength', 'multiple', 'accept', 'capture', 'form', 'formaction', 'formmethod', 'formtarget', 'formnovalidate', 'formenctype', 'list', 'size', 'cols', 'rows', 'wrap', 'spellcheck', 'inputmode', 'enterkeyhint'];

    /**
     * Skip these attributes (already handled elsewhere or not needed)
     */
    private const SKIP_ATTRIBUTES = ['class', 'id', 'style', 'href', 'src', 'alt', 'width', 'height'];

    /**
     * Process custom attributes AND event handlers
     *
     * @param \DOMElement $node
     * @param array &$element
     */
    public function processCustomAttributes(\DOMElement $node, array &$element): void
    {
        $attributes = [];
        $interactions = [];
        $toRemove = [];

        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            $value = $attr->value;

            // Skip already-handled attributes
            if (in_array($name, self::SKIP_ATTRIBUTES)) {
                continue;
            }

            // Check if this is an event handler → convert to Oxygen Interaction
            if (isset(self::EVENT_TO_TRIGGER_MAP[$name])) {
                $interaction = $this->createInteractionFromHandler(self::EVENT_TO_TRIGGER_MAP[$name], $value, $element);
                if ($interaction) {
                    $interactions[] = $interaction;
                    // Remove the attribute from the node so it doesn't end up in the output HTML
                    // and doesn't trigger warnings in TreeBuilder
                    $toRemove[] = $name;
                }
                continue;
            }

            // Framework specific handling (Alpine.js @click, etc.)
            if ($this->frameworkDetector && $this->frameworkDetector->isFrameworkAttribute($name)) {
                // Handle pre-processed @ symbols
                $originalName = $name;
                if (str_starts_with($name, 'data-oxy-at-')) {
                    $originalName = '@' . substr($name, 12);
                }

                // Try to convert simple Alpine @click or x-on:click
                if ($originalName === '@click' || $originalName === 'x-on:click') {
                    $interaction = $this->createInteractionFromHandler('click', $value, $element);
                    if ($interaction) {
                        $interactions[] = $interaction;
                    }
                }

                // Always preserve framework attributes (using original name)
                $attributes[] = [
                    'name' => $originalName,
                    'value' => $value,
                ];
                continue;
            }

            // Check if attribute should be preserved as a regular attribute
            $shouldPreserve = false;

            // Check against patterns
            foreach (self::PRESERVE_PATTERNS as $pattern) {
                if (preg_match($pattern, $name)) {
                    $shouldPreserve = true;
                    break;
                }
            }

            // Check against specific names
            if (!$shouldPreserve && in_array($name, self::PRESERVE_NAMES)) {
                $shouldPreserve = true;
            }

            if ($shouldPreserve) {
                $attributes[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }

        // Remove attributes after iteration to avoid modifying the collection while iterating
        foreach ($toRemove as $name) {
            $node->removeAttribute($name);
        }

        // Store regular attributes
        if (!empty($attributes)) {
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
            $element['data']['properties']['settings']['advanced']['attributes'] = $attributes;
        }

        // Store interactions (Oxygen's native event system)
        if (!empty($interactions)) {
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['interactions'] = $element['data']['properties']['settings']['interactions'] ?? [];
            $element['data']['properties']['settings']['interactions']['interactions'] = $interactions;
        }
    }

    /**
     * Create an Oxygen Interaction from an HTML event handler
     *
     * @param string $trigger The Oxygen trigger type
     * @param string $handlerCode The JavaScript code from the handler
     * @param array &$element Reference to element to add data attributes
     * @return array|null The interaction array or null
     */
    public function createInteractionFromHandler(string $trigger, string $handlerCode, array &$element): ?array
    {
        $handlerCode = trim($handlerCode);
        if (empty($handlerCode)) {
            return null;
        }

        // Check if handler contains string literals in function args or return statements.
        // These can't be cleanly converted to Oxygen interactions — preserve as attribute instead.
        if ($this->isComplexHandler($handlerCode)) {
            $this->preserveHandlerAsAttribute($trigger, $handlerCode, $element);
            return null;
        }

        // Strip "return false" / "return true" / "return;" parts
        $handlerCode = preg_replace('/\breturn\s+(false|true|!0|!1)\s*;?/', '', $handlerCode);
        $handlerCode = preg_replace('/\breturn\s*;/', '', $handlerCode);
        $handlerCode = trim($handlerCode, "; \t\n\r");

        if (empty($handlerCode)) {
            return null;
        }

        // Split by semicolon for simple cases like func1(); func2()
        $parts = array_filter(array_map('trim', explode(';', $handlerCode)));
        $actions = [];

        foreach ($parts as $part) {
            // Match: functionName() or functionName(args)
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/', $part, $matches)) {
                $functionName = $matches[1];
                $args = trim($matches[2]);

                // If args contain string literals, this is too complex for interaction conversion
                if (preg_match('/[\'"]/', $args)) {
                    $this->preserveHandlerAsAttribute($trigger, trim($handlerCode, "; \t\n\r"), $element);
                    return null;
                }

                // If there are arguments, store them as a data attribute
                if (strlen($args) > 0) {
                    $attrName = 'data-arg-' . strtolower($functionName);

                    $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
                    $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
                    $element['data']['properties']['settings']['advanced']['attributes'] = $element['data']['properties']['settings']['advanced']['attributes'] ?? [];

                    $element['data']['properties']['settings']['advanced']['attributes'][] = [
                        'name' => $attrName,
                        'value' => $args,
                    ];
                }

                $actions[] = [
                    'name' => 'javascript_function',
                    'target' => 'this_element',
                    'js_function_name' => $functionName,
                ];
            }
        }

        if (empty($actions)) {
            return null;
        }

        return [
            'trigger' => $trigger,
            'target' => 'this_element',
            'actions' => $actions,
        ];
    }

    /**
     * Check if handler code is too complex for interaction conversion
     */
    private function isComplexHandler(string $code): bool
    {
        // Contains string literal arguments in function calls: alert('hello'), func("test")
        if (preg_match('/[a-zA-Z_]\s*\([^)]*[\'"][^)]*\)/', $code)) {
            return true;
        }

        return false;
    }

    /**
     * Preserve an event handler as a custom attribute instead of converting to interaction
     */
    private function preserveHandlerAsAttribute(string $trigger, string $handlerCode, array &$element): void
    {
        // Map trigger back to HTML attribute name
        $triggerToAttr = array_flip(self::EVENT_TO_TRIGGER_MAP);
        $attrName = $triggerToAttr[$trigger] ?? ('on' . $trigger);

        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['attributes'] = $element['data']['properties']['settings']['advanced']['attributes'] ?? [];

        $element['data']['properties']['settings']['advanced']['attributes'][] = [
            'name' => $attrName,
            'value' => $handlerCode,
        ];
    }

    // ─── JavaScript pattern detection ────────────────────────────────

    /**
     * Scan JavaScript code for classList.add/remove/toggle patterns and build
     * Oxygen interactions keyed by element ID.
     *
     * @param string $jsCode  Raw JavaScript code
     * @return array  ['elementId' => ['interaction' => [...]], ...]
     */
    public function detectTogglePatterns(string $jsCode): array
    {
        $results = [];

        // Pattern: getElementById('id').addEventListener('click', () => { ...classList.add/remove/toggle('className')... })
        // Also handles: const varName = document.getElementById('id'); ... varName.addEventListener(...)
        $varMap = [];

        // Step 1: Map variable assignments to element IDs
        // const navToggle = document.getElementById('navToggle');
        if (preg_match_all(
            '/(?:const|let|var)\s+(\w+)\s*=\s*document\.getElementById\s*\(\s*[\'"](\w+)[\'"]\s*\)/',
            $jsCode,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $varMap[$m[1]] = $m[2]; // varName => elementId
            }
        }

        // Step 2: Find addEventListener blocks that toggle/add/remove classes
        // Match: varName.addEventListener('click', ... { ... classList.add/remove/toggle('className') ... })
        // Handles: function() {, () => {, (e) => {, e => {
        $pattern = '/(\w+)\.addEventListener\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(?:function\s*\([^)]*\)|\([^)]*\)\s*=>|\w+\s*=>)\s*\{/';
        if (preg_match_all($pattern, $jsCode, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $varName = $m[1][0];
                $event = $m[2][0];
                $blockStart = $m[0][1] + strlen($m[0][0]) - 1; // position of opening {

                // Resolve variable to element ID
                $elementId = $varMap[$varName] ?? null;
                if (!$elementId) {
                    continue;
                }

                // Find the closing brace of this block
                $blockEnd = $this->findMatchingBrace($jsCode, $blockStart);
                if ($blockEnd === false) {
                    continue;
                }

                $blockBody = substr($jsCode, $blockStart + 1, $blockEnd - $blockStart - 1);

                // Look for classList operations in the block body
                $actions = $this->extractClassListActions($blockBody, $varMap);
                if (empty($actions)) {
                    continue;
                }

                $results[$elementId] = [
                    'interaction' => [
                        'trigger' => $event,
                        'target' => 'this_element',
                        'actions' => $actions,
                    ],
                ];
            }
        }

        // Step 3: Handle querySelectorAll forEach patterns (e.g., mobileLinks)
        // document.querySelectorAll('.mobile-link').forEach(link => { link.addEventListener('click', () => { ... }) })
        $forEachPattern = '/document\.querySelectorAll\s*\(\s*[\'"]([\w.#-]+)[\'"]\s*\)\.forEach\s*\(\s*\w+\s*=>\s*\{\s*\w+\.addEventListener\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(?:function\s*\([^)]*\)|\([^)]*\)\s*=>|\w+\s*=>)\s*\{/';
        if (preg_match_all($forEachPattern, $jsCode, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $selector = $m[1][0];
                $event = $m[2][0];
                $outerStart = $m[0][1];

                // Find the innermost { of the addEventListener callback
                $innerBracePos = $outerStart + strlen($m[0][0]) - 1;
                $innerEnd = $this->findMatchingBrace($jsCode, $innerBracePos);
                if ($innerEnd === false) {
                    continue;
                }

                $blockBody = substr($jsCode, $innerBracePos + 1, $innerEnd - $innerBracePos - 1);
                $actions = $this->extractClassListActions($blockBody, $varMap);

                if (!empty($actions)) {
                    // Store under selector (e.g., '.mobile-link')
                    $results['__selector__' . $selector] = [
                        'selector' => $selector,
                        'interaction' => [
                            'trigger' => $event,
                            'target' => 'this_element',
                            'actions' => $actions,
                        ],
                    ];
                }
            }
        }

        $this->detectedToggles = $results;
        return $results;
    }

    /**
     * Detect if smooth scroll pattern exists in JavaScript.
     *
     * @param string $jsCode  Raw JavaScript
     * @return bool  True if smooth scroll anchor link pattern detected
     */
    public function detectSmoothScrollPattern(string $jsCode): bool
    {
        // Pattern: a[href^="#"] + scrollIntoView or window.scrollTo
        $hasAnchorSelector = (
            strpos($jsCode, 'a[href^="#"]') !== false ||
            strpos($jsCode, "a[href^='#']") !== false
        );
        $hasSmoothScroll = (
            strpos($jsCode, 'scrollIntoView') !== false ||
            strpos($jsCode, 'window.scrollTo') !== false
        );

        $this->smoothScrollDetected = $hasAnchorSelector && $hasSmoothScroll;
        return $this->smoothScrollDetected;
    }

    /**
     * Apply a pre-detected interaction to an element during tree building.
     *
     * @param string $elementId  HTML ID of the element
     * @param array  $interaction  Interaction array
     * @param array  &$element  Reference to the Oxygen element being built
     */
    public function applyDetectedInteraction(string $elementId, array $interaction, array &$element): void
    {
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['interactions'] = $element['data']['properties']['settings']['interactions'] ?? [];
        $element['data']['properties']['settings']['interactions']['interactions'] = $element['data']['properties']['settings']['interactions']['interactions'] ?? [];

        $element['data']['properties']['settings']['interactions']['interactions'][] = $interaction;
    }

    /**
     * Get pre-detected toggle patterns.
     */
    public function getDetectedToggles(): array
    {
        return $this->detectedToggles;
    }

    /**
     * Whether smooth scroll was detected.
     */
    public function isSmoothScrollDetected(): bool
    {
        return $this->smoothScrollDetected;
    }

    /**
     * Get list of JS pattern descriptions that were consumed.
     */
    public function getConsumedScriptBlocks(): array
    {
        return $this->consumedScriptBlocks;
    }

    /**
     * Mark a script block type as consumed.
     */
    public function addConsumedScriptBlock(string $blockType): void
    {
        $this->consumedScriptBlocks[] = $blockType;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Extract classList.add/remove/toggle actions from a JS block body.
     */
    private function extractClassListActions(string $body, array $varMap): array
    {
        $actions = [];

        // Match: varName.classList.add('className') / remove / toggle
        $pattern = '/(\w+)\.classList\.(add|remove|toggle)\s*\(\s*[\'"](\w[\w-]*)[\'"]\s*\)/';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $targetVar = $m[1];
                $method = $m[2];
                $className = $m[3];

                // Resolve target variable to element ID
                $targetId = $varMap[$targetVar] ?? null;
                if (!$targetId) {
                    continue;
                }

                $actionName = $method === 'add' ? 'add_class'
                    : ($method === 'remove' ? 'remove_class' : 'toggle_class');

                $actions[] = [
                    'name' => $actionName,
                    'target' => '#' . $targetId,
                    'class_name' => $className,
                ];
            }
        }

        return $actions;
    }

    /**
     * Find matching closing brace in JavaScript code.
     */
    private function findMatchingBrace(string $code, int $openBracePos): ?int
    {
        $length = strlen($code);
        $depth = 1;
        $pos = $openBracePos + 1;
        $inString = false;
        $stringChar = '';

        while ($pos < $length && $depth > 0) {
            $char = $code[$pos];
            $prevChar = $pos > 0 ? $code[$pos - 1] : '';

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

            if ($depth === 0) {
                return $pos;
            }
            $pos++;
        }

        return null;
    }
}
