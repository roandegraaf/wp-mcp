<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SystemTool extends AbstractTool
{
    /**
     * Allowed wp-cli subcommands. Anything not in this list is rejected.
     * Each entry is the leading token sequence; longer commands (e.g. `option get foo`) match.
     */
    private const CLI_ALLOWLIST = [
        'acorn cache:clear',
        'acorn config:clear',
        'acorn view:clear',
        'acorn route:clear',
        'acorn optimize:clear',
        'cache flush',
        'rewrite flush',
        'transient delete',
        'option get',
        'option list',
        'plugin list',
        'theme list',
        'user list',
        'cron event list',
    ];

    /**
     * Clear Roots Acorn's caches so newly registered Composer blocks/fields are picked up.
     */
    #[McpTool(name: 'wp_clear_acorn_cache', description: 'Clear Roots Acorn caches (app + view + bootstrap) so newly registered ACF Composer blocks and field groups become discoverable. Equivalent to running `wp acorn cache:clear` + `wp acorn view:clear` from the CLI.')]
    public function clearAcornCache(): string
    {
        if (! function_exists('Roots\\app') && ! class_exists('Roots\\Acorn\\Application')) {
            throw new \RuntimeException('Roots Acorn is not active in this WordPress instance.');
        }

        $cleared = [];
        $errors = [];

        try {
            $app = \Roots\app();

            // Flush all cache stores
            try {
                $config = $app->make('config');
                $stores = $config->get('cache.stores', []);
                $cacheManager = $app->make('cache');
                foreach (array_keys($stores) as $store) {
                    try {
                        $cacheManager->store($store)->flush();
                        $cleared[] = "cache:{$store}";
                    } catch (\Throwable $e) {
                        $errors[] = "cache:{$store}: " . $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = 'cache: ' . $e->getMessage();
            }

            // Flush compiled views
            try {
                $viewFactory = $app->make('view');
                if (method_exists($viewFactory, 'flushFinderCache')) {
                    $viewFactory->flushFinderCache();
                }
                $finder = $app->make('view.finder');
                if (method_exists($finder, 'flush')) {
                    $finder->flush();
                }
                $cleared[] = 'view';

                // Remove compiled blade files
                $compiledPath = $app->make('config')->get('view.compiled');
                if ($compiledPath && is_dir($compiledPath)) {
                    foreach (glob(rtrim($compiledPath, '/') . '/*.php') ?: [] as $file) {
                        @unlink($file);
                    }
                    $cleared[] = 'view:compiled';
                }
            } catch (\Throwable $e) {
                $errors[] = 'view: ' . $e->getMessage();
            }

            // Drop bootstrap cache (where service-provider manifests live)
            try {
                $bootstrapCache = $app->bootstrapPath('cache');
                if (is_dir($bootstrapCache)) {
                    foreach (glob(rtrim($bootstrapCache, '/') . '/*.php') ?: [] as $file) {
                        @unlink($file);
                    }
                    $cleared[] = 'bootstrap';
                }
            } catch (\Throwable $e) {
                $errors[] = 'bootstrap: ' . $e->getMessage();
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to access Acorn application: ' . $e->getMessage());
        }

        return ResponseFormatter::toJson([
            'cleared' => $cleared,
            'errors'  => $errors,
            'message' => empty($errors) ? 'Acorn caches cleared.' : 'Acorn caches partially cleared; see errors.',
        ]);
    }

    /**
     * Purge caches directly through PHP APIs (no wp-cli / shell required).
     */
    #[McpTool(name: 'wp_purge_caches', description: 'Purge caches directly via PHP APIs — works even when shell_exec/wp-cli are disabled. Flushes the WordPress object cache (Redis/Memcached drop-ins included), resets PHP OPcache, clears WP Rocket page + minified caches, and can delete transients (handy for clearing stuck admin notices). Each layer is independently selectable and reported. Returns which layers ran, were skipped (not active), or failed.')]
    public function purgeCaches(
        #[Schema(description: 'Flush the persistent object cache via wp_cache_flush() (covers Redis/Memcached/APCu drop-ins).')]
        bool $object_cache = true,
        #[Schema(description: 'Clear WP Rocket page cache, minified files and cache-busting files, and rotate the minify keys (mirrors the "Clear cache" admin action). Skipped if WP Rocket is not active.')]
        bool $wp_rocket = true,
        #[Schema(description: 'Reset the PHP OPcache via opcache_reset(). Skipped if OPcache is unavailable.')]
        bool $opcache = true,
        #[Schema(description: 'Transient handling: "none" (default), "expired" (delete only timed-out transients — always safe), or "all" (delete every transient; they regenerate on demand).', enum: ['none', 'expired', 'all'])]
        string $transients = 'none',
        #[Schema(description: 'Optional transient name prefix to target (e.g. "wpml_" or a notice key). When set, only transients whose name starts with this prefix are deleted, overriding the "transients" mode.')]
        string $transient_prefix = '',
    ): string {
        $results = [];

        if ($object_cache) {
            $results['object_cache'] = $this->purgeObjectCache();
        }

        if ($opcache) {
            $results['opcache'] = $this->purgeOpcache();
        }

        if ($wp_rocket) {
            $results['wp_rocket'] = $this->purgeWpRocket();
        }

        $transient_prefix = $this->sanitizeText($transient_prefix);
        if ($transient_prefix !== '' || $transients !== 'none') {
            $results['transients'] = $this->purgeTransients($transients, $transient_prefix);
        }

        return ResponseFormatter::toJson([
            'purged'  => $results,
            'message' => 'Cache purge completed. See per-layer results.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function purgeObjectCache(): array
    {
        if (! function_exists('wp_cache_flush')) {
            return ['ran' => false, 'detail' => 'wp_cache_flush() unavailable.'];
        }

        $ok = (bool) wp_cache_flush();

        // wp_cache_flush_runtime() (WP 6.0+) clears the in-request cache too, which
        // some non-flushable external backends rely on.
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }

        $external = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        return [
            'ran'                 => true,
            'flushed'             => $ok,
            'external_object_cache' => $external,
            'detail'              => $ok
                ? ($external ? 'External object cache flushed.' : 'Object cache flushed.')
                : 'wp_cache_flush() returned false (backend may not support full flush).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purgeOpcache(): array
    {
        if (! function_exists('opcache_reset')) {
            return ['ran' => false, 'detail' => 'OPcache not available on this host.'];
        }

        // opcache_reset() returns false when OPcache is disabled or restricted.
        $ok = @opcache_reset();

        return [
            'ran'    => (bool) $ok,
            'detail' => $ok ? 'OPcache reset.' : 'opcache_reset() returned false (OPcache disabled or restricted).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purgeWpRocket(): array
    {
        if (! function_exists('rocket_clean_domain')) {
            return ['ran' => false, 'detail' => 'WP Rocket is not active.'];
        }

        $cleared = [];

        rocket_clean_domain();
        $cleared[] = 'domain';

        if (function_exists('rocket_clean_minify')) {
            rocket_clean_minify();
            $cleared[] = 'minify';
        }
        if (function_exists('rocket_clean_cache_busting')) {
            rocket_clean_cache_busting();
            $cleared[] = 'cache_busting';
        }

        // Rotate the minify cache keys so regenerated files get fresh URLs,
        // mirroring WP Rocket's own "Clear cache" admin action.
        if (defined('WP_ROCKET_SLUG') && function_exists('create_rocket_uniqid')) {
            $options = get_option(WP_ROCKET_SLUG);
            if (is_array($options)) {
                $options['minify_css_key'] = create_rocket_uniqid();
                $options['minify_js_key']  = create_rocket_uniqid();
                remove_all_filters('update_option_' . WP_ROCKET_SLUG);
                update_option(WP_ROCKET_SLUG, $options);
                $cleared[] = 'minify_keys_rotated';
            }
        }

        return ['ran' => true, 'cleared' => $cleared, 'detail' => 'WP Rocket caches cleared.'];
    }

    /**
     * Delete transients through the proper API so both the DB rows and any
     * object-cache copies are removed.
     *
     * @return array<string, mixed>
     */
    private function purgeTransients(string $mode, string $prefix): array
    {
        global $wpdb;

        // Expired-only is the safe path and has a dedicated core helper.
        if ($prefix === '' && $mode === 'expired') {
            if (function_exists('delete_expired_transients')) {
                delete_expired_transients(true);
                return ['ran' => true, 'mode' => 'expired', 'detail' => 'Expired transients deleted.'];
            }
            return ['ran' => false, 'detail' => 'delete_expired_transients() unavailable.'];
        }

        // Collect transient names from the options table. With an external object
        // cache transients may not live here — the object_cache flush covers those.
        $like = $prefix !== ''
            ? '_transient_' . $wpdb->esc_like($prefix) . '%'
            : '_transient_%';
        $siteLike = $prefix !== ''
            ? '_site_transient_' . $wpdb->esc_like($prefix) . '%'
            : '_site_transient_%';

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_name NOT LIKE %s",
            $like,
            '_transient_timeout_%'
        ));
        $siteNames = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_name NOT LIKE %s",
            $siteLike,
            '_site_transient_timeout_%'
        ));

        $deleted = 0;
        foreach ($names as $optionName) {
            $deleted += delete_transient(substr($optionName, strlen('_transient_'))) ? 1 : 0;
        }
        foreach ($siteNames as $optionName) {
            $deleted += delete_site_transient(substr($optionName, strlen('_site_transient_'))) ? 1 : 0;
        }

        return [
            'ran'     => true,
            'mode'    => $prefix !== '' ? 'prefix' : $mode,
            'prefix'  => $prefix,
            'deleted' => $deleted,
            'detail'  => "Deleted {$deleted} transient(s) from the options table." .
                ($prefix === '' ? '' : " (prefix: {$prefix})"),
        ];
    }

    /**
     * Run a safe-listed wp-cli command.
     */
    #[McpTool(name: 'wp_run_cli', description: 'Run a wp-cli command from a fixed allowlist. Permitted: acorn cache:clear, acorn config:clear, acorn view:clear, acorn route:clear, acorn optimize:clear, cache flush, rewrite flush, transient delete, option get, option list, plugin list, theme list, user list, cron event list. Requires wp-cli on PATH and shell_exec enabled on the host.')]
    public function runCli(
        #[Schema(description: 'wp-cli subcommand WITHOUT the leading "wp", e.g. "acorn cache:clear" or "option get blogname"')]
        string $command,
    ): string {
        $command = trim($command);
        if ($command === '') {
            throw new \RuntimeException('command is required.');
        }
        if (preg_match('/[;&|`$<>\\\\\n\r]/', $command)) {
            throw new \RuntimeException('command contains disallowed shell characters.');
        }

        if (! $this->isCommandAllowed($command)) {
            throw new \RuntimeException(
                'Command not in allowlist. Allowed prefixes: ' . implode(', ', self::CLI_ALLOWLIST)
            );
        }

        if (! function_exists('shell_exec')) {
            throw new \RuntimeException('shell_exec is disabled on this host; cannot invoke wp-cli.');
        }

        $wpBinary = $this->resolveWpBinary();
        if ($wpBinary === null) {
            throw new \RuntimeException('wp-cli binary not found on PATH. Tried: wp, wp-cli.phar.');
        }

        $abspath = defined('ABSPATH') ? ABSPATH : getcwd();
        $cmd = escapeshellcmd($wpBinary)
            . ' --path=' . escapeshellarg(rtrim($abspath, '/'))
            . ' --skip-plugins=wp-mcp'
            . ' ' . $command
            . ' 2>&1';

        $output = shell_exec($cmd);

        return ResponseFormatter::toJson([
            'command' => $command,
            'output'  => $output === null ? '' : trim((string) $output),
        ]);
    }

    private function isCommandAllowed(string $command): bool
    {
        foreach (self::CLI_ALLOWLIST as $allowed) {
            if ($command === $allowed || strpos($command, $allowed . ' ') === 0) {
                return true;
            }
        }
        return false;
    }

    private function resolveWpBinary(): ?string
    {
        foreach (['wp', 'wp-cli.phar'] as $candidate) {
            $path = shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($path) && trim($path) !== '') {
                return trim($path);
            }
        }
        return null;
    }
}
