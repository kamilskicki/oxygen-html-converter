<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

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
