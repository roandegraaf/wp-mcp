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
}
