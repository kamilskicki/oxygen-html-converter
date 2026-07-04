<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class OxygenStorageContract
{
    public const SUPPORTED_OXYGEN_VERSION = '6.1.0-beta.1';

    public const REQUIRED_CONTRACT_FIXTURES = [
        'page-tree' => 'page-tree.json',
        'selectors' => 'selectors.json',
        'variables' => 'variables.json',
        'global-settings' => 'global-settings.json',
        'template-settings' => 'template-settings.json',
        'block' => 'block.json',
        'component-instance' => 'component-instance.json',
    ];

    /**
     * @param array<string, array<string, mixed>> $fixtures
     */
    public function __construct(
        private readonly string $oxygenVersion,
        private readonly string $fixtureDirectory,
        private readonly array $fixtures
    ) {
    }

    public function getOxygenVersion(): string
    {
        return $this->oxygenVersion;
    }

    public function getFixtureDirectory(): string
    {
        return $this->fixtureDirectory;
    }

    /**
     * @return array<int, string>
     */
    public function getContractNames(): array
    {
        return array_keys($this->fixtures);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFixture(string $contract): array
    {
        return $this->fixtures[$contract] ?? [];
    }

    public function hasFixture(string $contract): bool
    {
        return isset($this->fixtures[$contract]);
    }

    public static function defaultFixtureDirectory(): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'tests'
            . DIRECTORY_SEPARATOR
            . 'fixtures'
            . DIRECTORY_SEPARATOR
            . 'oxygen6-contracts';
    }
}
