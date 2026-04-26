<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMElement;

/**
 * Matches CSS selectors against DOM nodes during inline style materialization.
 */
class SelectorMatcher
{
    /**
     * @param array<int, string> $elementClasses
     * @param array<string, mixed> $element
     */
    public function matchesElement(
        string $selector,
        array $elementClasses,
        ?string $elementId,
        DOMElement $node,
        array $element
    ): bool
    {
        $selector = trim($selector);
        $selector = (string) preg_replace('/::?[a-z-]+(\([^)]*\))?/i', '', $selector);
        $selector = trim($selector);

        if ($selector === '') {
            return false;
        }

        if (strpos($selector, '.') === 0 &&
            strpos($selector, ' ') === false &&
            strpos($selector, '>') === false &&
            strpos($selector, '+') === false &&
            strpos($selector, '~') === false &&
            strpos($selector, '[') === false &&
            strpos($selector, '#') === false) {
            preg_match_all('/\.([a-zA-Z0-9_\-\\\\:]+)/', $selector, $matches);
            $selectorClasses = $matches[1];

            if ($selectorClasses === []) {
                return false;
            }

            foreach ($selectorClasses as $class) {
                $class = str_replace('\\', '', $class);
                if (!in_array($class, $elementClasses, true)) {
                    return false;
                }
            }

            return true;
        }

        if ($elementId && $selector === '#' . $elementId) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/i', $selector)) {
            $tagName = $this->getTagNameFromElement($element);
            return $tagName !== null && strtolower($selector) === strtolower($tagName);
        }

        return $this->matchesDomPath($selector, $node);
    }

    public function containsPseudo(string $selector): bool
    {
        $withoutAttributes = preg_replace('/\[[^\]]*\]/', '', $selector);

        return (bool) preg_match('/::?[a-z-]+(\([^)]*\))?/i', (string) $withoutAttributes);
    }

    private function matchesDomPath(string $selector, DOMElement $node): bool
    {
        $selector = (string) preg_replace('/\s*[>+~]\s*/', ' ', $selector);
        $parts = preg_split('/\s+/', trim($selector));

        if (empty($parts)) {
            return false;
        }

        $current = $node;
        $lastIndex = count($parts) - 1;

        for ($i = $lastIndex; $i >= 0; $i--) {
            $part = trim((string) $parts[$i]);
            if ($part === '') {
                continue;
            }

            if (!($current instanceof DOMElement)) {
                return false;
            }

            if ($i === $lastIndex) {
                if (!$this->simpleSelectorMatchesNode($part, $current)) {
                    return false;
                }
                $current = ($current->parentNode instanceof DOMElement) ? $current->parentNode : null;
                continue;
            }

            $matched = false;
            while ($current instanceof DOMElement) {
                if ($this->simpleSelectorMatchesNode($part, $current)) {
                    $matched = true;
                    $current = ($current->parentNode instanceof DOMElement) ? $current->parentNode : null;
                    break;
                }
                $current = ($current->parentNode instanceof DOMElement) ? $current->parentNode : null;
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function simpleSelectorMatchesNode(string $selectorPart, DOMElement $node): bool
    {
        $selectorPart = trim($selectorPart);
        if ($selectorPart === '' || $selectorPart === '*') {
            return true;
        }

        $attributes = [];
        if (preg_match_all('/\[\s*([a-zA-Z0-9_\-:]+)(?:\s*=\s*[\'"]?([^\'"\]]+)[\'"]?)?\s*\]/', $selectorPart, $attrMatches, PREG_SET_ORDER)) {
            foreach ($attrMatches as $match) {
                $attributes[] = [
                    'name' => $match[1],
                    'value' => $match[2] ?? null,
                ];
            }
            $selectorPart = (string) preg_replace('/\[[^\]]+\]/', '', $selectorPart);
        }

        $tag = null;
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*/', $selectorPart, $tagMatch)) {
            $tag = strtolower($tagMatch[0]);
        }

        $id = null;
        if (preg_match('/#([a-zA-Z0-9_\-:\\\\]+)/', $selectorPart, $idMatch)) {
            $id = str_replace('\\', '', $idMatch[1]);
        }

        preg_match_all('/\.([a-zA-Z0-9_\-:\\\\]+)/', $selectorPart, $classMatches);
        $classes = $classMatches[1];

        if ($tag !== null && strtolower($node->tagName) !== $tag) {
            return false;
        }

        if ($id !== null && $node->getAttribute('id') !== $id) {
            return false;
        }

        if ($classes !== []) {
            $nodeClasses = array_filter(array_map('trim', explode(' ', $node->getAttribute('class'))));
            foreach ($classes as $class) {
                $class = str_replace('\\', '', $class);
                if (!in_array($class, $nodeClasses, true)) {
                    return false;
                }
            }
        }

        foreach ($attributes as $attribute) {
            $attrName = $attribute['name'];
            if (!$node->hasAttribute($attrName)) {
                return false;
            }
            if ($attribute['value'] !== null && $node->getAttribute($attrName) !== $attribute['value']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function getTagNameFromElement(array $element): ?string
    {
        $tag = $element['data']['properties']['design']['tag'] ?? null;
        if (is_string($tag) && $tag !== '') {
            return strtolower($tag);
        }

        $type = $element['data']['type'] ?? '';
        $mapping = [
            'OxygenElements\\Container' => 'div',
            'OxygenElements\\ContainerLink' => 'a',
            'OxygenElements\\Text' => 'span',
            'OxygenElements\\TextLink' => 'a',
            'OxygenElements\\Image' => 'img',
            'OxygenElements\\Html5Video' => 'video',
            'OxygenElements\\RichText' => 'div',
            'OxygenElements\\Header' => 'header',
            'EssentialElements\\Button' => 'button',
        ];

        return $mapping[$type] ?? null;
    }
}
