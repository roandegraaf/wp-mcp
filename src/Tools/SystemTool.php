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
