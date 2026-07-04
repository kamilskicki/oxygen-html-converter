<?php

namespace OxyHtmlConverter\Services;

/**
 * Validates runtime compatibility contracts for builder element classes.
 */
class BuilderContractService
{
    /**
     * Validate the EssentialElements Button contract used by the converter.
     *
     * @return array{compatible:bool,class:string,issues:array,details:array}
     */
    public function evaluateEssentialButtonContract(): array
    {
        $requiredBaseClass = class_exists('\\Breakdance\\Elements\\Element')
            ? '\\Breakdance\\Elements\\Element'
            : null;

        return $this->evaluateElementContract(
            '\\EssentialElements\\Button',
            ['content.content.text', 'content.content.link.url'],
            'oxygen',
            $requiredBaseClass
        );
    }

    /**
     * Validate a generic element contract.
     *
     * @param string $className Fully-qualified class name
     * @param array $requiredDynamicPaths Required dynamic property paths
     * @param string|null $requiredAvailability Required availableIn target (e.g. "oxygen")
     * @param string|null $requiredBaseClass Required base class
     * @return array{compatible:bool,class:string,issues:array,details:array}
     */
    public function evaluateElementContract(
        string $className,
        array $requiredDynamicPaths = [],
        ?string $requiredAvailability = null,
        ?string $requiredBaseClass = null
    ): array {
        $normalizedClass = $this->normalizeClassName($className);
        $issues = [];
        $details = [
            'dynamicPaths' => [],
            'availableIn' => null,
        ];

        if (!class_exists($normalizedClass)) {
            $issues[] = sprintf('Class %s is missing.', $normalizedClass);
            return [
                'compatible' => false,
                'class' => $normalizedClass,
                'issues' => $issues,
                'details' => $details,
            ];
        }

        if ($requiredBaseClass !== null && class_exists($requiredBaseClass)) {
            if (!is_subclass_of($normalizedClass, $requiredBaseClass)) {
                $issues[] = sprintf('Class %s does not extend %s.', $normalizedClass, $requiredBaseClass);
            }
        }

        if (!empty($requiredDynamicPaths)) {
            if (!method_exists($normalizedClass, 'dynamicPropertyPaths')) {
                $issues[] = sprintf('Class %s is missing dynamicPropertyPaths().', $normalizedClass);
            } else {
                $dynamicPathsResult = $this->callStatic($normalizedClass, 'dynamicPropertyPaths');
                if ($dynamicPathsResult['ok']) {
                    $dynamicPaths = $this->extractDynamicPaths($dynamicPathsResult['value']);
                    $details['dynamicPaths'] = $dynamicPaths;
                    foreach ($requiredDynamicPaths as $requiredPath) {
                        if (!in_array($requiredPath, $dynamicPaths, true)) {
                            $issues[] = sprintf(
                                'Class %s is missing dynamic path "%s".',
                                $normalizedClass,
                                $requiredPath
                            );
                        }
                    }
                } else {
                    $issues[] = sprintf(
                        'Class %s dynamicPropertyPaths() failed: %s',
                        $normalizedClass,
                        $dynamicPathsResult['error']
                    );
                }
            }
        }

        if ($requiredAvailability !== null) {
            if (!method_exists($normalizedClass, 'availableIn')) {
                $issues[] = sprintf('Class %s is missing availableIn().', $normalizedClass);
            } else {
                $availableInResult = $this->callStatic($normalizedClass, 'availableIn');
                if ($availableInResult['ok']) {
                    $availableIn = is_array($availableInResult['value']) ? $availableInResult['value'] : [];
                    $details['availableIn'] = $availableIn;
                    if (!in_array($requiredAvailability, $availableIn, true)) {
                        $issues[] = sprintf(
                            'Class %s is not available in "%s".',
                            $normalizedClass,
                            $requiredAvailability
                        );
                    }
                } else {
                    $issues[] = sprintf(
                        'Class %s availableIn() failed: %s',
                        $normalizedClass,
                        $availableInResult['error']
                    );
                }
            }
        }

        return [
            'compatible' => empty($issues),
            'class' => $normalizedClass,
            'issues' => $issues,
            'details' => $details,
        ];
    }

