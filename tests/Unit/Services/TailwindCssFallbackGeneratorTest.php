<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use PHPUnit\Framework\TestCase;

class TailwindCssFallbackGeneratorTest extends TestCase
{
    public function testGeneratesBaseAndResponsiveTypographyRules(): void
    {
        $generator = new TailwindCssFallbackGenerator();

        $css = $generator->generate([
            'text-6xl',
            'md:text-8xl',
            'lg:text-9xl',
            'text-white',
            'leading-[0.9]',
            'tracking-tight',
            'uppercase',
            'hidden',
            'md:flex',
        ]);

        $this->assertStringContainsString('.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; }', $css);
        $this->assertStringContainsString('.text-white { color: #ffffff !important; }', $css);
        $this->assertStringContainsString('.leading-\[0\.9\] { line-height: 0.9 !important; }', $css);
        $this->assertStringContainsString('.tracking-tight { letter-spacing: -0.025em !important; }', $css);
        $this->assertStringContainsString('.uppercase { text-transform: uppercase !important; }', $css);
        $this->assertStringContainsString('.hidden { display: none !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('.md\:text-8xl { font-size: 6rem !important; line-height: 1 !important; }', $css);
        $this->assertStringContainsString('.md\:flex { display: flex !important; }', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
        $this->assertStringContainsString('.lg\:text-9xl { font-size: 8rem !important; line-height: 1 !important; }', $css);
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
        $this->assertStringContainsString('.text-\[10px\] { font-size: 10px !important; }', $css);
        $this->assertStringContainsString('.italic { font-style: italic !important; }', $css);
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
}
