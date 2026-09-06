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
        if ($selector === '' || $this->containsPseudo($selector)) {
            return false;
        }

        if (preg_match('/^(?:\.[a-zA-Z0-9_-]+)+$/D', $selector) === 1) {
            preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $matches);
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
        return $this->containsSelectorToken($selector, ':');
    }

    public function requiresCssFallback(string $selector): bool
    {
        // Sibling relationships depend on source order; arbitrary Tailwind utilities
        // retain their generated !important declarations in the CSS owner.
        return $this->containsSelectorToken($selector, ':+~') || str_contains($selector, '\\[');
    }

    private function containsSelectorToken(string $selector, string $tokens): bool
    {
        if (strpbrk($selector, $tokens) === false) {
            return false;
        }

        $quote = '';
        $bracketDepth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[') {
                $bracketDepth++;
            } elseif ($char === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
            } elseif (str_contains($tokens, $char) && $bracketDepth === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchesDomPath(string $selector, DOMElement $node): bool
    {
        $parts = [];
        $combinators = [];
        $current = '';
        $pendingCombinator = null;
        $quote = '';
        $bracketDepth = 0;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($char === '\\') {
                $current .= $char . ($selector[++$i] ?? '');
                continue;
            }
            if ($quote !== '') {
                $current .= $char;
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[') {
                $bracketDepth++;
            } elseif ($char === ']') {
                $bracketDepth--;
                if ($bracketDepth < 0) {
                    return false;
                }
            }

            if ($bracketDepth === 0 && (ctype_space($char) || str_contains('>+~', $char))) {
                if ($current !== '') {
                    if ($parts !== []) {
                        $combinators[] = $pendingCombinator ?? ' ';
                    }
                    $parts[] = $current;
                    $current = '';
                    $pendingCombinator = null;
                }
                if (!ctype_space($char)) {
                    if ($parts === [] || $pendingCombinator !== null) {
                        return false;
                    }
                    $pendingCombinator = $char;
                }
                continue;
            }
            $current .= $char;
        }

        if ($current === '' || $quote !== '' || $bracketDepth !== 0) {
            return false;
        }
        if ($parts !== []) {
            $combinators[] = $pendingCombinator ?? ' ';
        }
        $parts[] = $current;

        /** @var \SplObjectStorage<DOMElement, array<int, bool>> $memo */
        $memo = new \SplObjectStorage();

        return $this->matchesSelectorPath($parts, $combinators, count($parts) - 1, $node, $memo);
    }

    /**
     * @param list<string> $parts
     * @param list<string> $combinators
     * @param \SplObjectStorage<DOMElement, array<int, bool>> $memo
     */
    private function matchesSelectorPath(array $parts, array $combinators, int $index, DOMElement $node, \SplObjectStorage $memo): bool
    {
        $cached = $memo->contains($node) ? $memo[$node] : [];
        if (array_key_exists($index, $cached)) {
            return $cached[$index];
        }

        $matched = $this->matchesSelectorPathUncached($parts, $combinators, $index, $node, $memo);
        $cached[$index] = $matched;
        $memo[$node] = $cached;

        return $matched;
    }

    /**
     * @param list<string> $parts
     * @param list<string> $combinators
     * @param \SplObjectStorage<DOMElement, array<int, bool>> $memo
     */
    private function matchesSelectorPathUncached(array $parts, array $combinators, int $index, DOMElement $node, \SplObjectStorage $memo): bool
    {
        if (!$this->simpleSelectorMatchesNode($parts[$index], $node)) {
            return false;
        }
        if ($index === 0) {
            return true;
        }

        $combinator = $combinators[$index - 1];
        $sibling = $combinator === '+' || $combinator === '~';
        $candidate = $sibling ? $node->previousSibling : $node->parentNode;
        while ($candidate !== null) {
            if ($candidate instanceof DOMElement) {
                if ($this->matchesSelectorPath($parts, $combinators, $index - 1, $candidate, $memo)) {
                    return true;
                }
                if ($combinator === '>' || $combinator === '+') {
                    return false;
                }
            }
            $candidate = $sibling ? $candidate->previousSibling : $candidate->parentNode;
        }

        return false;
    }

    private function simpleSelectorMatchesNode(string $selectorPart, DOMElement $node): bool
    {
        // Only materialize selectors we can evaluate completely. Unsupported syntax
        // stays in fallback CSS rather than accidentally matching every element.
        $remaining = $selectorPart;
        if (preg_match('/^(?:[a-zA-Z][a-zA-Z0-9-]*|\*)/', $remaining, $tagMatch) === 1) {
            if ($tagMatch[0] !== '*' && strtolower($tagMatch[0]) !== strtolower($node->tagName)) {
                return false;
            }
            $remaining = substr($remaining, strlen($tagMatch[0]));
        }

        while ($remaining !== '') {
            if (preg_match('/^([.#])((?:[a-zA-Z0-9_-]|\\\\[^0-9a-fA-F\r\n])+)/', $remaining, $match) === 1) {
                $identifier = (string) preg_replace('/\\\\(.)/s', '$1', $match[2]);
                if ($match[1] === '#') {
                    if ($node->getAttribute('id') !== $identifier) {
                        return false;
                    }
                } else {
                    $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
                    if (!in_array($identifier, $classes, true)) {
                        return false;
                    }
                }
                $remaining = substr($remaining, strlen($match[0]));
                continue;
            }

            if (preg_match('/^\[\s*([a-zA-Z0-9_:-]+)(?:\s*=\s*(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([^\s\]"\'\\\\]+)))?\s*\]/', $remaining, $match, PREG_UNMATCHED_AS_NULL) === 1) {
                if (!$node->hasAttribute($match[1])) {
                    return false;
                }
                $value = $match[2] ?? $match[3] ?? $match[4] ?? null;
                if ($value !== null && $node->getAttribute($match[1]) !== $value) {
                    return false;
                }
                $remaining = substr($remaining, strlen($match[0]));
                continue;
            }

            return false;
        }

        return $selectorPart !== '';
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
            'OxygenElements\\Component' => 'div',
            'EssentialElements\\Button' => 'button',
        ];

        return $mapping[$type] ?? null;
    }
}
