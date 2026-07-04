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
}
