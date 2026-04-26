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

    private const ALLOWED_TAGS = [
        'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'button',
        'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'details', 'dfn',
        'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer',
        'form', 'g', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i',
        'img', 'input', 'label', 'legend', 'li', 'main', 'mark', 'nav',
        'ol', 'option', 'p', 'path', 'polygon', 'polyline', 'pre', 'rect',
        'section', 'select', 'small', 'source', 'span', 'strong', 'sub', 'summary',
        'sup', 'svg', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th',
        'thead', 'tr', 'u', 'ul', 'video',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'accept', 'action', 'alt', 'autocomplete', 'autofocus', 'checked',
        'class', 'cols', 'colspan', 'controls', 'd', 'disabled', 'enctype',
        'for', 'height', 'href', 'id', 'loading', 'loop', 'max', 'maxlength',
        'method', 'min', 'minlength', 'multiple', 'muted', 'name', 'pattern',
        'placeholder', 'playsinline', 'poster', 'readonly', 'rel', 'required',
        'role', 'rows', 'rowspan', 'selected', 'src', 'step', 'tabindex',
        'target', 'title', 'type', 'value', 'viewbox', 'width', 'xlink:href',
        'xmlns',
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

        $this->sanitizeNode($root);

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

    private function sanitizeNode(DOMNode $node): void
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

        if ($tag !== 'div' || $node->getAttribute('id') !== 'oxy-safe-root') {
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                if ($node->parentNode) {
                    $children = [];
                    foreach ($node->childNodes as $child) {
                        $children[] = $child;
                    }
                    foreach ($children as $child) {
                        $node->parentNode->insertBefore($child, $node);
                    }
                    $node->parentNode->removeChild($node);
                }
                return;
            }
        }

        $attributeNames = [];
        foreach ($node->attributes as $attribute) {
            $attributeNames[] = $attribute->name;
        }

        foreach ($attributeNames as $attributeName) {
            $name = strtolower($attributeName);
            $value = $node->getAttribute($attributeName);

            if (strpos($name, 'on') === 0 || $name === 'style') {
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
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->sanitizeNode($child);
        }
    }

    private function isAllowedAttribute(string $attribute): bool
    {
        if (in_array($attribute, self::ALLOWED_ATTRIBUTES, true)) {
            return true;
        }

        return strpos($attribute, 'data-') === 0 || strpos($attribute, 'aria-') === 0;
    }

    /**
     * @param array<int, string> $allowedSchemes
     */
    private function sanitizeUrl(string $url, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'file://') === 0) {
            $parts = explode('/', str_replace('\\', '/', $url));
            $filename = end($parts);

            return $filename;
        }

        if (preg_match('/^(#|\/|\.\.?\/|\?)/', $url)) {
            return $url;
        }

        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches)) {
            return $url;
        }

        $scheme = strtolower($matches[1]);
        if (!in_array($scheme, $allowedSchemes, true)) {
            return '#';
        }

        if ($scheme === 'data') {
            if (preg_match('/^data:(image|video)\/[a-z0-9.+-]+;base64,[a-z0-9+\/=\s]+$/i', $url)) {
                $dataUrl = preg_replace('/\s+/', '', $url);
                return is_string($dataUrl) ? $dataUrl : '#';
            }

            return '#';
        }

        if ($scheme === 'http' || $scheme === 'https') {
            $sanitized = esc_url_raw($url);
            return is_string($sanitized) && $sanitized !== '' ? $sanitized : '#';
        }

        $safeUrl = preg_replace('/[\r\n]+/', '', $url);

        return is_string($safeUrl) ? $safeUrl : '#';
    }
}
