<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class StyleRoutingService
{
    /**
     * @return array<string, mixed>
     */
    public function route(string $css, bool $windPressMode = false): array
    {
        $css = trim($css);
        $sections = $this->splitSections($css);
        $pageSections = [];
        $globalSections = [];
        $pageScopedSections = [];
        $routes = [];
        $droppedSections = [];
        $windPressSafetySections = [];

        foreach ($sections as $section) {
            $content = trim($section['css']);
            if ($content === '') {
                continue;
            }

            $type = $this->classifySection($content);

            if ($type === 'global_asset') {
                $partitioned = $this->partitionGlobalAssetSection($content);

                if ($partitioned['global'] !== '') {
                    $globalSections[] = $partitioned['global'];
                    $routes[] = $this->routeItem($type, 'global_styles', $partitioned['global'], 'Global asset CSS');
                }

                if ($partitioned['page'] !== '') {
                    $pageType = str_contains($partitioned['page'], '/* Extracted from <style> tag */')
                        ? 'source_style'
                        : 'page_fallback';
                    $pageSections[] = $partitioned['page'];
                    $routes[] = $this->routeItem($pageType, 'page_css', $partitioned['page'], $this->labelForType($pageType));
                }

                continue;
            }

            if ($type === 'tailwind_utility_fallback' && $windPressMode) {
                $pageScopedSections[] = $content;
                $windPressSafetySections[] = $content;
                $routes[] = $this->routeItem(
                    $type,
                    'page_scoped_styles',
                    $content,
                    'Tailwind utility fallback safety CSS for WindPress'
                );
                continue;
            }

            $pageSections[] = $content;
            $routes[] = $this->routeItem($type, 'page_css', $content, $this->labelForType($type));
        }

        $pageCss = $this->joinSections($pageSections);
        $globalCss = $this->joinSections($globalSections);
        $pageScopedCss = $this->joinSections($pageScopedSections);

        return [
            'version' => 1,
            'mode' => $windPressMode ? 'windpress' : 'native',
            'pageCss' => $pageCss,
            'globalCss' => $globalCss,
            'pageScopedCss' => $pageScopedCss,
            'routes' => $routes,
            'summary' => [
                'pageCssBytes' => strlen($pageCss),
                'globalCssBytes' => strlen($globalCss),
                'pageScopedCssBytes' => strlen($pageScopedCss),
                'droppedCssBytes' => strlen($this->joinSections($droppedSections)),
                'windPressSafetyCssBytes' => strlen($this->joinSections($windPressSafetySections)),
                'routeCount' => count($routes),
                'hasPageCss' => $pageCss !== '',
                'hasGlobalCss' => $globalCss !== '',
                'hasPageScopedCss' => $pageScopedCss !== '',
                'usesWindPressRuntime' => $windPressMode && $windPressSafetySections !== [],
            ],
        ];
    }

    /**
     * @return list<array{css:string}>
     */
    private function splitSections(string $css): array
    {
        if ($css === '') {
            return [];
        }

        $pattern = '/(?=\/\*\s*(?:Extracted from <style> tag|Tailwind utility fallback)\s*\*\/)/';
        $parts = preg_split($pattern, $css, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts)) {
            return [['css' => $css]];
        }

        $sections = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $sections[] = ['css' => $part];
            }
        }

        return $sections;
    }

    private function classifySection(string $css): string
    {
        if (str_contains($css, '/* Tailwind utility fallback */')) {
            return 'tailwind_utility_fallback';
        }

        if (preg_match('/\.ohc-native-\d+\s*\{/', $css) === 1) {
            return 'native_mirror';
        }

        if (
            str_contains($css, '.material-symbols-outlined')
            || preg_match('/@font-face|@import\s+url\([^)]*fonts\.googleapis\.com/i', $css) === 1
        ) {
            return 'global_asset';
        }

        if (str_contains($css, '/* Extracted from <style> tag */')) {
            return 'source_style';
        }

        return 'page_fallback';
    }

    private function labelForType(string $type): string
    {
        return match ($type) {
            'native_mirror' => 'Native style mirror CSS',
            'tailwind_utility_fallback' => 'Tailwind utility fallback CSS',
            'source_style' => 'Source style CSS',
            'page_fallback' => 'Page fallback CSS',
            default => 'CSS',
        };
    }

    /**
     * @return array{global:string,page:string}
     */
    private function partitionGlobalAssetSection(string $css): array
    {
        $matches = [];
        $matched = preg_match_all(
            '/(?:\/\*.*?\*\/\s*)?(?:@import\s+[^;]+;|@font-face\s*\{[^{}]*\}|[^{}]+\{[^{}]*\})/is',
            $css,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return [
                'global' => $css,
                'page' => '',
            ];
        }

        $global = [];
        $page = [];
        $remainder = $css;

        foreach ($matches[0] as $match) {
            $rule = trim((string) $match);
            if ($rule === '') {
                continue;
            }

            $remainder = str_replace($match, '', $remainder);
            if ($this->isGlobalAssetRule($rule)) {
                $global[] = $rule;
            } else {
                $page[] = $rule;
            }
        }

        $remainder = trim($remainder);
        if ($remainder !== '') {
            $page[] = $remainder;
        }

        return [
            'global' => $this->joinSections($global),
            'page' => $this->joinSections($page),
        ];
    }

    private function isGlobalAssetRule(string $css): bool
    {
        return str_contains($css, '.material-symbols-outlined')
            || preg_match('/@font-face|@import\s+[^;]*fonts\.googleapis\.com/i', $css) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeItem(string $type, string $destination, string $css, string $label): array
    {
        return [
            'type' => $type,
            'destination' => $destination,
            'label' => $label,
            'bytes' => strlen($css),
            'ruleCount' => $this->countRules($css),
            'hash' => substr(sha1($type . ':' . $destination . ':' . $css), 0, 16),
        ];
    }

    private function countRules(string $css): int
    {
        $count = substr_count($css, '{');

        return max(1, $count);
    }

    /**
     * @param list<string> $sections
     */
    private function joinSections(array $sections): string
    {
        $sections = array_values(array_filter(array_map('trim', $sections), static fn (string $section): bool => $section !== ''));

        return implode("\n\n", $sections);
    }
}
