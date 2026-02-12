<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\AcfHelper;

class PostTool extends AbstractTool
{
    /**
     * List posts, pages, or custom post types with filtering options.
     */
    #[McpTool(name: 'wp_list_posts', description: 'List posts/pages/CPTs with filtering by status, search, taxonomy, date, author, and pagination.')]
    public function listPosts(
        #[Schema(description: 'Post type slug (post, page, or custom type)')]
        string $post_type = 'post',
        #[Schema(description: 'Post status: publish, draft, pending, private, trash')]
        string $status = 'any',
        #[Schema(description: 'Search query string')]
        string $search = '',
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
        #[Schema(description: 'Posts per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
        #[Schema(description: 'Author user ID')]
        int $author = 0,
        #[Schema(description: 'Order by: date, title, modified, menu_order, ID')]
        string $orderby = 'date',
        #[Schema(description: 'Order direction: ASC or DESC')]
        string $order = 'DESC',
        #[Schema(description: 'Taxonomy query as JSON, e.g. {"category": [1,2]} or {"category": "news"}')]
        string $taxonomy_filter = '',
    ): string {
        $args = [
            'post_type'      => $this->sanitizeText($post_type),
            'post_status'    => $this->sanitizeText($status),
            'orderby'        => $this->sanitizeText($orderby),
            'order'          => strtoupper($this->sanitizeText($order)) === 'ASC' ? 'ASC' : 'DESC',
        ];

        $args = array_merge($args, $this->paginationArgs($page, $per_page));

        if ($search !== '') {
            $args['s'] = $this->sanitizeText($search);
        }

        if ($author > 0) {
            $args['author'] = $author;
        }

        // Parse taxonomy filter
        if ($taxonomy_filter !== '') {
            $taxQuery = json_decode($taxonomy_filter, true);
            if (is_array($taxQuery)) {
                $args['tax_query'] = [];
                foreach ($taxQuery as $taxonomy => $terms) {
                    $termList = is_array($terms) ? $terms : [$terms];
                    $field = is_numeric($termList[0] ?? '') ? 'term_id' : 'slug';
                    $args['tax_query'][] = [
                        'taxonomy' => sanitize_key($taxonomy),
                        'field'    => $field,
                        'terms'    => $termList,
                    ];
                }
            }
        }

        $query = new \WP_Query($args);

        return ResponseFormatter::toJson(ResponseFormatter::formatPostList($query));
    }

    /**
     * Get a single post by ID with full content, ACF fields, SEO data, and featured image.
     */
    #[McpTool(name: 'wp_get_post', description: 'Get single post by ID with content, ACF fields, SEO data, featured image.')]
    public function getPost(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Include ACF field data')]
        bool $include_acf = true,
    ): string {
        $post = $this->getPostOrFail($post_id);

        $data = ResponseFormatter::formatPost($post, true, $include_acf);

        // Include SEO data if Yoast is active
        $seoTitle = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if ($seoTitle || metadata_exists('post', $post_id, '_yoast_wpseo_metadesc')) {
            $data['seo'] = [
                'title'       => $seoTitle ?: null,
                'description' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true) ?: null,
                'focus_keyword' => get_post_meta($post_id, '_yoast_wpseo_focuskw', true) ?: null,
            ];
        }

