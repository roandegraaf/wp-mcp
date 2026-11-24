<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Lets wp_render_post fetch a page as a logged-in user.
 *
 * The loopback request carries a logged_in cookie bound to a dedicated session
 * token that is destroyed right after the request, plus a one-time header token.
 * WPML only shows hidden languages on the front end to users with the
 * icl_show_hidden_languages preference, so while that header token is valid the
 * preference is reported as on for that user, for that request only; the stored
 * user meta is never changed.
 */
class RenderAsUser
{
    public const HEADER = 'X-WP-MCP-Render-As';
    private const SERVER_KEY = 'HTTP_X_WP_MCP_RENDER_AS';
    private const TRANSIENT_PREFIX = 'wp_mcp_render_as_';
    private const TTL = 120;

    public static function register(): void
    {
        $token = $_SERVER[self::SERVER_KEY] ?? null;
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }

        $userId = (int) get_transient(self::TRANSIENT_PREFIX . hash('sha256', $token));
        if ($userId <= 0) {
            return;
        }

        add_filter('get_user_metadata', static function ($value, $objectId, $metaKey) use ($userId) {
            return $metaKey === 'icl_show_hidden_languages' && (int) $objectId === $userId ? ['1'] : $value;
        }, 10, 3);
    }

    /**
     * @return array{cookies: array<string, string>, headers: array<string, string>, session_token: string, header_hash: string, user_id: int}
     */
    public static function issue(\WP_User $user): array
    {
        $expiration = time() + self::TTL;
        $sessionToken = \WP_Session_Tokens::get_instance($user->ID)->create($expiration);
        $cookie = wp_generate_auth_cookie($user->ID, $expiration, 'logged_in', $sessionToken);

        $headerToken = bin2hex(random_bytes(32));
        $headerHash = hash('sha256', $headerToken);
        set_transient(self::TRANSIENT_PREFIX . $headerHash, $user->ID, self::TTL);

        return [
            'cookies'       => [LOGGED_IN_COOKIE => $cookie],
            'headers'       => [self::HEADER => $headerToken],
            'session_token' => $sessionToken,
            'header_hash'   => $headerHash,
            'user_id'       => $user->ID,
        ];
    }

    /**
     * @param array{session_token: string, header_hash: string, user_id: int} $issued
     */
    public static function revoke(array $issued): void
    {
        \WP_Session_Tokens::get_instance($issued['user_id'])->destroy($issued['session_token']);
        delete_transient(self::TRANSIENT_PREFIX . $issued['header_hash']);
    }
}
