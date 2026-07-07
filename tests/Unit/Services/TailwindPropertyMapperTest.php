<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\TailwindPropertyMapper;
use PHPUnit\Framework\TestCase;

class TailwindPropertyMapperTest extends TestCase
{
    private TailwindPropertyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new TailwindPropertyMapper();
    }

    public function testMapsBaseUtilitiesToNativeOxygenProperties(): void
    {
        $properties = [];

        foreach ([
            'grid',
            'grid-cols-3',
            'gap-4',
            'gap-x-4',
            'gap-y-8',
            'p-4',
            'mx-auto',
            'w-1/3',
            'max-w-screen-2xl',
            'text-6xl',
            'font-bold',
            'leading-tight',
            'tracking-wide',
            'text-stone-500',
            'bg-[#ff0084]',
            'border-b',
            'border-[#ff0084]',
            'rounded-lg',
            'opacity-50',
        ] as $className) {
            $properties = $this->merge($properties, $this->mapper->mapClass($className));
        }

        $this->assertSame('grid', $properties['layout']['display']);
        $this->assertSame('3', $properties['layout']['grid']['simple_grid_template_columns']);
        $this->assertSame('1rem', $properties['layout']['gap']['column']['style']);
        $this->assertSame('2rem', $properties['layout']['gap']['row']['style']);
        $this->assertSame('1rem', $properties['spacing']['spacing']['padding']['top']['style']);
        $this->assertSame('auto', $properties['spacing']['spacing']['margin']['left']['style']);
        $this->assertSame('33.333333%', $properties['size']['width']['style']);
        $this->assertSame('1536px', $properties['size']['max_width']['style']);
        $this->assertSame('3.75rem', $properties['typography']['font_size']['style']);
        $this->assertSame(700, $properties['typography']['font_weight']);
        $this->assertSame('1.25', $properties['typography']['line_height']['style']);
        $this->assertSame('0.025em', $properties['typography']['letter_spacing']['style']);
        $this->assertSame('#78716CFF', $properties['typography']['color']);
        $this->assertSame('#FF0084FF', $properties['background']['background_color']);
        $this->assertSame('1px', $properties['borders']['borders']['bottom']['width']['style']);
        $this->assertSame('#FF0084FF', $properties['borders']['borders']['top']['color']);
        $this->assertSame('0.5rem', $properties['borders']['border_radius']['all']['style']);
        $this->assertSame(50, $properties['effects']['opacity']);
    }

    public function testRejectsVariantUtilitiesAndInvalidArbitraryValues(): void
    {
        $this->assertSame([], $this->mapper->mapClass('md:grid-cols-3'));
        $this->assertSame([], $this->mapper->mapClass('hover:bg-[#ff0084]'));
        $this->assertSame([], $this->mapper->mapClass('grid-cols-[minmax(nonsense,1fr)]'));
        $this->assertSame([], $this->mapper->mapClass('text-[url(javascript:alert(1))]'));
        $this->assertSame([], $this->mapper->mapClass('p-[1rem;}body{color:red]'));
    }

    public function testReportsCoreOnlyNativeMappingCapabilities(): void
    {
        $capabilities = $this->mapper->getIntegrationCapabilities();

        $this->assertSame('core_native_property_mapping', $capabilities['scope']);
        $this->assertFalse($capabilities['runtimeDependency']);
        $this->assertFalse($capabilities['fullUtilityParity']);
        $this->assertFalse($capabilities['variantMapping']);
        $this->assertSame('oxy_html_converter_convert_options', $capabilities['extensionPoint']);
    }

    public function testMapsUnitlessLeadingUtilitiesWithoutAddingPx(): void
    {
        $properties = $this->mapper->mapClass('leading-tight');

        $this->assertSame('1.25', $properties['typography']['line_height']['style']);
        $this->assertSame('custom', $properties['typography']['line_height']['unit']);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->merge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
