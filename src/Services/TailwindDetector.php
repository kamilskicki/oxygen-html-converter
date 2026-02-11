<?php

namespace OxyHtmlConverter\Services;

/**
 * Service to detect Tailwind CSS utility classes
 */
class TailwindDetector
{
    /**
     * Tailwind CSS utility patterns
     */
    public const TAILWIND_PATTERNS = [
        // Layout
        '/^(flex|grid|block|inline|hidden|container)$/',
        '/^(flex-|grid-|col-|row-|gap-|order-|justify-|items-|content-|self-|place-)/',

        // Spacing
        '/^[mp][xytblr]?-/',
        '/^space-[xy]-/',

        // Sizing
        '/^[wh]-/',
        '/^(min|max)-[wh]-/',
        '/^(size)-/',

        // Typography
        '/^(text-|font-|leading-|tracking-|indent-|align-|whitespace-|break-|hyphens-)/',
        '/^(uppercase|lowercase|capitalize|normal-case|truncate|line-clamp-)/',
        '/^(antialiased|subpixel-antialiased)$/',

        // Backgrounds
        '/^bg-/',
        '/^(from-|via-|to-)/',
        '/^bg-gradient-/',

        // Borders
        '/^(border|rounded|ring|outline|divide)-?/',

        // Effects
        '/^(shadow|opacity|mix-blend|bg-blend)-/',
        '/^(blur|brightness|contrast|grayscale|hue-rotate|invert|saturate|sepia|backdrop-)-?/',
        '/^drop-shadow/',

        // Filters & Transforms
        '/^(scale|rotate|translate|skew|origin)-/',
        '/^transform/',

        // Transitions & Animation
        '/^(transition|duration|ease|delay|animate)-/',

        // Interactivity
        '/^(cursor|pointer-events|resize|scroll|snap|touch|select|will-change)-/',
        '/^(appearance|accent)-/',

        // SVG
        '/^(fill|stroke)-/',

        // Accessibility
        '/^sr-only$/',
        '/^not-sr-only$/',

        // Position
        '/^(static|fixed|absolute|relative|sticky)$/',
        '/^(inset|top|right|bottom|left|z)-/',
        '/^(float|clear|isolate|isolation)-?/',
        '/^(object|overflow|overscroll)-/',

        // Visibility
        '/^(visible|invisible|collapse)$/',

        // Flexbox/Grid specific
        '/^(grow|shrink|basis)-?/',
        '/^auto-/',

        // Tables
        '/^(table|border-collapse|border-spacing)-?/',

        // Lists
        '/^list-/',

        // Aspect ratio
        '/^aspect-/',

        // Columns
        '/^columns-/',

        // Break
        '/^break-/',

        // Box
        '/^box-/',

        // Display
        '/^(contents|flow-root)$/',

        // Arbitrary values - [...]
        '/\[.+\]/',

        // Responsive prefixes
        '/^(sm|md|lg|xl|2xl):/',

        // State prefixes
        '/^(hover|focus|active|disabled|visited|checked|first|last|odd|even|group-hover|peer-):/',

        // Dark mode
        '/^dark:/',

        // Print
        '/^print:/',

        // Opacity modifiers (e.g. text-white/50)
        '/^[a-z0-9-]+?\/[0-9]{1,3}$/',
    ];

    /**
     * Check if a class name is a Tailwind utility class
     */
    public function isTailwindClass(string $className): bool
    {
        // Check against all Tailwind patterns
        foreach (self::TAILWIND_PATTERNS as $pattern) {
            if (preg_match($pattern, $className)) {
                return true;
            }
        }

        // Check for arbitrary value syntax
        if (strpos($className, '[') !== false && strpos($className, ']') !== false) {
            return true;
        }

        // Check for negative values (like -mt-4)
        if (preg_match('/^-[a-z]+-/', $className)) {
            return true;
        }

        return false;
    }
}
