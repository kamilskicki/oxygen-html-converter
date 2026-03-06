<?php

namespace OxyHtmlConverter\Services;

/**
 * Generates a small fallback CSS layer for commonly used Tailwind utilities.
 *
 * This is intentionally limited to typography/display helpers that frequently
 * lose against Oxygen's base styles or are not compiled by third-party Tailwind
 * runtimes in builder-rendered markup.
 */
class TailwindCssFallbackGenerator
{
    private const BREAKPOINTS = [
        'sm' => '640px',
        'md' => '768px',
        'lg' => '1024px',
        'xl' => '1280px',
        '2xl' => '1536px',
    ];

    private const FONT_SIZES = [
        'text-xs' => ['0.75rem', '1rem'],
        'text-sm' => ['0.875rem', '1.25rem'],
        'text-base' => ['1rem', '1.5rem'],
        'text-lg' => ['1.125rem', '1.75rem'],
        'text-xl' => ['1.25rem', '1.75rem'],
        'text-2xl' => ['1.5rem', '2rem'],
        'text-3xl' => ['1.875rem', '2.25rem'],
        'text-4xl' => ['2.25rem', '2.5rem'],
        'text-5xl' => ['3rem', '1'],
        'text-6xl' => ['3.75rem', '1'],
        'text-7xl' => ['4.5rem', '1'],
        'text-8xl' => ['6rem', '1'],
        'text-9xl' => ['8rem', '1'],
    ];

    private const FONT_WEIGHTS = [
        'font-light' => '300',
        'font-normal' => '400',
        'font-medium' => '500',
        'font-semibold' => '600',
        'font-bold' => '700',
        'font-extrabold' => '800',
        'font-black' => '900',
    ];

    private const LINE_HEIGHTS = [
        'leading-none' => '1',
        'leading-tight' => '1.25',
        'leading-snug' => '1.375',
        'leading-normal' => '1.5',
        'leading-relaxed' => '1.625',
        'leading-loose' => '2',
    ];

    private const LETTER_SPACING = [
        'tracking-tighter' => '-0.05em',
        'tracking-tight' => '-0.025em',
        'tracking-normal' => '0em',
        'tracking-wide' => '0.025em',
        'tracking-wider' => '0.05em',
        'tracking-widest' => '0.1em',
    ];

    private const TEXT_TRANSFORMS = [
        'uppercase' => 'uppercase',
        'lowercase' => 'lowercase',
        'capitalize' => 'capitalize',
        'normal-case' => 'none',
    ];

    private const DISPLAYS = [
        'hidden' => 'none',
        'block' => 'block',
        'inline-block' => 'inline-block',
        'inline' => 'inline',
        'flex' => 'flex',
        'inline-flex' => 'inline-flex',
    ];

    private const TEXT_ALIGNS = [
        'text-left' => 'left',
        'text-center' => 'center',
        'text-right' => 'right',
        'text-justify' => 'justify',
    ];

    private const COLORS = [
        'text-white' => '#ffffff',
        'text-black' => '#000000',
        'text-transparent' => 'transparent',
        'text-gray-400' => '#9ca3af',
        'text-gray-500' => '#6b7280',
        'text-gray-600' => '#4b5563',
        'text-gray-700' => '#374151',
        'text-gray-800' => '#1f2937',
        'text-gray-900' => '#111827',
    ];

