<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

interface OxygenStorageAdapter
{
    public function supports(string $oxygenVersion): bool;

    public function getAdapterId(): string;

    public function getContractVersion(): string;

    public function getContract(): OxygenStorageContract;

    /**
     * @param array<string, mixed> $rootOrTree
     * @return array<string, mixed>
     */
    public function buildDocumentTree(array $rootOrTree): array;

    /**
     * @param array<string, mixed> $tree
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateDocumentTree(array $tree): array;

    /**
     * @param array<string, mixed> $metaValue
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validatePageDocumentEnvelope(array $metaValue): array;

    /**
     * @return array<string, mixed>
     */
    public function readPageDocument(int $postId): array;

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writePageDocument(int $postId, array $tree, array $rollbackSnapshot = []): array;

    /**
     * @param array<string, mixed> $postSpec
     * @return array<string, mixed>
     */
    public function createOrUpdateDocumentPost(array $postSpec): array;

    /**
     * @return array<string, mixed>
     */
    public function readSelectors(): array;

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<int, string> $collections
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeSelectors(array $selectors, array $collections, array $rollbackSnapshot = []): array;

    /**
     * @return array<string, mixed>
     */
    public function readVariables(): array;

    /**
     * @param array<int, array<string, mixed>> $variables
     * @param array<int, string> $collections
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeVariables(array $variables, array $collections, array $rollbackSnapshot = []): array;

    /**
     * @return array<string, mixed>
     */
    public function readGlobalSettings(): array;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeGlobalSettings(array $settings, array $rollbackSnapshot = []): array;

    /**
     * @return array<string, mixed>
     */
    public function readTemplateSettings(int $postId): array;

    /**
     * @param array<string, mixed> $tree
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateTemplate(string $postType, array $tree, string $settingsJson): array;

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeTemplate(string $postType, array $tree, string $settingsJson, array $rollbackSnapshot = []): array;

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $blockSettings
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateBlock(array $tree, array $blockSettings): array;

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $blockSettings
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeBlock(array $tree, array $blockSettings, array $rollbackSnapshot = []): array;

    /**
     * @param array<string, mixed> $componentNode
     * @return array<string, mixed>
     */
    public function writeComponentInstance(array $componentNode): array;

    /**
     * @return array<string, mixed>
     */
    public function readGlobalStyles(): array;

    /**
     * @param array<string, mixed> $library
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writeGlobalStyles(array $library, array $rollbackSnapshot = []): array;

    /**
     * @return array<string, mixed>
     */
    public function readPageStyles(int $postId): array;

    /**
     * @param array<string, mixed> $pageStyles
     * @param array<string, mixed> $rollbackSnapshot
     * @return array<string, mixed>
     */
    public function writePageStyles(int $postId, array $pageStyles, array $rollbackSnapshot = []): array;

    /**
     * @param array<int, string> $stores
     * @return array<string, mixed>
     */
    public function captureRollbackSnapshot(array $stores): array;

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function restoreRollbackSnapshot(array $snapshot): array;

    public function invalidateDocumentCaches(int $postId): void;

    public function invalidateGlobalCaches(): void;

    /**
     * @param array<int, string> $integrationIds
     * @return array<string, mixed>
     */
    public function resetOptionalIntegrationCaches(array $integrationIds): array;
}
