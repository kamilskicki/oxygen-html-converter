<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

final class SiteConfigurationImporter
{
    /**
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    public function apply(array $manifest, array $pages): array
    {
        $pageIndex = $this->buildPageIndex($pages);
        $validation = $this->validate($manifest, $pageIndex);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Site configuration manifest failed validation.',
                'errors' => $validation['errors'],
                'options' => [],
                'menus' => [],
                'placements' => [],
                'rollback' => $this->emptyRollbackSnapshot(),
            ];
        }

        $rollback = $this->captureRollbackSnapshot($manifest);
        $options = [];
        $menus = [];
        $placements = [];

        try {
            $homepage = $this->normalizeHomepage($manifest['homepage'] ?? null);
            if ($homepage !== []) {
                $postId = $this->resolvePageReference($homepage['pageId'] ?? null, $pageIndex);
                $postsPageId = $this->homepagePostsPageId($homepage, $pageIndex);
                if ($postId > 0) {
                    $options = array_merge($options, $this->applyHomepageOptions($postId, $postsPageId));
                }
            }

            foreach ($this->menuRecords($manifest) as $menuRecord) {
                $menuResult = $this->applyMenu($menuRecord, $pageIndex, $rollback);
                $menus[] = $menuResult['menu'];
                if ($menuResult['placement'] !== null) {
                    $placements[] = $menuResult['placement'];
                }
            }
        } catch (\Throwable $e) {
            $restore = $this->restore($rollback);
            $restoreErrors = is_array($restore['errors'] ?? null) ? array_map('strval', $restore['errors']) : [];

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Site configuration import failed after partial persistence.',
                'errors' => array_merge([$e->getMessage()], $restoreErrors),
                'options' => $options,
                'menus' => $menus,
                'placements' => $placements,
                'rollback' => $rollback,
                'restore' => $restore,
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'options' => $options,
            'menus' => $menus,
            'placements' => $placements,
            'rollback' => $rollback,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function restore(array $snapshot): array
    {
        $stores = is_array($snapshot['stores'] ?? null) ? $snapshot['stores'] : [];
        $errors = [];
        $restored = 0;

        for ($index = count($stores) - 1; $index >= 0; $index--) {
            $store = $stores[$index];
            if (!is_array($store)) {
                continue;
            }

            if (!$this->restoreStore($store)) {
                $errors[] = (string) ($store['store'] ?? 'unknown') . ':' . (string) ($store['key'] ?? '');
                break;
            }

            $restored++;
        }

        return [
            'success' => $errors === [],
            'status' => $errors === [] ? 200 : 500,
            'restored' => $restored,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>}
     */
    private function buildPageIndex(array $pages): array
    {
        $index = [
            'byId' => [],
            'bySlug' => [],
            'byPostId' => [],
        ];

        foreach ($pages as $page) {
            $postId = (int) ($page['postId'] ?? 0);
            if ($postId < 1) {
                continue;
            }

            $id = is_scalar($page['id'] ?? null) ? trim((string) $page['id']) : '';
            if ($id !== '') {
                $index['byId'][$id] = $postId;
            }

            $slug = is_scalar($page['slug'] ?? null) ? sanitize_title((string) $page['slug']) : '';
            if ($slug !== '') {
                $index['bySlug'][$slug] = $postId;
            }

            $index['byPostId'][$postId] = $postId;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     * @return array{valid: bool, errors: list<string>}
     */
    private function validate(array $manifest, array $pageIndex): array
    {
        $errors = [];
        $homepage = $this->normalizeHomepage($manifest['homepage'] ?? null);

        if ($homepage !== [] && $this->resolvePageReference($homepage['pageId'] ?? null, $pageIndex) < 1) {
            $errors[] = 'Homepage target "' . (string) ($homepage['pageId'] ?? '') . '" could not be resolved.';
        }

        if ($homepage !== [] && $this->homepageHasPostsPageReference($homepage) && $this->homepagePostsPageId($homepage, $pageIndex) < 1) {
            $errors[] = 'Posts page target "' . $this->homepagePostsPageReference($homepage) . '" could not be resolved.';
        }

        foreach ($this->menuRecords($manifest) as $menuIndex => $menu) {
            if ($this->menuName($menu, (int) $menuIndex) === '') {
                $errors[] = '$.menus[' . (int) $menuIndex . '].name expected non-empty string.';
            }

            foreach ($this->menuItems($menu) as $itemIndex => $item) {
                if ($this->menuItemTargetPostId($item, $pageIndex) > 0 || $this->menuItemUrl($item) !== '') {
                    continue;
                }

                $errors[] = '$.menus[' . (int) $menuIndex . '].items[' . (int) $itemIndex
                    . '] expected targetPageId, pageId, postId, slug, or url.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function captureRollbackSnapshot(array $manifest): array
    {
        $stores = [
            $this->captureOptionStore('show_on_front'),
            $this->captureOptionStore('page_on_front'),
            $this->captureOptionStore('page_for_posts'),
            $this->captureThemeModStore('nav_menu_locations'),
        ];

        foreach ($this->menuRecords($manifest) as $menu) {
            $existing = $this->findMenu($menu);
            if ($existing !== null) {
                $stores[] = $this->captureMenuStore((int) $existing->term_id);
            }
        }

        return [
            'version' => 1,
            'capturedAt' => gmdate('c'),
            'stores' => $stores,
        ];
    }

    /**
     * @param array<string, mixed> $menu
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     * @param array<string, mixed> $rollback
     * @return array{menu: array<string, mixed>, placement: array<string, mixed>|null}
     */
    private function applyMenu(array $menu, array $pageIndex, array &$rollback): array
    {
        $existing = $this->findMenu($menu);
        $action = 'selected';

        if ($existing === null) {
            $menuId = $this->createMenu($this->menuName($menu, 0));
            $this->appendRollbackStore($rollback, [
                'storeType' => 'nav_menu',
                'store' => 'nav_menu',
                'key' => (string) $menuId,
                'menuId' => $menuId,
                'oldExists' => false,
                'oldValue' => null,
                'restoreOperation' => 'wp_delete_nav_menu',
            ]);
            $existing = $this->findMenu(['menuId' => $menuId]);
            $action = 'created';
        }

        if ($existing === null) {
            throw new \RuntimeException('Failed to create or select nav menu.');
        }

        $menuId = (int) $existing->term_id;
        $items = [];

        foreach ($this->menuItems($menu) as $position => $item) {
            $items[] = $this->applyMenuItem($menuId, $item, (int) $position, $pageIndex, $rollback);
        }

        $placement = $this->applyMenuPlacement($menu, $menuId);

        return [
            'menu' => [
                'id' => $this->recordString($menu, 'id', (string) $menuId),
                'name' => (string) $existing->name,
                'slug' => (string) $existing->slug,
                'menuId' => $menuId,
                'action' => $action,
                'items' => $items,
            ],
            'placement' => $placement,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>
     */
    private function applyMenuItem(int $menuId, array $item, int $position, array $pageIndex, array &$rollback): array
    {
        $targetPostId = $this->menuItemTargetPostId($item, $pageIndex);
        $url = $this->menuItemUrl($item);
        $existing = $this->findMenuItem($menuId, $item, $targetPostId, $url);
        if ($existing !== null) {
            return [
                'label' => (string) $existing->title,
                'itemId' => (int) $existing->ID,
                'action' => 'existing',
                'targetPostId' => $targetPostId,
                'url' => $url,
            ];
        }

        $label = $this->recordString($item, 'label', $this->recordString($item, 'title', 'Menu item'));
        $payload = [
            'menu-item-title' => $label,
            'menu-item-position' => $position + 1,
            'menu-item-status' => 'publish',
        ];

        if ($targetPostId > 0) {
            $payload['menu-item-object-id'] = $targetPostId;
            $payload['menu-item-object'] = 'page';
            $payload['menu-item-type'] = 'post_type';
        } else {
            $payload['menu-item-url'] = $url;
            $payload['menu-item-type'] = 'custom';
        }

        $itemId = function_exists('wp_update_nav_menu_item') ? wp_update_nav_menu_item($menuId, 0, $payload) : 0;
        if (function_exists('is_wp_error') && is_wp_error($itemId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal importer error, not rendered directly.
            throw new \RuntimeException($itemId->get_error_message());
        }

        $itemId = (int) $itemId;
        if ($itemId < 1) {
            throw new \RuntimeException('Failed to create nav menu item.');
        }

        $this->appendRollbackStore($rollback, [
            'storeType' => 'nav_menu_item',
            'store' => 'nav_menu_item',
            'key' => (string) $itemId,
            'menuId' => $menuId,
            'itemId' => $itemId,
            'oldExists' => false,
            'oldValue' => null,
            'restoreOperation' => 'wp_delete_post',
        ]);

        return [
            'label' => $label,
            'itemId' => $itemId,
            'action' => 'created',
            'targetPostId' => $targetPostId,
            'url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $menu
     * @return array<string, mixed>|null
     */
    private function applyMenuPlacement(array $menu, int $menuId): ?array
    {
        $location = $this->recordString($menu, 'location', $this->recordString($menu, 'themeLocation', ''));
        if ($location === '') {
            return null;
        }

        $oldLocations = function_exists('get_theme_mod') ? get_theme_mod('nav_menu_locations', []) : [];
        $locations = is_array($oldLocations) ? $oldLocations : [];
        $previous = (int) ($locations[$location] ?? 0);
        $locations[$location] = $menuId;

        if (function_exists('set_theme_mod')) {
            set_theme_mod('nav_menu_locations', $locations);
        }

        return [
            'location' => $location,
            'menuId' => $menuId,
            'previousMenuId' => $previous,
            'changed' => $previous !== $menuId,
        ];
    }

    /**
     * @return list<array{option: string, oldValue: mixed, newValue: mixed, changed: bool}>
     */
    private function applyHomepageOptions(int $postId, int $postsPageId): array
    {
        $changes = [];
        $options = [
            'show_on_front' => 'page',
            'page_on_front' => $postId,
        ];

        if ($postsPageId > 0) {
            $options['page_for_posts'] = $postsPageId;
        }

        foreach ($options as $option => $value) {
            $oldValue = function_exists('get_option') ? get_option((string) $option, null) : null;
            if (function_exists('update_option')) {
                update_option((string) $option, $value);
            }

            $changes[] = [
                'option' => (string) $option,
                'oldValue' => $oldValue,
                'newValue' => $value,
                'changed' => $oldValue !== $value,
            ];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $homepage
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     */
    private function homepagePostsPageId(array $homepage, array $pageIndex): int
    {
        if (!$this->homepageHasPostsPageReference($homepage)) {
            return 0;
        }

        foreach (['postsPageId', 'blogPageId', 'pageForPosts', 'page_for_posts'] as $key) {
            $postId = $this->resolvePageReference($homepage[$key] ?? null, $pageIndex);
            if ($postId > 0) {
                return $postId;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $homepage
     */
    private function homepageHasPostsPageReference(array $homepage): bool
    {
        foreach (['postsPageId', 'blogPageId', 'pageForPosts', 'page_for_posts'] as $key) {
            if (array_key_exists($key, $homepage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $homepage
     */
    private function homepagePostsPageReference(array $homepage): string
    {
        foreach (['postsPageId', 'blogPageId', 'pageForPosts', 'page_for_posts'] as $key) {
            if (is_scalar($homepage[$key] ?? null)) {
                return trim((string) $homepage[$key]);
            }
        }

        return '';
    }

    /**
     * @param mixed $homepage
     * @return array<string, mixed>
     */
    private function normalizeHomepage($homepage): array
    {
        if (is_array($homepage)) {
            return $homepage;
        }

        if (is_scalar($homepage) && trim((string) $homepage) !== '') {
            return ['pageId' => trim((string) $homepage)];
        }

        return [];
    }

    /**
     * @param mixed $reference
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     */
    private function resolvePageReference($reference, array $pageIndex): int
    {
        if (is_array($reference)) {
            foreach (['pageId', 'id', 'slug', 'postId', 'post_id'] as $key) {
                $postId = $this->resolvePageReference($reference[$key] ?? null, $pageIndex);
                if ($postId > 0) {
                    return $postId;
                }
            }

            return 0;
        }

        if (!is_scalar($reference)) {
            return 0;
        }

        $value = trim((string) $reference);
        if ($value === '') {
            return 0;
        }

        if (isset($pageIndex['byId'][$value])) {
            return $pageIndex['byId'][$value];
        }

        $slug = sanitize_title($value);
        if (isset($pageIndex['bySlug'][$slug])) {
            return $pageIndex['bySlug'][$slug];
        }

        if (ctype_digit($value) && isset($pageIndex['byPostId'][(int) $value])) {
            return $pageIndex['byPostId'][(int) $value];
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function menuRecords(array $manifest): array
    {
        $menus = is_array($manifest['menus'] ?? null) ? $manifest['menus'] : [];
        $records = [];

        foreach ($menus as $menu) {
            if (is_array($menu)) {
                $records[] = $menu;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $menu
     * @return list<array<string, mixed>>
     */
    private function menuItems(array $menu): array
    {
        $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];
        $records = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function menuName(array $menu, int $index): string
    {
        return $this->recordString($menu, 'name', $this->recordString($menu, 'title', 'Menu ' . ($index + 1)));
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function findMenu(array $menu): ?object
    {
        foreach (['menuId', 'termId', 'id', 'slug', 'name'] as $key) {
            $value = $menu[$key] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $menuObject = function_exists('wp_get_nav_menu_object') ? wp_get_nav_menu_object($value) : false;
            if (is_object($menuObject) && isset($menuObject->term_id)) {
                return $menuObject;
            }
        }

        return null;
    }

    private function createMenu(string $name): int
    {
        $result = function_exists('wp_create_nav_menu') ? wp_create_nav_menu($name) : 0;
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal importer error, not rendered directly.
            throw new \RuntimeException($result->get_error_message());
        }

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{byId: array<string, int>, bySlug: array<string, int>, byPostId: array<int, int>} $pageIndex
     */
    private function menuItemTargetPostId(array $item, array $pageIndex): int
    {
        foreach (['targetPageId', 'pageId', 'id', 'slug', 'postId', 'post_id'] as $key) {
            $postId = $this->resolvePageReference($item[$key] ?? null, $pageIndex);
            if ($postId > 0) {
                return $postId;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function menuItemUrl(array $item): string
    {
        $url = is_scalar($item['url'] ?? null) ? trim((string) $item['url']) : '';
        return $url !== '' ? esc_url_raw($url) : '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function findMenuItem(int $menuId, array $item, int $targetPostId, string $url): ?object
    {
        $items = function_exists('wp_get_nav_menu_items') ? wp_get_nav_menu_items($menuId) : [];

        foreach ($items as $existing) {
            if (!is_object($existing)) {
                continue;
            }

            if ($targetPostId > 0
                && (int) ($existing->object_id ?? 0) === $targetPostId
                && (string) ($existing->type ?? '') === 'post_type'
            ) {
                return $existing;
            }

            if ($url !== '' && (string) ($existing->url ?? '') === $url) {
                return $existing;
            }
        }

        return null;
    }

    private function captureOptionStore(string $option): array
    {
        $exists = $this->optionExists($option);

        return [
            'storeType' => 'option',
            'store' => 'site_option',
            'key' => $option,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_option') ? get_option($option) : null,
            'restoreOperation' => $exists ? 'update_option' : 'delete_option',
        ];
    }

    private function captureThemeModStore(string $name): array
    {
        $exists = $this->themeModExists($name);

        return [
            'storeType' => 'theme_mod',
            'store' => 'theme_mod',
            'key' => $name,
            'oldExists' => $exists,
            'oldValue' => $exists && function_exists('get_theme_mod') ? get_theme_mod($name) : null,
            'restoreOperation' => $exists ? 'set_theme_mod' : 'remove_theme_mod',
        ];
    }

    private function captureMenuStore(int $menuId): array
    {
        return [
            'storeType' => 'nav_menu',
            'store' => 'nav_menu',
            'key' => (string) $menuId,
            'menuId' => $menuId,
            'oldExists' => true,
            'oldValue' => [
                'menu' => $this->menuSnapshot($menuId),
                'items' => $this->menuItemSnapshots($menuId),
            ],
            'restoreOperation' => 'restore_nav_menu',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function menuSnapshot(int $menuId): ?array
    {
        $menu = function_exists('wp_get_nav_menu_object') ? wp_get_nav_menu_object($menuId) : false;
        if (!is_object($menu)) {
            return null;
        }

        return [
            'term_id' => (int) $menu->term_id,
            'name' => (string) $menu->name,
            'slug' => (string) $menu->slug,
            'taxonomy' => (string) ($menu->taxonomy ?? 'nav_menu'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuItemSnapshots(int $menuId): array
    {
        $items = function_exists('wp_get_nav_menu_items') ? wp_get_nav_menu_items($menuId) : [];
        $snapshots = [];

        foreach ($items as $item) {
            if (is_object($item)) {
                $snapshots[] = get_object_vars($item);
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $rollback
     * @param array<string, mixed> $store
     */
    private function appendRollbackStore(array &$rollback, array $store): void
    {
        $rollback['stores'] = is_array($rollback['stores'] ?? null) ? $rollback['stores'] : [];
        $rollback['stores'][] = $store;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function restoreStore(array $store): bool
    {
        $type = (string) ($store['storeType'] ?? '');
        $key = (string) ($store['key'] ?? '');
        $exists = (bool) ($store['oldExists'] ?? false);
        $value = $store['oldValue'] ?? null;

        if ($type === 'option') {
            if ($exists) {
                if (function_exists('update_option')) {
                    update_option($key, $value);
                }

                return !function_exists('get_option') || get_option($key, null) === $value;
            }

            if (function_exists('delete_option')) {
                delete_option($key);
            } elseif (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
                unset($GLOBALS['__wp_options'][$key]);
            }

            return !$this->optionExists($key);
        }

        if ($type === 'theme_mod') {
            if ($exists) {
                if (function_exists('set_theme_mod')) {
                    set_theme_mod($key, $value);
                }

                return true;
            }

            if (function_exists('remove_theme_mod')) {
                remove_theme_mod($key);
            }

            return true;
        }

        if ($type === 'nav_menu_item') {
            $itemId = (int) ($store['itemId'] ?? 0);
            return $itemId < 1 || !function_exists('wp_delete_post') || wp_delete_post($itemId, true) !== false;
        }

        if ($type === 'nav_menu') {
            $menuId = (int) ($store['menuId'] ?? 0);
            if (!$exists) {
                return $menuId < 1 || !function_exists('wp_delete_nav_menu') || (bool) wp_delete_nav_menu($menuId);
            }

            return $this->restoreExistingMenu($menuId, is_array($value) ? $value : []);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restoreExistingMenu(int $menuId, array $snapshot): bool
    {
        if (isset($GLOBALS['__wp_nav_menus']) && is_array($GLOBALS['__wp_nav_menus'])) {
            $menu = is_array($snapshot['menu'] ?? null) ? $snapshot['menu'] : [];
            $GLOBALS['__wp_nav_menus'][$menuId] = (object) $menu;
            $GLOBALS['__wp_nav_menu_items'][$menuId] = [];

            foreach (is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [] as $item) {
                if (is_array($item) && isset($item['ID'])) {
                    $GLOBALS['__wp_nav_menu_items'][$menuId][(int) $item['ID']] = (object) $item;
                }
            }

            return true;
        }

        return true;
    }

    private function optionExists(string $option): bool
    {
        if (isset($GLOBALS['__wp_options']) && is_array($GLOBALS['__wp_options'])) {
            return array_key_exists($option, $GLOBALS['__wp_options']);
        }

        return function_exists('get_option') && get_option($option, null) !== null;
    }

    private function themeModExists(string $name): bool
    {
        if (isset($GLOBALS['__wp_theme_mods']) && is_array($GLOBALS['__wp_theme_mods'])) {
            return array_key_exists($name, $GLOBALS['__wp_theme_mods']);
        }

        return function_exists('get_theme_mod') && get_theme_mod($name, null) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRollbackSnapshot(): array
    {
        return [
            'version' => 1,
            'capturedAt' => gmdate('c'),
            'stores' => [],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordString(array $record, string $field, string $default): string
    {
        $value = $record[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
