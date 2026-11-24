# WP MCP

**Expose your WordPress site as an MCP server for AI agents.**

WP MCP turns any WordPress installation into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server, giving AI assistants like Claude structured, authenticated access to your content, media, menus, SEO settings, and more — through a standardized tool interface.

## Features

- **104 tools** across 27 tool categories covering the full WordPress API surface
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

## Setup & Authentication

1. Go to **Settings > WP MCP** in the WordPress admin.
2. Click **Generate Password** to create a dedicated MCP password.
3. Copy the password immediately — it is only shown once.
4. Use the ready-made connection snippets shown on the settings page:

**Claude Code CLI:**

```bash
claude mcp add wordpress https://your-site.com/wp-json/wp-mcp/v1/mcp -t http -H "Authorization: Bearer YOUR_TOKEN"
```

**JSON configuration** (for MCP client config files):

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "streamable-http",
      "url": "https://your-site.com/wp-json/wp-mcp/v1/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

The settings page also shows a live connection status indicator so you can verify your client is connected.

### Headless setup with WP-CLI

For scripted or one-click provisioning (used by the WordPress WiZZard app), the plugin ships a WP-CLI command that creates an API key and returns everything needed to connect:

```bash
wp mcp connect --name="WordPress WiZZard - Roan's MacBook Pro" --server=my-site
```

```json
{
  "endpoint": "https://your-site.com/wp-json/wp-mcp/v1/mcp",
  "token": "...",
  "command": "claude mcp add my-site 'https://your-site.com/wp-json/wp-mcp/v1/mcp' -t http -H \"Authorization: Bearer ...\"",
  "config": "{ ... }",
  "site_name": "Your site",
  "plugin_version": "1.1.22"
}
```

| Option | Default | Description |
|--------|---------|-------------|
| `--name` | `WordPress WiZZard` | Name stored with the API key. |
| `--server` | `wordpress` | Server name used in the generated client snippets. |
| `--replace` | off | Revoke existing keys with the same name first (tokens are hashed and cannot be recovered). Leave it off and scope `--name` per machine so other clients keep working. |
| `--format` | `json` | `json` for the full payload, `text` for just the `claude mcp add` command. |

Install and provision in one go:

```bash
wp plugin install https://github.com/roandegraaf/wp-mcp/releases/latest/download/wp-mcp.zip --activate --force
wp mcp connect --name="WP MCP - $(hostname)" --format=text
```

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
| `wp_get_error_log` | Read the tail of the resolved PHP/WP error log with its path, size, last-modified time, `WP_DEBUG*` state and a `since` filter, so a stale copied log is obvious. |
| `wp_get_environment` | Read-only: site/home URL, `WP_ENVIRONMENT_TYPE` / `WP_ENV`, is-production, table prefix, paths, hostname, DB host/name, cache plugins and drop-ins, WPML/ACF versions, MCP caller identity. |

### Diagnostics & Debugging

Built for reproducing and explaining editor save problems on production sites.

| Tool | Description |
|------|-------------|
| `wp_create_login_link` | **Security-sensitive write.** One-time, short-lived magic login URL for a user (hashed token in a transient, consumed on first use, audit-logged with the MCP caller) with an optional `redirect_to`, e.g. straight into the block editor for a post. |
| `wp_rest_request` | **Write-capable.** Run an internal REST request through the real dispatcher as an impersonated user; returns status, headers, full body and `WP_Error` data. `dry_run` (default `true`) wraps it in a DB transaction and rolls back, so "would this user's PUT succeed" is testable without changing content. |
| `wp_list_failed_writes` | The plugin records every REST/admin-ajax write (POST/PUT/PATCH/DELETE) that returns >= 400 or a `WP_Error`, plus PHP fatals during REST/ajax: timestamp, user, route, status, error, post ID, request size (last 500). Filter by `since`, `user`, `post_id`, `route`. |
| `wp_get_post_edit_state` | Who touched a post and is it blocked: `_edit_lock` (user, age, active), `_edit_last`, `post_modified`, latest revision/autosave with author, and WPML translation status, needs-update flag, latest job editor and native/WPML/ATE editor mode. |
| `wp_list_post_revisions` | Revisions and autosaves of a post with type, author, date and content length. |
| `wp_diff_post_revisions` | Per-block diff between two versions (post or revision IDs): added/removed/changed ACF fields with before/after values instead of raw `post_content`. |
| `wp_render_post_block` | Render one ACF block server-side with supplied field data (optionally layered on a stored block) in a WPML language and post context, without saving. Returns HTML, resolved field values and every `WP_Query` the template ran with its post IDs. |
| `wp_render_post` | Front-end HTML of a post as the server generates it now, bypassing page caches via a loopback request; `language` picks the WPML translation. `as_user` renders logged in as that user (short-lived session, destroyed afterwards) so drafts and WPML hidden languages can be checked. |

