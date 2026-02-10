<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class ContentIntelligenceTool extends AbstractTool
{
    /**
     * Analyze a post's content quality.
     */
    #[McpTool(name: 'wp_analyze_content', description: 'Analyze a post\'s content quality: word count, headings, links, images, read time, and SEO indicators.')]
    public function analyzeContent(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        $post = $this->getPostOrFail($post_id);
        $content = $post->post_content;
        $text = strip_tags($content);

        $wordCount = str_word_count($text);

        // Count paragraphs: <p> tags or double newlines
        $paragraphCount = preg_match_all('/<p[\s>]/i', $content);
        if ($paragraphCount === 0) {
            $paragraphCount = count(array_filter(preg_split('/\n\s*\n/', $text)));
        }

        // Count headings
        $headings = [
            'h2' => preg_match_all('/<h2[\s>]/i', $content),
            'h3' => preg_match_all('/<h3[\s>]/i', $content),
            'h4' => preg_match_all('/<h4[\s>]/i', $content),
        ];

        // Extract links
        $internalLinks = 0;
        $externalLinks = 0;
        $homeUrl = home_url();

        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (str_starts_with($url, $homeUrl) || str_starts_with($url, '/')) {
                    $internalLinks++;
                } else {
                    $externalLinks++;
                }
            }
        }

        // Count images
        $imageCount = preg_match_all('/<img[\s]/i', $content);

        return ResponseFormatter::toJson([
            'post_id'              => $post_id,
            'word_count'           => $wordCount,
            'paragraph_count'      => $paragraphCount,
            'headings'             => $headings,
            'internal_links'       => $internalLinks,
            'external_links'       => $externalLinks,
            'image_count'          => $imageCount,
            'estimated_read_time'  => (int) ceil($wordCount / 200),
            'has_featured_image'   => has_post_thumbnail($post_id),
            'has_excerpt'          => ! empty($post->post_excerpt),
            'has_meta_description' => ! empty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true)),
        ]);
    }

    /**
     * Scan a post for links and optionally check their HTTP status.
     */
    #[McpTool(name: 'wp_find_broken_links', description: 'Scan a post for links and optionally check their HTTP status to find broken links.')]
    public function findBrokenLinks(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Whether to check HTTP status of each link')]
        bool $check_status = false,
        #[Schema(description: 'HTTP request timeout in seconds (max 5)', maximum: 5)]
        int $timeout = 3,
    ): string {
        $post = $this->getPostOrFail($post_id);
        $content = $post->post_content;
        $homeUrl = home_url();
        $timeout = min($timeout, 5);

        $links = [];

        // Extract links with anchor text
        if (! preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            return ResponseFormatter::toJson([
                'post_id' => $post_id,
                'total'   => 0,
                'links'   => [],
            ]);
        }

        // Limit to 50 links
        $matches = array_slice($matches, 0, 50);

        foreach ($matches as $match) {
            $url = $match[1];
            $anchorText = strip_tags($match[2]);
            $isInternal = str_starts_with($url, $homeUrl) || str_starts_with($url, '/');

            $link = [
                'url'         => $url,
                'anchor_text' => $anchorText,
                'type'        => $isInternal ? 'internal' : 'external',
            ];

            if ($check_status) {
                $response = wp_remote_head($url, [
                    'timeout'     => $timeout,
                    'redirection' => 2,
                ]);

                if (is_wp_error($response)) {
                    $link['status_code'] = 0;
                    $link['status'] = 'error';
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $link['status_code'] = $code;

                    if ($code >= 200 && $code < 300) {
                        $link['status'] = 'ok';
                    } elseif ($code >= 300 && $code < 400) {
                        $link['status'] = 'redirect';
                    } else {
                        $link['status'] = 'broken';
                    }
                }
            }

            $links[] = $link;
        }

        return ResponseFormatter::toJson([
            'post_id' => $post_id,
            'total'   => count($links),
            'links'   => $links,
        ]);
    }

    /**
     * Find posts with no internal links pointing to them.
     */
    #[McpTool(name: 'wp_find_orphan_content', description: 'Find published posts that have no internal links pointing to them from other content. This is an expensive operation.')]
    public function findOrphanContent(
        #[Schema(description: 'Post type to check')]
        string $post_type = 'post',
        #[Schema(description: 'Results per page', maximum: 50)]
        int $per_page = 20,
        #[Schema(description: 'Page number')]
        int $page = 1,
    ): string {
        global $wpdb;

        $page = max(1, $page);
        $per_page = min(max(1, $per_page), 50);

        // Get all published posts of this type
        $posts = get_posts([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ]);

        if (empty($posts)) {
            return ResponseFormatter::toJson([
                'orphaned_posts' => [],
                'pagination'     => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => 0,
                    'total_pages' => 0,
                ],
            ]);
        }

        $orphaned = [];

        foreach ($posts as $postId) {
            $permalink = get_permalink($postId);
            if (! $permalink) {
                continue;
            }

            // Search for this permalink in other posts' content
            $escapedUrl = $wpdb->esc_like($permalink);
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND ID != %d
                 AND post_content LIKE %s",
                $postId,
                '%' . $escapedUrl . '%'
            ));

            if ($count === 0) {
                $post = get_post($postId);
                $orphaned[] = [
                    'id'    => $postId,
                    'title' => $post->post_title,
                    'url'   => $permalink,
                    'date'  => $post->post_date,
                    'type'  => $post->post_type,
                ];
            }
        }

        $total = count($orphaned);
        $totalPages = (int) ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        $orphaned = array_slice($orphaned, $offset, $per_page);

        return ResponseFormatter::toJson([
            'orphaned_posts' => $orphaned,
            'pagination'     => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'note' => 'Limited to 500 posts for performance. Use pagination to scan more.',
        ]);
    }
}
