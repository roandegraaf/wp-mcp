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

    /**
     * Get Yoast's full SEO analysis results for a post.
     */
    #[McpTool(name: 'wp_get_seo_analysis', description: 'Get Yoast SEO analysis for a post: SEO score, readability score, keyword density, and actionable problems/improvements.')]
    public function getSeoAnalysis(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        $post = $this->getPostOrFail($post_id);

        $seoScore = (int) get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
        $readabilityScore = (int) get_post_meta($post_id, '_yoast_wpseo_content_score', true);
        $focusKeyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true) ?: null;
        $metaDesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true) ?: '';
        $seoTitle = get_post_meta($post_id, '_yoast_wpseo_title', true) ?: get_the_title($post_id);
        $noIndex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1';

        $content = strip_tags($post->post_content);
        $wordCount = str_word_count($content);

        $keywordDensity = null;
        if ($focusKeyword && $wordCount > 0) {
            $count = substr_count(strtolower($content), strtolower($focusKeyword));
            $keywordDensity = round(($count / $wordCount) * 100, 2);
        }

        $metaDescLength = mb_strlen($metaDesc);
        $titleLength = mb_strlen($seoTitle);

        $problems = [];
        $improvements = [];

        if (! $focusKeyword) {
            $problems[] = 'No focus keyword set.';
        }
        if ($metaDescLength === 0) {
            $problems[] = 'No meta description set.';
        }

        if ($metaDescLength > 0 && $metaDescLength < 120) {
            $improvements[] = 'Meta description is too short (optimal: 120-155 characters).';
        }
        if ($metaDescLength > 155) {
            $improvements[] = 'Meta description is too long (optimal: 120-155 characters).';
        }
        if ($titleLength < 30) {
            $improvements[] = 'SEO title is too short (optimal: 30-60 characters).';
        }
        if ($titleLength > 60) {
            $improvements[] = 'SEO title is too long (optimal: 30-60 characters).';
        }
        if (! has_post_thumbnail($post_id)) {
            $improvements[] = 'No featured image set.';
        }
        if ($wordCount < 300) {
            $improvements[] = "Low word count ({$wordCount}). Aim for at least 300 words.";
        }

        return ResponseFormatter::toJson([
            'post_id'               => $post_id,
            'seo_score'             => $seoScore,
            'readability_score'     => $readabilityScore,
            'focus_keyword'         => $focusKeyword,
            'keyword_density'       => $keywordDensity,
            'noindex'               => $noIndex,
            'meta_description_length' => $metaDescLength,
            'title_length'          => $titleLength,
            'problems'              => $problems,
            'improvements'          => $improvements,
        ]);
    }

    /**
     * Get Yoast sitemap configuration and status.
     */
    #[McpTool(name: 'wp_get_sitemap_info', description: 'Get Yoast XML sitemap configuration: sitemap URL, indexed post types, taxonomies, and last modified date.')]
    public function getSitemapInfo(): string
    {
        if (! defined('WPSEO_VERSION')) {
            throw new \RuntimeException('Yoast SEO plugin is not active.');
        }

        $sitemapUrl = home_url('/sitemap_index.xml');
        $wpseoOptions = get_option('wpseo', []);

        $indexedPostTypes = [];
        $postTypes = get_post_types(['public' => true], 'names');
        $titleOptions = get_option('wpseo_titles', []);
        foreach ($postTypes as $pt) {
            $excluded = ! empty($titleOptions["noindex-{$pt}"]);
            if (! $excluded) {
                $indexedPostTypes[] = $pt;
            }
        }

        $indexedTaxonomies = [];
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $tax) {
            $excluded = ! empty($titleOptions["noindex-tax-{$tax}"]);
            if (! $excluded) {
                $indexedTaxonomies[] = $tax;
            }
        }

        $lastModified = null;
        $latestPost = get_posts([
            'numberposts'  => 1,
            'post_type'    => $indexedPostTypes ?: 'post',
            'post_status'  => 'publish',
            'orderby'      => 'modified',
            'order'        => 'DESC',
        ]);
        if ($latestPost) {
            $lastModified = $latestPost[0]->post_modified_gmt;
        }

        return ResponseFormatter::toJson([
            'sitemap_url'        => $sitemapUrl,
            'yoast_version'      => WPSEO_VERSION,
            'indexed_post_types' => array_values($indexedPostTypes),
            'indexed_taxonomies' => array_values($indexedTaxonomies),
            'last_modified'      => $lastModified,
        ]);
    }

    /**
     * Update global Yoast SEO settings.
     */
    #[McpTool(name: 'wp_update_seo_settings', description: 'Update global Yoast SEO settings such as title separator, company name, social defaults, and sitemap toggle.')]
    public function updateSeoSettings(
        #[Schema(description: 'Setting name to update', enum: ['title_separator', 'company_name', 'company_or_person', 'company_logo', 'og_default_image', 'og_frontpage_title', 'og_frontpage_desc', 'twitter_site', 'enable_xml_sitemap'])]
        string $option_name,
        #[Schema(description: 'New value for the setting')]
        string $value,
    ): string {
        $allowedKeys = [
            'title_separator'    => 'wpseo_titles',
            'company_name'       => 'wpseo_titles',
            'company_or_person'  => 'wpseo_titles',
            'company_logo'       => 'wpseo_titles',
            'og_default_image'   => 'wpseo_social',
            'og_frontpage_title' => 'wpseo_social',
            'og_frontpage_desc'  => 'wpseo_social',
            'twitter_site'       => 'wpseo_social',
            'enable_xml_sitemap' => 'wpseo',
        ];

        if (! isset($allowedKeys[$option_name])) {
            throw new \RuntimeException("Setting '{$option_name}' is not allowed. Allowed: " . implode(', ', array_keys($allowedKeys)));
        }

        if (! defined('WPSEO_VERSION')) {
            throw new \RuntimeException('Yoast SEO is required but not active.');
        }

        $value = $this->sanitizeText($value);

        $group = $allowedKeys[$option_name];
        $options = get_option($group, []);
        $options[$option_name] = $value;
        update_option($group, $options);

        return ResponseFormatter::toJson([
            'setting'      => $option_name,
            'value'        => $value,
            'option_group' => $group,
            'updated'      => true,
        ]);
    }
}
