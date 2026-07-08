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
    public const CONTAINER_SHORTCODE = 'OxygenElements\\ContainerShortcode';

    // Text elements
    public const TEXT = 'OxygenElements\\Text';
    public const TEXT_LINK = 'OxygenElements\\TextLink';
    public const RICH_TEXT = 'OxygenElements\\RichText';

    // Media elements
    public const IMAGE = 'OxygenElements\\Image';
    public const SVG_ICON = 'OxygenElements\\SvgIcon';
    public const HTML5_VIDEO = 'OxygenElements\\Html5Video';
    public const OEMBED = 'OxygenElements\\oEmbed';

    // Loop and template elements
    public const DYNAMIC_DATA_LOOP = 'OxygenElements\\DynamicDataLoop';
    public const POSTS_LOOP = 'OxygenElements\\PostsLoop';
    public const TEMPLATE_CONTENT_AREA = 'OxygenElements\\TemplateContentArea';
    public const TERM_LOOP_BUILDER = 'OxygenElements\\TermLoopBuilder';

    // Embed and WordPress integration elements
    public const SHORTCODE = 'OxygenElements\\Shortcode';
    public const WP_WIDGET = 'OxygenElements\\WpWidget';

    // Code elements
    public const HTML_CODE = 'OxygenElements\\HtmlCode';
    public const CSS_CODE = 'OxygenElements\\CssCode';
    public const JAVASCRIPT_CODE = 'OxygenElements\\JavaScriptCode';
    public const PHP_CODE = 'OxygenElements\\PhpCode';

    // Reusable block component instance
    public const COMPONENT = 'OxygenElements\\Component';

    // Breakdance Elements for Oxygen element
    public const ESSENTIAL_BUTTON = 'EssentialElements\\Button';

    /**
     * Oxygen 6.1 stable first-party elements from oxygen/subplugins/oxygen-elements/elements.
     */
    public const FIRST_PARTY_OXYGEN_TYPES = [
        self::COMPONENT,
        self::CONTAINER,
        self::CONTAINER_LINK,
        self::CONTAINER_SHORTCODE,
        self::CSS_CODE,
        self::DYNAMIC_DATA_LOOP,
        self::HTML_CODE,
        self::HTML5_VIDEO,
        self::IMAGE,
        self::JAVASCRIPT_CODE,
        self::OEMBED,
        self::PHP_CODE,
        self::POSTS_LOOP,
        self::RICH_TEXT,
        self::SHORTCODE,
        self::SVG_ICON,
        self::TEMPLATE_CONTENT_AREA,
        self::TERM_LOOP_BUILDER,
        self::TEXT,
        self::TEXT_LINK,
        self::WP_WIDGET,
    ];

    /**
     * All valid element types this converter may recognize.
     */
    public const ALL_TYPES = [
        self::COMPONENT,
        self::CONTAINER,
        self::CONTAINER_LINK,
        self::CONTAINER_SHORTCODE,
        self::CSS_CODE,
        self::DYNAMIC_DATA_LOOP,
        self::HTML_CODE,
        self::HTML5_VIDEO,
        self::IMAGE,
        self::JAVASCRIPT_CODE,
        self::OEMBED,
        self::PHP_CODE,
        self::POSTS_LOOP,
        self::RICH_TEXT,
        self::SHORTCODE,
        self::SVG_ICON,
        self::TEMPLATE_CONTENT_AREA,
        self::TERM_LOOP_BUILDER,
        self::TEXT,
        self::TEXT_LINK,
        self::WP_WIDGET,
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