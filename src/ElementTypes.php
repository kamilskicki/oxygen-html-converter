<?php

namespace OxyHtmlConverter;

/**
 * Centralized Oxygen element type constants
 *
 * This is the single source of truth for all Oxygen element type strings.
 * Oxygen 6 uses the OxygenElements namespace with PascalCase naming.
 *
 * @see https://developer.oxygenbuilder.com/
 */
final class ElementTypes
{
    // Container elements
    public const CONTAINER = 'OxygenElements\\Container';
    public const CONTAINER_LINK = 'OxygenElements\\ContainerLink';

    // Text elements
    public const TEXT = 'OxygenElements\\Text';
    public const TEXT_LINK = 'OxygenElements\\TextLink';
    public const RICH_TEXT = 'OxygenElements\\RichText';

    // Media elements
    public const IMAGE = 'OxygenElements\\Image';
    public const HTML5_VIDEO = 'OxygenElements\\Html5Video';

    // Code elements
    public const HTML_CODE = 'OxygenElements\\HtmlCode';
    public const CSS_CODE = 'OxygenElements\\CssCode';
    public const JAVASCRIPT_CODE = 'OxygenElements\\JavaScriptCode';

    // Header element (for sticky headers)
    public const HEADER = 'OxygenElements\\Header';

    // Breakdance Elements for Oxygen element
    public const ESSENTIAL_BUTTON = 'EssentialElements\\Button';

    /**
     * All valid element types
     */
    public const ALL_TYPES = [
        self::CONTAINER,
        self::CONTAINER_LINK,
        self::TEXT,
        self::TEXT_LINK,
        self::RICH_TEXT,
        self::IMAGE,
        self::HTML5_VIDEO,
        self::HTML_CODE,
        self::CSS_CODE,
        self::JAVASCRIPT_CODE,
        self::HEADER,
        self::ESSENTIAL_BUTTON,
    ];

    /**
     * Check if a type string is a valid Oxygen element type
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL_TYPES, true);
    }

    /**
     * Get the short name (without namespace) from a full type string
     */
    public static function getShortName(string $type): string
    {
        $pos = strrpos($type, '\\');
        return $pos !== false ? substr($type, $pos + 1) : $type;
    }
}
