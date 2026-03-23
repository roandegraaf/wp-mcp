<?php
/**
 * Plugin Name: WP MCP
 * Plugin URI: https://github.com/wp-mcp/wp-mcp
 * Description: Exposes WordPress as an MCP (Model Context Protocol) server for AI agents. Provides tools for content management, ACF fields, blocks, media, taxonomies, menus, and SEO.
 * Version: 1.0.2
 * Requires PHP: 8.1
 * Author: WP MCP
 * License: GPL-2.0-or-later
 * Text Domain: wp-mcp
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('WP_MCP_VERSION', '1.0.2');
define('WP_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Composer autoload
$autoloader = WP_MCP_PLUGIN_DIR . 'vendor/autoload.php';
if (! file_exists($autoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WP MCP:</strong> Composer dependencies not installed. Run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return;
}
require_once $autoloader;

// Ensure the Authorization header is available on hosts that strip it.
// Apache often removes it unless passed via CGI/RewriteRule.
add_filter('wp_headers', function ($headers) { return $headers; }, 1);
if (! function_exists('getallheaders') || ! isset($_SERVER['HTTP_AUTHORIZATION'])) {
    // Try common fallbacks for the Authorization header.
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (! empty($_SERVER[$key])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$key];
            break;
        }
    }
}

// GitHub-based plugin updates
$GLOBALS['wp_mcp_updater'] = new \WpMcp\GitHubUpdater('zekerzichtbaar/wp-mcp', plugin_basename(__FILE__));

// Bootstrap the plugin
add_action('plugins_loaded', function () {
    $plugin = new \WpMcp\Plugin();
    $plugin->init();
});
