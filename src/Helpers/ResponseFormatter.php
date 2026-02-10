<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

class ResponseFormatter
{
    /**
     * Format a WP_Post for LLM output.
     */
    public static function formatPost(\WP_Post $post, bool $includeContent = true, bool $includeAcf = false): array
    {
        $data = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'type'           => $post->post_type,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author'         => self::formatAuthor((int) $post->post_author),
            'excerpt'        => $post->post_excerpt,
            'url'            => get_permalink($post->ID),
            'edit_url'       => get_edit_post_link($post->ID, 'raw'),
            'featured_image' => self::formatFeaturedImage($post->ID),
        ];

        if ($includeContent) {
            $data['content'] = $post->post_content;
        }

        if ($includeAcf && function_exists('get_fields')) {
            $data['acf_fields'] = AcfHelper::getFieldsWithSchema($post->ID);
        }

        // Include taxonomies
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy->name);
            if (! is_wp_error($terms) && ! empty($terms)) {
                $data['taxonomies'][$taxonomy->name] = array_map(function ($term) {
                    return [
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }, $terms);
            }
        }

        return $data;
    }

    /**
     * Format a list of posts with pagination info.
     */
    public static function formatPostList(\WP_Query $query): array
    {
        $posts = array_map(function ($post) {
            return self::formatPost($post, false, false);
        }, $query->posts);

        return [
            'posts'      => $posts,
            'pagination' => [
                'page'        => max(1, $query->get('paged') ?: 1),
                'per_page'    => (int) $query->get('posts_per_page'),
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ];
    }

    /**
     * Format author info.
     */
    public static function formatAuthor(int $userId): array
    {
        $user = get_userdata($userId);
        if (! $user) {
            return ['id' => $userId, 'name' => 'Unknown'];
        }

        return [
            'id'           => $userId,
            'name'         => $user->display_name,
            'email'        => $user->user_email,
        ];
    }

    /**
     * Format featured image with URLs.
     */
    public static function formatFeaturedImage(int $postId): ?array
    {
        $thumbnailId = get_post_thumbnail_id($postId);
        if (! $thumbnailId) {
            return null;
        }

        return self::formatAttachment((int) $thumbnailId);
    }

    /**
     * Format an attachment/media item.
     */
    public static function formatAttachment(int $attachmentId): ?array
    {
        $post = get_post($attachmentId);
        if (! $post || $post->post_type !== 'attachment') {
            return null;
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        $url = wp_get_attachment_url($attachmentId);

        $data = [
            'id'        => $attachmentId,
            'title'     => $post->post_title,
            'url'       => $url,
            'alt'       => get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'caption'   => $post->post_excerpt,
            'mime_type' => $post->post_mime_type,
        ];

        if ($metadata) {
            $data['width'] = $metadata['width'] ?? null;
            $data['height'] = $metadata['height'] ?? null;
            $data['file'] = $metadata['file'] ?? null;

            // Include available sizes
            if (! empty($metadata['sizes'])) {
                $data['sizes'] = [];
                foreach ($metadata['sizes'] as $size => $sizeData) {
                    $sizeUrl = wp_get_attachment_image_src($attachmentId, $size);
                    $data['sizes'][$size] = [
                        'url'    => $sizeUrl ? $sizeUrl[0] : null,
                        'width'  => $sizeData['width'],
                        'height' => $sizeData['height'],
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Format a taxonomy term.
     */
    public static function formatTerm(\WP_Term $term, bool $includeAcf = false): array
    {
        $data = [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
            'url'         => get_term_link($term),
        ];

        if ($includeAcf && function_exists('get_fields')) {
            $fields = get_fields('term_' . $term->term_id);
            if ($fields) {
                $data['acf_fields'] = $fields;
            }
        }

        return $data;
    }

    /**
     * Format a user.
     */
    public static function formatUser(\WP_User $user): array
    {
        return [
            'id'           => $user->ID,
            'username'     => $user->user_login,
            'name'         => $user->display_name,
            'email'        => $user->user_email,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
            'url'          => $user->user_url,
        ];
    }

    /**
     * Format a menu item.
     */
    public static function formatMenuItem(\WP_Post $item): array
    {
        return [
            'id'        => $item->ID,
            'title'     => $item->title,
            'url'       => $item->url,
            'type'      => $item->type,
            'object'    => $item->object,
            'object_id' => (int) $item->object_id,
            'parent'    => (int) $item->menu_item_parent,
            'order'     => (int) $item->menu_order,
            'target'    => $item->target,
            'classes'   => array_filter($item->classes ?? []),
        ];
    }

    /**
     * Encode result as JSON for tool response.
     */
    public static function toJson(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
