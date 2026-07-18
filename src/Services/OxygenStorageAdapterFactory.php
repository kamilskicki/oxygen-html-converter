<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class OxygenStorageAdapterFactory
{
    /**
     * Runtime versions that are compatible with the pinned Oxygen 6 storage contract fixtures.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_RUNTIME_OXYGEN_VERSIONS = [
        OxygenStorageContract::SUPPORTED_OXYGEN_VERSION,
        '6.1.0',
    ];

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
        $this->runtimeOxygenVersion = $runtimeOxygenVersion ?? self::detectRuntimeOxygenVersion();
    }

    public function supports(string $oxygenVersion): bool
    {
        return in_array($oxygenVersion, self::SUPPORTED_RUNTIME_OXYGEN_VERSIONS, true);
    }

    public function create(): OxygenStorageAdapter
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal compatibility errors, not rendered directly.
        if ($this->runtimeOxygenVersion !== null && !$this->supports($this->runtimeOxygenVersion)) {
            throw new \RuntimeException(
                sprintf(
                    'Unsupported Oxygen runtime version "%s"; supported versions are "%s".',
                    $this->runtimeOxygenVersion,
                    implode('", "', self::SUPPORTED_RUNTIME_OXYGEN_VERSIONS)
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
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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

    public static function detectRuntimeOxygenVersion(): ?string
    {
        if (defined('__BREAKDANCE_VERSION')) {
            return (string) constant('__BREAKDANCE_VERSION');
        }

        if (defined('BREAKDANCE_VERSION')) {
            return (string) constant('BREAKDANCE_VERSION');
        }

        if (defined('CT_VERSION')) {
            return (string) constant('CT_VERSION');
        }

        return null;
    }
}
