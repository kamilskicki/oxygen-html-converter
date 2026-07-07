<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\StyleExtractor;

/**
 * Maps a conservative subset of Tailwind utilities to native Oxygen properties.
 *
 * The mapper intentionally ignores responsive/state-prefixed utilities so they
 * remain in fallback CSS/classes rather than being flattened into base styles.
 */
class TailwindPropertyMapper
{
    private OxygenValueNormalizer $valueNormalizer;

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
        'font-thin' => '100',
        'font-extralight' => '200',
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

    private const TEXT_COLORS = [
        'white' => '#ffffff',
        'black' => '#000000',
        'transparent' => 'transparent',
        'gray-200' => '#e5e7eb',
        'gray-400' => '#9ca3af',
        'gray-500' => '#6b7280',
        'gray-600' => '#4b5563',
        'gray-700' => '#374151',
        'gray-800' => '#1f2937',
        'gray-900' => '#111827',
        'neutral-200' => '#e5e5e5',
        'stone-400' => '#a8a29e',
        'stone-500' => '#78716c',
        'stone-800' => '#292524',
        'stone-950' => '#0c0a09',
        'red-500' => '#ef4444',
        'red-600' => '#dc2626',
        'red-700' => '#b91c1c',
        'red-800' => '#991b1b',
        'red-900' => '#7f1d1d',
        'background' => '#fff8f5',
        'brass-accent' => '#9A7440',
        'copper-highlight' => '#BE8656',
        'ink-black' => '#17120F',
        'ink-soft' => '#544B45',
        'ivory-base' => '#F3EDE4',
        'on-background' => '#201a17',
        'on-primary' => '#ffffff',
        'on-surface' => '#201a17',
        'oxblood-primary' => '#731B19',
        'paper-bright' => '#FCF9F4',
    ];

    private const BACKGROUND_COLORS = [
        'white' => '#ffffff',
        'black' => '#000000',
        'transparent' => 'transparent',
        'background' => '#fff8f5',
        'brass-accent' => '#9A7440',
        'copper-highlight' => '#BE8656',
        'ink-black' => '#17120F',
        'ink-soft' => '#544B45',
        'ivory-base' => '#F3EDE4',
        'on-primary' => '#ffffff',
        'on-surface' => '#201a17',
        'oxblood-primary' => '#731B19',
        'paper-bright' => '#FCF9F4',
        'paper-soft' => '#E8DED0',
        'stone-50' => '#fafaf9',
        'stone-950' => '#0c0a09',
        'surface-variant' => '#ece0db',
    ];

