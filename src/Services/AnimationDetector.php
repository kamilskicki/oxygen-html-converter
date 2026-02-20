<?php

namespace OxyHtmlConverter\Services;

use DOMElement;

/**
 * Detects CSS animation patterns and converts them to native Oxygen 6 entrance animations.
 *
 * Handles:
 * - Scroll-reveal patterns (.animate-on-scroll, .reveal, .fade-in, etc.)
 * - CSS @keyframes fadeInUp patterns (hero-badge, hero-headline, etc.)
 * - Stagger delay classes (.stagger-1 through .stagger-4)
 */
class AnimationDetector
{
    /** Classes that indicate scroll-reveal animation */
    private const SCROLL_REVEAL_CLASSES = [
        'animate-on-scroll', 'reveal', 'scroll-reveal',
        'fade-in', 'slide-up', 'slide-in',
        'aos-animate', 'wow',
    ];

    /** Stagger class prefix → delay multiplier (ms) */
    private const STAGGER_DELAY_MS = 100;

    /** CSS rules indexed by selector */
    private array $cssRulesBySelector = [];

    /** Raw CSS string for cleanup */
    private string $rawCss = '';

    /** Classes consumed by the last detectAnimations() call */
    private array $lastConsumedClasses = [];

    /** All CSS selectors that have been converted to native animations */
    private array $consumedCssSelectors = [];

    /**
     * Pre-analyze CSS rules for animation patterns.
     * Call this once before processing elements.
     */
    public function analyzeCssRules(array $cssRules, string $rawCss): void
    {
        $this->rawCss = $rawCss;
        $this->cssRulesBySelector = [];
        $this->consumedCssSelectors = [];

        foreach ($cssRules as $rule) {
            $sel = $rule['selector'];
            $this->cssRulesBySelector[$sel] = $rule['declarations'];
        }
    }

    /**
     * Detect entrance animation for an element based on its classes and CSS rules.
     *
     * @param DOMElement $node  The DOM node being converted
     * @param array $classes    Class names on this element
     * @param array $cssRules   Parsed CSS rules (unused here, kept for interface compat)
     * @return array|null       Entrance animation settings or null
     */
    public function detectAnimations(DOMElement $node, array $classes, array $cssRules): ?array
    {
        $this->lastConsumedClasses = [];

        // Strategy A: scroll-reveal class pattern
        $result = $this->detectScrollReveal($classes);
        if ($result) {
            return $result;
        }

        // Strategy B: @keyframes fadeInUp pattern (hero elements)
        $result = $this->detectKeyframeAnimation($classes);
        if ($result) {
            return $result;
        }

        return null;
    }

    /**
     * Get classes consumed by the last detectAnimations() call.
     */
    public function getConsumedClasses(): array
    {
        return $this->lastConsumedClasses;
    }

    /**
     * Get all CSS selectors that were converted to native animations.
     */
    public function getConsumedCssSelectors(): array
    {
        return $this->consumedCssSelectors;
    }

    /**
     * Remove converted CSS rules from the raw CSS string.
     */
    public function cleanupConvertedCss(string $css): string
    {
        if (empty($this->consumedCssSelectors)) {
            return $css;
        }

        // Remove scroll-reveal override block added by neutralizeScrollRevealCss
        $css = preg_replace(
            '/\/\*\s*Override:\s*JS-dependent scroll-reveal.*?\*\/\s*[^{]+\{[^}]+\}\s*/s',
            '',
            $css
        );

        // Remove each consumed selector's rule block
        foreach ($this->consumedCssSelectors as $selector) {
            $escaped = preg_quote($selector, '/');
            // Match: selector { ... }  (handling multiline)
            $css = preg_replace(
                '/\s*' . $escaped . '\s*\{[^}]*\}\s*/s',
                "\n",
                $css
            );
        }

        // Remove @keyframes fadeInUp if all hero classes were consumed
        $heroClasses = ['.hero-badge', '.hero-headline', '.hero-subheadline', '.hero-cta-group'];
        $allHeroConsumed = !array_diff($heroClasses, $this->consumedCssSelectors);
        if ($allHeroConsumed) {
            $css = preg_replace(
                '/@keyframes\s+fadeInUp\s*\{[^}]*\{[^}]*\}[^}]*\{[^}]*\}\s*\}\s*/s',
                '',
                $css
            );
        }

        // Clean up excessive blank lines
        $css = preg_replace('/\n{3,}/', "\n\n", $css);

        return trim($css) . "\n";
    }

    // ─── Private detection strategies ────────────────────────────────

