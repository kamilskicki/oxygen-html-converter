<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

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
            'flex' => ['layout' => ['display' => 'flex']],
            'inline-flex' => ['layout' => ['display' => 'inline-flex']],
            'block' => ['layout' => ['display' => 'block']],
            'inline-block' => ['layout' => ['display' => 'inline-block']],
            'hidden' => ['layout' => ['display' => 'none']],
            'items-start' => ['layout' => ['align-items' => 'flex-start']],
            'items-center' => ['layout' => ['align-items' => 'center']],
            'items-end' => ['layout' => ['align-items' => 'flex-end']],
            'justify-start' => ['layout' => ['justify-content' => 'flex-start']],
            'justify-center' => ['layout' => ['justify-content' => 'center']],
            'justify-end' => ['layout' => ['justify-content' => 'flex-end']],
            'justify-between' => ['layout' => ['justify-content' => 'space-between']],
            'relative' => ['position' => ['position' => 'relative']],
            'absolute' => ['position' => ['position' => 'absolute']],
            'fixed' => ['position' => ['position' => 'fixed']],
            'sticky' => ['position' => ['position' => 'sticky']],
            'w-full' => ['size' => ['width' => '100%']],
            'h-full' => ['size' => ['height' => '100%']],
            'overflow-hidden' => ['overflow' => ['overflow' => 'hidden']],
            'rounded-full' => ['border' => ['border-radius' => '9999px']],
            'uppercase' => ['typography' => ['text-transform' => 'uppercase']],
            'lowercase' => ['typography' => ['text-transform' => 'lowercase']],
            'text-left' => ['typography' => ['text-align' => 'left']],
            'text-center' => ['typography' => ['text-align' => 'center']],
            'text-right' => ['typography' => ['text-align' => 'right']],
            'font-serif' => ['typography' => ['font-family' => 'serif']],
            'font-sans' => ['typography' => ['font-family' => 'sans-serif']],
            'font-mono' => ['typography' => ['font-family' => 'monospace']],
            'italic' => ['typography' => ['font-style' => 'italic']],
            'not-italic' => ['typography' => ['font-style' => 'normal']],
            'transition-all' => ['effects' => ['transition' => 'all 150ms ease']],
            'transition-colors' => ['effects' => ['transition' => 'color 150ms ease, background-color 150ms ease, border-color 150ms ease, text-decoration-color 150ms ease, fill 150ms ease, stroke 150ms ease']],
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
            return ['typography' => ['color' => self::TEXT_COLORS[$className]]];
        }

        if (isset(self::BACKGROUND_COLORS[$className])) {
            return ['background' => ['background-color' => self::BACKGROUND_COLORS[$className]]];
        }

        if (preg_match('/^bg-\[(.+)\]$/', $className, $matches) === 1) {
            $color = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($color)) {
                return ['background' => ['background-color' => $color]];
            }
        }

        if (preg_match('/^text-\[(.+)\]$/', $className, $matches) === 1) {
            $value = $this->normalizeArbitraryValue($matches[1]);
            if ($this->looksLikeColor($value)) {
                return ['typography' => ['color' => $value]];
            }
        }

        if ($className === 'inset-0') {
            return [
                'position' => [
                    'top' => '0',
                    'right' => '0',
                    'bottom' => '0',
                    'left' => '0',
                ],
            ];
        }

        if (preg_match('/^z-(\d+)$/', $className, $matches) === 1) {
            return ['position' => ['z-index' => $matches[1]]];
        }

        if (preg_match('/^(top|right|bottom|left)-0$/', $className, $matches) === 1) {
            return ['position' => [$matches[1] => '0']];
        }

        return [];
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
