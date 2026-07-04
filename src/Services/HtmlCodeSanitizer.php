<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Strict allowlist sanitizer for HtmlCode payloads in safe mode.
 */
class HtmlCodeSanitizer
{
    private const BLOCKED_TAGS = [
        'script',
        'style',
        'link',
        'meta',
        'iframe',
        'object',
        'embed',
        'base',
    ];

    private const HTML_CODE_ALLOWED_TAGS = [
        'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'button',
        'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'details', 'dfn',
        'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i',
        'img', 'label', 'legend', 'li', 'main', 'mark', 'nav',
        'ol', 'p', 'pre', 'section', 'small', 'span', 'strong', 'sub', 'summary',
        'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const INLINE_RICH_TEXT_ALLOWED_TAGS = [
        'a', 'abbr', 'b', 'br', 'cite', 'code', 'dfn', 'em', 'i', 'mark',
        'small', 'span', 'strong', 'sub', 'sup', 'u', 's',
    ];

    private const RICH_TEXT_ALLOWED_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'cite', 'code', 'col',
        'colgroup', 'dd', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'mark',
        'ol', 'p', 'pre', 'small', 'span', 'strong', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'alt', 'class', 'colspan', 'dir', 'for', 'height', 'href', 'id',
        'lang', 'loading', 'rel', 'role', 'rowspan', 'src', 'tabindex',
        'target', 'title', 'type', 'width',
    ];

    /**
     * @param array<string, mixed> $element
     */
    public function sanitizeElement(array &$element): bool
    {
        $html = $element['data']['properties']['content']['content']['html_code'] ?? null;
        if (!is_string($html) || trim($html) === '') {
            return true;
        }

        $sanitized = $this->sanitizeFragment($html);
        if ($sanitized === '') {
            return false;
        }

        $element['data']['properties']['content']['content']['html_code'] = $sanitized;

        return true;
    }

    public function sanitizeFragment(string $html): string
    {
        return $this->sanitizeFragmentWithAllowedTags($html, self::HTML_CODE_ALLOWED_TAGS);
    }

    public function sanitizeInlineRichText(string $html): string
    {
        return $this->sanitizeFragmentWithAllowedTags($html, self::INLINE_RICH_TEXT_ALLOWED_TAGS);
    }

    public function sanitizeRichText(string $html): string
    {
        return $this->sanitizeFragmentWithAllowedTags($html, self::RICH_TEXT_ALLOWED_TAGS);
    }

    public function sanitizePlainText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text);

        return trim(is_string($text) ? $text : '');
    }

    public function escapePlainText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text);
        $text = is_string($text) ? $text : '';

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<int, string> $allowedTags
     */
    private function sanitizeFragmentWithAllowedTags(string $html, array $allowedTags): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previousUseErrors = libxml_use_internal_errors(true);
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="oxy-safe-root">'
            . $html
            . '</div></body></html>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//*[@id="oxy-safe-root"]')->item(0);
        if (!($root instanceof DOMElement)) {
            return '';
        }

        $this->sanitizeNode($root, $allowedTags);

        $output = '';
        $children = [];
        foreach ($root->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * @param array<int, string> $allowedTags
     */
    private function sanitizeNode(DOMNode $node, array $allowedTags): void
    {
        if (!($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::BLOCKED_TAGS, true)) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
            return;
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->sanitizeNode($child, $allowedTags);
        }

        $isRoot = $tag === 'div' && $node->getAttribute('id') === 'oxy-safe-root';
        if (!$isRoot && !in_array($tag, $allowedTags, true)) {
            $this->unwrapOrRemoveNode($node);
            return;
        }

        $attributeNames = [];
        foreach ($node->attributes as $attribute) {
            $attributeNames[] = $attribute->name;
        }

        foreach ($attributeNames as $attributeName) {
            $name = strtolower($attributeName);
            $value = $node->getAttribute($attributeName);

            if (strpos($name, 'on') === 0 || $name === 'style' || $this->isBlockedDirectiveAttribute($name)) {
                $node->removeAttribute($attributeName);
                continue;
            }

            if (!$this->isAllowedAttribute($name)) {
                $node->removeAttribute($attributeName);
                continue;
            }

            if (in_array($name, ['href', 'action', 'formaction', 'xlink:href'], true)) {
                $node->setAttribute($attributeName, $this->sanitizeUrl($value, ['http', 'https', 'mailto', 'tel']));
                continue;
            }

            if (in_array($name, ['src', 'poster'], true)) {
                $node->setAttribute($attributeName, $this->sanitizeUrl($value, ['http', 'https', 'data']));
                continue;
            }

            if ($name === 'target' && !in_array($value, ['_self', '_blank', '_parent', '_top'], true)) {
                $node->removeAttribute($attributeName);
                continue;
            }

            if ($name === 'target' && $value === '_blank') {
                $existingRel = trim($node->getAttribute('rel'));
                $relParts = preg_split('/\s+/', $existingRel) ?: [];
                $relParts = array_filter($relParts, static fn (string $part): bool => $part !== '');
                foreach (['noopener', 'noreferrer'] as $requiredRel) {
                    if (!in_array($requiredRel, $relParts, true)) {
                        $relParts[] = $requiredRel;
                    }
                }
                $node->setAttribute('rel', implode(' ', $relParts));
            }
        }
    }

    private function unwrapOrRemoveNode(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->parentNode === $node) {
                $parent->insertBefore($child, $node);
            }
        }

        $parent->removeChild($node);
    }

    private function isAllowedAttribute(string $attribute): bool
    {
        if (in_array($attribute, self::ALLOWED_ATTRIBUTES, true)) {
            return true;
        }

        return (strpos($attribute, 'data-') === 0 && !$this->isBlockedDirectiveAttribute($attribute))
            || strpos($attribute, 'aria-') === 0;
    }

    private function isBlockedDirectiveAttribute(string $attribute): bool
    {
        return strpos($attribute, 'data-oxy-at-') === 0
            || strpos($attribute, 'x-') === 0
            || strpos($attribute, 'v-') === 0
            || strpos($attribute, 'ng-') === 0
            || strpos($attribute, 'hx-on') === 0
            || strpos($attribute, 'bind:') === 0
            || strpos($attribute, ':') === 0
            || strpos($attribute, '@') === 0;
    }

    /**
     * @param array<int, string> $allowedSchemes
     */
    public function sanitizeUrl(string $url, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'file://') === 0) {
            $parts = explode('/', str_replace('\\', '/', $url));
            $filename = end($parts);

            return $filename;
        }

        if (strpos($url, '//') === 0) {
            return '#';
        }

        $scheme = $this->extractNormalizedScheme($url);

        if (preg_match('/^(#|\/|\.\.?\/|\?)/', $url) && $scheme === null) {
            return $this->stripUrlControlCharacters($url);
        }

        if ($scheme === null) {
            return $this->stripUrlControlCharacters($url);
        }

        if (!in_array($scheme, $allowedSchemes, true)) {
            return '#';
        }

        if ($scheme === 'data') {
            $dataUrl = preg_replace('/[\x00-\x20\x7F]+/', '', $url);
            if (!is_string($dataUrl)) {
                return '#';
            }

            if (preg_match('/^data:([^;,]+);base64,[a-z0-9+\/=]+$/i', $dataUrl, $matches)) {
                $mediaType = strtolower($matches[1]);
                if (in_array($mediaType, [
                    'image/png',
                    'image/jpeg',
                    'image/gif',
                    'image/webp',
                    'image/avif',
                    'video/mp4',
                    'video/webm',
                ], true)) {
                    return $dataUrl;
                }
            }

            return '#';
        }

        if ($scheme === 'http' || $scheme === 'https') {
            $sanitized = esc_url_raw($url);
            return is_string($sanitized) && $sanitized !== '' ? $sanitized : '#';
        }

        $safeUrl = $this->stripUrlControlCharacters($url);
        if ($scheme === 'mailto' && preg_match('/(?:[\r\n]|%0a|%0d|[?&]bcc=)/i', $safeUrl) === 1) {
            return '#';
        }

        return $safeUrl !== '' ? $safeUrl : '#';
    }

    private function extractNormalizedScheme(string $url): ?string
    {
        $probe = rawurldecode($url);
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $probe);
        if (!is_string($probe) || $probe === '') {
            return null;
        }

        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $probe, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function stripUrlControlCharacters(string $url): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/', '', $url);

        return is_string($stripped) ? $stripped : '#';
    }
}
