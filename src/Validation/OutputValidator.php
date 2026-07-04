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
    private OxygenSchemaValidator $schemaValidator;

    /**
     * Validation errors collected during validation
     */
    private array $errors = [];

    /**
     * Validation warnings (non-fatal issues)
     */
    private array $warnings = [];

    public function __construct(?OxygenSchemaValidator $schemaValidator = null)
    {
        $this->schemaValidator = $schemaValidator ?? new OxygenSchemaValidator();
    }

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

        if (isset($result['headLinkElements']) && is_array($result['headLinkElements'])) {
            foreach ($result['headLinkElements'] as $index => $headElement) {
                $this->validateElement($headElement, "headLinkElements[$index]");
            }
        }

        if (isset($result['headScriptElements']) && is_array($result['headScriptElements'])) {
            foreach ($result['headScriptElements'] as $index => $headElement) {
                $this->validateElement($headElement, "headScriptElements[$index]");
            }
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
                $this->validateProperties($element['data']['properties'], "$path.data.properties", (string) ($element['data']['type'] ?? ''));
                if (!$this->validateContractProperties($element['data']['type'] ?? '', $element['data']['properties'], $path)) {
                    $valid = false;
                }
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

        $schemaResult = $this->schemaValidator->validateNode($element, '$');
        if (!$schemaResult['valid']) {
            foreach ($schemaResult['errors'] as $error) {
                $this->errors[] = sprintf(
                    '[%s] Oxygen schema %s expected %s, got %s. %s',
                    $path,
                    $error['path'],
                    $error['expected'],
                    $error['actual'],
                    $error['remediation']
                );
            }

            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate element properties structure
     */
    private function validateProperties(array $properties, string $path, string $type = ''): void
    {
        $this->validateUnsafeProperties($properties, $path, $type);

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
                    } else {
                        $this->validateAdvancedAttribute($attr, "$path.settings.advanced.attributes[$index]");
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
        } else {
            foreach ($interaction['actions'] as $index => $action) {
                if (is_array($action)) {
                    $this->validateInteractionAction($action, "$path.actions[$index]");
                }
            }
        }
    }

    /**
     * Validate security-sensitive rendered Oxygen properties.
     */
    private function validateUnsafeProperties(array $properties, string $path, string $type): void
    {
        $content = is_array($properties['content'] ?? null) ? $properties['content'] : [];
        $contentContent = is_array($content['content'] ?? null) ? $content['content'] : [];

        if (isset($contentContent['text']) && is_string($contentContent['text'])) {
            $this->validateRenderedContentString($contentContent['text'], "$path.content.content.text");
        }

        if (isset($contentContent['html_code']) && is_string($contentContent['html_code'])) {
            $this->validateRenderedContentString($contentContent['html_code'], "$path.content.content.html_code");
        }

        if (isset($contentContent['css_code']) && is_string($contentContent['css_code'])) {
            $this->validateCssString($contentContent['css_code'], "$path.content.content.css_code");
        }

        if (isset($contentContent['url']) && is_string($contentContent['url'])) {
            $this->validateUrlString($contentContent['url'], "$path.content.content.url", ['http', 'https', 'mailto', 'tel']);
        }

        if (isset($contentContent['link']['url']) && is_string($contentContent['link']['url'])) {
            $this->validateUrlString(
                $contentContent['link']['url'],
                "$path.content.content.link.url",
                ['http', 'https', 'mailto', 'tel']
            );
        }

        if (isset($contentContent['video_file_url']) && is_string($contentContent['video_file_url'])) {
            $this->validateUrlString(
                $contentContent['video_file_url'],
                "$path.content.content.video_file_url",
                ['http', 'https', 'data']
            );
        }

        $image = is_array($content['image'] ?? null) ? $content['image'] : [];
        if (isset($image['url']) && is_string($image['url'])) {
            $this->validateUrlString($image['url'], "$path.content.image.url", ['http', 'https', 'data']);
        }

        if (isset($image['custom_alt_when_from_url']) && is_string($image['custom_alt_when_from_url'])) {
            $this->validatePlainTextString($image['custom_alt_when_from_url'], "$path.content.image.custom_alt_when_from_url");
        }

        if ($type === ElementTypes::HTML_CODE && isset($contentContent['html_code']) && is_string($contentContent['html_code'])) {
            $this->validateRenderedContentString($contentContent['html_code'], "$path.content.content.html_code");
        }
    }

    private function validateRenderedContentString(string $value, string $path): void
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lower = strtolower($decoded);

        $unsafePatterns = [
            '/<\s*script\b/i',
            '/<\s*iframe\b/i',
            '/<\s*object\b/i',
            '/<\s*embed\b/i',
            '/<\s*svg\b/i',
            '/\s+on[a-z0-9_-]*\s*=/i',
            '/\s+srcdoc\s*=/i',
            '/\s+(?:xlink:href|href|src|action|formaction)\s*=\s*["\']?\s*(?:javascript|vbscript)\s*:/i',
            '/data:image\/svg\+xml/i',
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $decoded) === 1) {
                $this->errors[] = "[$path] Unsafe rendered content detected";
                return;
            }
        }

        if (strpos($lower, 'javascript:') !== false || strpos($lower, 'vbscript:') !== false) {
            $this->errors[] = "[$path] Unsafe rendered content detected";
        }
    }

    private function validateCssString(string $value, string $path): void
    {
        $decoded = strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $decoded = preg_replace('/\\\\[0-9a-f]{1,6}\s?/i', '', $decoded);
        if (!is_string($decoded)) {
            $this->errors[] = "[$path] Unsafe CSS detected";
            return;
        }

        if (strpos($decoded, 'expression(') !== false
            || preg_match('/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript|data:text\/html|data:image\/svg\+xml)\s*:/i', $decoded) === 1
        ) {
            $this->errors[] = "[$path] Unsafe CSS detected";
        }
    }

    private function validatePlainTextString(string $value, string $path): void
    {
        if ($value !== strip_tags($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $this->errors[] = "[$path] Unsafe plain text detected";
        }
    }

    /**
     * @param array<int, string> $allowedSchemes
     */
    private function validateUrlString(string $url, string $path, array $allowedSchemes): void
    {
        $trimmed = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($trimmed === '' || $trimmed === '#') {
            return;
        }

        if (strpos($trimmed, '//') === 0) {
            $this->errors[] = "[$path] Unsafe URL detected";
            return;
        }

        $scheme = $this->extractNormalizedScheme($trimmed);
        if ($scheme === null) {
            if (preg_match('/^(#|\/|\.\.?\/|\?)/', $trimmed) === 1) {
                return;
            }

            if (preg_match('/^[a-zA-Z0-9._~!$&\'()*+,;=@%-]+(?:\/|$)/', $trimmed) === 1) {
                return;
            }

            $this->errors[] = "[$path] Unsafe URL detected";
            return;
        }

        if (!in_array($scheme, $allowedSchemes, true)) {
            $this->errors[] = "[$path] Unsafe URL detected";
            return;
        }

        if ($scheme === 'data' && !$this->isAllowedDataUrl($trimmed)) {
            $this->errors[] = "[$path] Unsafe URL detected";
            return;
        }

        if ($scheme === 'mailto' && preg_match('/(?:[\r\n]|%0a|%0d|[?&]bcc=)/i', $trimmed) === 1) {
            $this->errors[] = "[$path] Unsafe URL detected";
        }
    }

    /**
     * @param array<string, mixed> $attr
     */
    private function validateAdvancedAttribute(array $attr, string $path): void
    {
        $name = strtolower(trim((string) ($attr['name'] ?? '')));
        $value = (string) ($attr['value'] ?? '');

        if ($name === ''
            || preg_match('/[\x00-\x20\x7F]/', $name) === 1
            || strpos($name, 'on') === 0
            || $this->isDirectiveAttribute($name)
            || in_array($name, ['ping', 'formaction', 'formtarget', 'action', 'srcdoc'], true)
        ) {
            $this->errors[] = "[$path] Unsafe advanced attribute detected";
            return;
        }

        if (in_array($name, ['href', 'src', 'poster', 'xlink:href'], true)) {
            $this->validateUrlString($value, "$path.value", ['http', 'https', 'mailto', 'tel']);
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function validateInteractionAction(array $action, string $path): void
    {
        if (($action['name'] ?? '') === 'javascript_function') {
            $this->errors[] = "[$path] Unsafe interaction action detected";
        }
    }

    private function isDirectiveAttribute(string $name): bool
    {
        return strpos($name, 'data-oxy-at-') === 0
            || strpos($name, 'x-') === 0
            || strpos($name, 'v-') === 0
            || strpos($name, 'ng-') === 0
            || strpos($name, 'hx-on') === 0
            || strpos($name, 'bind:') === 0
            || strpos($name, ':') === 0
            || strpos($name, '@') === 0;
    }

    private function extractNormalizedScheme(string $url): ?string
    {
        $probe = rawurldecode($url);
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $probe);
        if (!is_string($probe) || $probe === '') {
            return null;
        }

        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $probe, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function isAllowedDataUrl(string $url): bool
    {
        $dataUrl = preg_replace('/[\x00-\x20\x7F]+/', '', $url);
        if (!is_string($dataUrl)) {
            return false;
        }

        if (!preg_match('/^data:([^;,]+);base64,[a-z0-9+\/=]+$/i', $dataUrl, $matches)) {
            return false;
        }

        return in_array(strtolower($matches[1]), [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'image/avif',
            'video/mp4',
            'video/webm',
        ], true);
    }

    /**
     * Validate required contract property paths for known element types.
     */
    private function validateContractProperties(string $type, array $properties, string $path): bool
    {
        if ($type === '') {
            return true;
        }

        $requiredPaths = ElementContractRegistry::getRequiredPropertyPaths($type);
        if (empty($requiredPaths)) {
            return true;
        }

        $valid = true;
        foreach ($requiredPaths as $requiredPath) {
            if (!$this->hasPath($properties, $requiredPath)) {
                $this->errors[] = sprintf(
                    '[%s] Missing contract path for %s: %s',
                    $path,
                    $type,
                    $requiredPath
                );
                $valid = false;
            }
        }

        return $valid;
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
