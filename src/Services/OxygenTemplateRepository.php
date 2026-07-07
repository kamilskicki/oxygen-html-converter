<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class OxygenTemplateRepository
{
    /**
     * @var array<int, string>
     */
    private const POST_TYPES = [
        'oxygen_template',
        'oxygen_header',
        'oxygen_footer',
        'oxygen_part',
    ];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_META_KEYS = [
        '_oxygen_data',
        '_oxygen_template_settings',
    ];

    /**
     * @var array<string, string>
     */
    private const MANIFEST_SECTION_POST_TYPES = [
        'templates' => 'oxygen_template',
        'headers' => 'oxygen_header',
        'footers' => 'oxygen_footer',
        'parts' => 'oxygen_part',
    ];

    private ?OxygenStorageAdapter $storageAdapter;

    public function __construct(?OxygenStorageAdapter $storageAdapter = null)
    {
        $this->storageAdapter = $storageAdapter;
    }

    /**
     * @return array<int, string>
     */
    public function supportedPostTypes(): array
    {
        return self::POST_TYPES;
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
    public function validateTemplateSpec(array $spec): array
    {
        $errors = [];
        $postType = is_string($spec['post_type'] ?? null) ? (string) $spec['post_type'] : '';
        $oxygenData = is_array($spec['_oxygen_data'] ?? null) ? $spec['_oxygen_data'] : null;
        $settingsJson = is_string($spec['_oxygen_template_settings'] ?? null)
            ? (string) $spec['_oxygen_template_settings']
            : null;

        if (!in_array($postType, self::POST_TYPES, true)) {
            $errors[] = sprintf('Unsupported Oxygen template post type "%s".', $postType);
        }

        if ($oxygenData === null) {
            $errors[] = '_oxygen_data must be an object with tree_json_string.';
        } else {
            $envelopeValidation = $this->getStorageAdapter()->validatePageDocumentEnvelope($oxygenData);
            $errors = array_merge($errors, $envelopeValidation['errors']);
        }

        if ($settingsJson === null) {
            $errors[] = '_oxygen_template_settings must be a JSON string.';
        }

        $tree = $this->decodeTreeFromEnvelope($oxygenData);
        if ($tree !== null && $settingsJson !== null) {
            $templateValidation = $this->getStorageAdapter()->validateTemplate($postType, $tree, $settingsJson);
            $errors = array_merge($errors, $templateValidation['errors']);
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'postType' => $postType,
            'metaKeys' => self::REQUIRED_META_KEYS,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public function createOrUpdateTemplate(array $spec): array
    {
        $validation = $this->validateTemplateSpec($spec);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Oxygen template spec failed contract validation.',
                'errors' => $validation['errors'],
                'postType' => $validation['postType'],
                'metaKeys' => $validation['metaKeys'],
            ];
        }

        return $this->getStorageAdapter()->createOrUpdateDocumentPost($spec);
    }

    public function postTypeForManifestSection(string $section): string
    {
        return self::MANIFEST_SECTION_POST_TYPES[$section] ?? '';
    }

    /**
     * @param array<string, mixed> $record
     */
    public function classifyManifestTemplateOperation(array $record): string
    {
        $settings = $this->extractTemplateSettings($record) ?? [];

        return $this->classifyTemplateSettingsOperation($settings);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function manifestRecordToTemplateSpec(array $record, string $section, int $index): array
    {
        $postType = $this->postTypeForManifestSection($section);
        $tree = $this->extractDocumentTree($record) ?? [];
        $settings = $this->extractTemplateSettings($record) ?? $this->defaultTemplateSettings();
        $treeJson = wp_json_encode($tree);
        $settingsJson = wp_json_encode($settings);
        $postId = (int) ($record['postId'] ?? $record['post_id'] ?? 0);

        $spec = [
            'post_type' => $postType,
            'post_status' => $this->recordString($record, 'postStatus', $this->recordString($record, 'post_status', 'publish')),
            'post_title' => $this->recordString($record, 'title', ucfirst(rtrim($section, 's')) . ' ' . ($index + 1)),
            'post_name' => $this->recordString($record, 'slug', ''),
            '_oxygen_data' => [
                'tree_json_string' => is_string($treeJson) ? $treeJson : '[]',
            ],
            '_oxygen_template_settings' => is_string($settingsJson) ? $settingsJson : '{}',
        ];

        if ($postId > 0) {
            $spec['ID'] = $postId;
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{templates: list<array<string, mixed>>, headers: list<array<string, mixed>>, footers: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    public function normalizeManifestSections(array $manifest): array
    {
        $sections = [
            'templates' => [],
            'headers' => [],
            'footers' => [],
            'parts' => [],
        ];

        foreach (self::MANIFEST_SECTION_POST_TYPES as $section => $postType) {
            $records = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }

                $sections[$section][] = $this->normalizeManifestTemplateRecord($record, $postType, $section, (int) $index);
            }
        }

        return $sections;
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
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeManifestTemplateRecord(array $record, string $postType, string $section, int $index): array
    {
        $tree = $this->extractDocumentTree($record);
        $settings = $this->extractTemplateSettings($record);
        $treeSummary = $this->documentTreeSummary($tree);

        return [
            'id' => $this->recordString($record, 'id', $section . '-' . ($index + 1)),
            'title' => $this->recordString($record, 'title', ucfirst(rtrim($section, 's')) . ' ' . ($index + 1)),
            'slug' => $this->recordString($record, 'slug', ''),
            'postType' => $postType,
            'postId' => (int) ($record['postId'] ?? $record['post_id'] ?? 0),
            'hasDocumentTree' => $tree !== null,
            'treeHash' => $treeSummary['treeHash'],
            'nodeCount' => $treeSummary['nodeCount'],
            'elementTypes' => $treeSummary['elementTypes'],
            'semanticTags' => $treeSummary['semanticTags'],
            'settings' => $settings,
            'operationScope' => $settings !== null ? $this->classifyTemplateSettingsOperation($settings) : 'template',
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function extractDocumentTree(array $record): ?array
    {
        foreach (['documentTree', 'tree'] as $field) {
            if (is_array($record[$field] ?? null)) {
                return $this->getStorageAdapter()->buildDocumentTree($record[$field]);
            }
        }

        $oxygenData = is_array($record['_oxygen_data'] ?? null) ? $record['_oxygen_data'] : null;
        if ($oxygenData !== null) {
            $tree = $this->decodeTreeFromEnvelope($oxygenData);

            return $tree !== null ? $this->getStorageAdapter()->buildDocumentTree($tree) : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function extractTemplateSettings(array $record): ?array
    {
        $settings = $record['templateSettings'] ?? $record['settings'] ?? null;
        if (is_array($settings)) {
            return $settings;
        }

        $settingsJson = $record['_oxygen_template_settings'] ?? null;
        if (!is_string($settingsJson) || trim($settingsJson) === '') {
            return null;
        }

        $decoded = json_decode($settingsJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function classifyTemplateSettingsOperation(array $settings): string
    {
        $type = strtolower(trim(is_scalar($settings['type'] ?? null) ? (string) $settings['type'] : ''));
        $encoded = wp_json_encode($settings);
        $haystack = strtolower(is_string($encoded) ? $encoded : '');

        if (str_contains($type, 'archive') || str_contains($haystack, 'rulecategoryslug":"archive')) {
            return 'archive_template';
        }

        if (str_contains($type, 'single')
            || str_contains($type, 'singular')
            || str_contains($haystack, 'rulecategoryslug":"singular')
        ) {
            return 'single_template';
        }

        return 'template';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTemplateSettings(): array
    {
        return [
            'type' => 'everywhere',
            'ruleGroups' => [],
            'triggers' => [],
            'priority' => 10,
            'fallback' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $tree
     * @return array{treeHash: string, nodeCount: int, elementTypes: list<string>, semanticTags: list<string>}
     */
    private function documentTreeSummary(?array $tree): array
    {
        if ($tree === null) {
            return [
                'treeHash' => '',
                'nodeCount' => 0,
                'elementTypes' => [],
                'semanticTags' => [],
            ];
        }

        $encoded = wp_json_encode($tree);
        $summary = [
            'nodeCount' => 0,
            'elementTypes' => [],
            'semanticTags' => [],
        ];
        $this->walkTreeNode($tree['root'] ?? null, $summary);

        return [
            'treeHash' => is_string($encoded) ? sha1($encoded) : '',
            'nodeCount' => $summary['nodeCount'],
            'elementTypes' => array_values(array_unique($summary['elementTypes'])),
            'semanticTags' => array_values(array_unique($summary['semanticTags'])),
        ];
    }

    /**
     * @param mixed $node
     * @param array{nodeCount: int, elementTypes: list<string>, semanticTags: list<string>} $summary
     */
    private function walkTreeNode($node, array &$summary): void
    {
        if (!is_array($node)) {
            return;
        }

        $summary['nodeCount']++;
        $type = is_string($node['data']['type'] ?? null) ? (string) $node['data']['type'] : '';
        if ($type !== '') {
            $summary['elementTypes'][] = $type;
        }

        $tag = is_string($node['data']['properties']['settings']['advanced']['tag'] ?? null)
            ? (string) $node['data']['properties']['settings']['advanced']['tag']
            : '';
        if ($tag !== '') {
            $summary['semanticTags'][] = $tag;
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            $this->walkTreeNode($child, $summary);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordString(array $record, string $field, string $default): string
    {
        $value = $record[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
