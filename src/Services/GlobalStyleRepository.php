<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class GlobalStyleRepository
{
    public const OPTION_NAME = 'oxy_html_converter_global_styles';

    /**
     * @return array<string, mixed>
     */
    public function getLibrary(): array
    {
        $raw = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return $this->normalizeLibrary(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveFromPayload(array $payload): array
    {
        $routing = is_array($payload['styleRouting'] ?? null) ? $payload['styleRouting'] : [];
        $globalCss = is_string($payload['globalCss'] ?? null)
            ? trim($payload['globalCss'])
            : trim((string) ($routing['globalCss'] ?? ''));

        if ($globalCss === '') {
            return [
                'saved' => false,
                'changes' => 0,
                'library' => $this->getLibrary(),
            ];
        }

        $library = $this->getLibrary();
        $entry = [
            'id' => $this->deterministicId($globalCss),
            'type' => 'global_css',
            'label' => 'Imported global CSS asset',
            'css' => $globalCss,
            'bytes' => strlen($globalCss),
            'firstSeenAt' => gmdate('c'),
            'lastSeenAt' => gmdate('c'),
        ];

        $existingIndex = $this->findById($library['styles'], $entry['id']);
        $changes = 0;
        if ($existingIndex === null) {
            $library['styles'][] = $entry;
            $changes = 1;
        } else {
            $existing = is_array($library['styles'][$existingIndex]) ? $library['styles'][$existingIndex] : [];
            $entry['firstSeenAt'] = is_string($existing['firstSeenAt'] ?? null) ? $existing['firstSeenAt'] : $entry['firstSeenAt'];
            $library['styles'][$existingIndex] = array_merge($existing, $entry);
        }

        $library['updatedAt'] = gmdate('c');
        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, wp_json_encode($library));
        }

        return [
            'saved' => true,
            'changes' => $changes,
            'library' => $library,
        ];
    }

    public function getCombinedCss(): string
    {
        $library = $this->getLibrary();
        $css = [];

        foreach ($library['styles'] as $style) {
            if (!is_array($style)) {
                continue;
            }

            $value = is_string($style['css'] ?? null) ? trim($style['css']) : '';
            if ($value !== '') {
                $css[] = $value;
            }
        }

        return implode("\n\n", array_values(array_unique($css)));
    }

    /**
     * @param array<string, mixed> $library
     * @return array<string, mixed>
     */
    private function normalizeLibrary(array $library): array
    {
        return [
            'version' => 1,
            'updatedAt' => is_string($library['updatedAt'] ?? null) ? $library['updatedAt'] : '',
            'styles' => array_values(is_array($library['styles'] ?? null) ? $library['styles'] : []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function findById(array $items, string $id): ?int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        return null;
    }

    private function deterministicId(string $css): string
    {
        return substr(sha1('oxy-html-converter-global-style:' . $css), 0, 16);
    }
}
