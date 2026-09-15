<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SiteHealthTool extends AbstractTool
{
    /**
     * Get site health information including versions, configuration and environment details.
     */
    #[McpTool(name: 'wp_get_site_health', description: 'Get site health info: PHP/WP/MySQL versions, debug mode, SSL, memory, upload size, plugins count, and more.')]
    public function getSiteHealth(): string
    {
        global $wpdb;

        $uploadsDir = wp_upload_dir();
        $timezone = get_option('timezone_string');
        if (empty($timezone)) {
            $gmtOffset = get_option('gmt_offset');
            $timezone = $gmtOffset ? 'UTC' . ($gmtOffset >= 0 ? '+' : '') . $gmtOffset : 'UTC';
        }

        $data = [
            'wp_version'          => get_bloginfo('version'),
            'php_version'         => phpversion(),
            'mysql_version'       => $wpdb->db_version(),
            'server_software'     => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'max_upload_size'     => size_format(wp_max_upload_size()),
            'memory_limit'        => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : 'unknown',
            'wp_debug'            => defined('WP_DEBUG') && WP_DEBUG,
            'ssl'                 => is_ssl(),
            'multisite'           => is_multisite(),
            'object_cache'        => wp_using_ext_object_cache(),
            'permalink_structure' => get_option('permalink_structure'),
            'active_plugin_count' => count(get_option('active_plugins', [])),
            'uploads_dir_size'    => size_format(get_dirsize($uploadsDir['basedir'])),
            'timezone'            => $timezone,
            'language'            => get_locale(),
        ];

        return ResponseFormatter::toJson($data);
    }

    /**
     * List all scheduled WordPress cron events.
     */
    #[McpTool(name: 'wp_list_cron_events', description: 'List all scheduled WordPress cron events with their next run time, schedule and arguments.')]
    public function listCronEvents(): string
    {
        $crons = _get_cron_array();
        if (empty($crons)) {
            return ResponseFormatter::toJson([]);
        }

        $events = [];
        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $schedules) {
                foreach ($schedules as $key => $event) {
                    $events[] = [
                        'hook'               => $hook,
                        'next_run'           => wp_date('Y-m-d H:i:s', $timestamp),
                        'next_run_timestamp' => $timestamp,
                        'schedule'           => $event['schedule'] ?: 'one-time',
                        'args'               => $event['args'],
                    ];
                }
            }
        }

        usort($events, fn($a, $b) => $a['next_run_timestamp'] <=> $b['next_run_timestamp']);

        return ResponseFormatter::toJson($events);
    }

    /**
     * List transients stored in the database with optional search filtering.
     */
    #[McpTool(name: 'wp_list_transients', description: 'List transients stored in the database. Optionally filter by name search.')]
    public function listTransients(
        #[Schema(description: 'Filter transients by name (partial match).')]
        string $search = '',
        #[Schema(description: 'Number of transients to return.', minimum: 1, maximum: 200)]
        int $per_page = 50,
    ): string {
        global $wpdb;

        $search = $this->sanitizeText($search);
        $per_page = min(max(1, $per_page), 200);

        if ($search !== '') {
            $query = $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT %d",
                '_transient_%' . $wpdb->esc_like($search) . '%',
                '_transient_timeout_%',
                $per_page
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT %d",
                '_transient_%',
                '_transient_timeout_%',
                $per_page
            );
        }

        $results = $wpdb->get_results($query);
        $transients = [];

        foreach ($results as $row) {
            $name = str_replace('_transient_', '', $row->option_name);
            $value = $row->option_value;
            if (strlen($value) > 500) {
                $value = substr($value, 0, 500) . '... (truncated)';
            }

            $timeoutKey = '_transient_timeout_' . $name;
            $timeout = get_option($timeoutKey);
            if ($timeout) {
                $expires = wp_date('Y-m-d H:i:s', (int) $timeout);
            } else {
                $expires = 'no expiration';
            }

            $transients[] = [
                'name'    => $name,
                'value'   => $value,
                'expires' => $expires,
            ];
        }

        return ResponseFormatter::toJson($transients);
    }

    /**
     * Read the last N lines from the WordPress debug.log file.
     */
    #[McpTool(name: 'wp_get_error_log', description: 'Read the tail of the PHP/WordPress error log. Reports the resolved log path (PHP ini error_log as set by WP_DEBUG_LOG, then WP_DEBUG_LOG as a path, then wp-content/debug.log), every candidate with existence/size/last-modified, the WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY state and server time — so a stale file copied from another machine is obvious. since filters entries by their "[dd-Mon-yyyy hh:mm:ss UTC]" timestamp.')]
    public function getErrorLog(
        #[Schema(description: 'Number of lines to return from the end of the log (after the since filter).', minimum: 1, maximum: 500)]
        int $lines = 50,
        #[Schema(description: 'Only return log entries at/after this time (ISO 8601 or strtotime-compatible, e.g. "2026-09-08 12:00" or "-2 hours"). Continuation lines without a timestamp follow their entry.')]
        string $since = '',
        #[Schema(description: 'Read a specific candidate instead of the resolved one: "ini", "wp_debug_log" or "wp_content"')]
        string $source = '',
    ): string {
        $lines = min(max(1, $lines), 500);
        $since = trim($this->sanitizeText($since));
        $sinceTs = null;
        if ($since !== '') {
            $sinceTs = strtotime($since);
            if ($sinceTs === false) {
                throw new \RuntimeException("Could not parse since: {$since}");
            }
        }

        $candidates = $this->logCandidates();
        $source = trim($this->sanitizeText($source));
        if ($source !== '' && ! isset($candidates[$source])) {
            throw new \RuntimeException('source must be one of: ' . implode(', ', array_keys($candidates)));
        }

        $path = null;
        $resolvedFrom = null;
        foreach ($candidates as $key => $candidate) {
            if ($source !== '' && $key !== $source) {
                continue;
            }
            if ($candidate['exists']) {
                $path = $candidate['path'];
                $resolvedFrom = $key;
                break;
            }
        }

        $data = [
            'log_path'         => $path,
            'resolved_from'    => $resolvedFrom,
            'log_exists'       => $path !== null,
            'size_bytes'       => $path !== null ? filesize($path) : null,
            'last_modified'    => $path !== null ? gmdate('c', (int) filemtime($path)) : null,
            'server_time'      => gmdate('c'),
            'candidates'       => $candidates,
            'wp_debug'         => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log'     => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null,
            'wp_debug_display' => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : null,
            'php_error_log_ini' => ini_get('error_log') ?: null,
            'php_log_errors'   => (bool) ini_get('log_errors'),
            'since'            => $since !== '' ? gmdate('c', $sinceTs) : null,
        ];

        if ($path === null) {
            $data['lines'] = [];
            $data['note'] = 'No log file exists at any candidate path. Enable WP_DEBUG + WP_DEBUG_LOG or check the PHP error_log ini setting.';
            return ResponseFormatter::toJson($data);
        }

        // With a since filter the matching entries may sit well before the tail,
        // so read a larger window (2 MB) and filter; otherwise just the tail.
        $maxBytes = $sinceTs !== null ? 2 * 1024 * 1024 : 256 * 1024;
        $tail = $this->readTail($path, $lines + 1, $maxBytes);
        $allLines = explode("\n", trim($tail['buffer']));

        if ($sinceTs !== null) {
            $allLines = self::filterLinesSince($allLines, $sinceTs);
        }

        $data['window_truncated'] = $tail['truncated'];
        $data['lines'] = array_values(array_filter(array_slice($allLines, -$lines), fn($line) => $line !== ''));
        $data['returned'] = count($data['lines']);

        return ResponseFormatter::toJson($data);
    }

    /**
     * Keep only log entries whose leading "[dd-Mon-yyyy hh:mm:ss TZ]" timestamp is
     * at/after $sinceTs; lines without a timestamp inherit the previous decision.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function filterLinesSince(array $lines, int $sinceTs): array
    {
        $keep = false;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]{10,40})\]/', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false) {
                    $keep = $ts >= $sinceTs;
                }
            }
            if ($keep) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array{path: string, exists: bool, size_bytes: ?int, last_modified: ?string}>
     */
    private function logCandidates(): array
    {
        $paths = [];

        $ini = (string) ini_get('error_log');
        if ($ini !== '' && ! in_array(strtolower($ini), ['syslog', 'stderr'], true)) {
            $paths['ini'] = $ini;
        }
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '' && ! in_array(strtolower(WP_DEBUG_LOG), ['1', 'true'], true)) {
            $paths['wp_debug_log'] = WP_DEBUG_LOG;
        }
        $paths['wp_content'] = WP_CONTENT_DIR . '/debug.log';

        $candidates = [];
        foreach ($paths as $key => $path) {
            $exists = is_file($path) && is_readable($path);
            $candidates[$key] = [
                'path'          => $path,
                'exists'        => $exists,
                'size_bytes'    => $exists ? filesize($path) : null,
                'last_modified' => $exists ? gmdate('c', (int) filemtime($path)) : null,
            ];
        }

        return $candidates;
    }

    /**
     * @return array{buffer: string, truncated: bool}
     */
    private function readTail(string $path, int $minLines, int $maxBytes): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException('Cannot open log file: ' . $path);
        }

        $buffer = '';
        $chunk = 8192;
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $read = 0;

        while ($pos > 0 && $read < $maxBytes && substr_count($buffer, "\n") < $minLines) {
            $readSize = min($chunk, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $buffer = fread($handle, $readSize) . $buffer;
            $read += $readSize;
        }
        fclose($handle);

        return ['buffer' => $buffer, 'truncated' => $pos > 0];
    }

    #[McpTool(name: 'wp_get_environment', description: 'Read-only: what is this MCP connected to? Site/home URL, WP_ENVIRONMENT_TYPE and WP_ENV, whether it is production, table prefix, ABSPATH/WP_CONTENT_DIR, server hostname, DB host/name, PHP SAPI, active cache plugins and drop-ins (advanced-cache.php, object-cache.php), WP_DEBUG flags, WPML/ACF versions and the identity of this MCP caller.')]
    public function getEnvironment(): string
    {
        global $wpdb, $table_prefix;

        $envType = function_exists('wp_get_environment_type') ? wp_get_environment_type() : null;

        $activePlugins = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $activePlugins = array_merge($activePlugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $cacheSlugs = [
            'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'cache-enabler',
            'redis-cache', 'object-cache-pro', 'memcached', 'wp-optimize', 'hummingbird-performance', 'sg-cachepress',
            'nginx-helper', 'breeze', 'swift-performance', 'autoptimize', 'flying-press', 'perfmatters', 'cloudflare',
            'varnish-http-purge', 'comet-cache', 'kinsta-mu-plugins', 'wpengine-common',
        ];
        $cachePlugins = [];
        foreach ($activePlugins as $file) {
            $dir = strtolower(explode('/', (string) $file)[0]);
            foreach ($cacheSlugs as $slug) {
                if (str_contains($dir, $slug)) {
                    $cachePlugins[] = $file;
                    break;
                }
            }
        }
        $muPlugins = function_exists('get_mu_plugins') ? array_keys(get_mu_plugins()) : [];

        return ResponseFormatter::toJson([
            'site_url'            => get_site_url(),
            'home_url'            => get_home_url(),
            'rest_url'            => rest_url(),
            'wp_environment_type' => $envType,
            'wp_environment_type_defined' => defined('WP_ENVIRONMENT_TYPE'),
            'wp_env'              => defined('WP_ENV') ? WP_ENV : null,
            'is_production'       => $envType === 'production' || $envType === null,
            'production_note'     => defined('WP_ENVIRONMENT_TYPE') ? null : 'WP_ENVIRONMENT_TYPE is not defined; WordPress assumes production.',
            'table_prefix'        => $wpdb->prefix,
            'base_prefix'         => $wpdb->base_prefix,
            'abspath'             => ABSPATH,
            'wp_content_dir'      => WP_CONTENT_DIR,
            'server_hostname'     => gethostname() ?: null,
            'server_addr'         => $_SERVER['SERVER_ADDR'] ?? null,
            'php_sapi'            => PHP_SAPI,
            'php_version'         => PHP_VERSION,
            'wp_version'          => get_bloginfo('version'),
            'db_host'             => defined('DB_HOST') ? DB_HOST : null,
            'db_name'             => defined('DB_NAME') ? DB_NAME : null,
            'db_version'          => $wpdb->db_version(),
            'multisite'           => is_multisite(),
            'is_ssl'              => is_ssl(),
            'wp_debug'            => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log'        => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null,
            'wp_debug_display'    => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : null,
            'script_debug'        => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
            'disallow_file_mods'  => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
            'wp_cache_constant'   => defined('WP_CACHE') && WP_CACHE,
            'advanced_cache_dropin' => file_exists(WP_CONTENT_DIR . '/advanced-cache.php'),
            'object_cache_dropin' => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
            'external_object_cache' => wp_using_ext_object_cache(),
            'cache_plugins_active' => $cachePlugins,
            'mu_plugins'          => $muPlugins,
            'active_plugin_count' => count($activePlugins),
            'wpml_version'        => defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : null,
            'acf_version'         => defined('ACF_VERSION') ? ACF_VERSION : null,
            'theme'               => wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version'),
            'wp_mcp_version'      => WP_MCP_VERSION,
            'mcp_caller'          => $this->callerIdentity(),
            'server_time'         => gmdate('c'),
            'timezone'            => wp_timezone_string(),
        ]);
    }
}
