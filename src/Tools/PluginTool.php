<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use WpMcp\Helpers\ResponseFormatter;

class PluginTool extends AbstractTool
{
    /**
     * List installed plugins with their status and version.
     */
    #[McpTool(name: 'wp_list_plugins', description: 'List all installed plugins with active/inactive status, version, and description.')]
    public function listPlugins(): string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $allPlugins = get_plugins();
        $activePlugins = get_option('active_plugins', []);

        $data = [];
        foreach ($allPlugins as $file => $plugin) {
            $data[] = [
                'file'        => $file,
                'name'        => $plugin['Name'],
                'version'     => $plugin['Version'],
                'description' => $plugin['Description'],
                'author'      => $plugin['Author'],
                'active'      => in_array($file, $activePlugins, true),
                'plugin_uri'  => $plugin['PluginURI'] ?? null,
            ];
        }

        return ResponseFormatter::toJson([
            'total'   => count($data),
            'active'  => count(array_filter($data, fn($p) => $p['active'])),
            'plugins' => $data,
        ]);
    }

    /**
     * Get active theme information.
     */
    #[McpTool(name: 'wp_get_theme_info', description: 'Get active theme info: name, version, author, template, and parent theme details.')]
    public function getThemeInfo(): string
    {
        $theme = wp_get_theme();

        $data = [
            'name'        => $theme->get('Name'),
            'version'     => $theme->get('Version'),
            'author'      => $theme->get('Author'),
            'description' => $theme->get('Description'),
            'template'    => $theme->get_template(),
            'stylesheet'  => $theme->get_stylesheet(),
            'theme_uri'   => $theme->get('ThemeURI'),
            'text_domain' => $theme->get('TextDomain'),
        ];

        // Check for parent theme (child theme setup)
        if ($theme->parent()) {
            $parent = $theme->parent();
            $data['parent_theme'] = [
                'name'    => $parent->get('Name'),
                'version' => $parent->get('Version'),
            ];
        }

        // Theme support features
        $supports = [];
        $features = [
            'post-thumbnails', 'custom-header', 'custom-background',
            'menus', 'automatic-feed-links', 'editor-styles',
            'wp-block-styles', 'responsive-embeds',
        ];
        foreach ($features as $feature) {
            if (current_theme_supports($feature)) {
                $supports[] = $feature;
            }
        }
        $data['supports'] = $supports;

        return ResponseFormatter::toJson($data);
    }
}
