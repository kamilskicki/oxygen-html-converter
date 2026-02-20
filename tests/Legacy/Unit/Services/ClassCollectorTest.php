<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\ClassCollector;
use OxyHtmlConverter\Tests\TestCase;

class ClassCollectorTest extends TestCase
{
    private ClassCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new ClassCollector();
    }

    public function testGenerateAllSkipsUtilityClassNamesAndSanitizesSelectorName(): void
    {
        $this->collector->addHtmlClassNames(1, ['md:w-1/2', '[state=open]', 'Hero@CTA']);
        $this->collector->addDesignProperties(1, ['color' => 'red']);

        $result = $this->collector->generateAll();

        $this->assertCount(1, $result['definitions']);
        $this->assertEquals('hero-cta', $result['definitions'][0]['name']);
    }

    public function testGenerateAllFallsBackWhenOnlyUtilityNamesExist(): void
    {
        $this->collector->addHtmlClassNames(1, ['md:w-1/2', 'text-center', 'hover:bg-red-500']);
        $this->collector->addDesignProperties(1, ['font-size' => '16px']);

        $result = $this->collector->generateAll();

        $this->assertCount(1, $result['definitions']);
        $this->assertStringStartsWith('converted-', $result['definitions'][0]['name']);
    }

    public function testGenerateAllEnsuresUniqueNamesForDifferentDeclarations(): void
    {
        $this->collector->addHtmlClassNames(10, ['card']);
        $this->collector->addDesignProperties(10, ['color' => 'red']);

        $this->collector->addHtmlClassNames(20, ['card']);
        $this->collector->addDesignProperties(20, ['color' => 'blue']);

        $result = $this->collector->generateAll();

        $this->assertCount(2, $result['definitions']);
        $names = array_column($result['definitions'], 'name');

        $this->assertContains('card', $names);
        $this->assertCount(2, array_unique($names));

        $collisionName = $names[0] === 'card' ? $names[1] : $names[0];
        $this->assertMatchesRegularExpression('/^card-[a-f0-9]{6}(?:-\d+)?$/', $collisionName);
    }

    public function testGenerateAllDeduplicatesIdenticalDeclarationsByUuid(): void
    {
        $this->collector->addHtmlClassNames(1, ['card']);
        $this->collector->addDesignProperties(1, ['color' => 'red', 'font-size' => '16px']);

        $this->collector->addHtmlClassNames(2, ['card-alt']);
        $this->collector->addDesignProperties(2, ['font-size' => '16px', 'color' => 'red']);

        $result = $this->collector->generateAll();

        $this->assertCount(1, $result['definitions']);
        $this->assertEquals($result['elementMap'][1][0], $result['elementMap'][2][0]);
    }
}
