<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Validation\OxygenSchemaValidator;

final class OxygenBlockRepository
{
    public const POST_TYPE = 'oxygen_block';

    /**
     * @var array<int, string>
     */
    private const REQUIRED_META_KEYS = [
        '_oxygen_data',
        '_breakdance_block_settings',
    ];

    public const DEFAULT_MIN_OCCURRENCES = 3;
    public const DEFAULT_MIN_CONFIDENCE = 0.75;
    public const DEFAULT_MIN_EDITABLE_PROPERTIES = 1;

    private ?OxygenStorageAdapter $storageAdapter;

    public function __construct(?OxygenStorageAdapter $storageAdapter = null)
    {
        $this->storageAdapter = $storageAdapter;
    }

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    /**
     * @return array<int, string>
     */
    public function requiredMetaKeys(): array
    {
        return self::REQUIRED_META_KEYS;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, errors: array<int, string>, postType: string, metaKeys: array<int, string>}
     */
    public function validateBlockSpec(array $spec): array
    {
        $errors = [];
        $postType = is_string($spec['post_type'] ?? null) ? (string) $spec['post_type'] : '';
        $postStatus = is_string($spec['post_status'] ?? null) ? (string) $spec['post_status'] : '';
        $oxygenData = is_array($spec['_oxygen_data'] ?? null) ? $spec['_oxygen_data'] : null;
        $blockSettings = is_array($spec['_breakdance_block_settings'] ?? null)
            ? $spec['_breakdance_block_settings']
            : null;

        if ($postType !== self::POST_TYPE) {
            $errors[] = 'post_type must be oxygen_block.';
        }

        if ($postStatus !== 'publish') {
            $errors[] = 'oxygen_block post_status must be publish.';
        }

        if ($oxygenData === null) {
            $errors[] = '_oxygen_data must be an object with tree_json_string.';
        } else {
            $envelopeValidation = $this->getStorageAdapter()->validatePageDocumentEnvelope($oxygenData);
            $errors = array_merge($errors, $envelopeValidation['errors']);
        }

        if ($blockSettings === null) {
            $blockSettings = [];
        }

        $tree = $this->decodeTreeFromEnvelope($oxygenData);
        if ($tree !== null) {
            $blockValidation = $this->getStorageAdapter()->validateBlock($tree, $blockSettings);
            $errors = array_merge($errors, $blockValidation['errors']);
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'postType' => self::POST_TYPE,
            'metaKeys' => self::REQUIRED_META_KEYS,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function buildBlockSpecFromCandidate(array $candidate): array
    {
        $tree = $this->treeFromCandidate($candidate);
        $componentProperties = is_array($candidate['componentProperties'] ?? null)
            ? $candidate['componentProperties']
            : [];
        if ($tree !== null && $componentProperties === []) {
            $componentProperties = $this->buildComponentPropertiesFromTree($tree, $candidate);
        }
        if ($tree !== null && $componentProperties !== []) {
            $tree = $this->applyEditablePropertiesToTree($tree, $componentProperties);
        }
        $componentCss = $tree !== null ? $this->componentCssFromTree($tree, $candidate) : [];

        $treeJson = '';
        if ($tree !== null) {
            $encoded = wp_json_encode($this->getStorageAdapter()->buildDocumentTree($tree));
            $treeJson = is_string($encoded) ? $encoded : '';
        }

        $suggestedName = $this->nonEmptyString(
            $candidate['suggestedName'] ?? $candidate['name'] ?? $candidate['title'] ?? null,
            'imported-component'
        );
        $postTitle = $this->nonEmptyString(
            $candidate['post_title'] ?? $candidate['title'] ?? null,
            $this->titleFromComponentName($suggestedName)
        );
        $postName = $this->nonEmptyString(
            $candidate['post_name'] ?? $candidate['slug'] ?? null,
            $this->sanitizeSlug($suggestedName)
        );
        $postId = (int) ($candidate['ID'] ?? $candidate['postId'] ?? $candidate['post_id'] ?? 0);

        $spec = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $postTitle,
            'post_name' => $postName,
            '_oxygen_data' => [
                'tree_json_string' => $treeJson,
            ],
            '_breakdance_block_settings' => $this->blockSettingsFromCandidate($candidate, $componentCss),
            'sourceCandidate' => [
                'suggestedName' => $suggestedName,
                'signature' => is_scalar($candidate['signature'] ?? null) ? (string) $candidate['signature'] : '',
                'occurrences' => $this->candidateOccurrences($candidate),
                'confidence' => $this->candidateConfidence($candidate, $this->candidateOccurrences($candidate), self::DEFAULT_MIN_OCCURRENCES),
                'componentProperties' => $componentProperties,
                'componentCss' => $componentCss,
            ],
        ];

        if ($postId > 0) {
            $spec['ID'] = $postId;
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function createOrUpdateBlockSpec(array $spec, array $rollbackSnapshot = []): array
    {
        $validation = $this->validateBlockSpec($spec);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen block payload failed contract validation.',
                'errors' => $validation['errors'],
                'postType' => self::POST_TYPE,
                'metaKeys' => self::REQUIRED_META_KEYS,
            ];
        }

        $oxygenData = is_array($spec['_oxygen_data'] ?? null) ? $spec['_oxygen_data'] : [];
        $tree = $this->decodeTreeFromEnvelope($oxygenData);
        if ($tree === null) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen block payload failed contract validation.',
                'errors' => ['_oxygen_data.tree_json_string must decode to an Oxygen document tree.'],
                'postType' => self::POST_TYPE,
                'metaKeys' => self::REQUIRED_META_KEYS,
            ];
        }

