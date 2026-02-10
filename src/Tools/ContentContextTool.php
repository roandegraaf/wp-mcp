<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\BlockHelper;
use WpMcp\Helpers\ResponseFormatter;

class ContentContextTool extends AbstractTool
{
    /**
     * Get everything an AI needs to write on-brand content.
     */
    #[McpTool(name: 'wp_get_writing_context', description: 'Get site voice, structure patterns, and topic data an AI needs to write on-brand content.')]
    public function getWritingContext(
        #[Schema(description: 'Post type slug to analyze')]
        string $post_type = 'post',
        #[Schema(description: 'Category ID to scope analysis to (0 for all)')]
        int $category_id = 0,
    ): string {
        $post_type = $this->sanitizeText($post_type);

        // Recent titles
        $titleArgs = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'fields'         => 'ids',
        ];
        if ($category_id > 0) {
            $titleArgs['cat'] = $category_id;
        }
        $titleQuery = new \WP_Query($titleArgs);
        $recentTitles = array_map(function (int $id) {
            return get_the_title($id);
        }, $titleQuery->posts);

        // Common tags
        $tags = get_tags(['orderby' => 'count', 'order' => 'DESC', 'number' => 10]);
        $commonTags = [];
        if (is_array($tags)) {
            $commonTags = array_map(function ($tag) {
                return ['name' => $tag->name, 'count' => $tag->count];
            }, $tags);
        }

        // Common categories
        $categories = get_categories(['orderby' => 'count', 'order' => 'DESC', 'number' => 10]);
        $commonCategories = [];
        if (is_array($categories)) {
            $commonCategories = array_map(function ($cat) {
                return ['name' => $cat->name, 'count' => $cat->count];
            }, $categories);
        }

        // Sample last 20 posts for word count and heading analysis
        $sampleArgs = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
        ];
        if ($category_id > 0) {
            $sampleArgs['cat'] = $category_id;
        }
        $sampleQuery = new \WP_Query($sampleArgs);

        $totalWords = 0;
        $totalHeadings = 0;
        $sampleCount = count($sampleQuery->posts);

        foreach ($sampleQuery->posts as $post) {
            $rendered = BlockHelper::getRenderedContent($post->ID);
            $totalWords += str_word_count(strip_tags($rendered));
            preg_match_all('/<h[23]/i', $rendered, $matches);
            $totalHeadings += count($matches[0]);
        }

        $averageWordCount = $sampleCount > 0 ? (int) round($totalWords / $sampleCount) : 0;
        $typicalHeadingCount = $sampleCount > 0 ? round($totalHeadings / $sampleCount, 1) : 0;

        return ResponseFormatter::toJson([
            'site_title'            => get_bloginfo('name'),
            'tagline'               => get_bloginfo('description'),
            'language'              => get_locale(),
            'recent_titles'         => $recentTitles,
            'common_tags'           => $commonTags,
            'average_word_count'    => $averageWordCount,
            'typical_heading_count' => $typicalHeadingCount,
            'common_categories'     => $commonCategories,
        ]);
    }

    /**
     * Get a unified recent activity log of content changes.
     */
    #[McpTool(name: 'wp_get_recent_changes', description: 'Get a unified recent activity log showing recently modified posts across all types.')]
    public function getRecentChanges(
        #[Schema(description: 'Number of results per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
        #[Schema(description: 'Only show changes after this ISO 8601 date (e.g. 2025-01-01)')]
        string $since = '',
    ): string {
        $args = array_merge([
            'post_type'   => 'any',
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ], $this->paginationArgs(1, $per_page));

        $since = $this->sanitizeText($since);
        if ($since !== '') {
            $args['date_query'] = [
                [
                    'after'  => $since,
                    'column' => 'post_modified',
                ],
            ];
        }

        $query = new \WP_Query($args);

        $changes = array_map(function (\WP_Post $post) {
            $author = get_userdata((int) $post->post_author);
            return [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'type'     => $post->post_type,
                'status'   => $post->post_status,
                'modified' => $post->post_modified,
                'author'   => $author ? $author->display_name : 'Unknown',
                'url'      => get_permalink($post->ID),
            ];
        }, $query->posts);

        return ResponseFormatter::toJson([
            'changes'    => $changes,
            'pagination' => [
                'page'        => max(1, $query->get('paged') ?: 1),
                'per_page'    => (int) $query->get('posts_per_page'),
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ]);
    }
}
