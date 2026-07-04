<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class OxygenStorageAdapterFactory
{
    private BuilderContractService $contractService;
    private string $fixtureDirectory;
    private ?string $runtimeOxygenVersion;

    public function __construct(
        ?BuilderContractService $contractService = null,
        ?string $fixtureDirectory = null,
        ?string $runtimeOxygenVersion = null
    )
    {
        $this->contractService = $contractService ?? new BuilderContractService();
        $this->fixtureDirectory = $fixtureDirectory ?? OxygenStorageContract::defaultFixtureDirectory();
        $this->runtimeOxygenVersion = $runtimeOxygenVersion ?? $this->detectRuntimeOxygenVersion();
    }

    public function supports(string $oxygenVersion): bool
    {
        return $oxygenVersion === OxygenStorageContract::SUPPORTED_OXYGEN_VERSION;
    }

    public function create(): OxygenStorageAdapter
    {
        if ($this->runtimeOxygenVersion !== null && !$this->supports($this->runtimeOxygenVersion)) {
            throw new \RuntimeException(
                sprintf(
                    'Unsupported Oxygen runtime version "%s"; supported version is "%s".',
                    $this->runtimeOxygenVersion,
                    OxygenStorageContract::SUPPORTED_OXYGEN_VERSION
                )
            );
        }

        $evaluation = $this->evaluate();

        if (empty($evaluation['compatible'])) {
            throw new \RuntimeException(
                'Oxygen storage adapter unavailable: ' . implode(' ', array_map('strval', $evaluation['issues']))
            );
        }

        $version = $evaluation['oxygenVersion'];
        if ($version !== OxygenStorageContract::SUPPORTED_OXYGEN_VERSION) {
            throw new \RuntimeException(
                sprintf(
                    'Unsupported Oxygen storage contract version "%s"; supported version is "%s".',
                    $version,
                    OxygenStorageContract::SUPPORTED_OXYGEN_VERSION
                )
            );
        }

        return new OxygenSixStorageAdapter(new OxygenStorageContract(
            $version,
            $this->fixtureDirectory,
            $evaluation['contracts']
        ));
    }

    /**
     * @return array{compatible: bool, oxygenVersion: string, supportedVersion: string, contracts: array<string, array<string, mixed>>, issues: array<int, string>}
     */
    public function evaluate(): array
    {
        return $this->contractService->evaluateOxygenStorageFixtures($this->fixtureDirectory);
    }

    private function detectRuntimeOxygenVersion(): ?string
    {
        if (defined('__BREAKDANCE_VERSION')) {
            return (string) constant('__BREAKDANCE_VERSION');
        }

        if (defined('BREAKDANCE_VERSION')) {
            return (string) constant('BREAKDANCE_VERSION');
        }

        return null;
    }
}
