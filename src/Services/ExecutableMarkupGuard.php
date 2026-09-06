<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/** Shared executable markup check for raw imports, including native rich text. */
final class ExecutableMarkupGuard
{
    public function containsExecutableMarkup(string $html): bool
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $urlDecoded = rawurldecode($decoded);
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $urlDecoded);
        if (!is_string($normalized)) {
            return true;
        }

        // HTML accepts a slash between attributes; whitespace-only matching
        // misses constructs such as <img src=x /onerror=...>.
        $pattern = '/<\s*(?:script|iframe|object|embed|svg|form|input|textarea|select|base|meta|link)\b|[\s\/]on[a-z0-9_-]+\s*=|[\s\/](?:data-oxy-at-|x-|v-|ng-|hx-on|bind:|:|@)[^\s=<>]*\s*=|(?:javascript|vbscript)\s*:|data:(?:text\/html|image\/svg\+xml)(?:[;,\s]|$)|srcdoc\s*=/i';
        foreach ([$html, $decoded, $urlDecoded, $normalized] as $candidate) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $content */
    public function contentContainsExecutableMarkup(array $content, int $depth = 0): bool
    {
        if ($depth > 64) {
            return true;
        }

        foreach ($content as $value) {
            if (is_string($value) && $this->containsExecutableMarkup($value)) {
                return true;
            }
            if (is_array($value) && $this->contentContainsExecutableMarkup($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