    /**
     * Strategy A: Scroll-reveal classes (.animate-on-scroll, etc.)
     */
    private function detectScrollReveal(array $classes): ?array
    {
        $matchedClass = null;
        foreach ($classes as $cls) {
            if (in_array($cls, self::SCROLL_REVEAL_CLASSES, true)) {
                $matchedClass = $cls;
                break;
            }
        }

        if (!$matchedClass) {
            return null;
        }

        // Look up CSS declarations for this class
        $declarations = $this->cssRulesBySelector['.' . $matchedClass] ?? [];

        // Determine animation type and distance from transform
        $type = 'fade';
        $distance = 0;
        if (isset($declarations['transform'])) {
            $parsed = $this->parseTransform($declarations['transform']);
            $type = $parsed['type'];
            $distance = $parsed['distance'];
        }

        // Duration from transition
        $duration = 600; // default ms
        if (isset($declarations['transition'])) {
            $durationParsed = $this->parseTransitionDuration($declarations['transition']);
            if ($durationParsed > 0) {
                $duration = $durationParsed;
            }
        }

        // Easing from transition
        $easing = 'ease';
        if (isset($declarations['transition'])) {
            $easingParsed = $this->parseTransitionEasing($declarations['transition']);
            if ($easingParsed) {
                $easing = $easingParsed;
            }
        }

        // Delay from stagger classes
        $delay = 0;
        foreach ($classes as $cls) {
            if (preg_match('/^stagger-(\d+)$/', $cls, $m)) {
                $delay = (int) $m[1] * self::STAGGER_DELAY_MS;
                $this->lastConsumedClasses[] = $cls;
                $this->consumedCssSelectors[] = '.' . $cls;
            }
        }

        // Mark consumed
        $this->lastConsumedClasses[] = $matchedClass;
        $this->consumedCssSelectors[] = '.' . $matchedClass;
        // Also consume the .visible variant
        $this->consumedCssSelectors[] = '.' . $matchedClass . '.visible';

        return [
            'type' => $type,
            'duration' => $duration,
            'delay' => $delay,
            'easing' => $easing,
            'distance' => $distance,
            'once' => true,
        ];
    }

    /**
     * Strategy B: @keyframes fadeInUp pattern on specific classes (hero elements)
     */
    private function detectKeyframeAnimation(array $classes): ?array
    {
        foreach ($classes as $cls) {
            $declarations = $this->cssRulesBySelector['.' . $cls] ?? [];

            // Must have animation property referencing fadeInUp and opacity: 0
            if (!isset($declarations['animation']) || !isset($declarations['opacity'])) {
                continue;
            }

            if (strpos($declarations['animation'], 'fadeInUp') === false) {
                continue;
            }

            if ($declarations['opacity'] !== '0') {
                continue;
            }

            // Parse animation shorthand: fadeInUp 0.8s ease forwards
            $duration = 800; // default ms
            if (preg_match('/([\d.]+)s/', $declarations['animation'], $m)) {
                $duration = (int) round((float) $m[1] * 1000);
            }

            $easing = 'ease';
            if (preg_match('/\b(ease|ease-in|ease-out|ease-in-out|linear)\b/', $declarations['animation'], $m)) {
                $easing = $m[1];
            }

            // Parse animation-delay
            $delay = 0;
            if (isset($declarations['animation-delay'])) {
                if (preg_match('/([\d.]+)s/', $declarations['animation-delay'], $m)) {
                    $delay = (int) round((float) $m[1] * 1000);
                }
            }

            // Distance from @keyframes fadeInUp: translateY(40px)
            $distance = 40; // default based on typical fadeInUp

            // Keep the original class on element: keyframe classes often carry
            // non-animation styling (layout/typography/button visuals).
            $this->consumedCssSelectors[] = '.' . $cls;

            return [
                'type' => 'slideUp',
                'duration' => $duration,
                'delay' => $delay,
                'easing' => $easing,
                'distance' => $distance,
                'once' => true,
            ];
        }

        return null;
    }

    // ─── CSS value parsers ───────────────────────────────────────────

    /**
     * Parse transform value and determine animation type + distance.
     */
    private function parseTransform(string $transform): array
    {
        // translateY(30px)
        if (preg_match('/translateY\(\s*([-\d.]+)/', $transform, $m)) {
            $val = (float) $m[1];
            return [
                'type' => $val > 0 ? 'slideUp' : 'slideDown',
                'distance' => abs((int) $val),
            ];
        }

        // translateX(30px)
        if (preg_match('/translateX\(\s*([-\d.]+)/', $transform, $m)) {
            $val = (float) $m[1];
            return [
                'type' => $val > 0 ? 'slideLeft' : 'slideRight',
                'distance' => abs((int) $val),
            ];
        }

        // scale(0.x)
        if (preg_match('/scale\(\s*([\d.]+)/', $transform, $m)) {
            $val = (float) $m[1];
            if ($val < 1) {
                return [
                    'type' => 'zoomIn',
                    'distance' => 0,
                ];
            }
        }

        return ['type' => 'fade', 'distance' => 0];
    }

    /**
     * Extract duration (ms) from transition shorthand.
     */
    private function parseTransitionDuration(string $transition): int
    {
        // Match first time value: 0.6s or 600ms
        if (preg_match('/([\d.]+)(ms|s)\b/', $transition, $m)) {
            $val = (float) $m[1];
            return $m[2] === 's' ? (int) round($val * 1000) : (int) $val;
        }
        return 0;
    }

    /**
     * Extract easing function from transition shorthand.
     */
    private function parseTransitionEasing(string $transition): ?string
    {
        if (preg_match('/\b(ease|ease-in|ease-out|ease-in-out|linear)\b/', $transition, $m)) {
            return $m[1];
        }
        return null;
    }
}
