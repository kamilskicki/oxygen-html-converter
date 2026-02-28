<?php

namespace OxyHtmlConverter\Validation;

use OxyHtmlConverter\Contracts\ElementContractRegistry;
use OxyHtmlConverter\ElementTypes;

/**
 * Validates converted Oxygen element structure
 *
 * Ensures output conforms to Oxygen Builder's expected schema:
 * - Required fields: id, data.type, data.properties, children
 * - Valid element types from ElementTypes constants
 * - Correct data types for all fields
 */
class OutputValidator
{
    /**
     * Validation errors collected during validation
     */
    private array $errors = [];

    /**
     * Validation warnings (non-fatal issues)
     */
    private array $warnings = [];

    /**
     * Validate a complete conversion result
     *
     * @param array $result The full conversion result from TreeBuilder
     * @return bool True if valid, false if validation errors exist
     */
    public function validateConversionResult(array $result): bool
    {
        $this->errors = [];
        $this->warnings = [];

        if (!isset($result['success'])) {
            $this->errors[] = "Missing 'success' field in conversion result";
            return false;
        }

        if (!$result['success']) {
            // Failed conversion - nothing more to validate
            return true;
        }

        if (!isset($result['element'])) {
            $this->errors[] = "Missing 'element' field in successful conversion result";
            return false;
        }

        $this->validateElement($result['element'], 'root');

        // Validate CSS element if present
        if (isset($result['cssElement']) && $result['cssElement'] !== null) {
            $this->validateElement($result['cssElement'], 'cssElement');
        }

        // Validate icon script elements if present
        if (isset($result['iconScriptElements']) && is_array($result['iconScriptElements'])) {
            foreach ($result['iconScriptElements'] as $index => $iconElement) {
                $this->validateElement($iconElement, "iconScriptElements[$index]");
            }
        }

        // Validate stats structure
        if (isset($result['stats'])) {
            $this->validateStats($result['stats']);
        }

        return empty($this->errors);
    }

    /**
     * Validate a single Oxygen element
     *
     * @param array $element The element to validate
     * @param string $path Current path for error messages
     * @return bool True if valid
     */
    public function validateElement(array $element, string $path = 'element'): bool
    {
        $valid = true;

        // Check required fields
        if (!isset($element['id'])) {
            $this->errors[] = "[$path] Missing required 'id' field";
            $valid = false;
        } elseif (!is_int($element['id'])) {
            $this->errors[] = "[$path] Field 'id' must be an integer, got " . gettype($element['id']);
            $valid = false;
        }

        if (!isset($element['data'])) {
            $this->errors[] = "[$path] Missing required 'data' field";
            $valid = false;
        } else {
            // Validate data structure
            if (!isset($element['data']['type'])) {
                $this->errors[] = "[$path] Missing required 'data.type' field";
                $valid = false;
            } elseif (!is_string($element['data']['type'])) {
                $this->errors[] = "[$path] Field 'data.type' must be a string";
                $valid = false;
            } elseif (!ElementTypes::isValid($element['data']['type'])) {
                $this->warnings[] = "[$path] Unknown element type: {$element['data']['type']}";
            }

            if (!isset($element['data']['properties'])) {
                $this->errors[] = "[$path] Missing required 'data.properties' field";
                $valid = false;
            } elseif (!is_array($element['data']['properties'])) {
                $this->errors[] = "[$path] Field 'data.properties' must be an array";
                $valid = false;
            } else {
                // Validate properties structure
                $this->validateProperties($element['data']['properties'], "$path.data.properties");
                $this->validateContractProperties($element['data']['type'] ?? '', $element['data']['properties'], $path);
            }
        }

        if (!isset($element['children'])) {
            $this->errors[] = "[$path] Missing required 'children' field";
            $valid = false;
        } elseif (!is_array($element['children'])) {
            $this->errors[] = "[$path] Field 'children' must be an array";
            $valid = false;
        } else {
            // Recursively validate children
            foreach ($element['children'] as $index => $child) {
                $this->validateElement($child, "$path.children[$index]");
            }
        }

        return $valid;
    }

