<?php

namespace OxyHtmlConverter\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to get private/protected properties for testing.
     *
     * @param object $object
     * @param string $propertyName
     * @return mixed
     */
    protected function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}