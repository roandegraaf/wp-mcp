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
    #[McpTool(name: 'wp_get_error_log', description: 'Read the last N lines from the WordPress debug.log file.')]
    public function getErrorLog(
        #[Schema(description: 'Number of lines to read from the end of the log.', minimum: 1, maximum: 200)]
        int $lines = 50,
    ): string {
        $path = WP_CONTENT_DIR . '/debug.log';

        if (! file_exists($path)) {
            throw new \RuntimeException('Debug log file not found at: ' . $path);
        }

        $lines = min(max(1, $lines), 200);

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        $startLine = max(0, $totalLines - $lines);
        $file->seek($startLine);
        $lastLines = [];
        while (! $file->eof()) {
            $line = rtrim($file->current(), "\r\n");
            if ($line !== '') {
                $lastLines[] = $line;
            }
            $file->next();
        }

        $data = [
            'file'          => $path,
            'total_lines'   => $totalLines,
            'lines'         => $lastLines,
            'wp_debug'      => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log'  => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
        ];

        return ResponseFormatter::toJson($data);
    }
}
