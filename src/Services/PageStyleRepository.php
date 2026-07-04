<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class PageStyleRepository
{
    public const META_KEY = '_oxy_html_converter_page_styles';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForPost(int $postId, array $payload): array
    {
        if ($postId < 1) {
            return [
                'saved' => false,
                'bytes' => 0,
                'hash' => '',
            ];
        }

        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        $pageScopedCss = is_string($payload['pageScopedCss'] ?? null)
            ? trim($payload['pageScopedCss'])
            : trim((string) ($routing['pageScopedCss'] ?? ''));

        if ($pageScopedCss === '') {
            if (function_exists('delete_post_meta')) {
                delete_post_meta($postId, self::META_KEY);
            }

            return [
                'saved' => false,
                'bytes' => 0,
                'hash' => '',
            ];
        }

        $payload = [
            'version' => 1,
            'updatedAt' => gmdate('c'),
            'css' => $pageScopedCss,
            'bytes' => strlen($pageScopedCss),
            'hash' => substr(sha1('oxy-html-converter-page-style:' . $pageScopedCss), 0, 16),
        ];

        if (function_exists('update_post_meta')) {
            update_post_meta($postId, self::META_KEY, wp_slash(wp_json_encode($payload)));
        }

        return [
            'saved' => true,
            'bytes' => $payload['bytes'],
            'hash' => $payload['hash'],
        ];
    }

    public function getCssForPost(int $postId): string
    {
        if ($postId < 1 || !function_exists('get_post_meta')) {
            return '';
        }

        $raw = get_post_meta($postId, self::META_KEY, true);
        $raw = is_string($raw) ? $raw : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) && $raw !== '') {
            $decoded = json_decode(stripslashes($raw), true);
        }

        if (!is_array($decoded)) {
            return '';
        }

        return is_string($decoded['css'] ?? null) ? trim($decoded['css']) : '';
    }
}
