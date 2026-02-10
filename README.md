# WP MCP

**Expose your WordPress site as an MCP server for AI agents.**

WP MCP turns any WordPress installation into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server, giving AI assistants like Claude structured, authenticated access to your content, media, menus, SEO settings, and more — through a standardized tool interface.

## Features

- **58 tools** across 20 tool categories covering the full WordPress API surface
- Standards-based MCP transport over the WordPress REST API
- Full support for ACF (Advanced Custom Fields), Gutenberg blocks, Yoast SEO, Gravity Forms, WPML, and the Redirection plugin
- Read *and* write — create posts, upload media, manage redirects, update SEO metadata
- Designed for AI-first workflows: site discovery, content analysis, writing context

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | >= 8.1 |
| WordPress | >= 6.0 |
| Composer | Required for dependency installation |

## Installation

1. Clone or download this repository into your `wp-content/plugins/` directory:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/wp-mcp/wp-mcp.git
   cd wp-mcp
   composer install
   ```

2. Activate the plugin in **WordPress Admin > Plugins**.

3. The MCP server endpoint will be available at:

   ```
   https://your-site.com/wp-json/wp-mcp/v1/mcp
   ```

## Connecting an MCP Client

Add the server to your MCP client configuration. For example, in Claude Desktop:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/wp-mcp/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64-encoded credentials>"
      }
    }
  }
}
```

> Authentication uses WordPress application passwords. Generate one under **Users > Your Profile > Application Passwords**.

---

## Tools Reference

### Site Discovery & Health

| Tool | Description |
|------|-------------|
| `wp_discover_site` | Discover site capabilities: post types, taxonomies, active plugins, theme, ACF field groups. **Call this first.** |
| `wp_get_site_settings` | Get site title, tagline, URL, timezone, date format, and other settings. |
| `wp_get_site_health` | Get PHP/WP/MySQL versions, debug mode, SSL, memory limit, upload size, plugin count, and more. |
| `wp_list_cron_events` | List all scheduled WordPress cron events with next run time, schedule, and arguments. |
| `wp_list_transients` | List database transients, optionally filtered by name. |
| `wp_get_error_log` | Read the last N lines from the WordPress `debug.log` file. |

### Posts & Content

| Tool | Description |
|------|-------------|
| `wp_list_posts` | List posts, pages, or custom post types with filtering by status, search, taxonomy, date, author, and pagination. |
| `wp_get_post` | Get a single post by ID with content, ACF fields, SEO data, and featured image. |
| `wp_create_post` | Create a post, page, or CPT with title, content, status, and ACF fields. |
| `wp_update_post` | Partially update an existing post — only provided fields are changed. |
| `wp_delete_post` | Trash or permanently delete a post. |
| `wp_search_content` | Full-text search across all content types. |

### Taxonomies & Terms

| Tool | Description |
|------|-------------|
| `wp_list_terms` | List terms for any taxonomy (categories, tags, custom) with search and parent filtering. |
| `wp_get_term` | Get a single term by ID with ACF fields if available. |
| `wp_create_term` | Create a new term in any taxonomy. |
| `wp_update_term` | Update term name, slug, description, or parent. |

### Media Library

| Tool | Description |
|------|-------------|
| `wp_list_media` | List media library items with filtering by MIME type, search, and pagination. |
| `wp_get_media` | Get media item details with all image sizes, alt text, dimensions, and metadata. |
| `wp_upload_media` | Upload media from a URL to the WordPress media library. |
| `wp_update_media` | Update media metadata: title, alt text, caption, description. |

### Navigation Menus

| Tool | Description |
|------|-------------|
| `wp_list_menus` | List all navigation menus and their registered locations. |
| `wp_get_menu_items` | Get all items in a menu with hierarchy (parent/child relationships). |
| `wp_update_menu_item` | Update a menu item's title, URL, CSS classes, target, or position. |

### Users

| Tool | Description |
|------|-------------|
| `wp_list_users` | List WordPress users with their roles, names, and emails. |

### Options

| Tool | Description |
|------|-------------|
| `wp_get_option` | Read any WordPress option by name. |
| `wp_update_option` | Update a WordPress option. Critical options are blocked for safety. |

### Plugins & Theme

| Tool | Description |
|------|-------------|
| `wp_list_plugins` | List all installed plugins with active/inactive status, version, and description. |
| `wp_get_theme_info` | Get active theme info: name, version, author, template, and parent theme details. |

### ACF (Advanced Custom Fields)

