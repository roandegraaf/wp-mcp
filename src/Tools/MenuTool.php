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
        string $menu,
    ): string {
        $menuObj = is_numeric($menu) ? wp_get_nav_menu_object((int) $menu) : wp_get_nav_menu_object($menu);

        if (! $menuObj) {
            throw new \RuntimeException("Menu not found: {$menu}");
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