    /**
     * Validate element properties structure
     */
    private function validateProperties(array $properties, string $path): void
    {
        // Validate settings.advanced.classes if present
        if (isset($properties['settings']['advanced']['classes'])) {
            $classes = $properties['settings']['advanced']['classes'];
            if (!is_array($classes)) {
                $this->errors[] = "[$path] Field 'settings.advanced.classes' must be an array, got " . gettype($classes);
            } else {
                foreach ($classes as $index => $class) {
                    if (!is_string($class)) {
                        $this->errors[] = "[$path] Each class in 'settings.advanced.classes' must be a string, got " . gettype($class) . " at index $index";
                    }
                }
            }
        }

        // Validate settings.advanced.id if present
        if (isset($properties['settings']['advanced']['id'])) {
            if (!is_string($properties['settings']['advanced']['id'])) {
                $this->errors[] = "[$path] Field 'settings.advanced.id' must be a string";
            }
        }

        // Validate settings.advanced.attributes if present
        if (isset($properties['settings']['advanced']['attributes'])) {
            $attrs = $properties['settings']['advanced']['attributes'];
            if (!is_array($attrs)) {
                $this->errors[] = "[$path] Field 'settings.advanced.attributes' must be an array";
            } else {
                foreach ($attrs as $index => $attr) {
                    if (!is_array($attr)) {
                        $this->errors[] = "[$path] Each attribute must be an array with 'name' and 'value' keys";
                    } elseif (!isset($attr['name']) || !isset($attr['value'])) {
                        $this->errors[] = "[$path] Attribute at index $index missing 'name' or 'value'";
                    }
                }
            }
        }

        // Validate settings.interactions if present
        if (isset($properties['settings']['interactions']['interactions'])) {
            $interactions = $properties['settings']['interactions']['interactions'];
            if (!is_array($interactions)) {
                $this->errors[] = "[$path] Field 'settings.interactions.interactions' must be an array";
            } else {
                foreach ($interactions as $index => $interaction) {
                    $this->validateInteraction($interaction, "$path.settings.interactions.interactions[$index]");
                }
            }
        }
    }

    /**
     * Validate an interaction structure
     */
    private function validateInteraction(array $interaction, string $path): void
    {
        if (!isset($interaction['trigger'])) {
            $this->errors[] = "[$path] Interaction missing required 'trigger' field";
        } elseif (!is_string($interaction['trigger'])) {
            $this->errors[] = "[$path] Interaction 'trigger' must be a string";
        }

        if (!isset($interaction['actions'])) {
            $this->errors[] = "[$path] Interaction missing required 'actions' field";
        } elseif (!is_array($interaction['actions'])) {
            $this->errors[] = "[$path] Interaction 'actions' must be an array";
        }
    }

    /**
     * Validate required contract property paths for known element types.
     */
    private function validateContractProperties(string $type, array $properties, string $path): void
    {
        if ($type === '') {
            return;
        }

        $requiredPaths = ElementContractRegistry::getRequiredPropertyPaths($type);
        if (empty($requiredPaths)) {
            return;
        }

        foreach ($requiredPaths as $requiredPath) {
            if (!$this->hasPath($properties, $requiredPath)) {
                $this->warnings[] = sprintf(
                    '[%s] Missing contract path for %s: %s',
                    $path,
                    $type,
                    $requiredPath
                );
            }
        }
    }

    private function hasPath(array $properties, string $path): bool
    {
        $segments = explode('.', $path);
        $current = $properties;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Validate stats structure
     */
    private function validateStats(array $stats): void
    {
        $requiredFields = ['elements', 'tailwindClasses', 'customClasses', 'warnings', 'info'];
        foreach ($requiredFields as $field) {
            if (!isset($stats[$field])) {
                $this->warnings[] = "Stats missing expected field: $field";
            }
        }

        if (isset($stats['elements']) && !is_int($stats['elements'])) {
            $this->errors[] = "Stats 'elements' must be an integer";
        }

        if (isset($stats['warnings']) && !is_array($stats['warnings'])) {
            $this->errors[] = "Stats 'warnings' must be an array";
        }
    }

    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if validation passed (no errors)
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Reset validator state
     */
    public function reset(): void
    {
        $this->errors = [];
        $this->warnings = [];
    }

    /**
     * Static convenience method to validate and return result
     *
     * @param array $element Element to validate
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function validate(array $element): array
    {
        $validator = new self();
        $valid = $validator->validateElement($element);
        return [
            'valid' => $valid,
            'errors' => $validator->getErrors(),
            'warnings' => $validator->getWarnings(),
        ];
    }
}
