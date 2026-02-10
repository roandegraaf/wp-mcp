<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SchemaMarkupTool extends AbstractTool
{
    /**
     * Get JSON-LD structured data for a post.
     */
    #[McpTool(name: 'wp_get_schema_markup', description: 'Get JSON-LD structured data for a post. Returns Yoast schema if available, otherwise generates a basic Article/WebPage schema.')]
    public function getSchemaMarkup(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        $post = $this->getPostOrFail($post_id);

        if (defined('WPSEO_VERSION')) {
            return ResponseFormatter::toJson($this->getYoastSchema($post));
        }

        return ResponseFormatter::toJson($this->generateSchema($post));
    }

    private function getYoastSchema(\WP_Post $post): array
    {
        $schemaPageType = get_post_meta($post->ID, '_yoast_wpseo_schema_page_type', true) ?: 'WebPage';
        $schemaArticleType = get_post_meta($post->ID, '_yoast_wpseo_schema_article_type', true) ?: 'Article';

        $author = get_userdata((int) $post->post_author);
        $authorName = $author ? $author->display_name : 'Unknown';

        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'         => $schemaPageType,
                    '@id'           => get_permalink($post->ID) . '#webpage',
                    'url'           => get_permalink($post->ID),
                    'name'          => get_post_meta($post->ID, '_yoast_wpseo_title', true) ?: $post->post_title,
                    'description'   => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: wp_trim_words($post->post_excerpt ?: $post->post_content, 30, '...'),
                    'datePublished' => gmdate('c', strtotime($post->post_date_gmt)),
                    'dateModified'  => gmdate('c', strtotime($post->post_modified_gmt)),
                    'isPartOf'      => ['@id' => home_url('/') . '#website'],
                ],
                [
                    '@type'          => $schemaArticleType,
                    '@id'            => get_permalink($post->ID) . '#article',
                    'headline'       => $post->post_title,
                    'datePublished'  => gmdate('c', strtotime($post->post_date_gmt)),
                    'dateModified'   => gmdate('c', strtotime($post->post_modified_gmt)),
                    'mainEntityOfPage' => ['@id' => get_permalink($post->ID) . '#webpage'],
                    'author'         => ['@type' => 'Person', 'name' => $authorName],
                    'publisher'      => ['@type' => 'Organization', 'name' => get_bloginfo('name')],
                ],
                [
                    '@type' => 'WebSite',
                    '@id'   => home_url('/') . '#website',
                    'url'   => home_url('/'),
                    'name'  => get_bloginfo('name'),
                ],
            ],
        ];

        $thumbnailUrl = get_the_post_thumbnail_url($post->ID, 'full');
        if ($thumbnailUrl) {
            $schema['@graph'][1]['image'] = $thumbnailUrl;
        }

        return [
            'post_id' => $post->ID,
            'source'  => 'yoast',
            'schema'  => $schema,
        ];
    }

    private function generateSchema(\WP_Post $post): array
    {
        $author = get_userdata((int) $post->post_author);
        $authorName = $author ? $author->display_name : 'Unknown';
        $description = $post->post_excerpt ?: wp_trim_words($post->post_content, 30, '...');

        $schema = [
            '@context'       => 'https://schema.org',
            '@type'          => 'Article',
            'headline'       => $post->post_title,
            'datePublished'  => gmdate('c', strtotime($post->post_date_gmt)),
            'dateModified'   => gmdate('c', strtotime($post->post_modified_gmt)),
            'author'         => ['@type' => 'Person', 'name' => $authorName],
            'publisher'      => ['@type' => 'Organization', 'name' => get_bloginfo('name')],
            'url'            => get_permalink($post->ID),
            'description'    => $description,
        ];

        $thumbnailUrl = get_the_post_thumbnail_url($post->ID, 'full');
        if ($thumbnailUrl) {
            $schema['image'] = $thumbnailUrl;
        }

        return [
            'post_id' => $post->ID,
            'source'  => 'generated',
            'schema'  => $schema,
        ];
    }
}