    /**
     * @param array<int, string> $classTokens
     */
    public function generate(array $classTokens): string
    {
        $rulesByBreakpoint = [];

        foreach (array_values(array_unique($classTokens)) as $classToken) {
            $classToken = trim($classToken);
            if ($classToken === '') {
                continue;
            }

            [$breakpoint, $utility] = $this->splitResponsivePrefix($classToken);
            $declarations = $this->mapUtilityToDeclarations($utility);
            if ($declarations === []) {
                continue;
            }

            $selector = '.' . $this->escapeSelector($classToken);
            $rule = $selector . ' { ' . implode(' ', $declarations) . ' }';
            $rulesByBreakpoint[$breakpoint][] = $rule;
        }

        if ($rulesByBreakpoint === []) {
            return '';
        }

        $css = [];

        foreach ($rulesByBreakpoint['base'] ?? [] as $rule) {
            $css[] = $rule;
        }

        foreach (self::BREAKPOINTS as $prefix => $minWidth) {
            if (empty($rulesByBreakpoint[$prefix])) {
                continue;
            }

            $css[] = '@media (min-width: ' . $minWidth . ') {';
            foreach ($rulesByBreakpoint[$prefix] as $rule) {
                $css[] = '  ' . $rule;
            }
            $css[] = '}';
        }

        return implode("\n", $css);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitResponsivePrefix(string $classToken): array
    {
        if (preg_match('/^(sm|md|lg|xl|2xl):(.*)$/', $classToken, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['base', $classToken];
    }

    /**
     * @return array<int, string>
     */
    private function mapUtilityToDeclarations(string $utility): array
    {
        $declarations = [];

        if (isset(self::FONT_SIZES[$utility])) {
            [$fontSize, $lineHeight] = self::FONT_SIZES[$utility];
            $declarations[] = 'font-size: ' . $fontSize . ' !important;';
            $declarations[] = 'line-height: ' . $lineHeight . ' !important;';
            return $declarations;
        }

        if (isset(self::FONT_WEIGHTS[$utility])) {
            return ['font-weight: ' . self::FONT_WEIGHTS[$utility] . ' !important;'];
        }

        if (isset(self::LINE_HEIGHTS[$utility])) {
            return ['line-height: ' . self::LINE_HEIGHTS[$utility] . ' !important;'];
        }

        if (isset(self::LETTER_SPACING[$utility])) {
            return ['letter-spacing: ' . self::LETTER_SPACING[$utility] . ' !important;'];
        }

        if (isset(self::TEXT_TRANSFORMS[$utility])) {
            return ['text-transform: ' . self::TEXT_TRANSFORMS[$utility] . ' !important;'];
        }

        if (isset(self::DISPLAYS[$utility])) {
            return ['display: ' . self::DISPLAYS[$utility] . ' !important;'];
        }

        if (isset(self::TEXT_ALIGNS[$utility])) {
            return ['text-align: ' . self::TEXT_ALIGNS[$utility] . ' !important;'];
        }

        if (isset(self::COLORS[$utility])) {
            return ['color: ' . self::COLORS[$utility] . ' !important;'];
        }

        if ($utility === 'italic') {
            return ['font-style: italic !important;'];
        }

        if ($utility === 'not-italic') {
            return ['font-style: normal !important;'];
        }

        if (preg_match('/^leading-\[(.+)\]$/', $utility, $matches)) {
            return ['line-height: ' . $this->normalizeArbitraryValue($matches[1]) . ' !important;'];
        }

        if (preg_match('/^tracking-\[(.+)\]$/', $utility, $matches)) {
            return ['letter-spacing: ' . $this->normalizeArbitraryValue($matches[1]) . ' !important;'];
        }

        if (preg_match('/^text-\[(.+)\]$/', $utility, $matches)) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($value)) {
                return ['color: ' . $value . ' !important;'];
            }

            if ($this->looksLikeMeasurement($value)) {
                return ['font-size: ' . $value . ' !important;'];
            }
        }

        return [];
    }

    private function normalizeArbitraryValue(string $value): string
    {
        return str_replace('_', ' ', trim($value));
    }

    private function looksLikeColor(string $value): bool
    {
        return (bool) preg_match('/^(#|rgb|hsl|var\(|transparent\b)/i', $value);
    }

    private function looksLikeMeasurement(string $value): bool
    {
        return (bool) preg_match('/^-?\d*\.?\d+(px|rem|em|vw|vh|%|ch|ex)?$/i', $value);
    }

    private function escapeSelector(string $className): string
    {
        return preg_replace_callback(
            '/([^a-zA-Z0-9_-])/',
            static fn (array $matches): string => '\\' . $matches[1],
            $className
        ) ?? $className;
    }
}
