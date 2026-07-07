<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use PHPUnit\Framework\TestCase;

class TailwindCssFallbackGeneratorTest extends TestCase
{
    public function testReportsCoreFallbackPolicyWithoutRuntimeDependency(): void
    {
        $generator = new TailwindCssFallbackGenerator();
        $policy = $generator->getFallbackPolicy();

        $this->assertSame('core_safety_css', $policy['scope']);
        $this->assertFalse($policy['runtimeDependency']);
        $this->assertSame('page_css', $policy['defaultDestination']);
        $this->assertSame('page_scoped_styles', $policy['windPressDestination']);
        $this->assertSame('oxy_html_converter_convert_options', $policy['extensionPoint']);
    }

    public function testGeneratesBaseAndResponsiveTypographyRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'text-6xl',
            'md:text-8xl',
            'lg:text-9xl',
            'text-white',
            'text-stone-500',
            'bg-oxblood-primary',
            'leading-[0.9]',
            'tracking-tight',
            'uppercase',
            'hidden',
            'md:flex',
            'md:flex-row',
            'md:justify-between',
            'md:items-end',
        ]);

        $this->assertStringContainsString('*, ::before, ::after { box-sizing: border-box; }', $css);
        $this->assertStringContainsString('img, svg, video, canvas { display: block; max-width: 100%; }', $css);
        $this->assertStringContainsString('html, body { overflow-x: hidden; }', $css);
        $this->assertStringContainsString('.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; color: inherit !important; }', $css);
        $this->assertStringContainsString('.text-white { color: #ffffff !important; }', $css);
        $this->assertStringContainsString('.text-stone-500 { color: #78716c !important; }', $css);
        $this->assertStringContainsString('.bg-oxblood-primary { background-color: #731B19 !important; }', $css);
        $this->assertStringContainsString('.leading-\[0\.9\] { line-height: 0.9 !important; }', $css);
        $this->assertStringContainsString('.tracking-tight { letter-spacing: -0.025em !important; }', $css);
        $this->assertStringContainsString('.uppercase { text-transform: uppercase !important; }', $css);
        $this->assertStringContainsString('.hidden { display: none !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('.md\:text-8xl { font-size: 6rem !important; line-height: 1 !important; color: inherit !important; }', $css);
        $this->assertStringContainsString('.md\:flex { display: flex !important; }', $css);
        $this->assertStringContainsString('.md\:flex-row { flex-direction: row !important; }', $css);
        $this->assertStringContainsString('.md\:justify-between { justify-content: space-between !important; }', $css);
        $this->assertStringContainsString('.md\:items-end { align-items: flex-end !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
        $this->assertStringContainsString('.lg\:text-9xl { font-size: 8rem !important; line-height: 1 !important; color: inherit !important; }', $css);
    }

    public function testGeneratesArbitraryTextColorAndSizeRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'text-[#ff0084]',
            'text-[10px]',
            'italic',
        ]);

        $this->assertStringContainsString('.text-\[\#ff0084\] { color: #ff0084 !important; }', $css);
        $this->assertStringContainsString('.text-\[10px\] { font-size: 10px !important; color: inherit !important; }', $css);
        $this->assertStringContainsString('.italic { font-style: italic !important; }', $css);
    }

    public function testTypographyUtilitiesPreserveInheritedTextColorAgainstBuilderHeadingDefaults(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'text-6xl',
            'md:text-7xl',
            'text-black',
            'text-white',
        ]);

        $this->assertStringContainsString('.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; color: inherit !important; }', $css);
        $this->assertStringContainsString('.md\:text-7xl { font-size: 4.5rem !important; line-height: 1 !important; color: inherit !important; }', $css);
        $this->assertStringContainsString('.text-black { color: #000000 !important; }', $css);
        $this->assertStringContainsString('.text-white { color: #ffffff !important; }', $css);
    }

    public function testGeneratesGradientTextRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'text-transparent',
            'bg-clip-text',
            'bg-gradient-to-r',
            'from-white',
            'via-white',
            'to-[#ff0084]',
        ]);

        $this->assertStringContainsString('.text-transparent { color: transparent !important; -webkit-text-fill-color: transparent !important; }', $css);
        $this->assertStringContainsString('.bg-clip-text { background-clip: text !important; -webkit-background-clip: text !important; }', $css);
        $this->assertStringContainsString('.bg-gradient-to-r { background-image: linear-gradient(to right, var(--tw-gradient-stops)) !important; }', $css);
        $this->assertStringContainsString('.from-white { --tw-gradient-from: #ffffff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(255, 255, 255, 0)) !important; }', $css);
        $this->assertStringContainsString('.via-white { --tw-gradient-stops: var(--tw-gradient-from), #ffffff, var(--tw-gradient-to, rgba(255, 255, 255, 0)) !important; }', $css);
        $this->assertStringContainsString('.to-\[\#ff0084\] { --tw-gradient-to: #ff0084 !important; }', $css);
    }

    public function testGeneratesHoverAndGroupHoverVariantRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'hover:text-white',
            'disabled:bg-[#ff0084]',
            'group-hover:text-gray-200',
            'hover:border-[#ff0084]/50',
            'group-hover:bg-[#ff0084]/20',
            'group-hover:opacity-20',
            'hover:grayscale-0',
            'group-hover:translate-x-2',
            'focus:outline-none',
            'focus:ring-0',
        ]);

        $this->assertStringContainsString('.hover\:text-white:hover { color: #ffffff !important; }', $css);
        $this->assertStringContainsString('.disabled\:bg-\[\#ff0084\]:disabled { background-color: #ff0084 !important; }', $css);
        $this->assertStringContainsString('.group:hover .group-hover\:text-gray-200 { color: #e5e7eb !important; }', $css);
        $this->assertStringContainsString('.hover\:border-\[\#ff0084\]\/50:hover { border-color: rgba(255, 0, 132, 0.500) !important; }', $css);
        $this->assertStringContainsString('.group:hover .group-hover\:bg-\[\#ff0084\]\/20 { background-color: rgba(255, 0, 132, 0.200) !important; }', $css);
        $this->assertStringContainsString('.group:hover .group-hover\:opacity-20 { opacity: 0.2 !important; }', $css);
        $this->assertStringContainsString('.hover\:grayscale-0:hover { filter: grayscale(0) !important; }', $css);
        $this->assertStringContainsString('.group:hover .group-hover\:translate-x-2 { --tw-translate-x: 0.5rem !important; transform: translate(var(--tw-translate-x, 0), var(--tw-translate-y, 0))', $css);
        $this->assertStringContainsString('.focus\:outline-none:focus { outline: 2px solid transparent !important; outline-offset: 2px !important; }', $css);
        $this->assertStringContainsString('.focus\:ring-0:focus { box-shadow: 0 0 #0000 !important; }', $css);
    }

    public function testGeneratesGridSpacingAndResponsiveLayoutRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'grid',
            'grid-cols-1',
            'lg:grid-cols-3',
            'lg:grid-cols-12',
            'lg:col-span-5',
            'lg:col-start-2',
            'gap-8',
            'gap-x-4',
            'gap-y-6',
            'gap-gutter-grid',
            'px-8',
            'py-section-gap',
            'max-w-screen-2xl',
            'mx-auto',
            'w-1/3',
            'h-[600px]',
            '-bottom-6',
            '-inset-4',
            'translate-x-20',
            '-skew-x-12',
            'border-b',
        ]);

        $this->assertStringContainsString('.grid { display: grid !important; }', $css);
        $this->assertStringContainsString('.grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }', $css);
        $this->assertStringContainsString('.gap-8 { gap: 2rem !important; }', $css);
        $this->assertStringContainsString('.gap-x-4 { column-gap: 1rem !important; }', $css);
        $this->assertStringContainsString('.gap-y-6 { row-gap: 1.5rem !important; }', $css);
        $this->assertStringContainsString('.gap-gutter-grid { gap: 24px !important; }', $css);
        $this->assertStringContainsString('.px-8 { padding-left: 2rem !important; padding-right: 2rem !important; }', $css);
        $this->assertStringContainsString('.py-section-gap { padding-top: 120px !important; padding-bottom: 120px !important; }', $css);
        $this->assertStringContainsString('.max-w-screen-2xl { max-width: 1536px !important; }', $css);
        $this->assertStringContainsString('.mx-auto { margin-left: auto !important; margin-right: auto !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
        $this->assertStringContainsString('.lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }', $css);
        $this->assertStringContainsString('.lg\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; }', $css);
        $this->assertStringContainsString('.lg\:col-span-5 { grid-column: span 5 / span 5 !important; }', $css);
        $this->assertStringContainsString('.lg\:col-start-2 { grid-column-start: 2 !important; }', $css);
        $this->assertStringContainsString('.w-1\/3 { width: 33.333333% !important; }', $css);
        $this->assertStringContainsString('.h-\[600px\] { height: 600px !important; }', $css);
        $this->assertStringContainsString('.-bottom-6 { bottom: -1.5rem !important; }', $css);
        $this->assertStringContainsString('.-inset-4 { top: -1rem !important; right: -1rem !important; bottom: -1rem !important; left: -1rem !important; }', $css);
        $this->assertStringContainsString('.translate-x-20 { --tw-translate-x: 5rem !important; transform: translate(var(--tw-translate-x, 0), var(--tw-translate-y, 0))', $css);
        $this->assertStringContainsString('.-skew-x-12 { --tw-skew-x: -12deg !important; transform: translate(var(--tw-translate-x, 0), var(--tw-translate-y, 0))', $css);
        $this->assertStringContainsString('.border-b { border-bottom-width: 1px !important; }', $css);
    }

    public function testRejectsUnsafeArbitraryFallbackValues(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'bg-[#fff;}body{color:red]',
            'p-[1rem;}body{color:red]',
            'grid-cols-[1fr;}body{color:red]',
            'text-[#ff0084]',
        ]);

        $this->assertStringContainsString('.text-\[\#ff0084\] { color: #ff0084 !important; }', $css);
        $this->assertStringNotContainsString('body{color:red', $css);
        $this->assertStringNotContainsString('background-color: #fff', $css);
        $this->assertStringNotContainsString('padding: 1rem', $css);
        $this->assertStringNotContainsString('grid-template-columns: 1fr', $css);
    }
}
