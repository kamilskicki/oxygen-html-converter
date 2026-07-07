<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\SiteConfigurationImporter;
use PHPUnit\Framework\TestCase;

class SiteConfigurationImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_nav_menus'] = [];
        $GLOBALS['__wp_nav_menu_items'] = [];
        $GLOBALS['__wp_next_nav_menu_id'] = 1;
        $GLOBALS['__wp_next_nav_menu_item_id'] = 1000;
        $GLOBALS['__wp_theme_mods'] = [];
        unset($GLOBALS['__wp_update_nav_menu_item_result']);
    }

    public function testApplyAssignsHomepageCreatesMenuAndPlacesHeaderMenu(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        $blogPostId = $this->createPage('Blog', 'blog');
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);

        $manifest = $this->manifest();
        $manifest['homepage']['postsPageId'] = 'blog';
        $result = (new SiteConfigurationImporter())->apply($manifest, $this->pageRecords($homePostId, $blogPostId));

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertSame($homePostId, get_option('page_on_front'));
        $this->assertSame($blogPostId, get_option('page_for_posts'));
        $this->assertSame('show_on_front', $result['options'][0]['option']);
        $this->assertSame('page_on_front', $result['options'][1]['option']);
        $this->assertSame('page_for_posts', $result['options'][2]['option']);
        $this->assertSame('created', $result['menus'][0]['action']);
        $this->assertSame('Primary', $result['menus'][0]['name']);
        $this->assertSame('created', $result['menus'][0]['items'][0]['action']);
        $this->assertSame($homePostId, $result['menus'][0]['items'][0]['targetPostId']);
        $this->assertSame('primary', $result['placements'][0]['location']);
        $this->assertSame($result['menus'][0]['menuId'], get_theme_mod('nav_menu_locations', [])['primary']);
        $this->assertNotEmpty($result['rollback']['stores']);
    }

    public function testApplyIsIdempotentForExistingMenuAndMenuItems(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        $importer = new SiteConfigurationImporter();

        $first = $importer->apply($this->manifest(), $this->pageRecords($homePostId));
        $second = $importer->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertSame('selected', $second['menus'][0]['action']);
        $this->assertSame('existing', $second['menus'][0]['items'][0]['action']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menu_items'][(int) $first['menus'][0]['menuId']]);
    }

    public function testApplySelectsExistingWordPressTermMenu(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        $menuId = wp_create_nav_menu('Primary');
        $this->assertIsInt($menuId);
        $GLOBALS['__wp_nav_menus'][(int) $menuId] = new \WP_Term([
            'term_id' => (int) $menuId,
            'name' => 'Primary',
            'slug' => 'primary',
            'taxonomy' => 'nav_menu',
        ]);

        $result = (new SiteConfigurationImporter())->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame('selected', $result['menus'][0]['action']);
        $this->assertSame((int) $menuId, $result['menus'][0]['menuId']);
        $this->assertSame('created', $result['menus'][0]['items'][0]['action']);
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);
    }

    public function testApplyRejectsUnknownHomepageTargetBeforeWrites(): void
    {
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);

        $manifest = $this->manifest();
        $manifest['homepage']['pageId'] = 'missing-page';

        $result = (new SiteConfigurationImporter())->apply($manifest, $this->pageRecords(12));

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Homepage target "missing-page" could not be resolved', implode(' ', $result['errors']));
        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertSame(0, get_option('page_on_front'));
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
    }

    public function testRestoreRollbackSnapshotRestoresOptionsAndRemovesCreatedMenus(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);
        set_theme_mod('nav_menu_locations', ['primary' => 99]);

        $importer = new SiteConfigurationImporter();
        $result = $importer->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertTrue($result['success']);
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertCount(1, $GLOBALS['__wp_nav_menus']);

        $restore = $importer->restore($result['rollback']);

        $this->assertTrue($restore['success'], implode(' ', $restore['errors'] ?? []));
        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertSame(0, get_option('page_on_front'));
        $this->assertSame(['primary' => 99], get_theme_mod('nav_menu_locations', []));
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
        $this->assertSame([], $GLOBALS['__wp_nav_menu_items']);
    }

    public function testApplyRestoresHomepageAndMenusWhenMenuItemCreationThrows(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);
        $GLOBALS['__wp_update_nav_menu_item_result'] = new \WP_Error('Simulated menu item failure.');

        $result = (new SiteConfigurationImporter())->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('Simulated menu item failure.', implode(' ', $result['errors']));
        $this->assertTrue($result['restore']['success'], implode(' ', $result['restore']['errors'] ?? []));
        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertSame(0, get_option('page_on_front'));
        $this->assertSame([], $GLOBALS['__wp_nav_menus']);
        $this->assertSame([], $GLOBALS['__wp_nav_menu_items']);
    }

    public function testApplyDoesNotReuseSameLabelMenuItemWithDifferentTarget(): void
    {
        $homePostId = $this->createPage('Home', 'home');
        $otherPostId = $this->createPage('Other', 'other');
        $menuId = wp_create_nav_menu('Primary');
        $this->assertIsInt($menuId);

        $existingItemId = wp_update_nav_menu_item((int) $menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-object-id' => $otherPostId,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
            'menu-item-position' => 1,
        ]);
        $this->assertIsInt($existingItemId);

        $importer = new SiteConfigurationImporter();
        $first = $importer->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertTrue($first['success'], implode(' ', $first['errors'] ?? []));
        $this->assertSame('selected', $first['menus'][0]['action']);
        $this->assertSame('created', $first['menus'][0]['items'][0]['action']);
        $this->assertSame($homePostId, $first['menus'][0]['items'][0]['targetPostId']);

        $items = wp_get_nav_menu_items((int) $menuId);
        $this->assertCount(2, $items);
        $homeItems = array_values(array_filter($items, static function ($item) use ($homePostId): bool {
            return $item instanceof \stdClass && (int) ($item->object_id ?? 0) === $homePostId;
        }));
        $this->assertCount(1, $homeItems);

        $second = $importer->apply($this->manifest(), $this->pageRecords($homePostId));

        $this->assertTrue($second['success'], implode(' ', $second['errors'] ?? []));
        $this->assertSame('existing', $second['menus'][0]['items'][0]['action']);
        $this->assertCount(2, wp_get_nav_menu_items((int) $menuId));
    }

    public function testRestoreTreatsUnchangedOptionValueAsSuccess(): void
    {
        update_option('show_on_front', 'posts');

        $restore = (new SiteConfigurationImporter())->restore([
            'stores' => [[
                'storeType' => 'option',
                'store' => 'site_option',
                'key' => 'show_on_front',
                'oldExists' => true,
                'oldValue' => 'posts',
            ]],
        ]);

        $this->assertTrue($restore['success'], implode(' ', $restore['errors'] ?? []));
        $this->assertSame(1, $restore['restored']);
        $this->assertSame('posts', get_option('show_on_front'));
    }

    private function createPage(string $title, string $slug): int
    {
        return (int) wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => '',
        ], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageRecords(int $homePostId, int $blogPostId = 0): array
    {
        $records = [[
            'id' => 'home',
            'postId' => $homePostId,
            'slug' => 'home',
            'title' => 'Home',
        ]];

        if ($blogPostId > 0) {
            $records[] = [
                'id' => 'blog',
                'postId' => $blogPostId,
                'slug' => 'blog',
                'title' => 'Blog',
            ];
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'homepage' => [
                'pageId' => 'home',
            ],
            'menus' => [[
                'id' => 'primary',
                'name' => 'Primary',
                'location' => 'primary',
                'items' => [[
                    'label' => 'Home',
                    'targetPageId' => 'home',
                ]],
            ]],
        ];
    }
}