    /**
     * @return array{compatible: bool, oxygenVersion: string, supportedVersion: string, contracts: array<string, array<string, mixed>>, issues: array<int, string>}
     */
    public function evaluateOxygenStorageFixtures(string $fixtureDirectory): array
    {
        $issues = [];
        $contracts = [];
        $oxygenVersions = [];

        if (!is_dir($fixtureDirectory)) {
            return [
                'compatible' => false,
                'oxygenVersion' => '',
                'supportedVersion' => OxygenStorageContract::SUPPORTED_OXYGEN_VERSION,
                'contracts' => [],
                'issues' => [
                    'Oxygen storage contract fixture directory is missing: ' . $fixtureDirectory,
                ],
            ];
        }

        foreach (OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES as $contract => $fileName) {
            $file = rtrim($fixtureDirectory, "\\/") . DIRECTORY_SEPARATOR . $fileName;
            if (!is_file($file)) {
                $issues[] = sprintf(
                    'Missing Oxygen storage contract fixture "%s" at %s.',
                    $contract,
                    $file
                );
                continue;
            }

            $decoded = $this->decodeFixtureFile($file, $contract, $issues);
            if ($decoded === null) {
                continue;
            }

            if (($decoded['contract'] ?? null) !== $contract) {
                $issues[] = sprintf(
                    'Oxygen storage contract fixture "%s" declares contract "%s".',
                    $contract,
                    is_scalar($decoded['contract'] ?? null) ? (string) $decoded['contract'] : 'invalid'
                );
            }

            $version = is_string($decoded['oxygenVersion'] ?? null) ? $decoded['oxygenVersion'] : '';
            if ($version === '') {
                $issues[] = sprintf('Oxygen storage contract fixture "%s" is missing oxygenVersion.', $contract);
            } else {
                $oxygenVersions[] = $version;
                if ($version !== OxygenStorageContract::SUPPORTED_OXYGEN_VERSION) {
                    $issues[] = sprintf(
                        'Unsupported Oxygen storage contract version "%s" in "%s"; supported version is "%s".',
                        $version,
                        $contract,
                        OxygenStorageContract::SUPPORTED_OXYGEN_VERSION
                    );
                }
            }

            if (empty($decoded['sourceFiles']) || !is_array($decoded['sourceFiles'])) {
                $issues[] = sprintf('Oxygen storage contract fixture "%s" must list sourceFiles.', $contract);
            }

            if (!isset($decoded['payload']) || !is_array($decoded['payload'])) {
                $issues[] = sprintf('Oxygen storage contract fixture "%s" must contain an array payload.', $contract);
            }

            $contracts[$contract] = $decoded;
        }

        $uniqueVersions = array_values(array_unique($oxygenVersions));
        if (count($uniqueVersions) > 1) {
            $issues[] = 'Oxygen storage contract fixtures declare mixed versions: ' . implode(', ', $uniqueVersions) . '.';
        }

        return [
            'compatible' => $issues === [],
            'oxygenVersion' => $uniqueVersions[0] ?? '',
            'supportedVersion' => OxygenStorageContract::SUPPORTED_OXYGEN_VERSION,
            'contracts' => $contracts,
            'issues' => $issues,
        ];
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function extractDynamicPaths($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = $item['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<int, string> $issues
     * @return array<string, mixed>|null
     */
    private function decodeFixtureFile(string $file, string $contract, array &$issues): ?array
    {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            $issues[] = sprintf('Oxygen storage contract fixture "%s" could not be read.', $contract);
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $issues[] = sprintf(
                'Oxygen storage contract fixture "%s" contains invalid JSON: %s.',
                $contract,
                $e->getMessage()
            );
            return null;
        }

        if (!is_array($decoded)) {
            $issues[] = sprintf('Oxygen storage contract fixture "%s" must decode to an object.', $contract);
            return null;
        }

        return $decoded;
    }

    /**
     * @return array{ok:bool,value:mixed,error:string}
     */
    private function callStatic(string $className, string $method): array
    {
        try {
            if (!is_callable([$className, $method])) {
                return [
                    'ok' => false,
                    'value' => null,
                    'error' => sprintf('%s::%s is not callable', $className, $method),
                ];
            }

            return [
                'ok' => true,
                'value' => call_user_func([$className, $method]),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'value' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizeClassName(string $className): string
    {
        return '\\' . ltrim($className, '\\');
    }
}
