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
        'text-gray-200' => '#e5e7eb',
        'text-gray-400' => '#9ca3af',
        'text-gray-500' => '#6b7280',
        'text-gray-600' => '#4b5563',
        'text-gray-700' => '#374151',
        'text-gray-800' => '#1f2937',
        'text-gray-900' => '#111827',
        'text-neutral-200' => '#e5e5e5',
    ];

    private const GRADIENT_DIRECTIONS = [
        'bg-gradient-to-r' => 'to right',
        'bg-gradient-to-l' => 'to left',
        'bg-gradient-to-t' => 'to top',
        'bg-gradient-to-b' => 'to bottom',
        'bg-gradient-to-tr' => 'to top right',
        'bg-gradient-to-tl' => 'to top left',
        'bg-gradient-to-br' => 'to bottom right',
        'bg-gradient-to-bl' => 'to bottom left',
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

            [$breakpoint, $variant, $utility] = $this->splitUtilityModifiers($classToken);
            $declarations = $this->mapUtilityToDeclarations($utility);
            if ($declarations === []) {
                continue;
            }

            $selector = $this->buildSelector($classToken, $variant);
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
     * @return array{0:string,1:string,2:string}
     */
    private function splitUtilityModifiers(string $classToken): array
    {
        $parts = explode(':', $classToken);
        if ($parts === []) {
            return ['base', 'base', $classToken];
        }

        $utility = array_pop($parts);
        if (!is_string($utility) || $utility === '') {
            return ['base', 'base', $classToken];
        }

        $breakpoint = 'base';
        $variant = 'base';

        foreach ($parts as $part) {
            if (isset(self::BREAKPOINTS[$part])) {
                $breakpoint = $part;
                continue;
            }

            if (in_array($part, ['hover', 'focus', 'active', 'group-hover'], true)) {
                $variant = $part;
                continue;
            }

            $utility = $part . ':' . $utility;
        }

        return [$breakpoint, $variant, $utility];
    }

    private function buildSelector(string $classToken, string $variant): string
    {
        $escaped = '.' . $this->escapeSelector($classToken);

        switch ($variant) {
            case 'hover':
                return $escaped . ':hover';

            case 'focus':
                return $escaped . ':focus';

            case 'active':
                return $escaped . ':active';

            case 'group-hover':
                return '.group:hover ' . $escaped;

            default:
                return $escaped;
        }
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
            $declarations[] = 'color: inherit !important;';
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

        if ($utility === 'text-transparent') {
            return [
                'color: transparent !important;',
                '-webkit-text-fill-color: transparent !important;',
            ];
        }

        if (isset(self::COLORS[$utility])) {
            return ['color: ' . self::COLORS[$utility] . ' !important;'];
        }

        if (preg_match('/^text-(.+)$/', $utility, $matches)) {
            $color = $this->resolveUtilityColorValue($matches[1], 'text');
            if ($color !== null) {
                return ['color: ' . $color . ' !important;'];
            }
        }

        if ($utility === 'italic') {
            return ['font-style: italic !important;'];
        }

        if ($utility === 'not-italic') {
            return ['font-style: normal !important;'];
        }

        if ($utility === 'bg-clip-text') {
            return [
                'background-clip: text !important;',
                '-webkit-background-clip: text !important;',
            ];
        }

        if (preg_match('/^bg-(.+)$/', $utility, $matches)) {
            $color = $this->resolveUtilityColorValue($matches[1], 'background');
            if ($color !== null) {
                return ['background-color: ' . $color . ' !important;'];
            }
        }

        if (preg_match('/^border-(.+)$/', $utility, $matches)) {
            $color = $this->resolveUtilityColorValue($matches[1], 'border');
            if ($color !== null) {
                return ['border-color: ' . $color . ' !important;'];
            }
        }

        if (preg_match('/^opacity-(\d{1,3})$/', $utility, $matches)) {
            $value = max(0, min(100, (int) $matches[1])) / 100;
            return ['opacity: ' . rtrim(rtrim(sprintf('%.2F', $value), '0'), '.') . ' !important;'];
        }

        if ($utility === 'grayscale-0') {
            return ['filter: grayscale(0) !important;'];
        }

        if (preg_match('/^scale-(\d{2,3})$/', $utility, $matches)) {
            $value = ((int) $matches[1]) / 100;
            return ['transform: scale(' . rtrim(rtrim(sprintf('%.2F', $value), '0'), '.') . ') !important;'];
        }

        if (preg_match('/^translate-x-(\d+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingScale((int) $matches[1]);
            if ($value !== null) {
                return ['transform: translateX(' . $value . ') !important;'];
            }
        }

        if (preg_match('/^translate-y-(\d+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingScale((int) $matches[1]);
            if ($value !== null) {
                return ['transform: translateY(' . $value . ') !important;'];
            }
        }

        if ($utility === 'outline-none') {
            return [
                'outline: 2px solid transparent !important;',
                'outline-offset: 2px !important;',
            ];
        }

        if ($utility === 'ring-0') {
            return ['box-shadow: 0 0 #0000 !important;'];
        }

        if (isset(self::GRADIENT_DIRECTIONS[$utility])) {
            return ['background-image: linear-gradient(' . self::GRADIENT_DIRECTIONS[$utility] . ', var(--tw-gradient-stops)) !important;'];
        }

        if (preg_match('/^from-(.+)$/', $utility, $matches)) {
            $color = $this->resolveGradientColorValue($matches[1]);
            if ($color !== null) {
                return [
                    '--tw-gradient-from: ' . $color . ' !important;',
                    '--tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(255, 255, 255, 0)) !important;',
                ];
            }
        }

        if (preg_match('/^via-(.+)$/', $utility, $matches)) {
            $color = $this->resolveGradientColorValue($matches[1]);
            if ($color !== null) {
                return ['--tw-gradient-stops: var(--tw-gradient-from), ' . $color . ', var(--tw-gradient-to, rgba(255, 255, 255, 0)) !important;'];
            }
        }

        if (preg_match('/^to-(.+)$/', $utility, $matches)) {
            $color = $this->resolveGradientColorValue($matches[1]);
            if ($color !== null) {
                return ['--tw-gradient-to: ' . $color . ' !important;'];
            }
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
                return [
                    'font-size: ' . $value . ' !important;',
                    'color: inherit !important;',
                ];
            }
        }

        return [];
    }

    private function resolveGradientColorValue(string $value): ?string
    {
        return $this->resolveUtilityColorValue($value, 'text');
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

    private function resolveUtilityColorValue(string $value, string $context = 'text'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $opacity = null;
        if (preg_match('/^(.+)\/(\d{1,3})$/', $value, $matches)) {
            $value = trim($matches[1]);
            $opacity = max(0, min(100, (int) $matches[2])) / 100;
        }

        $directKey = $context . '-' . $value;
        if (isset(self::COLORS[$directKey])) {
            return $this->applyOpacityToColor(self::COLORS[$directKey], $opacity);
        }

        $namedColorKey = 'text-' . $value;
        if (isset(self::COLORS[$namedColorKey])) {
            return $this->applyOpacityToColor(self::COLORS[$namedColorKey], $opacity);
        }

        if (preg_match('/^\[(.+)\]$/', $value, $matches)) {
            $normalized = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($normalized)) {
                return $this->applyOpacityToColor($normalized, $opacity);
            }
        }

        if ($this->looksLikeColor($value)) {
            return $this->applyOpacityToColor($value, $opacity);
        }

        return null;
    }

    private function applyOpacityToColor(string $color, ?float $opacity): string
    {
        if ($opacity === null) {
            return $color;
        }

        if (preg_match('/^#([a-f0-9]{6})$/i', $color, $matches)) {
            $hex = $matches[1];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return sprintf('rgba(%d, %d, %d, %.3F)', $r, $g, $b, $opacity);
        }

        if (preg_match('/^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*\/\s*([^)]+)\)$/i', $color, $matches)) {
            return sprintf('rgba(%d, %d, %d, %.3F)', (int) $matches[1], (int) $matches[2], (int) $matches[3], $opacity);
        }

        if (preg_match('/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $color, $matches)) {
            return sprintf('rgba(%d, %d, %d, %.3F)', (int) $matches[1], (int) $matches[2], (int) $matches[3], $opacity);
        }

        return $color;
    }

    private function resolveSpacingScale(int $step): ?string
    {
        $scale = [
            0 => '0px',
            1 => '0.25rem',
            2 => '0.5rem',
            3 => '0.75rem',
            4 => '1rem',
            5 => '1.25rem',
            6 => '1.5rem',
            8 => '2rem',
            10 => '2.5rem',
            12 => '3rem',
        ];

        return $scale[$step] ?? null;
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
