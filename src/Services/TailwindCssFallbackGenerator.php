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
    private OxygenValueNormalizer $valueNormalizer;

    private const BASE_COMPATIBILITY_CSS = [
        '*, ::before, ::after { box-sizing: border-box; }',
        'img, svg, video, canvas { display: block; max-width: 100%; }',
        'html, body { overflow-x: hidden; }',
    ];

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
        'grid' => 'grid',
    ];

    private const TEXT_ALIGNS = [
        'text-left' => 'left',
        'text-center' => 'center',
        'text-right' => 'right',
        'text-justify' => 'justify',
    ];

    private const FLEX_DIRECTIONS = [
        'flex-row' => 'row',
        'flex-row-reverse' => 'row-reverse',
        'flex-col' => 'column',
        'flex-col-reverse' => 'column-reverse',
    ];

    private const FLEX_WRAPS = [
        'flex-wrap' => 'wrap',
        'flex-wrap-reverse' => 'wrap-reverse',
        'flex-nowrap' => 'nowrap',
    ];

    private const JUSTIFY_CONTENT = [
        'justify-start' => 'flex-start',
        'justify-center' => 'center',
        'justify-end' => 'flex-end',
        'justify-between' => 'space-between',
        'justify-around' => 'space-around',
        'justify-evenly' => 'space-evenly',
    ];

    private const ALIGN_ITEMS = [
        'items-start' => 'flex-start',
        'items-center' => 'center',
        'items-end' => 'flex-end',
        'items-baseline' => 'baseline',
        'items-stretch' => 'stretch',
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
        'text-stone-400' => '#a8a29e',
        'text-stone-500' => '#78716c',
        'text-stone-800' => '#292524',
        'text-stone-950' => '#0c0a09',
        'text-red-500' => '#ef4444',
        'text-red-600' => '#dc2626',
        'text-red-700' => '#b91c1c',
        'text-red-800' => '#991b1b',
        'text-red-900' => '#7f1d1d',
        'text-background' => '#fff8f5',
        'text-brass-accent' => '#9A7440',
        'text-copper-highlight' => '#BE8656',
        'text-ink-black' => '#17120F',
        'text-ink-soft' => '#544B45',
        'text-ivory-base' => '#F3EDE4',
        'text-on-background' => '#201a17',
        'text-on-primary' => '#ffffff',
        'text-on-surface' => '#201a17',
        'text-oxblood-primary' => '#731B19',
        'text-paper-bright' => '#FCF9F4',
        'bg-background' => '#fff8f5',
        'bg-brass-accent' => '#9A7440',
        'bg-copper-highlight' => '#BE8656',
        'bg-ink-black' => '#17120F',
        'bg-ink-soft' => '#544B45',
        'bg-ivory-base' => '#F3EDE4',
        'bg-on-primary' => '#ffffff',
        'bg-on-surface' => '#201a17',
        'bg-oxblood-primary' => '#731B19',
        'bg-paper-bright' => '#FCF9F4',
        'bg-paper-soft' => '#E8DED0',
        'bg-stone-50' => '#fafaf9',
        'bg-stone-950' => '#0c0a09',
        'bg-surface-variant' => '#ece0db',
        'border-brass-accent' => '#9A7440',
        'border-ink-soft' => '#544B45',
        'border-oxblood-primary' => '#731B19',
        'border-paper-soft' => '#E8DED0',
        'border-red-900' => '#7f1d1d',
        'border-stone-200' => '#e7e5e4',
        'border-stone-800' => '#292524',
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

    private const MAX_WIDTHS = [
        'max-w-xs' => '20rem',
        'max-w-sm' => '24rem',
        'max-w-md' => '28rem',
        'max-w-lg' => '32rem',
        'max-w-xl' => '36rem',
        'max-w-2xl' => '42rem',
        'max-w-3xl' => '48rem',
        'max-w-4xl' => '56rem',
        'max-w-5xl' => '64rem',
        'max-w-6xl' => '72rem',
        'max-w-7xl' => '80rem',
        'max-w-screen-sm' => '640px',
        'max-w-screen-md' => '768px',
        'max-w-screen-lg' => '1024px',
        'max-w-screen-xl' => '1280px',
        'max-w-screen-2xl' => '1536px',
    ];

    private const CUSTOM_SPACING = [
        'component-padding' => '16px',
        'gutter-grid' => '24px',
        'margin-page' => '64px',
        'section-gap' => '120px',
        'unit' => '8px',
    ];

    public function __construct(?OxygenValueNormalizer $valueNormalizer = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new OxygenValueNormalizer();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFallbackPolicy(): array
    {
        return [
            'scope' => 'core_safety_css',
            'runtimeDependency' => false,
            'defaultDestination' => 'page_css',
            'windPressDestination' => 'page_scoped_styles',
            'extensionPoint' => 'oxy_html_converter_convert_options',
        ];
    }

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

        $css = self::BASE_COMPATIBILITY_CSS;

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

            if (in_array($part, ['hover', 'focus', 'active', 'disabled', 'visited', 'checked', 'first', 'last', 'odd', 'even', 'group-hover', 'dark'], true)) {
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

            case 'disabled':
                return $escaped . ':disabled';

            case 'visited':
                return $escaped . ':visited';

            case 'checked':
                return $escaped . ':checked';

            case 'first':
                return $escaped . ':first-child';

            case 'last':
                return $escaped . ':last-child';

            case 'odd':
                return $escaped . ':nth-child(odd)';

            case 'even':
                return $escaped . ':nth-child(even)';

            case 'group-hover':
                return '.group:hover ' . $escaped;

            case 'dark':
                return '.dark ' . $escaped;

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

        if (isset(self::FLEX_DIRECTIONS[$utility])) {
            return ['flex-direction: ' . self::FLEX_DIRECTIONS[$utility] . ' !important;'];
        }

        if (isset(self::FLEX_WRAPS[$utility])) {
            return ['flex-wrap: ' . self::FLEX_WRAPS[$utility] . ' !important;'];
        }

        if (isset(self::JUSTIFY_CONTENT[$utility])) {
            return ['justify-content: ' . self::JUSTIFY_CONTENT[$utility] . ' !important;'];
        }

        if (isset(self::ALIGN_ITEMS[$utility])) {
            return ['align-items: ' . self::ALIGN_ITEMS[$utility] . ' !important;'];
        }

        if (isset(self::MAX_WIDTHS[$utility])) {
            return ['max-width: ' . self::MAX_WIDTHS[$utility] . ' !important;'];
        }

        if ($utility === 'mx-auto') {
            return [
                'margin-left: auto !important;',
                'margin-right: auto !important;',
            ];
        }

        if ($utility === 'my-auto') {
            return [
                'margin-top: auto !important;',
                'margin-bottom: auto !important;',
            ];
        }

        if (preg_match('/^grid-cols-(\d+)$/', $utility, $matches)) {
            return ['grid-template-columns: repeat(' . (int) $matches[1] . ', minmax(0, 1fr)) !important;'];
        }

        if (preg_match('/^grid-cols-\[(.+)\]$/', $utility, $matches)) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            return $this->valueNormalizer->normalizeMeasurement($value) === null
                ? []
                : ['grid-template-columns: ' . $value . ' !important;'];
        }

        if (preg_match('/^col-span-(\d+)$/', $utility, $matches)) {
            return ['grid-column: span ' . (int) $matches[1] . ' / span ' . (int) $matches[1] . ' !important;'];
        }

        if (preg_match('/^col-start-(\d+)$/', $utility, $matches)) {
            return ['grid-column-start: ' . (int) $matches[1] . ' !important;'];
        }

        $spacingDeclarations = $this->mapSpacingUtilityToDeclarations($utility);
        if ($spacingDeclarations !== []) {
            return $spacingDeclarations;
        }

        $sizingDeclarations = $this->mapSizingUtilityToDeclarations($utility);
        if ($sizingDeclarations !== []) {
            return $sizingDeclarations;
        }

        $positionDeclarations = $this->mapPositionUtilityToDeclarations($utility);
        if ($positionDeclarations !== []) {
            return $positionDeclarations;
        }

        $transformDeclarations = $this->mapTransformUtilityToDeclarations($utility);
        if ($transformDeclarations !== []) {
            return $transformDeclarations;
        }

        $borderDeclarations = $this->mapBorderUtilityToDeclarations($utility);
        if ($borderDeclarations !== []) {
            return $borderDeclarations;
        }

        if ($utility === 'text-transparent') {
            return [
                'color: transparent !important;',
                '-webkit-text-fill-color: transparent !important;',
            ];
        }

        if (str_starts_with($utility, 'text-') && isset(self::COLORS[$utility])) {
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
            $value = $this->normalizeArbitraryValue($matches[1]);
            return $this->isSafeCssValue($value) ? ['line-height: ' . $value . ' !important;'] : [];
        }

        if (preg_match('/^tracking-\[(.+)\]$/', $utility, $matches)) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            return $this->isSafeCssValue($value) ? ['letter-spacing: ' . $value . ' !important;'] : [];
        }

        if (preg_match('/^text-\[(.+)\]$/', $utility, $matches)) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            if (!$this->isSafeCssValue($value)) {
                return [];
            }

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

    /**
     * @return array<int, string>
     */
    private function mapSizingUtilityToDeclarations(string $utility): array
    {
        if (preg_match('/^(w|h|min-w|min-h|max-w|max-h)-(.+)$/', $utility, $matches) !== 1) {
            return [];
        }

        $property = match ($matches[1]) {
            'w' => 'width',
            'h' => 'height',
            'min-w' => 'min-width',
            'min-h' => 'min-height',
            'max-w' => 'max-width',
            'max-h' => 'max-height',
        };

        if (isset(self::MAX_WIDTHS[$utility])) {
            return [];
        }

        $value = $this->resolveSizeValue($matches[2], $matches[1]);
        if ($value === null) {
            return [];
        }

        return [$property . ': ' . $value . ' !important;'];
    }

    /**
     * @return array<int, string>
     */
    private function mapPositionUtilityToDeclarations(string $utility): array
    {
        if (preg_match('/^(-?)inset(?:-([xy]))?-(.+)$/', $utility, $matches) === 1) {
            $value = $this->resolveSizeValue($matches[3], 'inset');
            if ($value === null) {
                return [];
            }

            if ($matches[1] === '-' && $value !== '0' && $value !== '0px') {
                $value = '-' . ltrim($value, '-');
            }

            $axis = $matches[2];

            return match ($axis) {
                'x' => [
                    'left: ' . $value . ' !important;',
                    'right: ' . $value . ' !important;',
                ],
                'y' => [
                    'top: ' . $value . ' !important;',
                    'bottom: ' . $value . ' !important;',
                ],
                default => [
                    'top: ' . $value . ' !important;',
                    'right: ' . $value . ' !important;',
                    'bottom: ' . $value . ' !important;',
                    'left: ' . $value . ' !important;',
                ],
            };
        }

        if (preg_match('/^(-?)(top|right|bottom|left)-(.+)$/', $utility, $matches) !== 1) {
            return [];
        }

        $value = $this->resolveSizeValue($matches[3], $matches[2]);
        if ($value === null) {
            return [];
        }

        if ($matches[1] === '-' && $value !== '0' && $value !== '0px') {
            $value = '-' . ltrim($value, '-');
        }

        return [$matches[2] . ': ' . $value . ' !important;'];
    }

    /**
     * @return array<int, string>
     */
    private function mapTransformUtilityToDeclarations(string $utility): array
    {
        if (preg_match('/^(-?)translate-(x|y)-(.+)$/', $utility, $matches) === 1) {
            $value = $this->resolveTranslateValue($matches[3]);
            if ($value === null) {
                return [];
            }

            if ($matches[1] === '-' && $value !== '0' && $value !== '0px') {
                $value = '-' . ltrim($value, '-');
            }

            $axis = $matches[2] === 'x' ? 'x' : 'y';
            return [
                '--tw-translate-' . $axis . ': ' . $value . ' !important;',
                $this->tailwindTransformDeclaration(),
            ];
        }

        if (preg_match('/^(-?)skew-(x|y)-(\d+)$/', $utility, $matches) === 1) {
            $value = (int) $matches[3] . 'deg';
            if ($matches[1] === '-') {
                $value = '-' . $value;
            }

            return [
                '--tw-skew-' . $matches[2] . ': ' . $value . ' !important;',
                $this->tailwindTransformDeclaration(),
            ];
        }

        if (preg_match('/^scale-(\d{2,3})$/', $utility, $matches) === 1) {
            $value = rtrim(rtrim(sprintf('%.2F', ((int) $matches[1]) / 100), '0'), '.');
            return [
                '--tw-scale-x: ' . $value . ' !important;',
                '--tw-scale-y: ' . $value . ' !important;',
                $this->tailwindTransformDeclaration(),
            ];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function mapBorderUtilityToDeclarations(string $utility): array
    {
        return match ($utility) {
            'border' => ['border-width: 1px !important;'],
            'border-0' => ['border-width: 0 !important;'],
            'border-t' => ['border-top-width: 1px !important;'],
            'border-r' => ['border-right-width: 1px !important;'],
            'border-b' => ['border-bottom-width: 1px !important;'],
            'border-l' => ['border-left-width: 1px !important;'],
            'border-x' => [
                'border-left-width: 1px !important;',
                'border-right-width: 1px !important;',
            ],
            'border-y' => [
                'border-top-width: 1px !important;',
                'border-bottom-width: 1px !important;',
            ],
            'rounded-none' => ['border-radius: 0 !important;'],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function mapSpacingUtilityToDeclarations(string $utility): array
    {
        if (preg_match('/^gap-x-(.+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : ['column-gap: ' . $value . ' !important;'];
        }

        if (preg_match('/^gap-y-(.+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : ['row-gap: ' . $value . ' !important;'];
        }

        if (preg_match('/^gap-(.+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : ['gap: ' . $value . ' !important;'];
        }

        if (preg_match('/^(p|px|py|pt|pr|pb|pl)-(.+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingValue($matches[2]);
            if ($value === null) {
                return [];
            }

            return $this->expandBoxSpacingDeclarations('padding', $matches[1], $value);
        }

        if (preg_match('/^(m|mx|my|mt|mr|mb|ml)-(.+)$/', $utility, $matches)) {
            $value = $this->resolveSpacingValue($matches[2]);
            if ($value === null) {
                return [];
            }

            return $this->expandBoxSpacingDeclarations('margin', $matches[1], $value);
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function expandBoxSpacingDeclarations(string $propertyRoot, string $prefix, string $value): array
    {
        $axis = substr($prefix, -1);

        if ($prefix === 'p' || $prefix === 'm') {
            return [$propertyRoot . ': ' . $value . ' !important;'];
        }

        if ($axis === 'x') {
            return [
                $propertyRoot . '-left: ' . $value . ' !important;',
                $propertyRoot . '-right: ' . $value . ' !important;',
            ];
        }

        if ($axis === 'y') {
            return [
                $propertyRoot . '-top: ' . $value . ' !important;',
                $propertyRoot . '-bottom: ' . $value . ' !important;',
            ];
        }

        $side = match ($axis) {
            't' => 'top',
            'r' => 'right',
            'b' => 'bottom',
            'l' => 'left',
            default => null,
        };

        return $side === null ? [] : [$propertyRoot . '-' . $side . ': ' . $value . ' !important;'];
    }

    private function resolveSpacingValue(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        if (isset(self::CUSTOM_SPACING[$token])) {
            return self::CUSTOM_SPACING[$token];
        }

        if (preg_match('/^\[(.+)\]$/', $token, $matches)) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            return $this->isSafeCssValue($value) ? $value : null;
        }

        if (preg_match('/^\d+$/', $token) === 1) {
            return $this->resolveSpacingScale((int) $token);
        }

        return null;
    }

    private function resolveSizeValue(string $token, string $axis = ''): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        if ($token === 'full') {
            return '100%';
        }

        if ($token === 'screen') {
            return str_contains($axis, 'h') || in_array($axis, ['top', 'bottom'], true) ? '100vh' : '100vw';
        }

        if ($token === 'px') {
            return '1px';
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $token, $matches) === 1) {
            $denominator = (int) $matches[2];
            if ($denominator === 0) {
                return null;
            }

            return rtrim(rtrim(sprintf('%.6F', ((int) $matches[1] / $denominator) * 100), '0'), '.') . '%';
        }

        return $this->resolveSpacingValue($token);
    }

    private function resolveTranslateValue(string $token): ?string
    {
        $token = trim($token);
        if ($token === 'full') {
            return '100%';
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $token, $matches) === 1) {
            $denominator = (int) $matches[2];
            if ($denominator === 0) {
                return null;
            }

            return rtrim(rtrim(sprintf('%.6F', ((int) $matches[1] / $denominator) * 100), '0'), '.') . '%';
        }

        return $this->resolveSpacingValue($token);
    }

    private function tailwindTransformDeclaration(): string
    {
        return 'transform: translate(var(--tw-translate-x, 0), var(--tw-translate-y, 0)) rotate(var(--tw-rotate, 0)) skewX(var(--tw-skew-x, 0)) skewY(var(--tw-skew-y, 0)) scaleX(var(--tw-scale-x, 1)) scaleY(var(--tw-scale-y, 1)) !important;';
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
            if ($this->isSafeCssValue($normalized) && $this->looksLikeColor($normalized)) {
                return $this->applyOpacityToColor($normalized, $opacity);
            }
        }

        if ($this->isSafeCssValue($value) && $this->looksLikeColor($value)) {
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

    private function isSafeCssValue(string $value): bool
    {
        return $value !== ''
            && preg_match('/[;{}<>]|javascript\s*:/i', $value) !== 1;
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
            16 => '4rem',
            20 => '5rem',
            24 => '6rem',
            28 => '7rem',
            32 => '8rem',
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