        return ResponseFormatter::toJson($data);
    }

    /**
     * Create a new post, page, or custom post type entry.
     */
    #[McpTool(name: 'wp_create_post', description: 'Create post/page/CPT with title, content, status, and ACF fields.')]
    public function createPost(
        #[Schema(description: 'Post title')]
        string $title,
        #[Schema(description: 'Post type slug')]
        string $post_type = 'post',
        #[Schema(description: 'Post content (HTML)')]
        string $content = '',
        #[Schema(description: 'Post status: draft, publish, pending, private')]
        string $status = 'draft',
        #[Schema(description: 'Post excerpt')]
        string $excerpt = '',
        #[Schema(description: 'Post slug')]
        string $slug = '',
        #[Schema(description: 'Parent post ID (for hierarchical types)')]
        int $parent = 0,
        #[Schema(description: 'Featured image attachment ID')]
        int $featured_image = 0,
        #[Schema(description: 'ACF fields as JSON: {"field_name": "value", ...}')]
        string $acf_fields = '',
    ): string {
        $postData = [
            'post_title'   => $this->sanitizeText($title),
            'post_type'    => $this->sanitizeText($post_type),
            'post_content' => $content,
            'post_status'  => $this->sanitizeText($status),
            'post_excerpt' => $this->sanitizeText($excerpt),
        ];

        if ($slug !== '') {
            $postData['post_name'] = sanitize_title($slug);
        }
        if ($parent > 0) {
            $postData['post_parent'] = $parent;
        }

        // Disable kses filters to preserve HTML inside block comment JSON attributes
        kses_remove_filters();
        $postId = wp_insert_post($postData, true);
        kses_init_filters();

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create post: ' . $postId->get_error_message());
        }

        if ($featured_image > 0) {
            set_post_thumbnail($postId, $featured_image);
        }

        // Update ACF fields if provided
        if ($acf_fields !== '' && function_exists('update_field')) {
            $fields = json_decode($acf_fields, true);
            if (is_array($fields)) {
                AcfHelper::updateFields($postId, $fields);
            }
        }

        $post = get_post($postId);
        $data = ResponseFormatter::formatPost($post, true, false);
        $data['message'] = "Post created successfully with ID {$postId}.";

        return ResponseFormatter::toJson($data);
    }

    /**
     * Update an existing post. Only provided fields are changed (partial update).
     */
    #[McpTool(name: 'wp_update_post', description: 'Update existing post (partial - only provided fields change).')]
    public function updatePost(
        #[Schema(description: 'Post ID to update')]
        int $post_id,
        #[Schema(description: 'New title')]
        string $title = '',
        #[Schema(description: 'New content (HTML)')]
        string $content = '',
        #[Schema(description: 'New status: draft, publish, pending, private')]
        string $status = '',
        #[Schema(description: 'New excerpt')]
        string $excerpt = '',
        #[Schema(description: 'New slug')]
        string $slug = '',
        #[Schema(description: 'Featured image attachment ID (0 to remove)')]
        int $featured_image = -1,
        #[Schema(description: 'ACF fields to update as JSON: {"field_name": "value", ...}')]
        string $acf_fields = '',
    ): string {
        $this->getPostOrFail($post_id);

        $postData = ['ID' => $post_id];

        if ($title !== '') {
            $postData['post_title'] = $this->sanitizeText($title);
        }
        if ($content !== '') {
            $postData['post_content'] = $content;
        }
        if ($status !== '') {
            $postData['post_status'] = $this->sanitizeText($status);
        }
        if ($excerpt !== '') {
            $postData['post_excerpt'] = $this->sanitizeText($excerpt);
        }
        if ($slug !== '') {
            $postData['post_name'] = sanitize_title($slug);
        }

        if (count($postData) > 1) {
            // Disable kses filters to preserve HTML inside block comment JSON attributes
            kses_remove_filters();
            $result = wp_update_post($postData, true);
            kses_init_filters();
            if (is_wp_error($result)) {
                throw new \RuntimeException('Failed to update post: ' . $result->get_error_message());
            }
        }

        // Handle featured image
        if ($featured_image >= 0) {
            if ($featured_image === 0) {
                delete_post_thumbnail($post_id);
            } else {
                set_post_thumbnail($post_id, $featured_image);
            }
        }

        // Update ACF fields if provided
        if ($acf_fields !== '' && function_exists('update_field')) {
            $fields = json_decode($acf_fields, true);
            if (is_array($fields)) {
                AcfHelper::updateFields($post_id, $fields);
            }
        }

        $post = get_post($post_id);
        $data = ResponseFormatter::formatPost($post, false, false);
        $data['message'] = "Post {$post_id} updated successfully.";

        return ResponseFormatter::toJson($data);
    }

    /**
     * Trash or permanently delete a post.
     */
    #[McpTool(name: 'wp_delete_post', description: 'Trash or permanently delete a post.')]
    public function deletePost(
        #[Schema(description: 'Post ID to delete')]
        int $post_id,
        #[Schema(description: 'Permanently delete instead of trashing')]
        bool $force = false,
    ): string {
        $post = $this->getPostOrFail($post_id);
        $title = $post->post_title;

        if ($force) {
            $result = wp_delete_post($post_id, true);
        } else {
            $result = wp_trash_post($post_id);
        }

        if (! $result) {
            throw new \RuntimeException("Failed to delete post: {$post_id}");
        }

        $action = $force ? 'permanently deleted' : 'trashed';

        return ResponseFormatter::toJson([
            'success' => true,
            'message' => "Post \"{$title}\" (ID: {$post_id}) has been {$action}.",
        ]);
    }
}
