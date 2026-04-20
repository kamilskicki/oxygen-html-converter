<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;

/**
 * Extracts source CSS from the document and appends compatibility fallback CSS.
 */
class DocumentCssExtractor
{
    public function __construct(
        private readonly HeuristicsService $heuristics,
        private readonly TailwindDetector $tailwindDetector,
        private readonly TailwindPropertyMapper $tailwindPropertyMapper,
        private readonly TailwindCssFallbackGenerator $tailwindFallbackGenerator
    )
    {
    }

    public function extract(DOMDocument $doc): string
    {
        $css = '';
        $styleTags = $doc->getElementsByTagName('style');

        foreach ($styleTags as $styleTag) {
            $content = str_replace('fontFamily', 'font-family', (string) $styleTag->textContent);

            if (trim($content) === '') {
                continue;
            }

            $content = $this->heuristics->applyNavScrolledCssRewrite($content);
            $content = $this->applyOxygenCssCompatibilityFixes($content);

            $css .= "/* Extracted from <style> tag */\n";
            $css .= trim($content) . "\n\n";
        }

        return $this->appendTailwindFallbackCss($css, $doc);
    }

    private function applyOxygenCssCompatibilityFixes(string $css): string
    {
        if (!$this->hasUniversalReset($css)) {
            return $css;
        }

        if (strpos($css, 'body h1, body h2, body h3, body h4, body h5, body h6') !== false) {
            return $css;
        }

        $shim = <<<'CSS'
/* Oxygen compatibility: preserve source reset against UA block margins */
body h1, body h2, body h3, body h4, body h5, body h6,
body p, body ul, body ol, body li, body blockquote, body figure {
    margin: 0;
    padding: 0;
}
CSS;

        return rtrim($css) . "\n\n" . $shim;
    }

    private function hasUniversalReset(string $css): bool
    {
        $patterns = [
            '/\*\s*,\s*\*::before\s*,\s*\*::after\s*\{[^}]*margin\s*:\s*0[^}]*padding\s*:\s*0/is',
            '/\*\s*,\s*::before\s*,\s*::after\s*\{[^}]*margin\s*:\s*0[^}]*padding\s*:\s*0/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $css) === 1) {
                return true;
            }
        }

        return false;
    }

    private function appendTailwindFallbackCss(string $css, DOMDocument $doc): string
    {
        $classTokens = [];
        $elements = $doc->getElementsByTagName('*');

        foreach ($elements as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $classAttr = trim((string) $element->getAttribute('class'));
            if ($classAttr === '') {
                continue;
            }

            foreach (preg_split('/\s+/', $classAttr) ?: [] as $token) {
                $token = trim((string) $token);
                if (
                    $token !== ''
                    && $this->tailwindDetector->isTailwindClass($token)
                    && !$this->tailwindPropertyMapper->canMapClass($token)
                ) {
                    $classTokens[] = $token;
                }
            }
        }

        $fallbackCss = $this->tailwindFallbackGenerator->generate($classTokens);
        if ($fallbackCss === '') {
            return $css;
        }

        if ($css !== '') {
            $css .= "\n";
        }

        return $css . "/* Tailwind utility fallback */\n" . $fallbackCss . "\n";
    }
}
