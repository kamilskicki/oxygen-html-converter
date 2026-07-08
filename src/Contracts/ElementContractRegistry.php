<?php

namespace OxyHtmlConverter\Contracts;

use OxyHtmlConverter\ElementTypes;

/**
 * Canonical property-path contracts and generation status for stable Oxygen elements.
 */
final class ElementContractRegistry
{
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_FALLBACK_ONLY = 'fallback-only';
    public const STATUS_UNSAFE_DEFERRED = 'unsafe-deferred';
    public const STATUS_NEVER_GENERATED = 'never-generated';
    public const STATUS_FORBIDDEN = 'forbidden';

    /**
     * @return array<string, string>
     */
    public static function getFirstPartyElementStatuses(): array
    {
        return [
            ElementTypes::COMPONENT => self::STATUS_SUPPORTED,
            ElementTypes::CONTAINER => self::STATUS_SUPPORTED,
            ElementTypes::CONTAINER_LINK => self::STATUS_SUPPORTED,
            ElementTypes::CONTAINER_SHORTCODE => self::STATUS_FALLBACK_ONLY,
            ElementTypes::CSS_CODE => self::STATUS_FALLBACK_ONLY,
            ElementTypes::DYNAMIC_DATA_LOOP => self::STATUS_NEVER_GENERATED,
            ElementTypes::HTML_CODE => self::STATUS_FALLBACK_ONLY,
            ElementTypes::HTML5_VIDEO => self::STATUS_SUPPORTED,
            ElementTypes::IMAGE => self::STATUS_SUPPORTED,
            ElementTypes::JAVASCRIPT_CODE => self::STATUS_UNSAFE_DEFERRED,
            ElementTypes::OEMBED => self::STATUS_FALLBACK_ONLY,
            ElementTypes::PHP_CODE => self::STATUS_UNSAFE_DEFERRED,
            ElementTypes::POSTS_LOOP => self::STATUS_NEVER_GENERATED,
            ElementTypes::RICH_TEXT => self::STATUS_SUPPORTED,
            ElementTypes::SHORTCODE => self::STATUS_FALLBACK_ONLY,
            ElementTypes::SVG_ICON => self::STATUS_SUPPORTED,
            ElementTypes::TEMPLATE_CONTENT_AREA => self::STATUS_NEVER_GENERATED,
            ElementTypes::TERM_LOOP_BUILDER => self::STATUS_NEVER_GENERATED,
            ElementTypes::TEXT => self::STATUS_SUPPORTED,
            ElementTypes::TEXT_LINK => self::STATUS_SUPPORTED,
            ElementTypes::WP_WIDGET => self::STATUS_FALLBACK_ONLY,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function forbiddenElements(): array
    {
        return [
            'OxygenElements\\Header' => self::STATUS_FORBIDDEN,
        ];
    }

    public static function getStatus(string $elementType): ?string
    {
        $statuses = self::getFirstPartyElementStatuses();
        if (array_key_exists($elementType, $statuses)) {
            return $statuses[$elementType];
        }

        $forbidden = self::forbiddenElements();
        return $forbidden[$elementType] ?? null;
    }

    public static function isForbidden(string $elementType): bool
    {
        return self::getStatus($elementType) === self::STATUS_FORBIDDEN;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function contracts(): array
    {
        return [
            ElementTypes::TEXT_LINK => [
                'content.content.url',
            ],
            ElementTypes::CONTAINER_LINK => [
                'content.content.url',
            ],
            ElementTypes::IMAGE => [
                'content.image.url',
            ],
            ElementTypes::HTML5_VIDEO => [
                'content.content.video_file_url',
            ],
            ElementTypes::ESSENTIAL_BUTTON => [
                'content.content.text',
                'content.content.link.url',
            ],
            ElementTypes::COMPONENT => [
                'content.content.block.componentId',
                'content.content.block.targets',
                'content.content.block.properties',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getRequiredPropertyPaths(string $elementType): array
    {
        $contracts = self::contracts();
        return $contracts[$elementType] ?? [];
    }
}