<?php

namespace OxyHtmlConverter;

use DOMElement;
use DOMText;
use DOMNode;
use OxyHtmlConverter\Services\GridDetector;
use OxyHtmlConverter\ElementTypes;

/**
 * Maps HTML elements to Oxygen element types
 */
class ElementMapper
{
    private GridDetector $gridDetector;

    public function __construct()
    {
        $this->gridDetector = new GridDetector();
    }

    /**
     * HTML tag to Oxygen element type mapping
     * Uses ElementTypes constants for single source of truth
     */
    private const TAG_MAP = [
        // Container elements
        'div'     => ElementTypes::CONTAINER,
        'section' => ElementTypes::CONTAINER,
        'article' => ElementTypes::CONTAINER,
        'aside'   => ElementTypes::CONTAINER,
        'header'  => ElementTypes::CONTAINER,
        'footer'  => ElementTypes::CONTAINER,
        'main'    => ElementTypes::CONTAINER,
        'nav'     => ElementTypes::CONTAINER,
        'figure'  => ElementTypes::CONTAINER,
        'figcaption' => ElementTypes::CONTAINER,
        'details' => ElementTypes::CONTAINER,
        'summary' => ElementTypes::CONTAINER,
        'ul'      => ElementTypes::CONTAINER,
        'li'      => ElementTypes::CONTAINER,
        'ol'      => ElementTypes::CONTAINER,

        // Text elements
        'p'          => ElementTypes::TEXT,
        'span'       => ElementTypes::TEXT,
        'h1'         => ElementTypes::TEXT,
        'h2'         => ElementTypes::TEXT,
        'h3'         => ElementTypes::TEXT,
        'h4'         => ElementTypes::TEXT,
        'h5'         => ElementTypes::TEXT,
        'h6'         => ElementTypes::TEXT,
        'blockquote' => ElementTypes::TEXT,
        'label'      => ElementTypes::TEXT,

        // Links - will be converted to ContainerLink for button-like links
        'a' => ElementTypes::TEXT_LINK,

        // Button - converted to ContainerLink (with href) or Container (without)
        // This is handled specially in getElementType() and buildProperties()
        'button' => ElementTypes::CONTAINER,

        // Media
        'img'    => ElementTypes::IMAGE,
        'video'  => ElementTypes::HTML5_VIDEO,
        'iframe' => ElementTypes::HTML_CODE,
        'svg'    => ElementTypes::HTML_CODE,
        'i'      => ElementTypes::HTML_CODE,

        // Form elements (as HTML code for now)
        'form'     => ElementTypes::HTML_CODE,
        'input'    => ElementTypes::HTML_CODE,
        'textarea' => ElementTypes::HTML_CODE,
        'select'   => ElementTypes::HTML_CODE,

        // Tables (as rich text to preserve structure)
        'table' => ElementTypes::RICH_TEXT,

        // Code elements
        'pre'  => ElementTypes::HTML_CODE,
        'code' => ElementTypes::TEXT,
    ];

    /**
     * Tags that should use the tag option in Container
     */
    private const CONTAINER_TAG_OPTIONS = [
        'section', 'footer', 'header', 'nav', 'aside', 'figure',
        'article', 'main', 'details', 'summary', 'ul', 'li', 'ol', 'button'
    ];

    /**
     * Tags that should use the tag option in Text
     */
    private const TEXT_TAG_OPTIONS = [
        'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote'
    ];

    /**
     * Tags where children should be kept as inner HTML
     */
    private const KEEP_INNER_HTML = [
        'table', 'pre', 'code', 'svg', 'form',
        'select', 'textarea', 'iframe', 'video'
    ];

    /**
     * Get Oxygen element type for HTML tag
     *
     * @param string $tag HTML tag name
     * @param DOMElement|null $node Optional DOM element for context-aware mapping
     */
    public function getElementType(string $tag, ?DOMElement $node = null): string
    {
        $tag = strtolower($tag);

        // Special handling for links that should become ContainerLink
        if ($tag === 'a' && $node !== null) {
            // If the link has complex children or looks like a button, use ContainerLink
            if ($this->isButtonLikeLink($node)) {
                return ElementTypes::CONTAINER_LINK;
            }
        }

        // Removed Header mapping - using standard Container for navbar
        // It provides better compatibility in Oxygen 6 basic elements.

        return self::TAG_MAP[$tag] ?? ElementTypes::CONTAINER;
    }

