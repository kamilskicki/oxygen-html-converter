<?php

namespace OxyHtmlConverter;

use DOMElement;

/**
 * Extracts inline styles and maps them to Oxygen properties
 */
class StyleExtractor
{
    /**
     * CSS property to Oxygen property path mapping
     */
    private const STYLE_MAP = [
        // Typography
        'font-family'      => ['typography', 'font-family'],
        'font-size'        => ['typography', 'font-size'],
        'font-weight'      => ['typography', 'font-weight'],
        'font-style'       => ['typography', 'font-style'],
        'line-height'      => ['typography', 'line-height'],
        'letter-spacing'   => ['typography', 'letter-spacing'],
        'text-align'       => ['typography', 'text-align'],
        'text-decoration'  => ['typography', 'text-decoration'],
        'text-transform'   => ['typography', 'text-transform'],
        'color'            => ['typography', 'color'],
        'white-space'      => ['typography', 'white-space'],
        'word-break'       => ['typography', 'word-break'],
        'text-overflow'    => ['typography', 'text-overflow'],

        // Spacing
        'margin'           => ['spacing', 'margin'],
        'margin-top'       => ['spacing', 'margin-top'],
        'margin-right'     => ['spacing', 'margin-right'],
        'margin-bottom'    => ['spacing', 'margin-bottom'],
        'margin-left'      => ['spacing', 'margin-left'],
        'padding'          => ['spacing', 'padding'],
        'padding-top'      => ['spacing', 'padding-top'],
        'padding-right'    => ['spacing', 'padding-right'],
        'padding-bottom'   => ['spacing', 'padding-bottom'],
        'padding-left'     => ['spacing', 'padding-left'],

        // Size
        'width'            => ['size', 'width'],
        'min-width'        => ['size', 'min-width'],
        'max-width'        => ['size', 'max-width'],
        'height'           => ['size', 'height'],
        'min-height'       => ['size', 'min-height'],
        'max-height'       => ['size', 'max-height'],
        'aspect-ratio'     => ['size', 'aspect-ratio'],

        // Layout
        'display'          => ['layout', 'display'],
        'flex-direction'   => ['layout', 'flex-direction'],
        'flex-wrap'        => ['layout', 'flex-wrap'],
        'justify-content'  => ['layout', 'justify-content'],
        'align-items'      => ['layout', 'align-items'],
        'align-content'    => ['layout', 'align-content'],
        'gap'              => ['layout', 'gap'],
        'row-gap'          => ['layout', 'row-gap'],
        'column-gap'       => ['layout', 'column-gap'],
        'flex-grow'        => ['layout', 'flex-grow'],
        'flex-shrink'      => ['layout', 'flex-shrink'],
        'flex-basis'       => ['layout', 'flex-basis'],
        'order'            => ['layout', 'order'],
        'grid-column'      => ['layout', 'grid-column'],
        'grid-row'         => ['layout', 'grid-row'],

        // Position
        'position'         => ['position', 'position'],
        'top'              => ['position', 'top'],
        'right'            => ['position', 'right'],
        'bottom'           => ['position', 'bottom'],
        'left'             => ['position', 'left'],
        'z-index'          => ['position', 'z-index'],

        // Background
        'background'       => ['background', 'background'],
        'background-color' => ['background', 'background-color'],
        'background-image' => ['background', 'background-image'],
        'background-size'  => ['background', 'background-size'],
        'background-position' => ['background', 'background-position'],
        'background-repeat' => ['background', 'background-repeat'],

        // Border
        'border'           => ['border', 'border'],
        'border-width'     => ['border', 'border-width'],
        'border-style'     => ['border', 'border-style'],
        'border-color'     => ['border', 'border-color'],
        'border-radius'    => ['border', 'border-radius'],
        'border-top'       => ['border', 'border-top'],
        'border-right'     => ['border', 'border-right'],
        'border-bottom'    => ['border', 'border-bottom'],
        'border-left'      => ['border', 'border-left'],
        'border-top-left-radius'     => ['border', 'border-top-left-radius'],
        'border-top-right-radius'    => ['border', 'border-top-right-radius'],
        'border-bottom-left-radius'  => ['border', 'border-bottom-left-radius'],
        'border-bottom-right-radius' => ['border', 'border-bottom-right-radius'],
        'outline'          => ['border', 'outline'],

        // Effects
        'opacity'          => ['effects', 'opacity'],
        'box-shadow'       => ['effects', 'box-shadow'],
        'transform'        => ['effects', 'transform'],
        'transition'       => ['effects', 'transition'],
        'filter'           => ['effects', 'filter'],
        'cursor'           => ['effects', 'cursor'],
        'backdrop-filter'  => ['effects', 'backdrop-filter'],
        'mix-blend-mode'   => ['effects', 'mix-blend-mode'],

        // Overflow
        'overflow'         => ['overflow', 'overflow'],
        'overflow-x'       => ['overflow', 'overflow-x'],
        'overflow-y'       => ['overflow', 'overflow-y'],
    ];