    private const BORDER_COLORS = [
        'brass-accent' => '#9A7440',
        'ink-soft' => '#544B45',
        'oxblood-primary' => '#731B19',
        'paper-soft' => '#E8DED0',
        'red-900' => '#7f1d1d',
        'stone-200' => '#e7e5e4',
        'stone-800' => '#292524',
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

    private const BORDER_RADIUS = [
        'rounded-none' => '0',
        'rounded-sm' => '0.125rem',
        'rounded' => '0.25rem',
        'rounded-md' => '0.375rem',
        'rounded-lg' => '0.5rem',
        'rounded-xl' => '0.75rem',
        'rounded-2xl' => '1rem',
        'rounded-3xl' => '1.5rem',
        'rounded-full' => '9999px',
    ];

    public function __construct(?OxygenValueNormalizer $valueNormalizer = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new OxygenValueNormalizer();
    }

    /**
     * @return array<string, mixed>
     */
    public function getIntegrationCapabilities(): array
    {
        return [
            'scope' => 'core_native_property_mapping',
            'runtimeDependency' => false,
            'fullUtilityParity' => false,
            'variantMapping' => false,
            'extensionPoint' => 'oxy_html_converter_convert_options',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapClass(string $className): array
    {
        $className = trim($className);
        if ($className === '' || $this->hasVariantPrefix($className)) {
            return [];
        }

        return match ($className) {
            'flex' => $this->mapDeclaration('display', 'flex'),
            'inline-flex' => $this->mapDeclaration('display', 'inline-flex'),
            'flex-row' => $this->mapDeclaration('flex-direction', 'row'),
            'flex-row-reverse' => $this->mapDeclaration('flex-direction', 'row-reverse'),
            'flex-col' => $this->mapDeclaration('flex-direction', 'column'),
            'flex-col-reverse' => $this->mapDeclaration('flex-direction', 'column-reverse'),
            'flex-grow' => $this->mapDeclaration('flex-grow', '1'),
            'grow' => $this->mapDeclaration('flex-grow', '1'),
            'grow-0' => $this->mapDeclaration('flex-grow', '0'),
            'shrink' => $this->mapDeclaration('flex-shrink', '1'),
            'shrink-0' => $this->mapDeclaration('flex-shrink', '0'),
            'block' => $this->mapDeclaration('display', 'block'),
            'inline-block' => $this->mapDeclaration('display', 'inline-block'),
            'inline' => $this->mapDeclaration('display', 'inline'),
            'hidden' => $this->mapDeclaration('display', 'none'),
            'grid' => $this->mapDeclaration('display', 'grid'),
            'items-start' => $this->mapDeclaration('align-items', 'flex-start'),
            'items-center' => $this->mapDeclaration('align-items', 'center'),
            'items-end' => $this->mapDeclaration('align-items', 'flex-end'),
            'items-baseline' => $this->mapDeclaration('align-items', 'baseline'),
            'items-stretch' => $this->mapDeclaration('align-items', 'stretch'),
            'justify-start' => $this->mapDeclaration('justify-content', 'flex-start'),
            'justify-center' => $this->mapDeclaration('justify-content', 'center'),
            'justify-end' => $this->mapDeclaration('justify-content', 'flex-end'),
            'justify-between' => $this->mapDeclaration('justify-content', 'space-between'),
            'justify-around' => $this->mapDeclaration('justify-content', 'space-around'),
            'justify-evenly' => $this->mapDeclaration('justify-content', 'space-evenly'),
            'relative' => $this->mapDeclaration('position', 'relative'),
            'absolute' => $this->mapDeclaration('position', 'absolute'),
            'fixed' => $this->mapDeclaration('position', 'fixed'),
            'sticky' => $this->mapDeclaration('position', 'sticky'),
            'w-full' => $this->mapDeclaration('width', '100%'),
            'h-full' => $this->mapDeclaration('height', '100%'),
            'overflow-hidden' => $this->mapDeclaration('overflow', 'hidden'),
            'object-cover' => $this->mapDeclaration('object-fit', 'cover'),
            'object-contain' => $this->mapDeclaration('object-fit', 'contain'),
            'mix-blend-multiply' => $this->mapDeclaration('mix-blend-mode', 'multiply'),
            'uppercase' => $this->mapDeclaration('text-transform', 'uppercase'),
            'lowercase' => $this->mapDeclaration('text-transform', 'lowercase'),
            'capitalize' => $this->mapDeclaration('text-transform', 'capitalize'),
            'normal-case' => $this->mapDeclaration('text-transform', 'none'),
            'text-left' => $this->mapDeclaration('text-align', 'left'),
            'text-center' => $this->mapDeclaration('text-align', 'center'),
            'text-right' => $this->mapDeclaration('text-align', 'right'),
            'text-justify' => $this->mapDeclaration('text-align', 'justify'),
            'font-serif' => $this->mapDeclaration('font-family', 'serif'),
            'font-sans' => $this->mapDeclaration('font-family', 'sans-serif'),
            'font-mono' => $this->mapDeclaration('font-family', 'monospace'),
            'italic' => $this->mapDeclaration('font-style', 'italic'),
            'not-italic' => $this->mapDeclaration('font-style', 'normal'),
            'transition-all' => $this->mapDeclaration('transition', 'all 150ms ease'),
            'transition-colors' => $this->mapDeclaration('transition', 'color 150ms ease, background-color 150ms ease, border-color 150ms ease, text-decoration-color 150ms ease, fill 150ms ease, stroke 150ms ease'),
            default => $this->mapDynamicClass($className),
        };
    }

    public function canMapClass(string $className): bool
    {
        return $this->mapClass($className) !== [];
    }

    private function hasVariantPrefix(string $className): bool
    {
        return strpos($className, ':') !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDynamicClass(string $className): array
    {
        if (isset(self::FONT_SIZES[$className])) {
            [$fontSize, $lineHeight] = self::FONT_SIZES[$className];
            return $this->mapDeclarations([
                'font-size' => $fontSize,
                'line-height' => $lineHeight,
            ]);
        }

        if (isset(self::FONT_WEIGHTS[$className])) {
            return $this->mapDeclaration('font-weight', self::FONT_WEIGHTS[$className]);
        }

        if (isset(self::LINE_HEIGHTS[$className])) {
            return $this->mapDeclaration('line-height', self::LINE_HEIGHTS[$className]);
        }

        if (isset(self::LETTER_SPACING[$className])) {
            return $this->mapDeclaration('letter-spacing', self::LETTER_SPACING[$className]);
        }

        if (isset(self::MAX_WIDTHS[$className])) {
            return $this->mapDeclaration('max-width', self::MAX_WIDTHS[$className]);
        }

        if (isset(self::BORDER_RADIUS[$className])) {
            return $this->mapDeclaration('border-radius', self::BORDER_RADIUS[$className]);
        }

        if (preg_match('/^grid-cols-(\d+)$/', $className, $matches) === 1) {
            return $this->mapDeclaration('grid-template-columns', 'repeat(' . $matches[1] . ', minmax(0, 1fr))');
        }

        if (preg_match('/^grid-cols-\[(.+)\]$/', $className, $matches) === 1) {
            return $this->mapDeclaration('grid-template-columns', $this->normalizeArbitraryValue($matches[1]));
        }

        if (preg_match('/^col-span-(\d+)$/', $className, $matches) === 1) {
            return $this->mapDeclaration('grid-column', 'span ' . $matches[1] . ' / span ' . $matches[1]);
        }

        if (preg_match('/^col-start-(\d+)$/', $className, $matches) === 1) {
            return $this->mapDeclaration('grid-column-start', $matches[1]);
        }

        if (preg_match('/^order-(-?\d+)$/', $className, $matches) === 1) {
            return $this->mapDeclaration('order', $matches[1]);
        }

        if (preg_match('/^(?:text)-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveColorValue($matches[1], self::TEXT_COLORS);
            if ($value !== null) {
                return $this->mapDeclaration('color', $value);
            }
        }

        if (preg_match('/^(?:bg)-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveColorValue($matches[1], self::BACKGROUND_COLORS);
            if ($value !== null) {
                return $this->mapDeclaration('background-color', $value);
            }
        }

        if (preg_match('/^border-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveColorValue($matches[1], self::BORDER_COLORS + self::TEXT_COLORS);
            if ($value !== null) {
                return $this->mapDeclaration('border-color', $value);
            }
        }

        if (preg_match('/^text-\[(.+)\]$/', $className, $matches) === 1) {
            return $this->mapArbitraryText($matches[1]);
        }

        if ($className === 'inset-0') {
            return $this->mergeMaps(
                $this->mapDeclaration('top', '0'),
                $this->mapDeclaration('right', '0'),
                $this->mapDeclaration('bottom', '0'),
                $this->mapDeclaration('left', '0')
            );
        }

        if ($className === 'border') {
            return $this->mapDeclaration('border-width', '1px');
        }

        if ($className === 'border-0') {
            return $this->mapDeclaration('border-width', '0');
        }

        if (preg_match('/^border-([trbl])$/', $className, $matches) === 1) {
            $side = match ($matches[1]) {
                't' => 'top',
                'r' => 'right',
                'b' => 'bottom',
                'l' => 'left',
            };

            return $this->mapDeclaration('border-' . $side . '-width', '1px');
        }

        if (preg_match('/^border-([xy])$/', $className, $matches) === 1) {
            return $matches[1] === 'x'
                ? $this->mapDeclarations(['border-left-width' => '1px', 'border-right-width' => '1px'])
                : $this->mapDeclarations(['border-top-width' => '1px', 'border-bottom-width' => '1px']);
        }

        if (preg_match('/^opacity-(\d{1,3})$/', $className, $matches) === 1) {
            $opacity = max(0, min(100, (int) $matches[1])) / 100;
            return $this->mapDeclaration('opacity', (string) $opacity);
        }

        $spacing = $this->mapSpacingClass($className);
        if ($spacing !== []) {
            return $spacing;
        }

        $sizing = $this->mapSizingClass($className);
        if ($sizing !== []) {
            return $sizing;
        }

        $position = $this->mapPositionClass($className);
        if ($position !== []) {
            return $position;
        }

        if (preg_match('/^z-(\d+)$/', $className, $matches) === 1) {
            return $this->mapDeclaration('z-index', $matches[1]);
        }

        if (preg_match('/^(top|right|bottom|left)-0$/', $className, $matches) === 1) {
            return $this->mapDeclaration($matches[1], '0');
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDeclaration(string $property, string $value): array
    {
        $properties = [];
        $converted = [];

        foreach (StyleExtractor::controlAssignmentsForDeclaration($property, $value) as $assignment) {
            $normalizedValue = $this->valueNormalizer->normalizeForPath(
                $assignment['path'],
                $assignment['value'],
                $property
            );

            if ($normalizedValue === null) {
                return [];
            }

            $converted[] = [
                'path' => $assignment['path'],
                'value' => $normalizedValue,
            ];
        }

        foreach ($converted as $assignment) {
            StyleExtractor::setControlPathValue($properties, $assignment['path'], $assignment['value']);
        }

        return $properties;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, mixed>
     */
    private function mapDeclarations(array $declarations): array
    {
        $maps = [];

        foreach ($declarations as $property => $value) {
            $mapped = $this->mapDeclaration($property, $value);
            if ($mapped === []) {
                return [];
            }

            $maps[] = $mapped;
        }

        return $this->mergeMaps(...$maps);
    }

    /**
     * @param array<string, mixed> ...$maps
     * @return array<string, mixed>
     */
    private function mergeMaps(array ...$maps): array
    {
        $merged = [];

        foreach ($maps as $map) {
            $merged = $this->mergeAssociative($merged, $map);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeAssociative(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeAssociative($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function normalizeArbitraryValue(string $value): string
    {
        return str_replace('_', ' ', trim($value));
    }

    /**
     * @param array<string, string> $palette
     */
    private function resolveColorValue(string $value, array $palette): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $opacity = null;
        if (preg_match('/^(.+)\/(\d{1,3})$/', $value, $matches) === 1) {
            $value = trim($matches[1]);
            $opacity = max(0, min(100, (int) $matches[2])) / 100;
        }

        if (isset($palette[$value])) {
            return $this->applyOpacityToColor($palette[$value], $opacity);
        }

        if (preg_match('/^\[(.+)\]$/', $value, $matches) === 1) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($value)) {
                return $this->applyOpacityToColor($value, $opacity);
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

        if (preg_match('/^#([a-f0-9]{6})$/i', $color, $matches) === 1) {
            $hex = $matches[1];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return sprintf('rgba(%d, %d, %d, %.3F)', $r, $g, $b, $opacity);
        }

        return $color;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArbitraryText(string $value): array
    {
        $value = $this->normalizeArbitraryValue($value);
        if ($this->looksLikeColor($value)) {
            return $this->mapDeclaration('color', $value);
        }

        if ($this->looksLikeMeasurement($value)) {
            return $this->mapDeclaration('font-size', $value);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSpacingClass(string $className): array
    {
        if (preg_match('/^gap-x-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : $this->mapDeclaration('column-gap', $value);
        }

        if (preg_match('/^gap-y-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : $this->mapDeclaration('row-gap', $value);
        }

        if (preg_match('/^gap-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveSpacingValue($matches[1]);
            return $value === null ? [] : $this->mapDeclaration('gap', $value);
        }

        if (preg_match('/^(p|px|py|pt|pr|pb|pl)-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveSpacingValue($matches[2]);
            if ($value === null) {
                return [];
            }

            return $this->mapBoxSpacingClass('padding', $matches[1], $value);
        }

        if (preg_match('/^(m|mx|my|mt|mr|mb|ml)-(.+)$/', $className, $matches) !== 1) {
            return [];
        }

        $value = $matches[2] === 'auto' ? 'auto' : $this->resolveSpacingValue($matches[2]);
        if ($value === null) {
            return [];
        }

        return $this->mapBoxSpacingClass('margin', $matches[1], $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBoxSpacingClass(string $propertyRoot, string $prefix, string $value): array
    {
        $axis = substr($prefix, -1);

        if ($prefix === 'p' || $prefix === 'm') {
            return $this->mapDeclaration($propertyRoot, $value);
        }

        if ($axis === 'x') {
            return $this->mapDeclarations([
                $propertyRoot . '-left' => $value,
                $propertyRoot . '-right' => $value,
            ]);
        }

        if ($axis === 'y') {
            return $this->mapDeclarations([
                $propertyRoot . '-top' => $value,
                $propertyRoot . '-bottom' => $value,
            ]);
        }

        $side = match ($axis) {
            't' => 'top',
            'r' => 'right',
            'b' => 'bottom',
            'l' => 'left',
            default => null,
        };

        return $side === null ? [] : $this->mapDeclaration($propertyRoot . '-' . $side, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSizingClass(string $className): array
    {
        if (preg_match('/^(w|h|min-w|min-h|max-w|max-h)-(.+)$/', $className, $matches) !== 1) {
            return [];
        }

        if (isset(self::MAX_WIDTHS[$className])) {
            return [];
        }

        $value = $this->resolveSizeValue($matches[2], $matches[1]);
        if ($value === null) {
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

        return $this->mapDeclaration($property, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPositionClass(string $className): array
    {
        if (preg_match('/^(-?)inset(?:-([xy]))?-(.+)$/', $className, $matches) === 1) {
            $value = $this->resolveSizeValue($matches[3], 'inset');
            if ($value === null) {
                return [];
            }

            if ($matches[1] === '-' && $value !== '0' && $value !== '0px') {
                $value = '-' . ltrim($value, '-');
            }

            return match ($matches[2]) {
                'x' => $this->mapDeclarations(['left' => $value, 'right' => $value]),
                'y' => $this->mapDeclarations(['top' => $value, 'bottom' => $value]),
                default => $this->mapDeclarations([
                    'top' => $value,
                    'right' => $value,
                    'bottom' => $value,
                    'left' => $value,
                ]),
            };
        }

        if (preg_match('/^(-?)(top|right|bottom|left)-(.+)$/', $className, $matches) !== 1) {
            return [];
        }

        $value = $this->resolveSizeValue($matches[3], $matches[2]);
        if ($value === null) {
            return [];
        }

        if ($matches[1] === '-' && $value !== '0' && $value !== '0px') {
            $value = '-' . ltrim($value, '-');
        }

        return $this->mapDeclaration($matches[2], $value);
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

        if (preg_match('/^\[(.+)\]$/', $token, $matches) === 1) {
            return $this->normalizeArbitraryValue($matches[1]);
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

    private function looksLikeColor(string $value): bool
    {
        return (bool) preg_match('/^(#|rgb|hsl|var\(|transparent\b|white\b|black\b)/i', $value);
    }

    private function looksLikeMeasurement(string $value): bool
    {
        return (bool) preg_match('/^-?\d*\.?\d+(px|rem|em|vw|vh|%|ch|ex)?$/i', $value);
    }
}