| Tool | Description |
|------|-------------|
| `wp_get_acf_fields` | Get all ACF field values for a post with type annotations. Handles repeaters, flex content, groups, and images. Use `"option"` as post_id for options pages. |
| `wp_update_acf_fields` | Update ACF fields on a post. Supports images (attachment ID), repeaters, flex content, and options pages. |
| `wp_list_field_groups` | List all ACF field groups with their assignments (post types, taxonomies, etc.). |
| `wp_get_field_group_schema` | Get complete field definitions: types, choices, sub-fields, conditional logic, validation rules. |

### Gutenberg Blocks

| Tool | Description |
|------|-------------|
| `wp_list_post_blocks` | Parse post content and list all Gutenberg blocks with ACF data, attributes, and field values. |
| `wp_update_post_block` | Update a specific block's field data by index. |
| `wp_insert_post_block` | Insert a new Gutenberg or ACF block at a specified position in the post content. |

### SEO (Yoast)

| Tool | Description |
|------|-------------|
| `wp_get_seo_data` | Get Yoast SEO metadata: title, description, focus keyword, canonical URL, robots, social media, and SEO score. |
| `wp_update_seo_data` | Update Yoast SEO meta fields: title, description, focus keyword, canonical, robots, and social metadata. |
| `wp_get_seo_analysis` | Get SEO and readability scores, keyword density, and actionable problems/improvements. |
| `wp_get_sitemap_info` | Get XML sitemap configuration: URL, indexed post types, taxonomies, and last modified dates. |
| `wp_update_seo_settings` | Update global Yoast settings: title separator, company name, social defaults, sitemap toggle. |
| `wp_get_schema_markup` | Get JSON-LD structured data for a post. Returns Yoast schema or generates a basic Article/WebPage schema. |

### Content Intelligence

| Tool | Description |
|------|-------------|
| `wp_get_writing_context` | Get site voice, structure patterns, and topic data an AI needs to write on-brand content. |
| `wp_get_recent_changes` | Get a unified recent activity log of modified posts across all types. |
| `wp_analyze_content` | Analyze content quality: word count, headings, links, images, read time, and SEO indicators. |
| `wp_find_broken_links` | Scan a post for links and optionally check HTTP status to find broken links. |
| `wp_find_orphan_content` | Find published posts with no internal links pointing to them. |

### Gravity Forms

| Tool | Description |
|------|-------------|
| `wp_list_forms` | List all Gravity Forms with ID, title, active status, and entry count. |
| `wp_get_form` | Get form structure: fields, confirmations, and notification names. |
| `wp_create_form` | Create a new form with title, description, and field definitions. |
| `wp_update_form` | Update form title, description, active status, or fields. |
| `wp_list_form_entries` | List form entries with pagination and status filtering. |

### Redirects (Redirection Plugin)

| Tool | Description |
|------|-------------|
| `wp_list_redirects` | List all redirects with optional search and pagination. |
| `wp_create_redirect` | Create a new URL redirect (301, 302, 307, 308). |
| `wp_delete_redirect` | Delete a redirect by ID. |

### WPML (Multilingual)

| Tool | Description |
|------|-------------|
| `wp_list_languages` | List all configured WPML languages with codes, names, and default status. |
| `wp_get_translations` | Get all translations of a post with language, title, status, and URL. |
| `wp_get_translation_status` | Get translation completeness overview per language for a post type. |
| `wp_create_translation` | Create a translation for an existing post and link it in WPML. |

---

## Plugin Integrations

WP MCP automatically detects and integrates with these plugins when active:

| Plugin | Tools Provided |
|--------|---------------|
| **Advanced Custom Fields (ACF)** | Field reading/writing, field group schemas, block-level ACF data |
| **Yoast SEO** | SEO metadata, analysis scores, sitemap config, schema markup, global settings |
| **Gravity Forms** | Form management, field definitions, entry listing |
| **WPML** | Language listing, translation status, translation creation |
| **Redirection** | Redirect management (list, create, delete) |

Tools for optional plugins gracefully return informative error messages when the required plugin is not active.

## Architecture

WP MCP is built on the [`php-mcp/server`](https://github.com/php-mcp/server) SDK (v3.3) and uses a custom `WordPressTransport` to bridge the WordPress REST API with the MCP protocol's async transport interface.

```
MCP Client (Claude, etc.)
    │
    ▼
WordPress REST API  ──►  WordPressTransport  ──►  php-mcp/server SDK
                                                        │
                                                        ▼
                                                   Tool Discovery
                                                   (#[McpTool] attributes)
                                                        │
                                                        ▼
                                                   Tool Execution
                                                   (WordPress APIs)
```

Tools are defined as PHP classes with `#[McpTool]` attributes and automatically discovered by the SDK at runtime.

## License

GPL-2.0-or-later
