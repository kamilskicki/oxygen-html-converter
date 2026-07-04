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
    private const TEXT_COLORS = [
        'text-white' => '#ffffff',
        'text-black' => '#000000',
        'text-transparent' => 'transparent',
    ];

    private const BACKGROUND_COLORS = [
        'bg-white' => '#ffffff',
        'bg-black' => '#000000',
        'bg-transparent' => 'transparent',
    ];

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
            'flex-col' => $this->mapDeclaration('flex-direction', 'column'),
            'flex-grow' => $this->mapDeclaration('flex-grow', '1'),
            'grow' => $this->mapDeclaration('flex-grow', '1'),
            'block' => $this->mapDeclaration('display', 'block'),
            'inline-block' => $this->mapDeclaration('display', 'inline-block'),
            'hidden' => $this->mapDeclaration('display', 'none'),
            'grid' => $this->mapDeclaration('display', 'grid'),
            'items-start' => $this->mapDeclaration('align-items', 'flex-start'),
            'items-center' => $this->mapDeclaration('align-items', 'center'),
            'items-end' => $this->mapDeclaration('align-items', 'flex-end'),
            'justify-start' => $this->mapDeclaration('justify-content', 'flex-start'),
            'justify-center' => $this->mapDeclaration('justify-content', 'center'),
            'justify-end' => $this->mapDeclaration('justify-content', 'flex-end'),
            'justify-between' => $this->mapDeclaration('justify-content', 'space-between'),
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
            'rounded-full' => $this->mapDeclaration('border-radius', '9999px'),
            'uppercase' => $this->mapDeclaration('text-transform', 'uppercase'),
            'lowercase' => $this->mapDeclaration('text-transform', 'lowercase'),
            'text-left' => $this->mapDeclaration('text-align', 'left'),
            'text-center' => $this->mapDeclaration('text-align', 'center'),
            'text-right' => $this->mapDeclaration('text-align', 'right'),
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
        if (isset(self::TEXT_COLORS[$className])) {
            return $this->mapDeclaration('color', self::TEXT_COLORS[$className]);
        }

        if (isset(self::BACKGROUND_COLORS[$className])) {
            return $this->mapDeclaration('background-color', self::BACKGROUND_COLORS[$className]);
        }

        if (preg_match('/^bg-\[(.+)\]$/', $className, $matches) === 1) {
            $color = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($color)) {
                return $this->mapDeclaration('background-color', $color);
            }
        }

        if (preg_match('/^text-\[(.+)\]$/', $className, $matches) === 1) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($value)) {
                return $this->mapDeclaration('color', $value);
            }
        }

        if ($className === 'inset-0') {
            return $this->mergeMaps(
                $this->mapDeclaration('top', '0'),
                $this->mapDeclaration('right', '0'),
                $this->mapDeclaration('bottom', '0'),
                $this->mapDeclaration('left', '0')
            );
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

        foreach (StyleExtractor::controlAssignmentsForDeclaration($property, $value) as $assignment) {
            StyleExtractor::setControlPathValue($properties, $assignment['path'], $assignment['value']);
        }

        return $properties;
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

    private function looksLikeColor(string $value): bool
    {
        return (bool) preg_match('/^(#|rgb|hsl|var\(|transparent\b|white\b|black\b)/i', $value);
    }
}
