<?php

namespace OxyHtmlConverter\Tests\Unit\Services\Fixtures;

abstract class FakeBaseElement
{
}

class GoodElement extends FakeBaseElement
{
    public static function dynamicPropertyPaths(): array
    {
        return [
            ['path' => 'content.content.text'],
            ['path' => 'content.content.link.url'],
        ];
    }

    public static function availableIn(): array
    {
        return ['oxygen'];
    }
}

class MissingPathElement extends FakeBaseElement
{
    public static function dynamicPropertyPaths(): array
    {
        return [
            ['path' => 'content.content.text'],
        ];
    }

    public static function availableIn(): array
    {
        return ['oxygen'];
    }
}

class WrongAvailabilityElement extends FakeBaseElement
{
    public static function dynamicPropertyPaths(): array
    {
        return [
            ['path' => 'content.content.text'],
            ['path' => 'content.content.link.url'],
        ];
    }

    public static function availableIn(): array
    {
        return ['breakdance'];
    }
}

class ThrowingDynamicPathsElement extends FakeBaseElement
{
    public static function dynamicPropertyPaths(): array
    {
        throw new \RuntimeException('boom');
    }

    public static function availableIn(): array
    {
        return ['oxygen'];
    }
}

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\BuilderContractService;
use OxyHtmlConverter\Services\OxygenStorageContract;
use OxyHtmlConverter\Tests\TestCase;

class BuilderContractServiceTest extends TestCase
{
    private BuilderContractService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BuilderContractService();
    }

    public function testEvaluateElementContractPassesForCompatibleClass(): void
    {
        $status = $this->service->evaluateElementContract(
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\GoodElement',
            ['content.content.text', 'content.content.link.url'],
            'oxygen',
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\FakeBaseElement'
        );

        $this->assertTrue($status['compatible']);
        $this->assertEmpty($status['issues']);
    }

    public function testEvaluateElementContractFailsWhenClassIsMissing(): void
    {
        $status = $this->service->evaluateElementContract(
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\MissingClass'
        );

        $this->assertFalse($status['compatible']);
        $this->assertNotEmpty($status['issues']);
    }

    public function testEvaluateElementContractFailsWhenRequiredPathMissing(): void
    {
        $status = $this->service->evaluateElementContract(
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\MissingPathElement',
            ['content.content.text', 'content.content.link.url'],
            'oxygen',
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\FakeBaseElement'
        );

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('content.content.link.url', implode(' ', $status['issues']));
    }

    public function testEvaluateElementContractFailsWhenAvailabilityDoesNotIncludeTarget(): void
    {
        $status = $this->service->evaluateElementContract(
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\WrongAvailabilityElement',
            ['content.content.text', 'content.content.link.url'],
            'oxygen',
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\FakeBaseElement'
        );

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('not available in "oxygen"', implode(' ', $status['issues']));
    }

    public function testEvaluateElementContractHandlesDynamicPathExceptions(): void
    {
        $status = $this->service->evaluateElementContract(
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\ThrowingDynamicPathsElement',
            ['content.content.text'],
            'oxygen',
            '\\OxyHtmlConverter\\Tests\\Unit\\Services\\Fixtures\\FakeBaseElement'
        );

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('dynamicPropertyPaths() failed', implode(' ', $status['issues']));
    }

    public function testEvaluateOxygenStorageFixturesPassesForCompleteFixtureSet(): void
    {
        $status = $this->service->evaluateOxygenStorageFixtures($this->fixtureDir());

        $this->assertTrue($status['compatible']);
        $this->assertSame(OxygenStorageContract::SUPPORTED_OXYGEN_VERSION, $status['oxygenVersion']);
        $this->assertSame(array_keys(OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES), array_keys($status['contracts']));
        $this->assertEmpty($status['issues']);
    }

    public function testEvaluateOxygenStorageFixturesFailsForMissingContract(): void
    {
        $tmp = $this->copyFixturesToTempDir();
        unlink($tmp . DIRECTORY_SEPARATOR . OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES['variables']);

        $status = $this->service->evaluateOxygenStorageFixtures($tmp);

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('Missing Oxygen storage contract fixture "variables"', implode(' ', $status['issues']));
    }

    public function testEvaluateOxygenStorageFixturesFailsForMalformedJson(): void
    {
        $tmp = $this->copyFixturesToTempDir();
        file_put_contents($tmp . DIRECTORY_SEPARATOR . OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES['selectors'], '{bad json');

        $status = $this->service->evaluateOxygenStorageFixtures($tmp);

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('selectors', implode(' ', $status['issues']));
        $this->assertStringContainsString('invalid JSON', implode(' ', $status['issues']));
    }

    public function testEvaluateOxygenStorageFixturesFailsForContractMismatch(): void
    {
        $tmp = $this->copyFixturesToTempDir();
        $file = $tmp . DIRECTORY_SEPARATOR . OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES['selectors'];
        $fixture = json_decode((string) file_get_contents($file), true);
        $fixture['contract'] = 'wrong-contract';
        file_put_contents($file, wp_json_encode($fixture));

        $status = $this->service->evaluateOxygenStorageFixtures($tmp);

        $this->assertFalse($status['compatible']);
        $this->assertStringContainsString('declares contract "wrong-contract"', implode(' ', $status['issues']));
    }

    public function testEvaluateOxygenStorageFixturesFailsForMissingSourceFilesOrPayload(): void
    {
        $tmp = $this->copyFixturesToTempDir();
        $file = $tmp . DIRECTORY_SEPARATOR . OxygenStorageContract::REQUIRED_CONTRACT_FIXTURES['variables'];
        $fixture = json_decode((string) file_get_contents($file), true);
        $fixture['sourceFiles'] = [];
        unset($fixture['payload']);
        file_put_contents($file, wp_json_encode($fixture));

        $status = $this->service->evaluateOxygenStorageFixtures($tmp);

        $this->assertFalse($status['compatible']);
        $joined = implode(' ', $status['issues']);
        $this->assertStringContainsString('must list sourceFiles', $joined);
        $this->assertStringContainsString('must contain an array payload', $joined);
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'oxygen6-contracts';
    }

    private function copyFixturesToTempDir(): string
    {
        $source = $this->fixtureDir();
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ohc-storage-contract-' . bin2hex(random_bytes(4));
        mkdir($target);

        foreach (glob($source . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            copy($file, $target . DIRECTORY_SEPARATOR . basename($file));
        }

        return $target;
    }
}
