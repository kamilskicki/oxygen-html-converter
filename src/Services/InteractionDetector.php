<?php

namespace OxyHtmlConverter\Services;

/**
 * Service to detect and process interactive attributes (event handlers)
 */
class InteractionDetector
{
    private ?FrameworkDetector $frameworkDetector;

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
                if (\str_starts_with($name, 'data-oxy-at-')) {
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

        // Split by semicolon but respect quotes and parentheses (basic implementation)
        // For now, let's just split by ; and trim, which covers simple cases like func1(); func2()
        $parts = array_filter(array_map('trim', explode(';', $handlerCode)));
        $actions = [];

        foreach ($parts as $part) {
            // Match: functionName() or functionName(args)
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/', $part, $matches)) {
                $functionName = $matches[1];
                $args = trim($matches[2]);

                // Handle 'this' by replacing it with a special placeholder if needed
                // Oxygen interactions are usually context-aware, but passing 'this' as an argument
                // might need the actual element reference.
                $hasThis = false;
                if (preg_match('/\bthis\b/', $args)) {
                    $hasThis = true;
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
}
