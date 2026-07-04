<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Validation;

final class OxygenSchemaValidator
{
    private const TEMPLATE_OPERANDS_REQUIRING_VALUE = [
        'is' => true,
        'is not' => true,
        'is one of' => true,
        'is all of' => true,
        'is none of' => true,
        'is before' => true,
        'is after' => true,
        'is greater than' => true,
        'is less than' => true,
        'contains' => true,
        'does not contain' => true,
    ];

    private const TEMPLATE_OPERANDS_WITHOUT_VALUE = [
        'is empty' => true,
        'is not empty' => true,
    ];

    /**
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateTree(array $tree): array
    {
        $errors = [];
        $allowedTopLevel = [
            'root' => true,
            '_nextNodeId' => true,
            'exportedLookupTable' => true,
        ];

        foreach ($tree as $key => $value) {
            if (!isset($allowedTopLevel[(string) $key])) {
                $errors[] = $this->error(
                    '$.' . (string) $key,
                    'no additional Oxygen tree fields',
                    $value,
                    'Remove converter-only metadata before writing tree_json_string.'
                );
            }
        }

        if (!isset($tree['root']) || !is_array($tree['root'])) {
            $errors[] = $this->error('$.root', 'TreeNode object', $tree['root'] ?? null, 'Write a root TreeNode object.');
        } else {
            $errors = array_merge($errors, $this->validateNode($tree['root'], '$.root')['errors']);
        }

        if (isset($tree['_nextNodeId'])) {
            if (!is_int($tree['_nextNodeId']) || $tree['_nextNodeId'] < 1) {
                $errors[] = $this->error('$._nextNodeId', 'integer >= 1', $tree['_nextNodeId'], 'Recalculate the next available Oxygen node ID.');
            }
        } else {
            $errors[] = $this->error('$._nextNodeId', 'integer >= 1', null, 'Include _nextNodeId before persisting the tree.');
        }

        if (isset($tree['exportedLookupTable']) && !is_array($tree['exportedLookupTable'])) {
            $errors[] = $this->error('$.exportedLookupTable', 'object', $tree['exportedLookupTable'], 'Use an object or an empty object for exportedLookupTable.');
        }

        return $this->result($errors);
    }

    /**
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validatePageDocumentEnvelope(array $envelope): array
    {
        $errors = [];

        foreach ($envelope as $key => $value) {
            if ((string) $key !== 'tree_json_string') {
                $errors[] = $this->error(
                    '$.' . (string) $key,
                    'no additional _oxygen_data fields',
                    $value,
                    'Persist only tree_json_string in the _oxygen_data API-level envelope.'
                );
            }
        }

        if (!isset($envelope['tree_json_string']) || !is_string($envelope['tree_json_string']) || $envelope['tree_json_string'] === '') {
            $errors[] = $this->error('$.tree_json_string', 'non-empty JSON string', $envelope['tree_json_string'] ?? null, 'JSON-encode the Oxygen tree and store it as tree_json_string.');
            return $this->result($errors);
        }

        try {
            $tree = json_decode($envelope['tree_json_string'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = $this->error('$.tree_json_string', 'valid OxygenTree JSON string', 'invalid JSON: ' . $e->getMessage(), 'Encode a valid OxygenTree object.');
            return $this->result($errors);
        }

        if (!is_array($tree)) {
            $errors[] = $this->error('$.tree_json_string', 'JSON object', $tree, 'Encode an OxygenTree object.');
            return $this->result($errors);
        }

        $errors = array_merge($errors, $this->validateTree($tree)['errors']);

        return $this->result($errors);
    }

    /**
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateNode(array $node, string $path = '$'): array
    {
        $errors = [];
        $allowedNodeFields = [
            'id' => true,
            'data' => true,
            'children' => true,
        ];

        foreach ($node as $key => $value) {
            if (!isset($allowedNodeFields[(string) $key])) {
                $errors[] = $this->error(
                    $path . '.' . (string) $key,
                    'no additional TreeNode fields',
                    $value,
                    'Remove converter-only node fields before persisting Oxygen output.'
                );
            }
        }

        if (!isset($node['id']) || !is_int($node['id']) || $node['id'] < 0) {
            $errors[] = $this->error($path . '.id', 'integer >= 0', $node['id'] ?? null, 'Assign a non-negative integer Oxygen node ID.');
        }

        if (!isset($node['data']) || !is_array($node['data'])) {
            $errors[] = $this->error($path . '.data', 'object', $node['data'] ?? null, 'Write node data with type and properties.');
        } else {
            $errors = array_merge($errors, $this->validateNodeData($node['data'], $path . '.data')['errors']);
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            $errors[] = $this->error($path . '.children', 'array', $node['children'] ?? null, 'Use an empty array when the node has no children.');
        } else {
            foreach ($node['children'] as $index => $child) {
                if (!is_array($child)) {
                    $errors[] = $this->error($path . '.children[' . (int) $index . ']', 'TreeNode object', $child, 'Remove non-node children from the tree.');
                    continue;
                }

                $errors = array_merge($errors, $this->validateNode($child, $path . '.children[' . (int) $index . ']')['errors']);
            }
        }

        return $this->result($errors);
    }

    /**
     * @param array<int, mixed> $selectors
     * @param array<int, mixed> $collections
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateSelectors(array $selectors, array $collections): array
    {
        $errors = [];

        foreach ($collections as $index => $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                $errors[] = $this->error('$collections[' . (int) $index . ']', 'non-empty string', $collection, 'Store selector collection names as strings.');
            }
        }

        foreach ($selectors as $index => $selector) {
            $path = '$[' . (int) $index . ']';
            if (!is_array($selector)) {
                $errors[] = $this->error($path, 'Oxygen selector object', $selector, 'Remove invalid selector records.');
                continue;
            }

            $allowed = [
                'id' => true,
                'name' => true,
                'type' => true,
                'collection' => true,
                'properties' => true,
                'children' => true,
                'locked' => true,
                'pseudo' => true,
            ];

            foreach ($selector as $key => $value) {
                if (!isset($allowed[(string) $key])) {
                    $expected = (string) $key === 'selector'
                        ? 'no importer-only selector field'
                        : 'no additional Oxygen selector fields';
                    $errors[] = $this->error($path . '.' . (string) $key, $expected, $value, 'Normalize importer metadata before writing Oxygen selector options.');
                }
            }

            foreach (['id', 'name', 'collection'] as $field) {
                if (!is_string($selector[$field] ?? null) || trim((string) $selector[$field]) === '') {
                    $errors[] = $this->error($path . '.' . $field, 'non-empty string', $selector[$field] ?? null, 'Write the required Oxygen selector field.');
                }
            }

            if (($selector['type'] ?? null) !== 'class' && ($selector['type'] ?? null) !== 'custom') {
                $errors[] = $this->error($path . '.type', 'class or custom', $selector['type'] ?? null, 'Use an Oxygen selector type supported by the builder.');
            }

            if (($selector['type'] ?? null) === 'class' && is_string($selector['name'] ?? null) && str_starts_with((string) $selector['name'], '.')) {
                $errors[] = $this->error($path . '.name', 'class name without leading dot', $selector['name'], 'Remove the leading dot from Oxygen selector names.');
            }

            $properties = $selector['properties'] ?? null;
            if (!is_array($properties) && !$properties instanceof \stdClass) {
                $errors[] = $this->error($path . '.properties', 'object', $properties, 'Store selector properties as an object.');
            }

            if (!isset($selector['children']) || !is_array($selector['children'])) {
                $errors[] = $this->error($path . '.children', 'array', $selector['children'] ?? null, 'Use an empty array when the selector has no nested selectors.');
            }
        }

        return $this->result($errors);
    }

    /**
     * @param array<int, mixed> $variables
     * @param array<int, mixed> $collections
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateVariables(array $variables, array $collections): array
    {
        $errors = [];

        foreach ($collections as $index => $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                $errors[] = $this->error('$collections[' . (int) $index . ']', 'non-empty string', $collection, 'Store variable collection names as strings.');
            }
        }

        foreach ($variables as $index => $variable) {
            $path = '$[' . (int) $index . ']';
            if (!is_array($variable)) {
                $errors[] = $this->error($path, 'Oxygen variable object', $variable, 'Remove invalid variable records.');
                continue;
            }

            foreach (['id', 'cssVariableName', 'label', 'type', 'collection'] as $field) {
                if (!is_string($variable[$field] ?? null) || trim((string) $variable[$field]) === '') {
                    $errors[] = $this->error($path . '.' . $field, 'non-empty string', $variable[$field] ?? null, 'Write the required Oxygen variable field.');
                }
            }

            if (!array_key_exists('value', $variable)) {
                $errors[] = $this->error($path . '.value', 'field present', null, 'Include the static or dynamic variable value.');
            }

            if (!array_key_exists('dynamicData', $variable)) {
                $errors[] = $this->error($path . '.dynamicData', 'field present', null, 'Use null for static variables.');
            }

            $name = is_string($variable['cssVariableName'] ?? null) ? (string) $variable['cssVariableName'] : '';
            if ($name !== '' && (str_starts_with($name, '--') || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1)) {
                $errors[] = $this->error($path . '.cssVariableName', 'CSS variable name without leading --', $name, 'Store the Oxygen cssVariableName without the CSS custom property prefix.');
            }
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateGlobalSettings(array $settings): array
    {
        $errors = [];

        if (!isset($settings['settings']) || !is_array($settings['settings'])) {
            $errors[] = $this->error('$.settings', 'object', $settings['settings'] ?? null, 'Write global settings under a top-level settings object.');
            return $this->result($errors);
        }

        foreach (['colors', 'typography', 'containers', 'code'] as $section) {
            if (isset($settings['settings'][$section]) && !is_array($settings['settings'][$section])) {
                $errors[] = $this->error('$.settings.' . $section, 'object', $settings['settings'][$section], 'Store this global settings section as an object.');
            }
        }

        if (isset($settings['settings']['colors']) && is_array($settings['settings']['colors'])) {
            $errors = array_merge($errors, $this->validateGlobalColors($settings['settings']['colors'], '$.settings.colors')['errors']);
        }

        if (isset($settings['settings']['typography']) && is_array($settings['settings']['typography'])) {
            $typography = $settings['settings']['typography'];
            if (isset($typography['base_size'])) {
                $errors = array_merge($errors, $this->validateMeasurementValue($typography['base_size'], '$.settings.typography.base_size')['errors']);
            }

            $presets = $typography['global_typography']['typography_presets'] ?? null;
            if (isset($typography['global_typography']) && !is_array($typography['global_typography'])) {
                $errors[] = $this->error('$.settings.typography.global_typography', 'object', $typography['global_typography'], 'Store global typography settings as an object.');
            } elseif ($presets !== null) {
                if (!is_array($presets)) {
                    $errors[] = $this->error('$.settings.typography.global_typography.typography_presets', 'array', $presets, 'Store typography presets as an array.');
                } else {
                    foreach ($presets as $index => $preset) {
                        $presetPath = '$.settings.typography.global_typography.typography_presets[' . (int) $index . ']';
                        if (!is_array($preset)) {
                            $errors[] = $this->error($presetPath, 'object', $preset, 'Store each typography preset as an object.');
                            continue;
                        }

                        if (!is_string($preset['preset'] ?? null) || trim((string) $preset['preset']) === '') {
                            $errors[] = $this->error($presetPath . '.preset', 'non-empty string', $preset['preset'] ?? null, 'Store the preset slug as a string.');
                        }

                        if (!isset($preset['custom']) || !is_array($preset['custom'])) {
                            $errors[] = $this->error($presetPath . '.custom', 'object', $preset['custom'] ?? null, 'Store custom preset settings as an object.');
                        }
                    }
                }
            }
        }

        if (isset($settings['settings']['containers']) && is_array($settings['settings']['containers'])) {
            $containers = $settings['settings']['containers'];
            if (isset($containers['sections'])) {
                if (!is_array($containers['sections'])) {
                    $errors[] = $this->error('$.settings.containers.sections', 'object', $containers['sections'], 'Store section container settings as an object.');
                } else {
                    foreach (['container_width', 'vertical_padding', 'horizontal_padding'] as $field) {
                        if (isset($containers['sections'][$field])) {
                            $errors = array_merge($errors, $this->validateMeasurementValue($containers['sections'][$field], '$.settings.containers.sections.' . $field)['errors']);
                        }
                    }
                }
            }

            if (isset($containers['column_gap'])) {
                $errors = array_merge($errors, $this->validateMeasurementValue($containers['column_gap'], '$.settings.containers.column_gap')['errors']);
            }
        }

        if (isset($settings['settings']['code']) && is_array($settings['settings']['code'])) {
            $errors = array_merge($errors, $this->validateCodeSection($settings['settings']['code'], '$.settings.code')['errors']);
        }

        return $this->result($errors);
    }

    /**
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateTemplateSettingsJson(string $settingsJson): array
    {
        try {
            $settings = json_decode($settingsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->result([
                $this->error('$', 'valid JSON string', 'invalid JSON: ' . $e->getMessage(), 'Encode _oxygen_template_settings as a JSON string.'),
            ]);
        }

        if ($settings === null) {
            return $this->result([]);
        }

        if (!is_array($settings)) {
            return $this->result([
                $this->error('$', 'object or null', $settings, 'Encode template settings as a JSON object or null.'),
            ]);
        }

        $errors = [];
        if (array_key_exists('type', $settings) && (!is_string($settings['type']) || trim($settings['type']) === '')) {
            $errors[] = $this->error('$.type', 'non-empty string', $settings['type'], 'Use a registered Oxygen template type slug.');
        }

        if (array_key_exists('ruleGroups', $settings)) {
            if (!is_array($settings['ruleGroups'])) {
                $errors[] = $this->error('$.ruleGroups', 'array', $settings['ruleGroups'], 'Store template rules as OR groups of rule arrays.');
            } else {
                $errors = array_merge($errors, $this->validateTemplateRuleGroups($settings['ruleGroups'])['errors']);
            }
        }

        if (array_key_exists('triggers', $settings)) {
            if (!is_array($settings['triggers'])) {
                $errors[] = $this->error('$.triggers', 'array', $settings['triggers'], 'Store template triggers as an array.');
            } else {
                foreach ($settings['triggers'] as $index => $trigger) {
                    if (!is_array($trigger)) {
                        $errors[] = $this->error('$.triggers[' . (int) $index . ']', 'object', $trigger, 'Store each template trigger as an object.');
                    }
                }
            }
        }

        if (array_key_exists('priority', $settings) && !is_int($settings['priority'])) {
            $errors[] = $this->error('$.priority', 'integer', $settings['priority'], 'Store template priority as an integer.');
        }

        if (array_key_exists('fallback', $settings) && !is_bool($settings['fallback'])) {
            $errors[] = $this->error('$.fallback', 'boolean', $settings['fallback'], 'Store fallback as a boolean.');
        }

        if (array_key_exists('disabled', $settings) && !is_bool($settings['disabled'])) {
            $errors[] = $this->error('$.disabled', 'boolean', $settings['disabled'], 'Store disabled as a boolean.');
        }

        if (array_key_exists('parentId', $settings) && (!is_int($settings['parentId']) || $settings['parentId'] < 1)) {
            $errors[] = $this->error('$.parentId', 'integer >= 1', $settings['parentId'], 'Store parentId as a positive post ID.');
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $blockSettings
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateBlockSettings(array $blockSettings): array
    {
        $errors = [];

        if (isset($blockSettings['preview'])) {
            if (!is_array($blockSettings['preview'])) {
                $errors[] = $this->error('$.preview', 'object', $blockSettings['preview'], 'Store block preview settings as an object.');
            } else {
                foreach (['acfFlexibleField', 'acfFlexibleFieldRow'] as $field) {
                    if (isset($blockSettings['preview'][$field]) && !is_string($blockSettings['preview'][$field])) {
                        $errors[] = $this->error('$.preview.' . $field, 'string', $blockSettings['preview'][$field], 'Store block preview ACF references as strings.');
                    }
                }
            }
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $componentNode
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    public function validateComponentInstance(array $componentNode): array
    {
        $errors = $this->validateNode($componentNode, '$')['errors'];

        if (($componentNode['data']['type'] ?? null) !== 'OxygenElements\\Component') {
            $errors[] = $this->error('$.data.type', 'OxygenElements\\Component', $componentNode['data']['type'] ?? null, 'Use the native Oxygen Component element for reusable block references.');
        }

        $block = $componentNode['data']['properties']['content']['content']['block'] ?? null;
        if (!is_array($block)) {
            $errors[] = $this->error('$.data.properties.content.content.block', 'object', $block, 'Write component block reference data under content.content.block.');
            return $this->result($errors);
        }

        if (!isset($block['componentId']) || !is_int($block['componentId']) || $block['componentId'] < 1) {
            $errors[] = $this->error('$.data.properties.content.content.block.componentId', 'integer', $block['componentId'] ?? null, 'Set componentId to a published oxygen_block post ID.');
        }

        if (!isset($block['targets']) || !is_array($block['targets'])) {
            $errors[] = $this->error('$.data.properties.content.content.block.targets', 'array', $block['targets'] ?? null, 'List editable target mappings for the component instance.');
        } else {
            foreach ($block['targets'] as $index => $target) {
                $targetPath = '$.data.properties.content.content.block.targets[' . (int) $index . ']';
                if (!is_array($target)) {
                    $errors[] = $this->error($targetPath, 'object', $target, 'Store target mappings as objects.');
                    continue;
                }

                if (!isset($target['nodeId']) || !is_int($target['nodeId'])) {
                    $errors[] = $this->error($targetPath . '.nodeId', 'integer', $target['nodeId'] ?? null, 'Point nodeId to a node inside the referenced block tree.');
                }

                foreach (['propertyKey', 'controlPath'] as $field) {
                    if (!is_string($target[$field] ?? null) || trim((string) $target[$field]) === '') {
                        $errors[] = $this->error($targetPath . '.' . $field, 'non-empty string', $target[$field] ?? null, 'Store component target ' . $field . ' as a non-empty string.');
                    }
                }
            }
        }

        if (!isset($block['properties']) || !is_array($block['properties'])) {
            $errors[] = $this->error('$.data.properties.content.content.block.properties', 'object', $block['properties'] ?? null, 'Store component override values as an object.');
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $colors
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateGlobalColors(array $colors, string $path): array
    {
        $errors = [];
        $palette = $colors['palette'] ?? null;

        if ($palette === null) {
            return $this->result([]);
        }

        if (!is_array($palette)) {
            return $this->result([
                $this->error($path . '.palette', 'object', $palette, 'Store palette colors and gradients in a palette object.'),
            ]);
        }

        foreach ($palette as $key => $value) {
            if (!in_array((string) $key, ['colors', 'gradients'], true)) {
                $errors[] = $this->error($path . '.palette.' . (string) $key, 'colors or gradients', $value, 'Remove unsupported palette fields before writing global settings.');
            }
        }

        if (isset($palette['colors'])) {
            if (!is_array($palette['colors'])) {
                $errors[] = $this->error($path . '.palette.colors', 'array', $palette['colors'], 'Store global colors as an array.');
            } else {
                foreach ($palette['colors'] as $index => $color) {
                    $colorPath = $path . '.palette.colors[' . (int) $index . ']';
                    if (!is_array($color)) {
                        $errors[] = $this->error($colorPath, 'object', $color, 'Store each global color as an object.');
                        continue;
                    }

                    foreach (['label', 'cssVariableName'] as $field) {
                        if (!is_string($color[$field] ?? null) || trim((string) $color[$field]) === '') {
                            $errors[] = $this->error($colorPath . '.' . $field, 'non-empty string', $color[$field] ?? null, 'Write required global color metadata.');
                        }
                    }

                    if (!array_key_exists('value', $color)) {
                        $errors[] = $this->error($colorPath . '.value', 'field present', null, 'Write the global color value.');
                    }
                }
            }
        }

        if (isset($palette['gradients'])) {
            if (!is_array($palette['gradients'])) {
                $errors[] = $this->error($path . '.palette.gradients', 'array', $palette['gradients'], 'Store global gradients as an array.');
            } else {
                foreach ($palette['gradients'] as $index => $gradient) {
                    $errors = array_merge($errors, $this->validateGlobalGradient($gradient, $path . '.palette.gradients[' . (int) $index . ']')['errors']);
                }
            }
        }

        return $this->result($errors);
    }

    /**
     * @param mixed $gradient
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateGlobalGradient($gradient, string $path): array
    {
        if (!is_array($gradient)) {
            return $this->result([
                $this->error($path, 'object', $gradient, 'Store each global gradient as an object.'),
            ]);
        }

        $errors = [];
        foreach (['label', 'cssVariableName'] as $field) {
            if (!is_string($gradient[$field] ?? null) || trim((string) $gradient[$field]) === '') {
                $errors[] = $this->error($path . '.' . $field, 'non-empty string', $gradient[$field] ?? null, 'Write required global gradient metadata.');
            }
        }

        if (!isset($gradient['value']) || !is_array($gradient['value'])) {
            $errors[] = $this->error($path . '.value', 'object with svgValue', $gradient['value'] ?? null, 'Store gradient values as an object with svgValue.');
            return $this->result($errors);
        }

        if (!is_string($gradient['value']['svgValue'] ?? null) || trim((string) $gradient['value']['svgValue']) === '') {
            $errors[] = $this->error($path . '.value.svgValue', 'non-empty string', $gradient['value']['svgValue'] ?? null, 'Include svgValue because Oxygen emits gradient SVG symbols from this field.');
        }

        if (array_key_exists('value', $gradient['value']) && !is_string($gradient['value']['value'])) {
            $errors[] = $this->error($path . '.value.value', 'string', $gradient['value']['value'], 'Store the optional CSS gradient value as a string.');
        }

        return $this->result($errors);
    }

    /**
     * @param mixed $value
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateMeasurementValue($value, string $path): array
    {
        if (!is_array($value)) {
            return $this->result([
                $this->error($path, 'measurement object', $value, 'Store measurement values with number, unit, and style fields.'),
            ]);
        }

        $errors = [];
        foreach ($value as $key => $fieldValue) {
            if (!in_array((string) $key, ['number', 'unit', 'style'], true)) {
                $errors[] = $this->error($path . '.' . (string) $key, 'number, unit, or style', $fieldValue, 'Remove unsupported measurement fields.');
            }
        }

        if (!array_key_exists('number', $value) || !(is_int($value['number']) || is_float($value['number']) || $value['number'] === null)) {
            $errors[] = $this->error($path . '.number', 'number or null', $value['number'] ?? null, 'Store the numeric measurement component or null.');
        }

        if (!is_string($value['unit'] ?? null)) {
            $errors[] = $this->error($path . '.unit', 'string', $value['unit'] ?? null, 'Store the measurement unit as a string.');
        }

        if (!is_string($value['style'] ?? null) || trim((string) $value['style']) === '') {
            $errors[] = $this->error($path . '.style', 'non-empty string', $value['style'] ?? null, 'Store the CSS-ready measurement value as style.');
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $code
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateCodeSection(array $code, string $path): array
    {
        $errors = [];

        foreach ($code as $key => $value) {
            if (!in_array((string) $key, ['stylesheets', 'scripts'], true)) {
                $errors[] = $this->error($path . '.' . (string) $key, 'stylesheets or scripts', $value, 'Remove unsupported global code fields.');
            }
        }

        foreach (['stylesheets', 'scripts'] as $field) {
            if (!isset($code[$field])) {
                continue;
            }

            if (!is_array($code[$field])) {
                $errors[] = $this->error($path . '.' . $field, 'array', $code[$field], 'Store named code blocks in an array.');
                continue;
            }

            foreach ($code[$field] as $index => $block) {
                $blockPath = $path . '.' . $field . '[' . (int) $index . ']';
                if (!is_array($block)) {
                    $errors[] = $this->error($blockPath, 'object', $block, 'Store each named code block as an object.');
                    continue;
                }

                foreach ($block as $key => $value) {
                    if (!in_array((string) $key, ['name', 'code'], true)) {
                        $errors[] = $this->error($blockPath . '.' . (string) $key, 'name or code', $value, 'Remove unsupported code block fields.');
                    }
                }

                foreach (['name', 'code'] as $required) {
                    if (!is_string($block[$required] ?? null)) {
                        $errors[] = $this->error($blockPath . '.' . $required, 'string', $block[$required] ?? null, 'Store named code block ' . $required . ' as a string.');
                    }
                }
            }
        }

        return $this->result($errors);
    }

    /**
     * @param array<int, mixed> $ruleGroups
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateTemplateRuleGroups(array $ruleGroups): array
    {
        $errors = [];

        foreach ($ruleGroups as $groupIndex => $group) {
            $groupPath = '$.ruleGroups[' . (int) $groupIndex . ']';
            if (!is_array($group)) {
                $errors[] = $this->error($groupPath, 'array of template rules', $group, 'Store each rule group as an array.');
                continue;
            }

            foreach ($group as $ruleIndex => $rule) {
                $errors = array_merge($errors, $this->validateTemplateRule($rule, $groupPath . '[' . (int) $ruleIndex . ']')['errors']);
            }
        }

        return $this->result($errors);
    }

    /**
     * @param mixed $rule
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateTemplateRule($rule, string $path): array
    {
        if (!is_array($rule)) {
            return $this->result([
                $this->error($path, 'template rule object', $rule, 'Store each template rule as an object.'),
            ]);
        }

        $errors = [];
        $allowed = [
            'operand' => true,
            'ruleCategorySlug' => true,
            'ruleSlug' => true,
            'ruleDynamic' => true,
            'value' => true,
        ];

        foreach ($rule as $key => $value) {
            if (!isset($allowed[(string) $key])) {
                $errors[] = $this->error($path . '.' . (string) $key, 'known template rule field', $value, 'Remove unsupported template rule fields.');
            }
        }

        $operand = is_string($rule['operand'] ?? null) ? trim((string) $rule['operand']) : '';
        if ($operand === '') {
            $errors[] = $this->error($path . '.operand', 'non-empty template operand', $rule['operand'] ?? null, 'Use an operand supported by Oxygen template rules.');
        } elseif (!isset(self::TEMPLATE_OPERANDS_REQUIRING_VALUE[$operand]) && !isset(self::TEMPLATE_OPERANDS_WITHOUT_VALUE[$operand])) {
            $errors[] = $this->error($path . '.operand', 'registered template operand', $operand, 'Use an operand from Oxygen themeless rule constants.');
        }

        if (!is_string($rule['ruleSlug'] ?? null) || trim((string) $rule['ruleSlug']) === '') {
            $errors[] = $this->error($path . '.ruleSlug', 'non-empty string', $rule['ruleSlug'] ?? null, 'Store the Oxygen template rule slug.');
        }

        foreach (['ruleCategorySlug', 'ruleDynamic'] as $field) {
            if (array_key_exists($field, $rule) && !is_string($rule[$field])) {
                $errors[] = $this->error($path . '.' . $field, 'string', $rule[$field], 'Store optional template rule metadata as strings.');
            }
        }

        if ($operand !== '' && isset(self::TEMPLATE_OPERANDS_REQUIRING_VALUE[$operand]) && !array_key_exists('value', $rule)) {
            $errors[] = $this->error($path . '.value', 'field required', null, 'Rules with this operand must include value.');
        }

        if (array_key_exists('value', $rule)) {
            $errors = array_merge($errors, $this->validateTemplateRuleValue($rule['value'], $path . '.value')['errors']);
        }

        return $this->result($errors);
    }

    /**
     * @param mixed $value
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateTemplateRuleValue($value, string $path): array
    {
        if (is_string($value)) {
            return $this->result([]);
        }

        if (!is_array($value)) {
            return $this->result([
                $this->error($path, 'string, string array, or object-value array', $value, 'Use a value shape accepted by Oxygen template rules.'),
            ]);
        }

        foreach ($value as $index => $item) {
            if (is_string($item)) {
                continue;
            }

            if (is_array($item) && is_string($item['value'] ?? null)) {
                continue;
            }

            return $this->result([
                $this->error($path . '[' . (int) $index . ']', 'string or object with string value', $item, 'Store each template rule value item as a string or object with value.'),
            ]);
        }

        return $this->result([]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateNodeData(array $data, string $path): array
    {
        $errors = [];
        $allowed = [
            'type' => true,
            'properties' => true,
        ];

        foreach ($data as $key => $value) {
            if (!isset($allowed[(string) $key])) {
                $errors[] = $this->error($path . '.' . (string) $key, 'no additional node data fields', $value, 'Move converter-only data outside the persisted Oxygen node.');
            }
        }

        if (!isset($data['type']) || !is_string($data['type']) || trim($data['type']) === '') {
            $errors[] = $this->error($path . '.type', 'non-empty string', $data['type'] ?? null, 'Use an Oxygen element type string.');
        }

        if (!isset($data['properties']) || !is_array($data['properties'])) {
            $errors[] = $this->error($path . '.properties', 'object', $data['properties'] ?? null, 'Use an empty object when an element has no properties.');
        } else {
            $errors = array_merge($errors, $this->validateNodeProperties($data['properties'], $path . '.properties')['errors']);
        }

        return $this->result($errors);
    }

    /**
     * @param array<string, mixed> $properties
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function validateNodeProperties(array $properties, string $path): array
    {
        $errors = [];
        $allowed = [
            'content' => true,
            'design' => true,
            'settings' => true,
            'meta' => true,
        ];

        foreach ($properties as $key => $value) {
            if (!isset($allowed[(string) $key])) {
                $errors[] = $this->error($path . '.' . (string) $key, 'content, design, settings, or meta property group', $value, 'Remove converter-internal fields before writing Oxygen properties.');
            }
        }

        return $this->result($errors);
    }

    /**
     * @param list<array{path:string,expected:string,actual:string,remediation:string,message:string}> $errors
     * @return array{valid: bool, errors: list<array{path:string,expected:string,actual:string,remediation:string,message:string}>}
     */
    private function result(array $errors): array
    {
        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param mixed $actual
     * @return array{path:string,expected:string,actual:string,remediation:string,message:string}
     */
    private function error(string $path, string $expected, $actual, string $remediation): array
    {
        $actualType = $this->actualType($actual);

        return [
            'path' => $path,
            'expected' => $expected,
            'actual' => $actualType,
            'remediation' => $remediation,
            'message' => $path . ' expected ' . $expected . ', got ' . $actualType . '. ' . $remediation,
        ];
    }

    /**
     * @param mixed $value
     */
    private function actualType($value): string
    {
        if ($value === null) {
            return 'missing/null';
        }

        if (is_object($value)) {
            return 'object(' . get_class($value) . ')';
        }

        if (is_array($value)) {
            return 'array';
        }

        return gettype($value);
    }
}
