<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\Services\TailwindDetector;

class TailwindDetectorTest extends TestCase
{
    private TailwindDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new TailwindDetector();
    }

    /**
     * @dataProvider tailwindClassesProvider
     */
    public function testIsTailwindClass(string $className, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->detector->isTailwindClass($className),
            "Class '{$className}' should " . ($expected ? '' : 'not ') . 'be detected as Tailwind'
        );
    }

    public static function tailwindClassesProvider(): array
    {
        return [
            // Layout utilities
            'flex' => ['flex', true],
            'grid' => ['grid', true],
            'block' => ['block', true],
            'inline' => ['inline', true],
            'hidden' => ['hidden', true],
            'container' => ['container', true],
            'flex-col' => ['flex-col', true],
            'grid-cols-3' => ['grid-cols-3', true],
            'gap-4' => ['gap-4', true],
            'justify-center' => ['justify-center', true],
            'items-center' => ['items-center', true],

            // Spacing utilities
            'p-4' => ['p-4', true],
            'm-2' => ['m-2', true],
            'px-6' => ['px-6', true],
            'py-3' => ['py-3', true],
            'mt-8' => ['mt-8', true],
            'mb-4' => ['mb-4', true],
            'ml-auto' => ['ml-auto', true],
            'mr-2' => ['mr-2', true],
            'space-x-4' => ['space-x-4', true],
            'space-y-2' => ['space-y-2', true],

            // Sizing utilities
            'w-full' => ['w-full', true],
            'h-screen' => ['h-screen', true],
            'min-w-0' => ['min-w-0', true],
            'max-w-lg' => ['max-w-lg', true],
            'size-6' => ['size-6', true],

            // Typography utilities
            'text-lg' => ['text-lg', true],
            'text-center' => ['text-center', true],
            'font-bold' => ['font-bold', true],
            'leading-tight' => ['leading-tight', true],
            'tracking-wide' => ['tracking-wide', true],
            'uppercase' => ['uppercase', true],
            'lowercase' => ['lowercase', true],
            'capitalize' => ['capitalize', true],
            'truncate' => ['truncate', true],

            // Background utilities
            'bg-white' => ['bg-white', true],
            'bg-blue-500' => ['bg-blue-500', true],
            'bg-gradient-to-r' => ['bg-gradient-to-r', true],
            'from-blue-500' => ['from-blue-500', true],
            'via-purple-500' => ['via-purple-500', true],
            'to-pink-500' => ['to-pink-500', true],

            // Border utilities
            'border' => ['border', true],
            'border-2' => ['border-2', true],
            'border-gray-300' => ['border-gray-300', true],
            'rounded' => ['rounded', true],
            'rounded-lg' => ['rounded-lg', true],
            'rounded-full' => ['rounded-full', true],
            'ring-2' => ['ring-2', true],

            // Effects utilities
            'shadow-lg' => ['shadow-lg', true],
            'shadow-md' => ['shadow-md', true],
            'opacity-50' => ['opacity-50', true],

            // Position utilities
            'relative' => ['relative', true],
            'absolute' => ['absolute', true],
            'fixed' => ['fixed', true],
            'sticky' => ['sticky', true],
            'static' => ['static', true],
            'top-0' => ['top-0', true],
            'inset-0' => ['inset-0', true],
            'z-10' => ['z-10', true],

            // Responsive prefixes
            'sm:flex' => ['sm:flex', true],
            'md:hidden' => ['md:hidden', true],
            'lg:grid-cols-3' => ['lg:grid-cols-3', true],
            'xl:text-2xl' => ['xl:text-2xl', true],
            '2xl:px-8' => ['2xl:px-8', true],

            // State variants
            'hover:bg-blue-600' => ['hover:bg-blue-600', true],
            'focus:ring-2' => ['focus:ring-2', true],
            'active:scale-95' => ['active:scale-95', true],
            'disabled:opacity-50' => ['disabled:opacity-50', true],
            'group-hover:visible' => ['group-hover:visible', true],

            // Dark mode
            'dark:bg-gray-900' => ['dark:bg-gray-900', true],
            'dark:text-white' => ['dark:text-white', true],

            // Arbitrary values
            'w-[100px]' => ['w-[100px]', true],
            'bg-[#ff0084]' => ['bg-[#ff0084]', true],
            'text-[14px]' => ['text-[14px]', true],
            'top-[calc(100%-1rem)]' => ['top-[calc(100%-1rem)]', true],

            // Opacity modifiers
            'text-white/50' => ['text-white/50', true],
            'bg-black/75' => ['bg-black/75', true],
            'border-gray-500/30' => ['border-gray-500/30', true],

            // Complex Tailwind classes
            'hover:border-[#ff0084]/50' => ['hover:border-[#ff0084]/50', true],

            // Negative values
            '-mt-4' => ['-mt-4', true],
            '-translate-x-1/2' => ['-translate-x-1/2', true],
            '-z-10' => ['-z-10', true],

            // Transitions & animations
            'transition-all' => ['transition-all', true],
            'transition-colors' => ['transition-colors', true],
            'duration-300' => ['duration-300', true],
            'ease-in-out' => ['ease-in-out', true],
            'animate-spin' => ['animate-spin', true],

            // Transform utilities
            'scale-95' => ['scale-95', true],
            'rotate-45' => ['rotate-45', true],
            'translate-x-4' => ['translate-x-4', true],
            'transform' => ['transform', true],

            // Visibility
            'visible' => ['visible', true],
            'invisible' => ['invisible', true],

            // Non-Tailwind classes (should return false)
            'header' => ['header', false],
            'nav-link' => ['nav-link', false],
            'card-body' => ['card-body', false],
            'glass-panel' => ['glass-panel', false],
            'some-random-class' => ['some-random-class', false],
            'hero-section' => ['hero-section', false],
        ];
    }

    public function testArbitraryValueDetection(): void
    {
        // Various arbitrary value formats
        $this->assertTrue($this->detector->isTailwindClass('w-[200px]'));
        $this->assertTrue($this->detector->isTailwindClass('h-[50vh]'));
        $this->assertTrue($this->detector->isTailwindClass('bg-[url("/img/hero.jpg")]'));
        $this->assertTrue($this->detector->isTailwindClass('grid-cols-[1fr_2fr]'));
    }

    public function testNegativeValueDetection(): void
    {
        $this->assertTrue($this->detector->isTailwindClass('-ml-4'));
        $this->assertTrue($this->detector->isTailwindClass('-top-2'));
        $this->assertTrue($this->detector->isTailwindClass('-skew-y-6'));
    }

    public function testCombinedModifiers(): void
    {
        // Classes with multiple modifiers
        $this->assertTrue($this->detector->isTailwindClass('sm:hover:bg-blue-500'));
        $this->assertTrue($this->detector->isTailwindClass('dark:hover:text-white'));
        $this->assertTrue($this->detector->isTailwindClass('lg:focus:ring-2'));
    }
}