    /**
     * Check if a link element should be treated as a button-like container
     */
    public function isButtonLikeLink(DOMElement $node): bool
    {
        $classAttr = strtolower($node->getAttribute('class'));

        // Check for button-like classes
        $buttonIndicators = ['btn', 'button', 'cta', 'action'];
        foreach ($buttonIndicators as $indicator) {
            if (strpos($classAttr, $indicator) !== false) {
                return true;
            }
        }

        // Check if link has block-level children (div, etc.)
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $childTag = strtolower($child->tagName);
                if (in_array($childTag, ['div', 'span', 'img', 'svg', 'i', 'icon'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a button/link element needs a child Text element
     */
    public function needsChildTextElement(DOMElement $node): bool
    {
        $tag = strtolower($node->tagName);

        // Only buttons get flat text children; links recurse their children naturally
        if ($tag === 'button') {
            return true;
        }

        return false;
    }

    /**
     * Build a child Text element for button content
     */
    public function buildChildTextElement(DOMElement $node, int $nodeId): ?array
    {
        $text = $this->getTextContent($node);

        if (empty(trim($text))) {
            return null;
        }

        return [
            'id' => $nodeId,
            'data' => [
                'type' => ElementTypes::TEXT,
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => $text,
                        ],
                    ],
                    // Buttons should contain phrasing content (not block-level wrappers).
                    // Force Text child to render as <span> inside <button>.
                    'design' => [
                        'tag' => 'span',
                    ],
                    'settings' => [
                        'advanced' => [
                            'tag' => 'span',
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
    }

    /**
     * Get text content from element, preserving inline formatting
     */
    private function getTextContent(DOMElement $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->textContent;
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                // Only include inline formatting elements
                if (in_array($tag, ['strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup', 'br', 'span'])) {
                    $text .= $node->ownerDocument->saveHTML($child);
                }
            }
        }
        return trim($text);
    }

    /**
     * Check if element is a container (can have children)
     */
    public function isContainer(string $tag, ?DOMElement $node = null): bool
    {
        $tag = strtolower($tag);
        $type = $this->getElementType($tag, $node);

        return $type === ElementTypes::CONTAINER ||
               $type === ElementTypes::CONTAINER_LINK ||
               $type === ElementTypes::TEXT_LINK; // Links can wrap other elements
    }

    /**
     * Check if inner HTML should be preserved as-is
     */
    public function shouldKeepInnerHtml(string $tag): bool
    {
        return in_array(strtolower($tag), self::KEEP_INNER_HTML);
    }

    /**
     * Check if element is text-based
     */
    public function isTextElement(string $tag): bool
    {
        $type = $this->getElementType(strtolower($tag));
        return in_array($type, [
            ElementTypes::TEXT,
            ElementTypes::RICH_TEXT,
            ElementTypes::TEXT_LINK,
        ]);
    }

    /**
     * Get tag option if applicable
     */
    public function getTagOption(string $tag): ?string
    {
        $tag = strtolower($tag);
        $type = $this->getElementType($tag);

        if ($type === ElementTypes::CONTAINER && in_array($tag, self::CONTAINER_TAG_OPTIONS)) {
            return $tag;
        }

        if ($type === ElementTypes::TEXT && in_array($tag, self::TEXT_TAG_OPTIONS)) {
            return $tag;
        }

        return null;
    }

    /**
     * Build properties for an element based on DOM node
     */
    public function buildProperties(DOMElement $node): array
    {
        $tag = strtolower($node->tagName);
        $type = $this->getElementType($tag, $node);
        $properties = [];

        switch ($type) {
            case ElementTypes::TEXT:
                $properties = $this->buildTextProperties($node);
                break;

            case ElementTypes::RICH_TEXT:
                $properties = $this->buildRichTextProperties($node);
                break;

            case ElementTypes::TEXT_LINK:
                $properties = $this->buildLinkProperties($node);
                break;

            case ElementTypes::CONTAINER_LINK:
                $properties = $this->buildContainerLinkProperties($node);
                break;

            case ElementTypes::IMAGE:
                $properties = $this->buildImageProperties($node);
                break;

            case ElementTypes::HTML_CODE:
                $properties = $this->buildHtmlCodeProperties($node);
                break;

            case ElementTypes::HTML5_VIDEO:
                $properties = $this->buildVideoProperties($node);
                break;

            case ElementTypes::CONTAINER:
            default:
                $properties = $this->buildContainerProperties($node);
                break;
        }

        return $properties;
    }

    /**
     * Build Text element properties
     */
    private function buildTextProperties(DOMElement $node): array
    {
        // Get inner HTML content (preserving inline formatting)
        $innerHTML = $this->getInnerHtml($node);

        return [
            'content' => [
                'content' => [
                    'text' => $innerHTML,
                ],
            ],
        ];
    }

    /**
     * Build RichText element properties
     */
    private function buildRichTextProperties(DOMElement $node): array
    {
        $innerHTML = $this->getInnerHtml($node);

        return [
            'content' => [
                'content' => [
                    'text' => $innerHTML,
                ],
            ],
        ];
    }

    /**
     * Build TextLink element properties
     */
    private function buildLinkProperties(DOMElement $node): array
    {
        $href = $node->getAttribute('href') ?: '#';
        $target = $node->getAttribute('target');
        $text = $this->getInnerHtml($node);

        $properties = [
            'content' => [
                'content' => [
                    'text' => $text,
                    'url' => $href,
                ],
            ],
            'design' => [
                'typography' => [
                    'text-decoration' => 'none',
                ],
            ],
        ];

        // If it's a nav link or looks like one, default to white
        if (($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'nav') || strpos($node->getAttribute('class'), 'nav') !== false) {
             $properties['design']['typography']['color'] = '#ffffff';
        }

        if ($target === '_blank') {
            $properties['content']['content']['open_in_new_tab'] = true;
        }

        return $properties;
    }

    /**
     * Build ContainerLink element properties (for button-like links)
     */
    private function buildContainerLinkProperties(DOMElement $node): array
    {
        $tag = strtolower($node->tagName);

        // Get URL from href (for <a>) or onclick (for <button>)
        $url = '#';
        if ($tag === 'a') {
            $url = $node->getAttribute('href') ?: '#';
        } elseif ($tag === 'button') {
            $onclick = $node->getAttribute('onclick');
            if (preg_match("/location\s*[=.]\s*['\"]([^'\"]+)['\"]/i", $onclick, $matches)) {
                $url = $matches[1];
            } elseif (preg_match("/window\.open\s*\(\s*['\"]([^'\"]+)['\"]/i", $onclick, $matches)) {
                $url = $matches[1];
            }
        }

        $target = $node->getAttribute('target');

        $properties = [
            'content' => [
                'content' => [
                    'url' => $url,
                ],
            ],
        ];

        if ($target === '_blank') {
            $properties['content']['content']['open_in_new_tab'] = true;
        }

        return $properties;
    }

    /**
     * Build Image element properties
     */
    private function buildImageProperties(DOMElement $node): array
    {
        $src = $node->getAttribute('src') ?: '';
        $alt = $node->getAttribute('alt') ?: '';

        $properties = [
            'content' => [
                'image' => [
                    'from' => 'url',
                    'url' => $src,
                    'lazy_load' => true,
                ],
            ],
        ];

        if ($alt) {
            $properties['content']['image']['alt_when_from_url'] = 'custom';
            $properties['content']['image']['custom_alt_when_from_url'] = $alt;
        }

        return $properties;
    }

    /**
     * Build HtmlCode element properties
     */
    private function buildHtmlCodeProperties(DOMElement $node): array
    {
        $outerHtml = $this->getOuterHtml($node);

        return [
            'content' => [
                'content' => [
                    'html_code' => $outerHtml,
                ],
            ],
        ];
    }

    /**
     * Build Video element properties
     */
    private function buildVideoProperties(DOMElement $node): array
    {
        // For video, use HTML code element as it's more flexible
        return $this->buildHtmlCodeProperties($node);
    }

    /**
     * Build Container element properties
     */
    private function buildContainerProperties(DOMElement $node): array
    {
        $classAttr = $node->getAttribute('class');
        $properties = [];

        // Check for grid and apply all detected properties
        $gridProps = $this->gridDetector->getGridProperties($classAttr);
        if (!empty($gridProps)) {
            $properties['design'] = $properties['design'] ?? [];
            $properties['design']['layout'] = array_merge($properties['design']['layout'] ?? [], $gridProps);
        }

        return $properties;
    }

    /**
     * Get inner HTML of an element
     */
    public function getInnerHtml(DOMElement $node): string
    {
        $innerHTML = '';
        foreach ($node->childNodes as $child) {
            $innerHTML .= $node->ownerDocument->saveHTML($child);
        }
        return trim($innerHTML);
    }

    /**
     * Get outer HTML of an element
     */
    public function getOuterHtml(DOMElement $node): string
    {
        return $node->ownerDocument->saveHTML($node);
    }

    /**
     * Check if a node has only text content (no child elements)
     */
    public function hasOnlyTextContent(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                // Check if this <i> is an icon element (not italic formatting)
                if ($tag === 'i' && $this->isIconElement($child)) {
                    return false; // Icon elements are not inline text
                }

                // Allow inline formatting elements
                if (!in_array($tag, ['strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup', 'br', 'span'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check if an <i> element is used as an icon (not italic text)
     */
    private function isIconElement(DOMElement $node): bool
    {
        // Lucide icons
        if ($node->hasAttribute('data-lucide')) {
            return true;
        }

        // Feather icons
        if ($node->hasAttribute('data-feather')) {
            return true;
        }

        // Font Awesome icons (class contains fa-, fas, far, fab, fal, fad)
        $class = $node->getAttribute('class');
        if (preg_match('/\b(fa-|fas|far|fab|fal|fad|fa)\b/', $class)) {
            return true;
        }

        // Material icons
        if (strpos($class, 'material-icons') !== false) {
            return true;
        }

        // Bootstrap icons
        if (preg_match('/\bbi-/', $class)) {
            return true;
        }

        // Iconify
        if ($node->hasAttribute('data-icon') || strpos($class, 'iconify') !== false) {
            return true;
        }

        // Empty <i> with no text is likely an icon
        if (trim($node->textContent) === '' && !$node->hasChildNodes()) {
            return true;
        }

        return false;
    }

    /**
     * Determine if a container with only text should become a Text element
     */
    public function shouldConvertToText(DOMElement $node): bool
    {
        $tag = strtolower($node->tagName);

        // Already mapped to text
        if ($this->isTextElement($tag)) {
            return false;
        }

        // Only convert if it has text content only
        return $this->hasOnlyTextContent($node);
    }
}
