<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SearchTool extends AbstractTool
{
    /**
     * Full-text search across all content types.
     */
    #[McpTool(name: 'wp_search_content', description: 'Full-text search across all content types.')]
    public function searchContent(
        #[Schema(description: 'Search query')]
        string $query,
        #[Schema(description: 'Comma-separated post types to search (default: all public types)')]
        string $post_types = '',
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
        #[Schema(description: 'Results per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
    ): string {
        $types = $post_types !== ''
            ? array_map('trim', explode(',', $post_types))
            : get_post_types(['public' => true]);

        $args = [
            's'              => $this->sanitizeText($query),
            'post_type'      => $types,
            'post_status'    => 'any',
            'orderby'        => 'relevance',
        ];

        $args = array_merge($args, $this->paginationArgs($page, $per_page));

        $wpQuery = new \WP_Query($args);

        return ResponseFormatter::toJson(ResponseFormatter::formatPostList($wpQuery));
    }
}
