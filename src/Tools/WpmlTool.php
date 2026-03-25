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
                'name'        => $lang['english_name'] ?? $lang['display_name'] ?? $lang['translated_name'] ?? $lang['code'],
                'native_name' => $lang['native_name'] ?? $lang['code'],
                'is_default'  => $lang['code'] === $defaultLanguage,
                'active'      => (bool) ($lang['active'] ?? true),
                'url'         => $lang['url'] ?? '',
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

    #[McpTool(name: 'wp_get_term_translations', description: 'Get all translations of a taxonomy term including their language, name, and slug.')]
    public function getTermTranslations(
        #[Schema(description: 'Term ID to get translations for')]
        int $term_id,
        #[Schema(description: 'Taxonomy name (e.g. category, post_tag, apartment_category)')]
        string $taxonomy,
    ): string {
        $this->requireWpml();

        $term = get_term($term_id, $taxonomy);
        if (! $term || is_wp_error($term)) {
            throw new \RuntimeException("Term not found: {$term_id}");
        }

        $elementType = 'tax_' . $taxonomy;
        $trid = apply_filters('wpml_element_trid', null, $term_id, $elementType);
        $translations = apply_filters('wpml_get_element_translations', null, $trid, $elementType);

        $result = [];
        foreach ($translations as $translation) {
            $translatedTerm = get_term((int) $translation->element_id, $taxonomy);
            $result[] = [
                'language_code' => $translation->language_code,
                'term_id'       => (int) $translation->element_id,
                'name'          => $translatedTerm ? $translatedTerm->name : '',
                'slug'          => $translatedTerm ? $translatedTerm->slug : '',
            ];
        }

        return ResponseFormatter::toJson([
            'source_term_id' => $term_id,
            'taxonomy'       => $taxonomy,
            'trid'           => $trid,
            'translations'   => $result,
        ]);
    }

    #[McpTool(name: 'wp_create_term_translation', description: 'Create a translation for a taxonomy term. Creates the translated term and links it via WPML.')]
    public function createTermTranslation(
        #[Schema(description: 'Source term ID to translate')]
        int $term_id,
        #[Schema(description: 'Taxonomy name (e.g. category, post_tag, apartment_category)')]
        string $taxonomy,
        #[Schema(description: 'Target language code (e.g. "en", "de", "fr")')]
        string $language,
        #[Schema(description: 'Translated term name')]
        string $name,
        #[Schema(description: 'Translated term slug (auto-generated from name if empty)')]
        string $slug = '',
        #[Schema(description: 'Translated term description')]
        string $description = '',
    ): string {
        $this->requireWpml();

        $term = get_term($term_id, $taxonomy);
        if (! $term || is_wp_error($term)) {
            throw new \RuntimeException("Term not found: {$term_id}");
        }

        $language = $this->sanitizeText($language);
        $name = $this->sanitizeText($name);

        // Create the translated term
        $args = [];
        if ($slug !== '') {
            $args['slug'] = sanitize_title($slug);
        }
        if ($description !== '') {
            $args['description'] = $this->sanitizeText($description);
        }

        $result = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to create term: ' . $result->get_error_message());
        }

        $newTermId = $result['term_id'];

        // Link as WPML translation
        $elementType = 'tax_' . $taxonomy;
        $trid = apply_filters('wpml_element_trid', null, $term_id, $elementType);
        $sourceLanguage = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $term_id,
            'element_type' => $elementType,
        ]);

        do_action('wpml_set_element_language_details', [
            'element_id'           => $newTermId,
            'element_type'         => $elementType,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage,
        ]);

        return ResponseFormatter::toJson([
            'source_term_id'     => $term_id,
            'translated_term_id' => $newTermId,
            'taxonomy'           => $taxonomy,
            'language'           => $language,
            'name'               => $name,
            'message'            => 'Term translation created successfully.',
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
        $language = $this->sanitizeText($language);

        $allowedStatuses = ['draft', 'publish', 'pending'];
        if (! in_array($status, $allowedStatuses, true)) {
            throw new \RuntimeException("Invalid status '{$status}'. Allowed: " . implode(', ', $allowedStatuses));
        }

        // Disable kses filters to preserve HTML inside block comment JSON attributes
        kses_remove_filters();
        $newPostId = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => $post->post_type,
            'post_author'  => $post->post_author,
        ]);
        kses_init_filters();

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

    #[McpTool(name: 'wp_register_wpml_string', description: 'Register a string for WPML String Translation and optionally provide its translation. Use this for theme/plugin strings wrapped in __() or _e().')]
    public function registerWpmlString(
        #[Schema(description: 'String domain/context (e.g. "theme-shortstayede", "plugin-name")')]
        string $domain,
        #[Schema(description: 'Unique string name/identifier within the domain')]
        string $name,
        #[Schema(description: 'The original string value (in the default language)')]
        string $value,
        #[Schema(description: 'Target language code for translation (e.g. "en", "de"). Leave empty to just register without translating.')]
        string $language = '',
        #[Schema(description: 'Translated string value. Required when language is provided.')]
        string $translation = '',
        #[Schema(description: 'Translation status: 10 = complete, 3 = needs update, 2 = needs review', minimum: 1, maximum: 10)]
        int $status = 10,
    ): string {
        $this->requireWpml();

        // Register the string
        do_action('wpml_register_single_string', $domain, $name, $value);

        $result = [
            'domain'  => $domain,
            'name'    => $name,
            'value'   => $value,
            'message' => "String '{$name}' registered in domain '{$domain}'.",
        ];

        // Add translation if language is provided
        if ($language !== '' && $translation !== '') {
            // Use WPML's icl_add_string_translation if available
            if (function_exists('icl_add_string_translation') && function_exists('icl_get_string_id')) {
                $stringId = icl_get_string_id($value, $domain, $name);
                if ($stringId) {
                    icl_add_string_translation($stringId, $language, $translation, $status);
                    $result['translated_to'] = $language;
                    $result['translation'] = $translation;
                    $result['message'] = "String '{$name}' registered and translated to {$language}.";
                } else {
                    $result['warning'] = 'String registered but could not find string ID for translation. Try translating separately.';
                }
            } else {
                $result['warning'] = 'WPML String Translation plugin not active. String registered but translation not saved.';
            }
        }

        return ResponseFormatter::toJson($result);
    }

    #[McpTool(name: 'wp_translate_wpml_string', description: 'Add or update a translation for an already registered WPML string.')]
    public function translateWpmlString(
        #[Schema(description: 'String domain/context (e.g. "theme-shortstayede")')]
        string $domain,
        #[Schema(description: 'String name/identifier within the domain')]
        string $name,
        #[Schema(description: 'The original string value (used to find the string)')]
        string $value,
        #[Schema(description: 'Target language code (e.g. "en", "de", "fr")')]
        string $language,
        #[Schema(description: 'Translated string value')]
        string $translation,
        #[Schema(description: 'Translation status: 10 = complete, 3 = needs update', minimum: 1, maximum: 10)]
        int $status = 10,
    ): string {
        $this->requireWpml();

        if (! function_exists('icl_add_string_translation') || ! function_exists('icl_get_string_id')) {
            throw new \RuntimeException('WPML String Translation plugin is required but not active.');
        }

        $stringId = icl_get_string_id($value, $domain, $name);
        if (! $stringId) {
            throw new \RuntimeException("String not found: '{$name}' in domain '{$domain}'. Register it first with wp_register_wpml_string.");
        }

        $result = icl_add_string_translation($stringId, $language, $translation, $status);

        if (! $result) {
            throw new \RuntimeException('Failed to save string translation.');
        }

        return ResponseFormatter::toJson([
            'domain'      => $domain,
            'name'        => $name,
            'language'    => $language,
            'translation' => $translation,
            'message'     => "Translation saved for '{$name}' in {$language}.",
        ]);
    }

    #[McpTool(name: 'wp_list_wpml_strings', description: 'List registered WPML strings in a domain, with their translations.')]
    public function listWpmlStrings(
        #[Schema(description: 'String domain to search in (e.g. "theme-shortstayede")')]
        string $domain,
        #[Schema(description: 'Search filter for string name or value')]
        string $search = '',
        #[Schema(description: 'Items per page', minimum: 1, maximum: 100)]
        int $per_page = 50,
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
    ): string {
        $this->requireWpml();

        global $wpdb;

        $table = $wpdb->prefix . 'icl_strings';
        $transTable = $wpdb->prefix . 'icl_string_translations';

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            throw new \RuntimeException('WPML String Translation tables not found. Is the plugin active?');
        }

        $domain = $this->sanitizeText($domain);
        $where = $wpdb->prepare("WHERE s.context = %s", $domain);

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (s.name LIKE %s OR s.value LIKE %s)", $like, $like);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} s {$where}");

        $offset = ($page - 1) * $per_page;
        $strings = $wpdb->get_results(
            "SELECT s.id, s.name, s.value, s.language
             FROM {$table} s {$where}
             ORDER BY s.name ASC
             LIMIT {$per_page} OFFSET {$offset}"
        );

        $result = [];
        foreach ($strings as $str) {
            $translations = $wpdb->get_results($wpdb->prepare(
                "SELECT language, value, status FROM {$transTable} WHERE string_id = %d",
                $str->id
            ));

            $transMap = [];
            foreach ($translations as $t) {
                $transMap[$t->language] = [
                    'value'  => $t->value,
                    'status' => (int) $t->status,
                ];
            }

            $result[] = [
                'id'           => (int) $str->id,
                'name'         => $str->name,
                'value'        => $str->value,
                'language'     => $str->language,
                'translations' => $transMap,
            ];
        }

        return ResponseFormatter::toJson([
            'domain'     => $domain,
            'strings'    => $result,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ]);
    }
}
