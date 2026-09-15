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
    #[McpTool(name: 'wp_get_recent_changes', description: 'Get a unified recent activity log showing recently modified posts across every public and admin-visible post type (including CPTs flagged exclude_from_search, which post_type=any silently drops). include_revisions=true adds a separate list of revisions/autosaves in the same window with their author, so "who saved what, when" is visible even when the parent post_modified did not move.')]
    public function getRecentChanges(
        #[Schema(description: 'Number of results per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
        #[Schema(description: 'Only show changes at/after this date-time (ISO 8601, e.g. 2026-09-08 or 2026-09-08T14:00:00)')]
        string $since = '',
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
        #[Schema(description: 'Restrict to one post type slug; empty means all')]
        string $post_type = '',
        #[Schema(description: 'Also list revisions and autosaves (with author) modified in the window')]
        bool $include_revisions = false,
    ): string {
        $post_type = $this->sanitizeText($post_type);
        $postTypes = $post_type !== ''
            ? [$post_type]
            : array_values(array_diff(
                array_unique(array_merge(get_post_types(['public' => true]), get_post_types(['show_ui' => true]))),
                ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_font_family', 'wp_font_face']
            ));

        $args = array_merge([
            'post_type'   => $postTypes,
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ], $this->paginationArgs($page, $per_page));

        $since = $this->sanitizeText($since);
        if ($since !== '') {
            if (strtotime($since) === false) {
                throw new \RuntimeException("Could not parse since: {$since}");
            }
            $args['date_query'] = [
                [
                    'after'     => $since,
                    'inclusive' => true,
                    'column'    => 'post_modified',
                ],
            ];
        }

        $query = new \WP_Query($args);

        $changes = array_map(function (\WP_Post $post) {
            $author = get_userdata((int) $post->post_author);
            $editLast = (int) get_post_meta($post->ID, '_edit_last', true);
            $editor = $editLast ? get_userdata($editLast) : null;
            return [
                'id'        => $post->ID,
                'title'     => $post->post_title,
                'type'      => $post->post_type,
                'status'    => $post->post_status,
                'modified'  => $post->post_modified,
                'author'    => $author ? $author->display_name : 'Unknown',
                'edit_last' => $editor ? $editor->display_name : null,
                'url'       => get_permalink($post->ID),
            ];
        }, $query->posts);

        $result = [
            'post_types' => $postTypes,
            'changes'    => $changes,
            'pagination' => [
                'page'        => max(1, $query->get('paged') ?: 1),
                'per_page'    => (int) $query->get('posts_per_page'),
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ];

        if ($include_revisions) {
            $revisionArgs = $args;
            $revisionArgs['post_type'] = 'revision';
            $revisionArgs['post_status'] = 'inherit';
            $revisionQuery = new \WP_Query($revisionArgs);

            $result['revisions'] = array_map(function (\WP_Post $revision) {
                $author = get_userdata((int) $revision->post_author);
                return [
                    'id'             => $revision->ID,
                    'type'           => str_contains($revision->post_name, 'autosave') ? 'autosave' : 'revision',
                    'parent_id'      => (int) $revision->post_parent,
                    'parent_title'   => get_the_title((int) $revision->post_parent),
                    'parent_type'    => get_post_type((int) $revision->post_parent),
                    'modified'       => $revision->post_modified,
                    'author'         => $author ? $author->display_name : 'Unknown',
                    'content_length' => strlen($revision->post_content),
                ];
            }, $revisionQuery->posts);
            $result['revisions_total'] = (int) $revisionQuery->found_posts;
        }

        return ResponseFormatter::toJson($result);
    }
}
