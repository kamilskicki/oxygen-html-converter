<?php

namespace OxyHtmlConverter;

use DOMDocument;
use DOMNode;
use DOMElement;
use DOMText;

/**
 * Parses HTML string into a traversable DOM structure
 */
class HtmlParser
{
    private DOMDocument $dom;
    private array $errors = [];

    public function __construct()
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
    }

    /**
     * Parse HTML string
     */
    public function parse(string $html): ?DOMElement
    {
        // Suppress libxml errors and collect them
        $previousUseErrors = libxml_use_internal_errors(true);

        // Wrap in UTF-8 meta and basic structure if needed
        $html = $this->prepareHtml($html);

        $success = $this->dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);

        // Collect errors
        $this->errors = array_map(function ($error) {
            return $error->message;
        }, libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$success) {
            return null;
        }

        // Find the body or root element
        $body = $this->dom->getElementsByTagName('body')->item(0);
        if ($body) {
            return $body;
        }

        // If no body, return the document element
        return $this->dom->documentElement;
    }

    /**
     * Prepare HTML for parsing
     */
    private function prepareHtml(string $html): string
    {
        $html = trim($html);

        // Pre-process special characters in attribute names that DOMDocument doesn't like
        // Replace @ with data-oxy-at-
        $html = preg_replace('/(\s)@([a-zA-Z0-9_\-\.]+)=/', '$1data-oxy-at-$2=', $html);

        // Check if it's a full HTML document
        if (stripos($html, '<!DOCTYPE') !== false || stripos($html, '<html') !== false) {
            // Ensure UTF-8 encoding
            if (stripos($html, '<meta charset') === false && stripos($html, 'charset=') === false) {
                $html = preg_replace('/<head>/i', '<head><meta charset="UTF-8">', $html, 1);
            }
            return $html;
        }

        // Wrap fragment in a basic structure
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
    }

    /**
     * Get parsing errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Extract content from body, removing scripts and styles
     */
    public function extractBodyContent(DOMElement $body): array
    {
        $children = [];

        foreach ($body->childNodes as $child) {
            if ($this->shouldSkipNode($child)) {
                continue;
            }
            $children[] = $child;
        }

        return $children;
    }

    /**
     * Check if node should be skipped during conversion
     */
    public function shouldSkipNode(DOMNode $node): bool
    {
        // Skip text nodes that are only whitespace
        if ($node instanceof DOMText && trim($node->textContent) === '') {
            return true;
        }

        // Skip comments
        if ($node->nodeType === XML_COMMENT_NODE) {
            return true;
        }

        // Skip script and style tags
        if ($node instanceof DOMElement) {
            $tagName = strtolower($node->tagName);
            if (in_array($tagName, ['meta', 'noscript'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all styles from the document (for potential future use)
     */
    public function extractStyles(): array
    {
        $styles = [];

        // Extract inline styles from style tags
        $styleTags = $this->dom->getElementsByTagName('style');
        foreach ($styleTags as $style) {
            $styles[] = [
                'type' => 'inline',
                'content' => $style->textContent,
            ];
        }

        // Extract linked stylesheets
        $links = $this->dom->getElementsByTagName('link');
        foreach ($links as $link) {
            if ($link->getAttribute('rel') === 'stylesheet') {
                $styles[] = [
                    'type' => 'external',
                    'href' => $link->getAttribute('href'),
                ];
            }
        }

        return $styles;
    }

    /**
     * Get the DOMDocument instance
     */
    public function getDom(): DOMDocument
    {
        return $this->dom;
    }
}
