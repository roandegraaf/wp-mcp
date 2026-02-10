<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class SeoTool extends AbstractTool
{
    /**
     * Get Yoast SEO metadata for a post.
     */
    #[McpTool(name: 'wp_get_seo_data', description: 'Get Yoast SEO metadata for a post: title, description, focus keyword, canonical URL, robots, social media, and SEO score.')]
    public function getSeoData(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        $this->getPostOrFail($post_id);

        $data = [
            'post_id'         => $post_id,
            'seo_title'       => get_post_meta($post_id, '_yoast_wpseo_title', true) ?: null,
            'meta_description' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true) ?: null,
            'focus_keyword'   => get_post_meta($post_id, '_yoast_wpseo_focuskw', true) ?: null,
            'canonical_url'   => get_post_meta($post_id, '_yoast_wpseo_canonical', true) ?: null,
            'no_index'        => get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1',
            'no_follow'       => get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true) === '1',
            'og_title'        => get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true) ?: null,
            'og_description'  => get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true) ?: null,
            'og_image'        => get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true) ?: null,
            'twitter_title'   => get_post_meta($post_id, '_yoast_wpseo_twitter-title', true) ?: null,
            'twitter_description' => get_post_meta($post_id, '_yoast_wpseo_twitter-description', true) ?: null,
            'schema_type'     => get_post_meta($post_id, '_yoast_wpseo_schema_page_type', true) ?: null,
        ];

        // Try to get SEO score from Yoast
        $seoScore = get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
        if ($seoScore) {
            $data['seo_score'] = (int) $seoScore;
        }

        $readabilityScore = get_post_meta($post_id, '_yoast_wpseo_content_score', true);
        if ($readabilityScore) {
            $data['readability_score'] = (int) $readabilityScore;
        }

        return ResponseFormatter::toJson($data);
    }

    /**
     * Update Yoast SEO meta fields for a post.
     */
    #[McpTool(name: 'wp_update_seo_data', description: 'Update Yoast SEO meta fields: title, description, focus keyword, canonical URL, robots directives, and social media metadata.')]
    public function updateSeoData(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'SEO title')]
        string $seo_title = '',
        #[Schema(description: 'Meta description')]
        string $meta_description = '',
        #[Schema(description: 'Focus keyword')]
        string $focus_keyword = '',
        #[Schema(description: 'Canonical URL')]
        string $canonical_url = '',
        #[Schema(description: 'Set noindex')]
        bool $no_index = false,
        #[Schema(description: 'Set nofollow')]
        bool $no_follow = false,
        #[Schema(description: 'Open Graph title')]
        string $og_title = '',
        #[Schema(description: 'Open Graph description')]
        string $og_description = '',
        #[Schema(description: 'Twitter title')]
        string $twitter_title = '',
        #[Schema(description: 'Twitter description')]
        string $twitter_description = '',
    ): string {
        $this->getPostOrFail($post_id);

        $updates = [];

        $fieldMap = [
            'seo_title'           => ['_yoast_wpseo_title', $seo_title],
            'meta_description'    => ['_yoast_wpseo_metadesc', $meta_description],
            'focus_keyword'       => ['_yoast_wpseo_focuskw', $focus_keyword],
            'canonical_url'       => ['_yoast_wpseo_canonical', $canonical_url],
            'og_title'            => ['_yoast_wpseo_opengraph-title', $og_title],
            'og_description'      => ['_yoast_wpseo_opengraph-description', $og_description],
            'twitter_title'       => ['_yoast_wpseo_twitter-title', $twitter_title],
            'twitter_description' => ['_yoast_wpseo_twitter-description', $twitter_description],
        ];

        foreach ($fieldMap as $label => [$metaKey, $value]) {
            if ($value !== '') {
                update_post_meta($post_id, $metaKey, $this->sanitizeText($value));
                $updates[] = $label;
            }
        }

        // Handle boolean flags - only update if explicitly set
        if ($no_index) {
            update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '1');
            $updates[] = 'no_index';
        }
        if ($no_follow) {
            update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '1');
            $updates[] = 'no_follow';
        }

        return ResponseFormatter::toJson([
            'post_id'  => $post_id,
            'updated'  => $updates,
            'message'  => empty($updates) ? 'No fields to update.' : 'SEO data updated successfully.',
        ]);
    }
}
