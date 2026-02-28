<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class TreeBuilderElementMappingTest extends TestCase
{
    public function testTreeBuilderKeepsOxygenButtonMappingByDefault(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<button>Click me</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
    }

    public function testTreeBuilderCanPreferEssentialButtonMapping(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button onclick="location=\'https://example.com\'">Buy now</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('EssentialElements\\Button', $result['element']['data']['type']);
        $this->assertSame('Buy now', $result['element']['data']['properties']['content']['content']['text']);
        $this->assertSame(
            'https://example.com',
            $result['element']['data']['properties']['content']['content']['link']['url']
        );
        $this->assertEmpty($result['element']['children']);
    }
}
