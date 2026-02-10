<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class WpmlTool extends AbstractTool
{
    private function requireWpml(): void
    {
        if (! function_exists('icl_get_languages')) {
            throw new \RuntimeException('WPML is required but not active.');
        }
    }

    #[McpTool(name: 'wp_list_languages', description: 'List all configured WPML languages with their codes, names, and default status.')]
    public function listLanguages(): string
    {
        $this->requireWpml();

        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=0');
        $defaultLanguage = apply_filters('wpml_default_language', null);

        $result = [];
        foreach ($languages as $lang) {
            $result[] = [
                'code'        => $lang['code'],
                'name'        => $lang['english_name'],
                'native_name' => $lang['native_name'],
                'is_default'  => $lang['code'] === $defaultLanguage,
                'active'      => (bool) $lang['active'],
                'url'         => $lang['url'],
            ];
        }

        return ResponseFormatter::toJson([
            'total'            => count($result),
            'default_language' => $defaultLanguage,
            'languages'        => $result,
        ]);
    }

    #[McpTool(name: 'wp_get_translations', description: 'Get all translations of a post including their language, title, status, and URL.')]
    public function getTranslations(
        #[Schema(description: 'Post ID to get translations for')]
        int $post_id,
    ): string {
        $this->requireWpml();

        $post = $this->getPostOrFail($post_id);
        $elementType = 'post_' . $post->post_type;
        $trid = apply_filters('wpml_element_trid', null, $post_id, $elementType);
        $translations = apply_filters('wpml_get_element_translations', null, $trid, $elementType);

        $result = [];
        foreach ($translations as $translation) {
            $result[] = [
                'language_code' => $translation->language_code,
                'post_id'       => (int) $translation->element_id,
                'title'         => get_the_title((int) $translation->element_id),
                'status'        => get_post_status((int) $translation->element_id),
                'url'           => get_permalink((int) $translation->element_id),
            ];
        }

        return ResponseFormatter::toJson([
            'source_post_id' => $post_id,
            'trid'           => $trid,
            'translations'   => $result,
        ]);
    }

    #[McpTool(name: 'wp_get_translation_status', description: 'Get an overview of translation completeness per language for a given post type.')]
    public function getTranslationStatus(
        #[Schema(description: 'Post type to check translation status for')]
        string $post_type = 'post',
    ): string {
        $this->requireWpml();

        global $wpdb;

        $post_type = $this->sanitizeText($post_type);
        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=0');
        $defaultLanguage = apply_filters('wpml_default_language', null);
        $elementType = 'post_' . $post_type;
        $table = $wpdb->prefix . 'icl_translations';

        $totalDefault = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} t
             INNER JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.element_type = %s AND t.language_code = %s AND p.post_status = 'publish'",
            $elementType,
            $defaultLanguage
        ));

        $languageStats = [];
        foreach ($languages as $lang) {
            if ($lang['code'] === $defaultLanguage) {
                continue;
            }

            $translated = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} t
                 INNER JOIN {$wpdb->posts} p ON t.element_id = p.ID
                 WHERE t.element_type = %s AND t.language_code = %s AND p.post_status = 'publish'",
                $elementType,
                $lang['code']
            ));

            $languageStats[$lang['code']] = [
                'total'      => $totalDefault,
                'translated' => $translated,
                'percentage' => $totalDefault > 0 ? round(($translated / $totalDefault) * 100, 1) : 0,
            ];
        }

        return ResponseFormatter::toJson([
            'post_type'        => $post_type,
            'default_language' => $defaultLanguage,
            'languages'        => $languageStats,
        ]);
    }

    #[McpTool(name: 'wp_create_translation', description: 'Create a translation for an existing post. Links the new post as a WPML translation of the source.')]
    public function createTranslation(
        #[Schema(description: 'Source post ID to translate')]
        int $post_id,
        #[Schema(description: 'Target language code (e.g. "fr", "de", "es")')]
        string $language,
        #[Schema(description: 'Title for the translated post')]
        string $title,
        #[Schema(description: 'Content for the translated post')]
        string $content,
        #[Schema(description: 'Post status: draft, publish, or pending')]
        string $status = 'draft',
    ): string {
        $this->requireWpml();

        $post = $this->getPostOrFail($post_id);

        $title = $this->sanitizeText($title);
        $content = $this->sanitizeHtml($content);
        $language = $this->sanitizeText($language);

        $allowedStatuses = ['draft', 'publish', 'pending'];
        if (! in_array($status, $allowedStatuses, true)) {
            throw new \RuntimeException("Invalid status '{$status}'. Allowed: " . implode(', ', $allowedStatuses));
        }

        $newPostId = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => $post->post_type,
            'post_author'  => $post->post_author,
        ]);

        if ($newPostId instanceof \WP_Error) {
            throw new \RuntimeException('Failed to create translation: ' . $newPostId->get_error_message());
        }

        $elementType = 'post_' . $post->post_type;
        $trid = apply_filters('wpml_element_trid', null, $post_id, $elementType);
        $sourceLanguage = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $post_id,
            'element_type' => $elementType,
        ]);

        do_action('wpml_set_element_language_details', [
            'element_id'           => $newPostId,
            'element_type'         => $elementType,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage,
        ]);

        return ResponseFormatter::toJson([
            'source_post_id'     => $post_id,
            'translated_post_id' => $newPostId,
            'language'           => $language,
            'title'              => $title,
            'status'             => $status,
            'message'            => 'Translation created successfully.',
        ]);
    }
}
