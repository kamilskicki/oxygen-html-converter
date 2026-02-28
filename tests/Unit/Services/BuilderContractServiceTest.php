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
}

