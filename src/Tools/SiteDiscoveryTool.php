<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SiteDiscoveryTool extends AbstractTool
{
    /**
     * Discover site capabilities: post types, taxonomies, active plugins, theme, ACF field groups.
     * Call this tool first to understand what the site supports.
     */
    #[McpTool(name: 'wp_discover_site', description: 'Discover site capabilities: post types, taxonomies, active plugins, theme, ACF field groups. Call this first.')]
    public function discoverSite(): string
    {
        $data = [];

        // Post types
        $postTypes = get_post_types(['public' => true], 'objects');
        $data['post_types'] = [];
        foreach ($postTypes as $pt) {
            $data['post_types'][] = [
                'name'         => $pt->name,
                'label'        => $pt->label,
                'hierarchical' => $pt->hierarchical,
                'has_archive'  => (bool) $pt->has_archive,
                'supports'     => get_all_post_type_supports($pt->name),
            ];
        }

        // Taxonomies
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $data['taxonomies'] = [];
        foreach ($taxonomies as $tax) {
            $data['taxonomies'][] = [
                'name'         => $tax->name,
                'label'        => $tax->label,
                'hierarchical' => $tax->hierarchical,
                'object_types' => $tax->object_type,
            ];
        }

        // Active plugins
        $activePlugins = get_option('active_plugins', []);
        $allPlugins = function_exists('get_plugins') ? get_plugins() : [];
        $data['active_plugins'] = [];
        foreach ($activePlugins as $pluginFile) {
            if (isset($allPlugins[$pluginFile])) {
                $data['active_plugins'][] = [
                    'file'    => $pluginFile,
                    'name'    => $allPlugins[$pluginFile]['Name'],
                    'version' => $allPlugins[$pluginFile]['Version'],
                ];
            }
        }

        // Theme
        $theme = wp_get_theme();
        $data['theme'] = [
            'name'       => $theme->get('Name'),
            'version'    => $theme->get('Version'),
            'template'   => $theme->get_template(),
            'stylesheet' => $theme->get_stylesheet(),
        ];

        // ACF field groups
        if (function_exists('acf_get_field_groups')) {
            $fieldGroups = acf_get_field_groups();
            $data['acf_field_groups'] = array_map(function ($group) {
                return [
                    'key'    => $group['key'],
                    'title'  => $group['title'],
                    'active' => $group['active'],
                ];
            }, $fieldGroups);
        }

        // WordPress version
        $data['wordpress_version'] = get_bloginfo('version');
        $data['php_version'] = PHP_VERSION;
        $data['site_url'] = get_site_url();
        $data['home_url'] = get_home_url();

        return ResponseFormatter::toJson($data);
    }

    /**
     * Read site title, tagline, URL, timezone, date format and other settings.
     */
    #[McpTool(name: 'wp_get_site_settings', description: 'Get site title, tagline, URL, timezone, date format and other settings.')]
    public function getSiteSettings(): string
    {
        $settings = [
            'title'           => get_option('blogname'),
            'tagline'         => get_option('blogdescription'),
            'site_url'        => get_option('siteurl'),
            'home_url'        => get_option('home'),
            'admin_email'     => get_option('admin_email'),
            'timezone'        => get_option('timezone_string') ?: 'UTC' . get_option('gmt_offset'),
            'date_format'     => get_option('date_format'),
            'time_format'     => get_option('time_format'),
            'language'        => get_option('WPLANG') ?: 'en_US',
            'posts_per_page'  => (int) get_option('posts_per_page'),
            'permalink_structure' => get_option('permalink_structure'),
            'uploads_path'    => wp_upload_dir()['baseurl'],
        ];

        return ResponseFormatter::toJson($settings);
    }
}
