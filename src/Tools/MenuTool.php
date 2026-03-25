<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class MenuTool extends AbstractTool
{
    /**
     * List all navigation menus and their registered locations.
     */
    #[McpTool(name: 'wp_list_menus', description: 'List all navigation menus and their registered locations.')]
    public function listMenus(): string
    {
        $menus = wp_get_nav_menus();
        $locations = get_nav_menu_locations();
        $registeredLocations = get_registered_nav_menus();

        $data = [
            'menus' => array_map(function ($menu) use ($locations) {
                $menuLocations = [];
                foreach ($locations as $location => $menuId) {
                    if ($menuId === $menu->term_id) {
                        $menuLocations[] = $location;
                    }
                }
                return [
                    'id'          => $menu->term_id,
                    'name'        => $menu->name,
                    'slug'        => $menu->slug,
                    'count'       => $menu->count,
                    'locations'   => $menuLocations,
                ];
            }, $menus),
            'registered_locations' => $registeredLocations,
        ];

        return ResponseFormatter::toJson($data);
    }

    /**
     * Get all items in a menu with their hierarchy.
     */
    #[McpTool(name: 'wp_get_menu_items', description: 'Get all items in a navigation menu with hierarchy (parent/child relationships).')]
    public function getMenuItems(
        #[Schema(description: 'Menu ID or slug')]
        string $menu_id,
    ): string {
        $menuObj = is_numeric($menu_id) ? wp_get_nav_menu_object((int) $menu_id) : wp_get_nav_menu_object($menu_id);

        if (! $menuObj) {
            throw new \RuntimeException("Menu not found: {$menu_id}");
        }

        $items = wp_get_nav_menu_items($menuObj->term_id);
        if (! $items) {
            $items = [];
        }

        $formatted = array_map(function ($item) {
            return ResponseFormatter::formatMenuItem($item);
        }, $items);

        return ResponseFormatter::toJson([
            'menu_id'   => $menuObj->term_id,
            'menu_name' => $menuObj->name,
            'items'     => $formatted,
        ]);
    }

    /**
     * Add a new item to a navigation menu.
     */
    #[McpTool(name: 'wp_create_menu_item', description: 'Add a new item to a navigation menu. Supports custom links, pages, posts, categories, and taxonomy terms.')]
    public function createMenuItem(
        #[Schema(description: 'Menu ID to add the item to')]
        int $menu_id,
        #[Schema(description: 'Display title')]
        string $title,
        #[Schema(description: 'Item type: custom, post_type, taxonomy', enum: ['custom', 'post_type', 'taxonomy'])]
        string $type = 'custom',
        #[Schema(description: 'URL (required for custom links)')]
        string $url = '',
        #[Schema(description: 'Object type: page, post, category, post_tag, or any custom taxonomy/post type (used with post_type/taxonomy types)')]
        string $object = '',
        #[Schema(description: 'Object ID — the post ID or term ID to link to (used with post_type/taxonomy types)')]
        int $object_id = 0,
        #[Schema(description: 'Parent menu item ID (for sub-items)')]
        int $parent = 0,
        #[Schema(description: 'Menu order position')]
        int $position = 0,
        #[Schema(description: 'Link target (_blank for new tab)')]
        string $target = '',
        #[Schema(description: 'CSS classes (comma-separated)')]
        string $classes = '',
    ): string {
        $args = [
            'menu-item-title'     => $this->sanitizeText($title),
            'menu-item-type'      => $type,
            'menu-item-status'    => 'publish',
        ];

        if ($type === 'custom') {
            if ($url === '') {
                throw new \RuntimeException('URL is required for custom link menu items.');
            }
            $args['menu-item-url'] = esc_url_raw($url);
        } elseif ($type === 'post_type') {
            $args['menu-item-object']    = $object !== '' ? $object : 'page';
            $args['menu-item-object-id'] = $object_id;
        } elseif ($type === 'taxonomy') {
            $args['menu-item-object']    = $object !== '' ? $object : 'category';
            $args['menu-item-object-id'] = $object_id;
        }

        if ($parent > 0) {
            $args['menu-item-parent-id'] = $parent;
        }
        if ($position > 0) {
            $args['menu-item-position'] = $position;
        }
        if ($target !== '') {
            $args['menu-item-target'] = $this->sanitizeText($target);
        }
        if ($classes !== '') {
            $args['menu-item-classes'] = $this->sanitizeText($classes);
        }

        $result = wp_update_nav_menu_item($menu_id, 0, $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to create menu item: ' . $result->get_error_message());
        }

        return ResponseFormatter::toJson([
            'menu_item_id' => $result,
            'message'      => "Menu item created successfully with ID {$result}.",
        ]);
    }

    /**
     * Update a menu item's title, URL, or position.
     */
    #[McpTool(name: 'wp_update_menu_item', description: 'Update a menu item title, URL, CSS classes, target, or position.')]
    public function updateMenuItem(
        #[Schema(description: 'Menu ID')]
        int $menu_id,
        #[Schema(description: 'Menu item ID')]
        int $item_id,
        #[Schema(description: 'New title')]
        string $title = '',
        #[Schema(description: 'New URL (for custom link items)')]
        string $url = '',
        #[Schema(description: 'Link target (_blank for new tab)')]
        string $target = '',
        #[Schema(description: 'CSS classes (comma-separated)')]
        string $classes = '',
        #[Schema(description: 'Menu order position')]
        int $position = -1,
        #[Schema(description: 'Parent menu item ID')]
        int $parent = -1,
    ): string {
        $menuItem = get_post($item_id);
        if (! $menuItem) {
            throw new \RuntimeException("Menu item not found: {$item_id}");
        }

        $args = [
            'menu-item-db-id' => $item_id,
        ];

        if ($title !== '') {
            $args['menu-item-title'] = $this->sanitizeText($title);
        }
        if ($url !== '') {
            $args['menu-item-url'] = esc_url_raw($url);
        }
        if ($target !== '') {
            $args['menu-item-target'] = $this->sanitizeText($target);
        }
        if ($classes !== '') {
            $args['menu-item-classes'] = $this->sanitizeText($classes);
        }
        if ($position >= 0) {
            $args['menu-item-position'] = $position;
        }
        if ($parent >= 0) {
            $args['menu-item-parent-id'] = $parent;
        }

        $result = wp_update_nav_menu_item($menu_id, $item_id, $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to update menu item: ' . $result->get_error_message());
        }

        return ResponseFormatter::toJson([
            'success' => true,
            'message' => "Menu item {$item_id} updated successfully.",
        ]);
    }
}
