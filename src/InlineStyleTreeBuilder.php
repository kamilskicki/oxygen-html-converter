<?php
/**
 * EXPERIMENTAL: Alternative converter that uses inline styles in content
 * This bypasses Oxygen's design properties and uses raw HTML with inline styles
 */

namespace OxyHtmlConverter;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * Experimental TreeBuilder that outputs inline styles instead of design properties
 */
class InlineStyleTreeBuilder extends TreeBuilder
{
    /**
     * Convert a DOM node with inline styles
     */
    private function convertNodeWithInlineStyles(DOMNode $node): ?array
    {
        $element = parent::convertNode($node);
        
        if (!$element || !($node instanceof DOMElement)) {
            return $element;
        }
        
        // Get original inline styles
        $inlineStyle = $node->getAttribute('style');
        
        // Get computed styles from CSS rules that matched this element
        $classAttr = $node->getAttribute('class');
        if ($classAttr) {
            $classes = array_filter(array_map('trim', explode(' ', $classAttr)));
            $computedStyles = $this->getComputedStylesForClasses($classes);
            
            // Merge with existing inline styles (inline takes precedence)
            $computedStyles = array_merge($computedStyles, $this->parseInlineStyles($inlineStyle));
            
            // Apply as inline style to the element's HTML content
            if (!empty($computedStyles)) {
                $styleString = $this->stylesToString($computedStyles);
                
                // For Container elements, we can't easily inject inline styles
                // So we wrap the content in a div with inline styles
                if ($element['data']['type'] === ElementTypes::CONTAINER && !empty($styleString)) {
                    // Add the inline style to a wrapper approach
                    $element = $this->wrapWithInlineStyle($element, $styleString);
                }
            }
        }
        
        return $element;
    }
    
    /**
     * Get computed styles for given classes from parsed CSS
     */
    private function getComputedStylesForClasses(array $classes): array
    {
        $styles = [];
        
        foreach ($this->cssRules as $rule) {
            $selector = $rule['selector'];
            
            // Check if this rule applies to any of our classes
            foreach ($classes as $class) {
                if (strpos($selector, '.' . $class) !== false) {
                    $styles = array_merge($styles, $rule['declarations']);
                }
            }
        }
        
        return $styles;
    }
    
    /**
     * Parse inline style string
     */
    private function parseInlineStyles(string $styleString): array
    {
        $styles = [];
        $declarations = array_filter(array_map('trim', explode(';', $styleString)));
        
        foreach ($declarations as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) === 2) {
                $prop = trim($parts[0]);
                $val = trim($parts[1]);
                $val = trim(str_replace('!important', '', $val));
                if ($prop && $val) {
                    $styles[$prop] = $val;
                }
            }
        }
        
        return $styles;
    }
    
    /**
     * Convert styles array to CSS string
     */
    private function stylesToString(array $styles): string
    {
        $parts = [];
        foreach ($styles as $prop => $val) {
            $parts[] = "$prop: $val";
        }
        return implode('; ', $parts);
    }
    
    /**
     * Wrap element with inline style using HTML Code element
     * This is a workaround to inject inline styles
     */
    private function wrapWithInlineStyle(array $element, string $styleString): array
    {
        // Instead of modifying the element, we add a data attribute with the style
        // This is then picked up by Oxygen's rendering
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['attributes'] = $element['data']['properties']['settings']['attributes'] ?? [];
        $element['data']['properties']['settings']['attributes']['style'] = $styleString;
        
        return $element;
    }
    
    /**
     * Override convert to use our inline style version
     */
    public function convert(string $html): array
    {
        // Reset state
        $this->nodeIdCounter = 1;
        $this->extractedCss = '';
        $this->customClasses = [];
        $this->detectedIconLibraries = [];
        $this->cssRules = [];
        $this->firstBodyElementProcessed = false;
        $this->fixedHeaderDetected = false;
        $this->jsPatterns = [];
        $this->consumedCssSelectors = [];
        $this->report->reset();

        // Parse HTML
        $root = $this->parser->parase($html);
        if (!$root) {
            return [
                'success' => false,
                'error' => 'Failed to parse HTML',
                'errors' => $this->parser->getErrors(),
            ];
        }

        // Extract custom CSS from <style> tags
        $this->extractedCss = $this->extractStyleTags($this->parser->getDom());

        // Parse extracted CSS rules
        $this->cssRules = $this->cssParser->parase($this->extractedCss);

        // Get body content
        $bodyNodes = $this->parser->extractBodyContent($root);

        // Build element tree with inline styles
        $children = [];
        foreach ($bodyNodes as $node) {
            $element = $this->convertNodeWithInlineStyles($node);
            if ($element !== null) {
                $children[] = $element;
            }
        }

        // If single child, use it as root; otherwise wrap in container
        $rootElement = null;
        if (count($children) === 1) {
            $rootElement = $children[0];
        } elseif (count($children) > 1) {
            $rootElement = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => $children,
            ];
        }

        if ($rootElement === null) {
            return [
                'success' => false,
                'error' => 'No convertible content found in HTML',
            ];
        }

        $result = [
            'success' => true,
            'element' => $rootElement,
            'cssElement' => null, // No CSS Code element in inline mode
            'headLinkElements' => [],
            'iconScriptElements' => [],
            'detectedIconLibraries' => [],
            'extractedCss' => '',
            'customClasses' => [],
            'stats' => $this->report->toArray(),
        ];

        return $result;
    }
}