        $blockSettings = is_array($spec['_breakdance_block_settings'] ?? null)
            ? $spec['_breakdance_block_settings']
            : [];
        $blockSettings['_post'] = [
            'ID' => (int) ($spec['ID'] ?? $spec['post_id'] ?? 0),
            'post_status' => is_string($spec['post_status'] ?? null) ? (string) $spec['post_status'] : 'publish',
            'post_title' => is_string($spec['post_title'] ?? null) ? (string) $spec['post_title'] : 'Imported Oxygen Block',
            'post_name' => is_string($spec['post_name'] ?? null) ? (string) $spec['post_name'] : '',
            'post_content' => is_string($spec['post_content'] ?? null) ? (string) $spec['post_content'] : '',
        ];

        $write = $this->getStorageAdapter()->writeBlock($tree, $blockSettings, $rollbackSnapshot);

        return array_merge([
            'postType' => self::POST_TYPE,
            'metaKeys' => self::REQUIRED_META_KEYS,
        ], $write);
    }

    /**
     * @param array<int, mixed> $candidates
     * @param array<string, mixed> $options
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function persistComponentCandidates(array $candidates, array $options = [], array $rollbackSnapshot = []): array
    {
        $minOccurrences = max(1, (int) (
            $options['componentMinOccurrences']
            ?? $options['component_min_occurrences']
            ?? $options['minOccurrences']
            ?? self::DEFAULT_MIN_OCCURRENCES
        ));
        $minConfidence = $this->floatOption(
            $options,
            ['componentMinConfidence', 'component_min_confidence', 'minConfidence'],
            self::DEFAULT_MIN_CONFIDENCE
        );
        $minEditableProperties = max(0, (int) (
            $options['componentMinEditableProperties']
            ?? $options['component_min_editable_properties']
            ?? $options['minEditableProperties']
            ?? self::DEFAULT_MIN_EDITABLE_PROPERTIES
        ));
        $created = [];
        $updated = [];
        $skipped = [];
        $errors = [];
        $candidateCount = 0;

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                $skipped[] = [
                    'suggestedName' => '',
                    'signature' => '',
                    'reason' => 'invalid_component_candidate',
                ];
                continue;
            }

            $candidateCount++;
            $decision = $this->componentCandidateDecision($candidate, $minOccurrences, $minConfidence, $minEditableProperties);
            if (!$decision['eligible']) {
                $skipped[] = $decision;
                continue;
            }

            $spec = $this->buildBlockSpecFromCandidate($candidate);
            $write = $this->createOrUpdateBlockSpec($spec, $rollbackSnapshot);

            if (empty($write['success'])) {
                $errors = array_merge($errors, array_values(array_map('strval', is_array($write['errors'] ?? null) ? $write['errors'] : [])));
                $skipped[] = array_merge($decision, [
                    'eligible' => false,
                    'reason' => 'block_persistence_failed',
                    'errors' => is_array($write['errors'] ?? null) ? $write['errors'] : [],
                ]);
                continue;
            }

            $record = array_merge($decision, [
                'postId' => (int) ($write['postId'] ?? 0),
                'action' => (string) ($write['action'] ?? 'created'),
                'treeHash' => (string) ($write['treeHash'] ?? ''),
                'settingsHash' => (string) ($write['settingsHash'] ?? ''),
                'rollback' => is_array($write['rollback'] ?? null) ? $write['rollback'] : [],
            ]);
            $componentProperties = is_array($candidate['componentProperties'] ?? null)
                ? $candidate['componentProperties']
                : $this->componentPropertiesFromBlockSpec($spec);
            if ($componentProperties !== []) {
                $record['componentProperties'] = $componentProperties;
            }
            $componentCss = is_array($candidate['componentCss'] ?? null)
                ? $this->normalizeComponentCssRecords($candidate['componentCss'], $candidate)
                : $this->componentCssFromBlockSpec($spec);
            if ($componentCss !== []) {
                $record['componentCss'] = $componentCss;
            }

            if (($write['action'] ?? '') === 'updated') {
                $updated[] = $record;
            } else {
                $created[] = $record;
            }
        }

        return [
            'success' => $errors === [],
            'status' => $errors === [] ? 200 : 207,
            'postType' => self::POST_TYPE,
            'metaKeys' => self::REQUIRED_META_KEYS,
            'candidates' => $candidateCount,
            'created' => count($created),
            'updated' => count($updated),
            'skipped' => count($skipped),
            'createdBlocks' => $created,
            'updatedBlocks' => $updated,
            'skippedCandidates' => $skipped,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param array<string, mixed> $componentNode
     * @param array<string, mixed> $spec
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateComponentInstanceAgainstBlockSpec(array $componentNode, array $spec): array
    {
        $errors = [];
        $componentValidation = (new OxygenSchemaValidator())->validateComponentInstance($componentNode);
        $errors = array_merge($errors, $this->flattenSchemaErrors($componentValidation['errors']));

        $blockValidation = $this->validateBlockSpec($spec);
        $errors = array_merge($errors, $blockValidation['errors']);

        $tree = $this->decodeTreeFromEnvelope(is_array($spec['_oxygen_data'] ?? null) ? $spec['_oxygen_data'] : null);
        if ($tree === null) {
            $errors[] = 'component block tree_json_string must decode before instance target validation.';
            return [
                'valid' => false,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        $nodesById = $this->indexTreeNodesById(is_array($tree['root'] ?? null) ? $tree['root'] : null);
        $block = $componentNode['data']['properties']['content']['content']['block'] ?? null;
        if (!is_array($block)) {
            return [
                'valid' => false,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        $targets = is_array($block['targets'] ?? null) ? $block['targets'] : [];
        $properties = is_array($block['properties'] ?? null) ? $block['properties'] : [];
        foreach ($targets as $index => $target) {
            if (!is_array($target)) {
                continue;
            }

            $nodeId = $target['nodeId'] ?? null;
            $propertyKey = is_string($target['propertyKey'] ?? null) ? trim((string) $target['propertyKey']) : '';
            $controlPath = is_string($target['controlPath'] ?? null) ? trim((string) $target['controlPath']) : '';
            $targetLabel = 'component target #' . (string) $index;

            if (!is_int($nodeId) || $propertyKey === '' || $controlPath === '') {
                continue;
            }

            if (!isset($nodesById[$nodeId])) {
                $errors[] = $targetLabel . ' nodeId ' . (string) $nodeId . ' must reference a node inside the oxygen_block tree.';
                continue;
            }

            $editableProperty = $this->findEditablePropertyByKey($nodesById[$nodeId], $propertyKey);
            if ($editableProperty === null) {
                $errors[] = $targetLabel . ' propertyKey ' . $propertyKey . ' must match a meta.component.editableProperties record on node ' . (string) $nodeId . '.';
                continue;
            }

            if (($editableProperty['controlPath'] ?? null) !== $controlPath) {
                $errors[] = $targetLabel . ' controlPath must match the editable property controlPath for ' . $propertyKey . '.';
            }

            $propertiesForNode = is_array($nodesById[$nodeId]['data']['properties'] ?? null)
                ? $nodesById[$nodeId]['data']['properties']
                : [];
            if (!$this->propertyPathExists($propertiesForNode, $controlPath)) {
                $errors[] = $targetLabel . ' controlPath ' . $controlPath . ' must resolve inside node ' . (string) $nodeId . ' properties.';
            }

            if (!array_key_exists($propertyKey, $properties)) {
                $errors[] = $targetLabel . ' propertyKey ' . $propertyKey . ' must have an override value in component block properties.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function componentCandidateDecision(
        array $candidate,
        int $minOccurrences,
        float $minConfidence,
        int $minEditableProperties
    ): array
    {
        $occurrences = $this->candidateOccurrences($candidate);
        $confidence = $this->candidateConfidence($candidate, $occurrences, $minOccurrences);
        $suggestedName = $this->nonEmptyString(
            $candidate['suggestedName'] ?? $candidate['name'] ?? $candidate['title'] ?? null,
            ''
        );
        $tree = $this->treeFromCandidate($candidate);
        $editablePropertyCount = $this->componentEditablePropertyCount($candidate, $tree);
        $reasons = [];

        if ($suggestedName === '') {
            $reasons[] = 'missing_component_name';
        }

        if ($occurrences < $minOccurrences) {
            $reasons[] = 'below_occurrence_threshold';
        }

        if ($confidence < $minConfidence) {
            $reasons[] = 'below_confidence_threshold';
        }

        if ($tree === null) {
            $reasons[] = 'missing_component_tree';
        }

        if ($editablePropertyCount < $minEditableProperties) {
            $reasons[] = 'insufficient_editable_properties';
        }

        return [
            'suggestedName' => $suggestedName,
            'signature' => is_scalar($candidate['signature'] ?? null) ? (string) $candidate['signature'] : '',
            'classes' => array_values(array_map('strval', is_array($candidate['classes'] ?? null) ? $candidate['classes'] : [])),
            'occurrences' => $occurrences,
            'confidence' => $confidence,
            'threshold' => [
                'minOccurrences' => $minOccurrences,
                'minConfidence' => $minConfidence,
                'minEditableProperties' => $minEditableProperties,
            ],
            'editablePropertyCount' => $editablePropertyCount,
            'editablePropertiesSufficient' => $editablePropertyCount >= $minEditableProperties,
            'eligible' => $reasons === [],
            'reason' => $reasons[0] ?? '',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed>|null $tree
     */
    private function componentEditablePropertyCount(array $candidate, ?array $tree): int
    {
        if (is_array($candidate['componentProperties'] ?? null)) {
            $targets = is_array($candidate['componentProperties']['targets'] ?? null)
                ? $candidate['componentProperties']['targets']
                : [];

            return count(array_filter($targets, 'is_array'));
        }

        if (isset($candidate['editablePropertyCount'])
            && (is_int($candidate['editablePropertyCount']) || is_float($candidate['editablePropertyCount']))
        ) {
            return max(0, (int) $candidate['editablePropertyCount']);
        }

        if ($tree !== null) {
            $schema = $this->buildComponentPropertiesFromTree($tree, $candidate);

            return count(array_filter($schema['targets'], 'is_array'));
        }

        $fieldTypes = is_array($candidate['editableFieldTypes'] ?? null) ? $candidate['editableFieldTypes'] : [];

        return count(array_unique(array_filter($fieldTypes, 'is_string')));
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateOccurrences(array $candidate): int
    {
        return max(0, (int) ($candidate['occurrences'] ?? $candidate['count'] ?? 0));
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateConfidence(array $candidate, int $occurrences, int $minOccurrences): float
    {
        if (is_int($candidate['confidence'] ?? null) || is_float($candidate['confidence'] ?? null)) {
            return max(0.0, min(1.0, (float) $candidate['confidence']));
        }

        if ($minOccurrences < 1) {
            return 0.0;
        }

        return max(0.0, min(1.0, $occurrences / $minOccurrences));
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>|null
     */
    private function treeFromCandidate(array $candidate): ?array
    {
        foreach (['documentTree', 'blockTree', 'tree', 'oxygenTree'] as $key) {
            if (is_array($candidate[$key] ?? null)) {
                return $candidate[$key];
            }
        }

        if (is_array($candidate['_oxygen_data'] ?? null)) {
            return $this->decodeTreeFromEnvelope($candidate['_oxygen_data']);
        }

        if (is_array($candidate['blockSpec']['_oxygen_data'] ?? null)) {
            return $this->decodeTreeFromEnvelope($candidate['blockSpec']['_oxygen_data']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $candidate
     * @return array{targets: list<array{nodeId:int,propertyKey:string,controlPath:string}>, properties: array<string, mixed>}
     */
    public function buildComponentPropertiesFromTree(array $tree, array $candidate = []): array
    {
        $schema = [
            'targets' => [],
            'properties' => [],
        ];
        $name = $this->sanitizeSlug($this->nonEmptyString(
            $candidate['suggestedName'] ?? $candidate['name'] ?? $candidate['title'] ?? null,
            'component'
        ));
        $counters = [];
        $this->collectEditablePropertiesFromNode(is_array($tree['root'] ?? null) ? $tree['root'] : null, $name, $schema, $counters);

        return $schema;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $componentProperties
     * @return array<string, mixed>
     */
    private function applyEditablePropertiesToTree(array $tree, array $componentProperties): array
    {
        $targets = is_array($componentProperties['targets'] ?? null) ? $componentProperties['targets'] : [];
        if ($targets === []) {
            return $tree;
        }

        $editableByNode = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $nodeId = $target['nodeId'] ?? null;
            $propertyKey = is_string($target['propertyKey'] ?? null) ? trim((string) $target['propertyKey']) : '';
            $controlPath = is_string($target['controlPath'] ?? null) ? trim((string) $target['controlPath']) : '';
            if (!is_int($nodeId) || $propertyKey === '' || $controlPath === '') {
                continue;
            }

            $editableByNode[$nodeId][] = [
                'enabled' => true,
                'label' => $this->labelFromPropertyKey($propertyKey),
                'controlPath' => $controlPath,
                'propertyKey' => $propertyKey,
            ];
        }

        if ($editableByNode === []) {
            return $tree;
        }

        if (is_array($tree['root'] ?? null)) {
            $tree['root'] = $this->applyEditablePropertiesToNode($tree['root'], $editableByNode);
        }

        return $tree;
    }

    /**
     * @param mixed $node
     * @param array{targets:list<array{nodeId:int,propertyKey:string,controlPath:string}>,properties:array<string,mixed>} $schema
     * @param array<string, int> $counters
     */
    private function collectEditablePropertiesFromNode($node, string $name, array &$schema, array &$counters): void
    {
        if (!is_array($node)) {
            return;
        }

        $nodeId = $node['id'] ?? null;
        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        $properties = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];

        if (is_int($nodeId)) {
            foreach ($this->editableFieldsForNode($type, $properties) as $field) {
                $propertyKey = $this->nextPropertyKey($name, (string) $field['slug'], $counters);
                $schema['targets'][] = [
                    'nodeId' => $nodeId,
                    'propertyKey' => $propertyKey,
                    'controlPath' => (string) $field['controlPath'],
                ];
                $schema['properties'][$propertyKey] = $field['value'];
            }
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->collectEditablePropertiesFromNode($child, $name, $schema, $counters);
        }
    }

    /**
     * @param array<string, mixed> $properties
     * @return list<array{slug:string,controlPath:string,value:mixed}>
     */
    private function editableFieldsForNode(string $type, array $properties): array
    {
        if ($type === 'root') {
            return [];
        }

        if ($type === ElementTypes::TEXT || $type === ElementTypes::TEXT_LINK || str_ends_with($type, '\\Text') || str_ends_with($type, '\\TextLink')) {
            $fields = [];
            $text = $properties['content']['content']['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $fields[] = [
                    'slug' => $type === ElementTypes::TEXT_LINK || str_ends_with($type, '\\TextLink') ? 'link_label' : 'text',
                    'controlPath' => 'content.content.text',
                    'value' => $text,
                ];
            }

            if ($type === ElementTypes::TEXT_LINK || str_ends_with($type, '\\TextLink')) {
                $url = $properties['content']['content']['url'] ?? null;
                if (is_string($url) && trim($url) !== '') {
                    $fields[] = [
                        'slug' => 'link_url',
                        'controlPath' => 'content.content.url',
                        'value' => $url,
                    ];
                }
            }

            return $fields;
        }

        if ($type === ElementTypes::CONTAINER_LINK || str_ends_with($type, '\\ContainerLink')) {
            $url = $properties['content']['content']['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                return [[
                    'slug' => 'link_url',
                    'controlPath' => 'content.content.url',
                    'value' => $url,
                ]];
            }

            return [];
        }

        if ($type === ElementTypes::IMAGE || str_ends_with($type, '\\Image')) {
            $fields = [];
            $url = $properties['content']['image']['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                $fields[] = [
                    'slug' => 'image_url',
                    'controlPath' => 'content.image.url',
                    'value' => $url,
                ];
            }

            $alt = $properties['content']['image']['custom_alt_when_from_url'] ?? null;
            if (is_string($alt) && trim($alt) !== '') {
                $fields[] = [
                    'slug' => 'image_alt',
                    'controlPath' => 'content.image.custom_alt_when_from_url',
                    'value' => $alt,
                ];
            }

            return $fields;
        }

        if ($type === ElementTypes::SVG_ICON || str_ends_with($type, '\\SvgIcon')) {
            $icon = $properties['content']['content']['icon'] ?? null;
            if (is_array($icon) && $icon !== []) {
                return [[
                    'slug' => 'icon',
                    'controlPath' => 'content.content.icon',
                    'value' => $icon,
                ]];
            }
        }

        return [];
    }

    /**
     * @param array<string, int> $counters
     */
    private function nextPropertyKey(string $name, string $slug, array &$counters): string
    {
        $base = trim($name . '_' . $slug, '_');
        $base = $this->sanitizeSlug($base);
        $base = str_replace('-', '_', $base);
        if ($base === '') {
            $base = 'component_property';
        }

        $counters[$base] = ($counters[$base] ?? 0) + 1;

        return $counters[$base] === 1 ? $base : $base . '_' . (string) $counters[$base];
    }

    private function labelFromPropertyKey(string $propertyKey): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $propertyKey));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, list<array{enabled:bool,label:string,controlPath:string,propertyKey:string}>> $editableByNode
     * @return array<string, mixed>
     */
    private function applyEditablePropertiesToNode(array $node, array $editableByNode): array
    {
        $nodeId = $node['id'] ?? null;
        if (is_int($nodeId) && isset($editableByNode[$nodeId])) {
            $node['data']['properties'] = is_array($node['data']['properties'] ?? null) ? $node['data']['properties'] : [];
            $node['data']['properties']['meta'] = is_array($node['data']['properties']['meta'] ?? null)
                ? $node['data']['properties']['meta']
                : [];
            $node['data']['properties']['meta']['component'] = is_array($node['data']['properties']['meta']['component'] ?? null)
                ? $node['data']['properties']['meta']['component']
                : [];
            $node['data']['properties']['meta']['component']['editableProperties'] = $editableByNode[$nodeId];
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $node['children'][$index] = $this->applyEditablePropertiesToNode($child, $editableByNode);
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function componentPropertiesFromBlockSpec(array $spec): array
    {
        $source = is_array($spec['sourceCandidate']['componentProperties'] ?? null)
            ? $spec['sourceCandidate']['componentProperties']
            : [];

        return $source;
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<array<string, mixed>>
     */
    private function componentCssFromBlockSpec(array $spec): array
    {
        $source = is_array($spec['sourceCandidate']['componentCss'] ?? null)
            ? $spec['sourceCandidate']['componentCss']
            : [];

        return $this->normalizeComponentCssRecords(
            $source,
            is_array($spec['sourceCandidate'] ?? null) ? $spec['sourceCandidate'] : []
        );
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<array<string, mixed>> $componentCss
     * @return array<string, mixed>
     */
    private function blockSettingsFromCandidate(array $candidate, array $componentCss = []): array
    {
        if (is_array($candidate['_breakdance_block_settings'] ?? null)) {
            return $this->blockSettingsWithComponentCss($candidate['_breakdance_block_settings'], $componentCss);
        }

        if (is_array($candidate['blockSettings'] ?? null)) {
            return $this->blockSettingsWithComponentCss($candidate['blockSettings'], $componentCss);
        }

        if (is_array($candidate['blockSpec']['_breakdance_block_settings'] ?? null)) {
            return $this->blockSettingsWithComponentCss($candidate['blockSpec']['_breakdance_block_settings'], $componentCss);
        }

        return $this->blockSettingsWithComponentCss([
            'preview' => [
                'acfFlexibleField' => '',
                'acfFlexibleFieldRow' => '',
            ],
        ], $componentCss);
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>> $componentCss
     * @return array<string, mixed>
     */
    private function blockSettingsWithComponentCss(array $settings, array $componentCss): array
    {
        if ($componentCss === []) {
            return $settings;
        }

        $settings['oxyHtmlConverter'] = is_array($settings['oxyHtmlConverter'] ?? null)
            ? $settings['oxyHtmlConverter']
            : [];
        $settings['oxyHtmlConverter']['componentCss'] = $componentCss;

        return $settings;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function componentCssFromTree(array $tree, array $candidate): array
    {
        $records = [];
        $this->collectComponentCssFromNode(is_array($tree['root'] ?? null) ? $tree['root'] : null, $records);

        return $this->normalizeComponentCssRecords($records, $candidate);
    }

    /**
     * @param mixed $node
     * @param list<array<string, mixed>> $records
     */
    private function collectComponentCssFromNode($node, array &$records): void
    {
        if (!is_array($node)) {
            return;
        }

        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type === ElementTypes::CSS_CODE || str_ends_with($type, '\\CssCode')) {
            $css = $node['data']['properties']['content']['content']['css_code'] ?? null;
            if (is_string($css) && trim($css) !== '') {
                $records[] = [
                    'css' => trim($css),
                    'nodeId' => is_int($node['id'] ?? null) ? (int) $node['id'] : 0,
                ];
            }
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->collectComponentCssFromNode($child, $records);
        }
    }

    /**
     * @param array<int, mixed> $records
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function normalizeComponentCssRecords(array $records, array $candidate): array
    {
        $normalized = [];
        $seen = [];
        $componentName = $this->sanitizeSlug($this->nonEmptyString(
            $candidate['suggestedName'] ?? $candidate['name'] ?? $candidate['title'] ?? $candidate['componentName'] ?? null,
            'component'
        ));
        $signature = is_scalar($candidate['signature'] ?? null) ? trim((string) $candidate['signature']) : '';

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $css = $this->nonEmptyString($record['css'] ?? null, '');
            if ($css === '') {
                continue;
            }

            $hash = $this->nonEmptyString($record['hash'] ?? null, $this->componentCssHash($css));
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $normalized[] = [
                'type' => 'component_css',
                'destination' => 'component_block',
                'owner' => 'component',
                'label' => 'Component CSS',
                'componentName' => $this->nonEmptyString($record['componentName'] ?? null, $componentName),
                'signature' => $this->nonEmptyString($record['signature'] ?? null, $signature),
                'nodeId' => (int) ($record['nodeId'] ?? 0),
                'css' => $css,
                'bytes' => strlen($css),
                'ruleCount' => $this->countCssRules($css),
                'hash' => $hash,
                'cascadeOrder' => (int) ($record['cascadeOrder'] ?? 500),
                'exportBehavior' => 'bridge_to_host_page_styles',
                'rollbackStore' => 'component_block',
            ];
        }

        return $normalized;
    }

    private function componentCssHash(string $css): string
    {
        return substr(sha1('oxy-html-converter-component-css:' . trim($css)), 0, 16);
    }

    private function countCssRules(string $css): int
    {
        return max(1, substr_count($css, '{'));
    }

    /**
     * @param mixed $value
     */
    private function nonEmptyString($value, string $default): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function titleFromComponentName(string $name): string
    {
        $title = ucwords(str_replace(['-', '_'], ' ', $name));

        return trim($title) !== '' ? trim($title) : 'Imported Component';
    }

    private function sanitizeSlug(string $value): string
    {
        if (function_exists('sanitize_title')) {
            $slug = sanitize_title($value);
        } else {
            $slug = strtolower(trim($value));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
            $slug = trim($slug, '-');
        }

        return $slug !== '' ? $slug : 'imported-component';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function floatOption(array $options, array $keys, float $default): float
    {
        $value = null;
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                $value = $options[$key];
                break;
            }
        }

        if (!is_int($value) && !is_float($value)) {
            return $default;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function getStorageAdapter(): OxygenStorageAdapter
    {
        if ($this->storageAdapter === null) {
            $this->storageAdapter = (new OxygenStorageAdapterFactory())->create();
        }

        return $this->storageAdapter;
    }

    /**
     * @param array<string, mixed>|null $oxygenData
     * @return array<string, mixed>|null
     */
    private function decodeTreeFromEnvelope(?array $oxygenData): ?array
    {
        if ($oxygenData === null || !is_string($oxygenData['tree_json_string'] ?? null)) {
            return null;
        }

        try {
            $tree = json_decode((string) $oxygenData['tree_json_string'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($tree) ? $tree : null;
    }

    /**
     * @param array<int, array<string, mixed>> $schemaErrors
     * @return array<int, string>
     */
    private function flattenSchemaErrors(array $schemaErrors): array
    {
        $messages = [];
        foreach ($schemaErrors as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $messages[] = (string) $error['message'];
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed>|null $node
     * @return array<int, array<string, mixed>>
     */
    private function indexTreeNodesById(?array $node): array
    {
        if ($node === null) {
            return [];
        }

        $nodes = [];
        if (is_int($node['id'] ?? null)) {
            $nodes[(int) $node['id']] = $node;
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $nodes += $this->indexTreeNodesById($child);
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function findEditablePropertyByKey(array $node, string $propertyKey): ?array
    {
        $editableProperties = $node['data']['properties']['meta']['component']['editableProperties'] ?? [];
        if (!is_array($editableProperties)) {
            return null;
        }

        foreach ($editableProperties as $editableProperty) {
            if (!is_array($editableProperty)) {
                continue;
            }

            if (($editableProperty['propertyKey'] ?? null) === $propertyKey) {
                return $editableProperty;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function propertyPathExists(array $properties, string $controlPath): bool
    {
        $cursor = $properties;
        foreach (explode('.', $controlPath) as $segment) {
            if ($segment === '' || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }
}