    /**
     * Extract styles from DOM element
     */
    public function extract(DOMElement $node): array
    {
        $styles = [];

        // Get inline style attribute
        $styleAttr = $node->getAttribute('style');
        if ($styleAttr) {
            $styles = array_merge($styles, $this->parseInlineStyles($styleAttr));
        }

        // Get class attribute for reference (stored but not converted)
        $classAttr = $node->getAttribute('class');
        if ($classAttr) {
            $styles['_original_classes'] = $classAttr;
        }

        return $styles;
    }

    /**
     * Parse inline style string into array
     */
    public function parseInlineStyles(string $styleString): array
    {
        $styles = [];
        $declarations = array_filter(array_map('trim', explode(';', $styleString)));

        foreach ($declarations as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) === 2) {
                $property = trim($parts[0]);
                $value = trim($parts[1]);

                // Remove !important
                $value = str_replace('!important', '', $value);
                $value = trim($value);

                if ($property !== '' && $value !== '') {
                    $styles[$property] = $value;
                }
            }
        }

        return $styles;
    }

    /**
     * Convert extracted styles to Oxygen properties format
     */
    public function toOxygenProperties(array $styles): array
    {
        $properties = [];

        foreach ($styles as $cssProp => $value) {
            // Skip internal properties
            if (strpos($cssProp, '_') === 0) {
                continue;
            }

            if (isset(self::STYLE_MAP[$cssProp])) {
                $path = self::STYLE_MAP[$cssProp];
                $this->setNestedValue($properties, $path, $value);
            }
        }

        return $properties;
    }

    /**
     * Set a nested array value by path
     */
    private function setNestedValue(array &$array, array $path, $value): void
    {
        $current = &$array;
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }

    /**
     * Extract and convert styles in one step
     */
    public function extractAndConvert(DOMElement $node): array
    {
        $styles = $this->extract($node);
        return $this->toOxygenProperties($styles);
    }

    /**
     * Parse shorthand margin/padding values
     */
    public function parseShorthandSpacing(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value));
        $result = [];

        switch (count($parts)) {
            case 1:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[0],
                    'bottom' => $parts[0],
                    'left' => $parts[0],
                ];
                break;
            case 2:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[0],
                    'left' => $parts[1],
                ];
                break;
            case 3:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[2],
                    'left' => $parts[1],
                ];
                break;
            case 4:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[2],
                    'left' => $parts[3],
                ];
                break;
        }

        return $result;
    }

    /**
     * Convert color value to standard format
     */
    public function normalizeColor(string $color): string
    {
        $color = trim($color);

        // Already hex or rgb/rgba
        if (preg_match('/^#|^rgb/i', $color)) {
            return $color;
        }

        // Named colors - return as-is (browser will handle)
        return $color;
    }

    /**
     * Get original CSS classes from extracted styles
     */
    public function getOriginalClasses(array $styles): array
    {
        if (isset($styles['_original_classes'])) {
            return array_filter(array_map('trim', explode(' ', $styles['_original_classes'])));
        }
        return [];
    }
}
