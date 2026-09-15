<?php

declare(strict_types=1);

namespace WpMcp\Tools;

abstract class AbstractTool
{
    protected function formatError(string $message): array
    {
        return ['error' => true, 'message' => $message];
    }

    protected function isAcfActive(): bool
    {
        return function_exists('get_field') && function_exists('acf_get_field_groups');
    }

    protected function requireAcf(): void
    {
        if (! $this->isAcfActive()) {
            throw new \RuntimeException('ACF PRO is required but not active.');
        }
    }

    protected function getPostOrFail(int $postId): \WP_Post
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }
        return $post;
    }

    protected function sanitizeHtml(string $content): string
    {
        return wp_kses_post($content);
    }

    protected function sanitizeText(string $text): string
    {
        return sanitize_text_field($text);
    }

    protected function paginationArgs(int $page = 1, int $perPage = 20): array
    {
        return [
            'paged'          => max(1, $page),
            'posts_per_page' => min(max(1, $perPage), 100),
        ];
    }

    /**
     * Resolve a user by ID, login or email.
     */
    protected function resolveUser(string $user): \WP_User
    {
        $user = trim($user);
        if ($user === '') {
            throw new \RuntimeException('user is required (ID, login or email).');
        }

        $found = is_numeric($user) ? get_user_by('id', (int) $user) : null;
        $found = $found ?: get_user_by('login', $user);
        $found = $found ?: get_user_by('email', $user);

        if (! $found instanceof \WP_User) {
            throw new \RuntimeException("User not found: {$user}");
        }

        return $found;
    }

    /**
     * Who is driving this MCP session: the logged-in WP user, or the API key name.
     */
    protected function callerIdentity(): string
    {
        $userId = get_current_user_id();
        if ($userId) {
            $user = get_userdata($userId);
            return 'user:' . ($user ? $user->user_login : $userId);
        }

        $keyName = $GLOBALS['wp_mcp_api_key_name'] ?? '';
        return 'api_key:' . ($keyName !== '' ? $keyName : 'unknown');
    }
}