### Posts & Content

| Tool | Description |
|------|-------------|
| `wp_list_posts` | List posts, pages, or custom post types with filtering by status, search, taxonomy, date, author, and pagination. `language` selects a WPML language (or `all`); rows carry `wpml.language_code`, `trid` and translation IDs. |
| `wp_get_post` | Get a single post by ID with content, ACF fields, SEO data, and featured image. |
| `wp_create_post` | Create a post, page, or CPT with title, content, status, and ACF fields. |
| `wp_update_post` | Partially update an existing post — only provided fields are changed (including `parent`). |
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
| `wp_create_menu_translation` | WPML: create a linked translation of a menu in another language, copying every item with its hierarchy. Page/term items point to their translations (untranslated ones are skipped and reported), custom links keep their URL unless `item_urls` overrides it, labels come from `item_titles`. Theme locations are left to WPML. |

### Users

| Tool | Description |
|------|-------------|
| `wp_list_users` | List WordPress users with their roles, names, and emails. |

### Options

| Tool | Description |
|------|-------------|
| `wp_get_option` | Read any WordPress option by name. |
| `wp_update_option` | Update a WordPress option. Critical options are blocked for safety. Array/object options only accept JSON: `merge=true` (default) changes only the passed keys, `merge=false` replaces the value; plain strings are rejected so they cannot overwrite the option. `icl_sitepress_settings` can only be merged. |

### Plugins & Theme

| Tool | Description |
|------|-------------|
| `wp_list_plugins` | List all installed plugins with active/inactive status, version, and description. |
| `wp_get_theme_info` | Get active theme info: name, version, author, template, and parent theme details. |

### ACF (Advanced Custom Fields)

| Tool | Description |
|------|-------------|
| `wp_get_acf_fields` | Get all ACF field values for a post with type annotations. Handles repeaters, flex content, groups, and images. Use `"option"` as post_id for options pages; with WPML, `language` reads that language's options (empty = default language). |
| `wp_update_acf_fields` | Update ACF fields on a post. Supports images (attachment ID), repeaters, flex content, and options pages; with WPML, `language` writes that language's options without touching the others. |
| `wp_list_field_groups` | List all ACF field groups with their assignments (post types, taxonomies, etc.). |
| `wp_get_field_group_schema` | Get complete field definitions: types, choices, sub-fields, conditional logic, validation rules. |

### Gutenberg Blocks

| Tool | Description |
|------|-------------|
| `wp_list_post_blocks` | Parse post content and list all Gutenberg blocks with ACF data, attributes, and field values. Accepts revision/autosave IDs; `compact=true` returns only `acf_data` for ACF blocks. |
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
| `wp_get_recent_changes` | Get a unified recent activity log of modified posts across every public and admin-visible post type (including `exclude_from_search` CPTs); `include_revisions` adds revisions/autosaves with their author. |
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
| `wp_create_translation` | Create a full WPML translation of a post: copies the source content byte for byte (unless `content` is given), page template, featured image, menu order and author, maps the parent to its translation, and runs WPML's custom-field sync (copy / copy once). Refuses when the language already has a translation. |

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
