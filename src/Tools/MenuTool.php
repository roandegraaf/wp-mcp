<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\TranslationPlan;
use WpMcp\Helpers\WpmlHelper;

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

    /**
     * Remove a menu item from a menu.
     */
    #[McpTool(name: 'wp_delete_menu_item', description: 'Remove a menu item from a navigation menu.')]
    public function deleteMenuItem(
        #[Schema(description: 'Menu item ID to remove')]
        int $item_id,
    ): string {
        $menuItem = get_post($item_id);
        if (! $menuItem || $menuItem->post_type !== 'nav_menu_item') {
            throw new \RuntimeException("Menu item not found: {$item_id}");
        }

        $title = $menuItem->post_title;
        $result = wp_delete_post($item_id, true);

        if (! $result) {
            throw new \RuntimeException("Failed to delete menu item: {$item_id}");
        }

        return ResponseFormatter::toJson([
            'success' => true,
            'message' => "Menu item {$item_id} ('{$title}') removed.",
        ]);
    }

    #[McpTool(name: 'wp_create_menu_translation', description: 'Create a WPML translation of a navigation menu. Creates the menu in the target language linked to the source menu, then copies every item with its hierarchy: page/post/term items point to the target-language translation of their object (items whose object has no translation are skipped and reported; their children move up to the nearest copied ancestor), custom links keep their URL unless item_urls gives a translated one. Each new item is linked to its source item in WPML and gets the source item\'s "copy" custom fields. Items without a custom label show the translated page/term title automatically; pass item_titles to set labels. Theme menu locations are left alone: WPML serves the translated menu for a location on the front end. Refuses when a translation in that language already exists.')]
    public function createMenuTranslation(
        #[Schema(description: 'Source menu ID')]
        int $menu_id,
        #[Schema(description: 'Target language code (e.g. "de")')]
        string $language,
        #[Schema(description: 'Translated menu name (empty = "<source name> (<language>)")')]
        string $name = '',
        #[Schema(description: 'JSON object mapping source menu item ID to translated label, e.g. {"123": "Contact"}')]
        string $item_titles = '{}',
        #[Schema(description: 'JSON object mapping source custom-link item ID to translated URL, e.g. {"124": "https://example.com/de/kontakt/"}')]
        string $item_urls = '{}',
    ): string {
        WpmlHelper::requireActive();

        // WPML swaps menu terms for their translation in the request language
        // (get_term filter). Inside wp_update_nav_menu_item that attaches new items
        // to the source menu whenever the target language cannot be switched to,
        // e.g. a hidden language during an API-key request.
        $noTermAdjust = static fn() => true;
        add_filter('wpml_disable_term_adjust_id', $noTermAdjust, PHP_INT_MAX);
        try {
            return $this->translateMenu($menu_id, $language, $name, $item_titles, $item_urls);
        } finally {
            remove_filter('wpml_disable_term_adjust_id', $noTermAdjust, PHP_INT_MAX);
        }
    }

    private function translateMenu(int $menu_id, string $language, string $name, string $item_titles, string $item_urls): string
    {
        $menu = wp_get_nav_menu_object($menu_id);
        if (! $menu instanceof \WP_Term) {
            throw new \RuntimeException("Menu not found: {$menu_id}");
        }
        $language = WpmlHelper::requireEnabledLanguage($this->sanitizeText($language));
        $titles = $this->decodeItemMap($item_titles, 'item_titles');
        $urls = $this->decodeItemMap($item_urls, 'item_urls');

        $details = apply_filters('wpml_element_language_details', null, [
            'element_id'   => (int) $menu->term_taxonomy_id,
            'element_type' => 'tax_nav_menu',
        ]);
        if (! is_object($details) || empty($details->trid)) {
            throw new \RuntimeException("Menu {$menu_id} is not registered in WPML (no translation group).");
        }
        $trid = (int) $details->trid;
        $sourceLanguage = (string) $details->language_code;
        if ($language === $sourceLanguage) {
            throw new \RuntimeException("Menu {$menu_id} is already in '{$language}'.");
        }

        $existing = apply_filters('wpml_get_element_translations', null, $trid, 'tax_nav_menu');
        if (is_array($existing) && isset($existing[$language])) {
            $existingId = (int) ($existing[$language]->term_id ?? $existing[$language]->element_id ?? 0);
            throw new \RuntimeException("Menu {$menu_id} already has a '{$language}' translation: menu {$existingId}.");
        }

        $sourceItems = WpmlHelper::withLanguage($sourceLanguage, fn() => wp_get_nav_menu_items($menu) ?: []);
        $itemsById = [];
        $planInput = [];
        foreach ($sourceItems as $item) {
            $itemsById[(int) $item->ID] = $item;
            $planInput[] = [
                'id'               => (int) $item->ID,
                'parent'           => (int) $item->menu_item_parent,
                'type'             => (string) $item->type,
                'target_object_id' => in_array($item->type, ['post_type', 'taxonomy'], true)
                    ? $this->translatedObjectId((int) $item->object_id, (string) $item->object, $language)
                    : null,
            ];
        }
        $targets = array_column($planInput, 'target_object_id', 'id');
        $plan = TranslationPlan::menuItems($planInput);

        $menuName = $name !== '' ? $this->sanitizeText($name) : sprintf('%s (%s)', $menu->name, $language);
        $newMenuId = wp_create_nav_menu($menuName);
        if (is_wp_error($newMenuId)) {
            throw new \RuntimeException('Failed to create menu: ' . $newMenuId->get_error_message());
        }
        $newMenu = wp_get_nav_menu_object($newMenuId);
        $newMenuTtId = (int) $newMenu->term_taxonomy_id;

        // The menu must be in the target language before items are added: WPML's
        // wp_update_nav_menu_item hook removes items whose object language differs
        // from the menu's language.
        do_action('wpml_set_element_language_details', [
            'element_id'           => $newMenuTtId,
            'element_type'         => 'tax_nav_menu',
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage,
        ]);

        $created = WpmlHelper::withLanguage($language, function () use ($plan, $itemsById, $targets, $titles, $urls, $newMenuId, $newMenuTtId, $language, $sourceLanguage) {
            global $wpdb;
            $map = [];
            $failed = [];
            $dropped = [];
            foreach ($plan['create'] as $entry) {
                $item = $itemsById[$entry['id']];
                $isCustom = $item->type === 'custom';

                $args = [
                    'menu-item-type'        => $item->type,
                    'menu-item-object'      => $item->object,
                    'menu-item-object-id'   => $targets[$entry['id']] ?? (int) $item->object_id,
                    'menu-item-parent-id'   => $entry['parent'] !== 0 ? ($map[$entry['parent']] ?? 0) : 0,
                    'menu-item-position'    => (int) $item->menu_order,
                    'menu-item-title'       => isset($titles[$entry['id']]) ? $this->sanitizeText($titles[$entry['id']]) : $item->post_title,
                    'menu-item-url'         => $isCustom ? (isset($urls[$entry['id']]) ? esc_url_raw($urls[$entry['id']]) : $item->url) : '',
                    'menu-item-description' => $item->post_content,
                    'menu-item-attr-title'  => $item->post_excerpt,
                    'menu-item-target'      => $item->target,
                    'menu-item-classes'     => trim(implode(' ', array_filter((array) $item->classes))),
                    'menu-item-xfn'         => $item->xfn,
                    'menu-item-status'      => 'publish',
                ];

                $newItemId = wp_update_nav_menu_item($newMenuId, 0, wp_slash($args));
                if (is_wp_error($newItemId) || ! $newItemId) {
                    $failed[] = [
                        'source_item_id' => $entry['id'],
                        'error'          => is_wp_error($newItemId) ? $newItemId->get_error_message() : 'unknown error',
                    ];
                    continue;
                }
                $newItemId = (int) $newItemId;
                $map[$entry['id']] = $newItemId;

                $itemTrid = apply_filters('wpml_element_trid', null, $entry['id'], 'post_nav_menu_item');
                if ($itemTrid) {
                    do_action('wpml_set_element_language_details', [
                        'element_id'           => $newItemId,
                        'element_type'         => 'post_nav_menu_item',
                        'trid'                 => (int) $itemTrid,
                        'language_code'        => $language,
                        'source_language_code' => $sourceLanguage,
                    ]);
                }
                WpmlHelper::syncCustomFields($entry['id'], $newItemId);

                $inMenu = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
                    $newItemId,
                    $newMenuTtId
                ));
                if (! $inMenu) {
                    $dropped[] = $entry['id'];
                }
            }
            return ['map' => $map, 'failed' => $failed, 'dropped' => $dropped];
        });

        $skipped = array_map(function (array $skip) use ($itemsById) {
            $item = $itemsById[$skip['id']];
            return [
                'source_item_id' => $skip['id'],
                'title'          => $item->title,
                'type'           => $item->type,
                'object'         => $item->object,
                'object_id'      => (int) $item->object_id,
                'reason'         => "The linked {$item->object} has no translation in this language. Translate it, then add the item with wp_create_menu_item.",
            ];
        }, $plan['skipped']);

        $items = [];
        foreach ($created['map'] as $sourceItemId => $newItemId) {
            $items[] = [
                'source_item_id' => $sourceItemId,
                'item_id'        => $newItemId,
                'source_title'   => $itemsById[$sourceItemId]->title,
                'label'          => (string) get_post_field('post_title', $newItemId) ?: null,
            ];
        }

        return ResponseFormatter::toJson([
            'source_menu_id' => $menu_id,
            'menu_id'        => (int) $newMenuId,
            'menu_name'      => $menuName,
            'language'       => $language,
            'trid'           => $trid,
            'items_copied'   => count($created['map']),
            'items'          => $items,
            'skipped'        => $skipped,
            'reparented'     => array_map(fn(array $r) => [
                'source_item_id'          => $r['id'],
                'source_parent_item_id'   => $r['from'],
                'copied_under_source_item' => $r['to'],
            ], $plan['reparented']),
            'failed'         => $created['failed'],
            'dropped_by_wpml' => $created['dropped'],
            'message'        => 'Menu translation created. Theme locations were not changed; WPML shows this menu for the source menu\'s locations in ' . $language . '.',
        ]);
    }

    private function translatedObjectId(int $objectId, string $objectType, string $language): ?int
    {
        $translated = apply_filters('wpml_object_id', $objectId, $objectType, false, $language);
        return $translated ? (int) $translated : null;
    }

    /**
     * @return array<int, string>
     */
    private function decodeItemMap(string $json, string $param): array
    {
        $decoded = json_decode($json === '' ? '{}' : $json, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$param} must be a JSON object of item ID => value.");
        }
        $map = [];
        foreach ($decoded as $id => $value) {
            if (! is_numeric($id) || ! is_scalar($value)) {
                throw new \RuntimeException("{$param}: keys must be source menu item IDs and values strings.");
            }
            $map[(int) $id] = (string) $value;
        }
        return $map;
    }
}
