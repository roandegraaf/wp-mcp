<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * One-time magic login URLs so an agent can open wp-admin as a specific user.
 *
 * The raw token only ever exists in the URL handed back to the caller; the
 * transient is keyed by its SHA-256 so a database read cannot reproduce it.
 */
class LoginLink
{
    private const TRANSIENT_PREFIX = 'wp_mcp_login_';
    private const LOG_OPTION = 'wp_mcp_login_link_log';
    private const LOG_MAX = 100;
    public const MAX_TTL = 900;

    public static function register(): void
    {
        add_action('init', [self::class, 'maybeConsume'], 1);
    }

    /**
     * @return array<string, mixed>
     */
    public static function issue(\WP_User $user, string $redirectTo, int $ttl, bool $singleUse, string $createdBy): array
    {
        $ttl = min(max(1, $ttl), self::MAX_TTL);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $now = time();

        $record = [
            'hash'        => $hash,
            'user_id'     => $user->ID,
            'user_login'  => $user->user_login,
            'redirect_to' => self::normalizeRedirect($redirectTo),
            'single_use'  => $singleUse,
            'created_by'  => $createdBy,
            'created_at'  => gmdate('c', $now),
            'expires_at'  => $now + $ttl,
            'used_at'     => null,
            'used_from'   => null,
        ];

        set_transient(self::TRANSIENT_PREFIX . $hash, $record, $ttl);
        self::appendLog($record);

        return [
            'url'         => add_query_arg('wp_mcp_login', $token, home_url('/')),
            'user'        => ['id' => $user->ID, 'login' => $user->user_login, 'roles' => $user->roles],
            'redirect_to' => $record['redirect_to'],
            'expires_at'  => gmdate('c', $record['expires_at']),
            'ttl_seconds' => $ttl,
            'single_use'  => $singleUse,
            'created_by'  => $createdBy,
        ];
    }

    public static function maybeConsume(): void
    {
        $token = $_GET['wp_mcp_login'] ?? null;
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }

        $hash = hash('sha256', $token);
        $key = self::TRANSIENT_PREFIX . $hash;
        $record = get_transient($key);

        if (! is_array($record) || (int) $record['expires_at'] < time()) {
            wp_die('This login link is invalid, expired or already used.', 'WP MCP', ['response' => 403]);
        }

        $user = get_user_by('id', (int) $record['user_id']);
        if (! $user instanceof \WP_User) {
            delete_transient($key);
            wp_die('The user for this login link no longer exists.', 'WP MCP', ['response' => 403]);
        }

        if (! empty($record['single_use'])) {
            delete_transient($key);
        }

        self::markUsed($hash);

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user);

        wp_safe_redirect($record['redirect_to']);
        exit;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function log(): array
    {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? array_reverse($log) : [];
    }

    private static function normalizeRedirect(string $redirectTo): string
    {
        $redirectTo = trim($redirectTo);
        if ($redirectTo === '') {
            return admin_url();
        }

        if (preg_match('#^https?://#i', $redirectTo)) {
            $host = wp_parse_url($redirectTo, PHP_URL_HOST);
            $allowed = array_filter([
                wp_parse_url(home_url('/'), PHP_URL_HOST),
                wp_parse_url(site_url('/'), PHP_URL_HOST),
            ]);
            if (! is_string($host) || ! in_array(strtolower($host), array_map('strtolower', $allowed), true)) {
                throw new \RuntimeException('redirect_to must be a relative path or a URL on this site.');
            }
            return esc_url_raw($redirectTo);
        }

        return site_url('/' . ltrim($redirectTo, '/'));
    }

    private static function appendLog(array $record): void
    {
        $log = get_option(self::LOG_OPTION, []);
        if (! is_array($log)) {
            $log = [];
        }
        $log[] = $record;
        $log = array_slice($log, -self::LOG_MAX);

        if (get_option(self::LOG_OPTION, null) === null) {
            add_option(self::LOG_OPTION, $log, '', 'no');
            return;
        }
        update_option(self::LOG_OPTION, $log, false);
    }

    private static function markUsed(string $hash): void
    {
        $log = get_option(self::LOG_OPTION, []);
        if (! is_array($log)) {
            return;
        }
        foreach ($log as $i => $entry) {
            if (($entry['hash'] ?? '') === $hash) {
                $log[$i]['used_at'] = gmdate('c');
                $log[$i]['used_from'] = $_SERVER['REMOTE_ADDR'] ?? null;
            }
        }
        update_option(self::LOG_OPTION, $log, false);
    }
}
