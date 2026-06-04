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

    /**
     * Codes of all languages enabled on the site (active set + hidden languages,
     * which remain enabled). Returns null when SitePress cannot be reached so the
     * caller can fall back gracefully.
     *
     * @return list<string>|null
     */
    private function getEnabledLanguageCodes(): ?array
    {
        global $sitepress;

        if (! is_object($sitepress) || ! method_exists($sitepress, 'get_active_languages')) {
            return null;
        }

        $active = $sitepress->get_active_languages();
        $codes = is_array($active) ? array_keys($active) : [];

        // Hidden languages are excluded from get_active_languages() in a normal
        // request but are still enabled — merge them back in.
        if (method_exists($sitepress, 'get_setting')) {
            $hidden = $sitepress->get_setting('hidden_languages', []);
            if (is_array($hidden)) {
                $codes = array_values(array_unique(array_merge($codes, $hidden)));
            }
        }

        return array_map('strval', $codes);
    }

    /**
     * Resolve the WPML element type prefix ("post_<type>" or "tax_<taxonomy>")
     * for a given element, validating that the element exists.
     *
     * @param string $element_type "post" for posts/pages/CPTs, or a taxonomy name for terms.
     * @return array{0:string,1:string} [wpmlType, languageCode]
     */
    private function resolveWpmlElement(int $element_id, string $element_type): array
    {
        $element_type = $this->sanitizeText($element_type);

        if ($element_type === 'post') {
            $post = get_post($element_id);
            if (! $post instanceof \WP_Post) {
                throw new \RuntimeException("Post not found: {$element_id}");
            }
            $wpmlType = 'post_' . $post->post_type;
        } else {
            $term = get_term($element_id, $element_type);
            if (! $term || is_wp_error($term)) {
                throw new \RuntimeException("Term not found: {$element_id} in taxonomy {$element_type}");
            }
            $wpmlType = 'tax_' . $element_type;
        }

        $language = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $element_id,
            'element_type' => $wpmlType,
        ]);

        return [$wpmlType, (string) $language];
    }

    /**
     * Read every row of a translation group (trid) directly from icl_translations,
     * including orphan rows whose underlying post/term no longer exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readTranslationGroup(int $trid, string $wpmlType): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT translation_id, element_id, language_code, source_language_code
             FROM {$table}
             WHERE trid = %d AND element_type = %s
             ORDER BY (source_language_code IS NULL) DESC, language_code ASC",
            $trid,
            $wpmlType
        ));

        $isTax = strpos($wpmlType, 'tax_') === 0;
        $taxonomy = $isTax ? substr($wpmlType, 4) : '';

        $result = [];
        foreach ($rows as $row) {
            $elementId = $row->element_id !== null ? (int) $row->element_id : null;
            $entry = [
                'translation_id'       => (int) $row->translation_id,
                'element_id'           => $elementId,
                'language_code'        => $row->language_code,
                'source_language_code' => $row->source_language_code,
                'is_source'            => $row->source_language_code === null,
            ];

            if ($elementId !== null) {
                if ($isTax) {
                    $term = get_term($elementId, $taxonomy);
                    $entry['exists'] = (bool) ($term && ! is_wp_error($term));
                    $entry['title'] = $entry['exists'] ? $term->name : null;
                } else {
                    $post = get_post($elementId);
                    $entry['exists'] = $post instanceof \WP_Post;
                    $entry['title'] = $entry['exists'] ? get_the_title($elementId) : null;
                    $entry['status'] = $entry['exists'] ? get_post_status($elementId) : null;
                }
            } else {
                $entry['exists'] = false;
                $entry['title'] = null;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Flush WPML's translation caches after a low-level icl_translations write so
     * subsequent reads do not return stale element-translation data.
     */
    private function flushWpmlTranslationCaches(): void
    {
        global $sitepress;

        if (is_object($sitepress) && method_exists($sitepress, 'get_translations_cache')) {
            try {
                $cache = $sitepress->get_translations_cache();
                if (is_object($cache) && method_exists($cache, 'clear')) {
                    $cache->clear();
                }
            } catch (\Throwable $e) {
                // Non-fatal: fall through to the object-cache flush below.
            }
        }

        // Backstop: WPML memoizes source-language-by-trid and element language
        // details in the WP object cache. A full flush guarantees coherence.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    #[McpTool(name: 'wp_list_languages', description: 'List all configured WPML languages with their codes, names, and default status.')]
    public function listLanguages(): string
    {
        $this->requireWpml();

        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=0');
        $defaultLanguage = apply_filters('wpml_default_language', null);

        // The per-language `active` key returned by the `wpml_active_languages`
        // filter means "is this the language of the current request" — NOT
        // "is this language enabled site-wide". Under a REST request the current
        // language is usually the default, so every other language would wrongly
        // report active:false. Resolve the real enabled set from SitePress
        // instead (including hidden languages, which are still enabled — they are
        // merely hidden from the language switcher).
        $enabledCodes = $this->getEnabledLanguageCodes();

        $result = [];
        foreach ($languages as $lang) {
            $code = $lang['code'];
            $result[] = [
                'code'         => $code,
                'name'         => $lang['english_name'] ?? $lang['display_name'] ?? $lang['translated_name'] ?? $code,
                'native_name'  => $lang['native_name'] ?? $code,
                'is_default'   => $code === $defaultLanguage,
                // Enabled on the site. Falls back to true (every code returned by
                // the filter is part of the active set) when SitePress is unavailable.
                'active'       => $enabledCodes === null ? true : in_array($code, $enabledCodes, true),
                // Whether this is the language of the current request — what the
                // filter's own `active` flag actually reports.
                'is_current'   => (bool) ($lang['active'] ?? false),
                'url'          => $lang['url'] ?? '',
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

            // Count only published elements in this language whose translation
            // group (trid) actually has a published default-language source.
            // The previous query counted *every* published element in the
            // language, so orphan/duplicate rows with no published source
            // inflated the count above the default total (e.g. 125%). Joining to
            // the source row guarantees translated <= total.
            $translated = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} t
                 INNER JOIN {$wpdb->posts} p ON t.element_id = p.ID
                 INNER JOIN {$table} src
                        ON src.trid = t.trid
                       AND src.element_type = t.element_type
                       AND src.language_code = %s
                 INNER JOIN {$wpdb->posts} sp
                        ON src.element_id = sp.ID
                       AND sp.post_status = 'publish'
                 WHERE t.element_type = %s
                   AND t.language_code = %s
                   AND p.post_status = 'publish'",
                $defaultLanguage,
                $elementType,
                $lang['code']
            ));

            // Total published elements in this language regardless of source —
            // the gap versus $translated surfaces orphan/unlinked content.
            $rawTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} t
                 INNER JOIN {$wpdb->posts} p ON t.element_id = p.ID
                 WHERE t.element_type = %s AND t.language_code = %s AND p.post_status = 'publish'",
                $elementType,
                $lang['code']
            ));

            $languageStats[$lang['code']] = [
                'total'       => $totalDefault,
                'translated'  => $translated,
                'untranslated' => max(0, $totalDefault - $translated),
                // Published posts in this language with no published default-language
                // source (orphans / inverted-trid leftovers). 0 in a healthy site.
                'orphans'     => max(0, $rawTotal - $translated),
                'percentage'  => $totalDefault > 0 ? round(($translated / $totalDefault) * 100, 1) : 0,
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

    #[McpTool(name: 'wp_link_wpml_translation', description: 'Link two existing posts or taxonomy terms as WPML translations of each other. Use this when the elements already exist but are not linked in WPML.')]
    public function linkWpmlTranslation(
        #[Schema(description: 'Source element ID (post ID or term ID) — the one already registered in WPML or in the default language')]
        int $source_id,
        #[Schema(description: 'Target element ID (post ID or term ID) — the one to link as a translation')]
        int $target_id,
        #[Schema(description: 'Target language code (e.g. "nl", "en", "de")')]
        string $language,
        #[Schema(description: 'Element type: "post" for posts/pages/CPTs, or a taxonomy name like "category", "apartment_category" for terms')]
        string $element_type,
    ): string {
        $this->requireWpml();

        $language = $this->sanitizeText($language);
        $element_type = $this->sanitizeText($element_type);

        // Determine WPML element type prefix
        if ($element_type === 'post') {
            $post = get_post($source_id);
            if (! $post) {
                throw new \RuntimeException("Source post not found: {$source_id}");
            }
            $wpmlType = 'post_' . $post->post_type;

            $targetPost = get_post($target_id);
            if (! $targetPost) {
                throw new \RuntimeException("Target post not found: {$target_id}");
            }
        } else {
            // It's a taxonomy
            $wpmlType = 'tax_' . $element_type;

            $sourceTerm = get_term($source_id, $element_type);
            if (! $sourceTerm || is_wp_error($sourceTerm)) {
                throw new \RuntimeException("Source term not found: {$source_id} in taxonomy {$element_type}");
            }

            $targetTerm = get_term($target_id, $element_type);
            if (! $targetTerm || is_wp_error($targetTerm)) {
                throw new \RuntimeException("Target term not found: {$target_id} in taxonomy {$element_type}");
            }
        }

        // Get or create trid for the source element
        $trid = apply_filters('wpml_element_trid', null, $source_id, $wpmlType);

        if (! $trid) {
            // Source element not in WPML yet — register it in the default language first
            $defaultLanguage = apply_filters('wpml_default_language', null);
            do_action('wpml_set_element_language_details', [
                'element_id'           => $source_id,
                'element_type'         => $wpmlType,
                'trid'                 => false,
                'language_code'        => $defaultLanguage,
                'source_language_code' => null,
            ]);
            $trid = apply_filters('wpml_element_trid', null, $source_id, $wpmlType);
        }

        if (! $trid) {
            throw new \RuntimeException("Could not get or create translation group for source element {$source_id}");
        }

        $sourceLanguage = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $source_id,
            'element_type' => $wpmlType,
        ]);

        // Link the target element as a translation
        do_action('wpml_set_element_language_details', [
            'element_id'           => $target_id,
            'element_type'         => $wpmlType,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage,
        ]);

        // Verify the link was created
        $newTrid = apply_filters('wpml_element_trid', null, $target_id, $wpmlType);

        return ResponseFormatter::toJson([
            'source_id'       => $source_id,
            'target_id'       => $target_id,
            'language'        => $language,
            'element_type'    => $wpmlType,
            'trid'            => $newTrid,
            'linked'          => $newTrid === $trid,
            'message'         => $newTrid === $trid
                ? "Successfully linked element {$target_id} as {$language} translation of {$source_id}."
                : "Warning: linkage may not have succeeded. Verify in WPML admin.",
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

    #[McpTool(name: 'wp_inspect_translation_group', description: 'Inspect the raw WPML translation group (trid) an element belongs to. Shows every row in icl_translations for that trid — including the source/original row (source_language_code IS NULL) and any orphan rows whose post/term no longer exists. Use this to diagnose inverted or broken translation structures before repairing them.')]
    public function inspectTranslationGroup(
        #[Schema(description: 'Element ID (post ID or term ID) whose translation group to inspect')]
        int $element_id,
        #[Schema(description: 'Element type: "post" for posts/pages/CPTs, or a taxonomy name like "category" for terms')]
        string $element_type,
    ): string {
        $this->requireWpml();

        [$wpmlType, $language] = $this->resolveWpmlElement($element_id, $element_type);
        $trid = apply_filters('wpml_element_trid', null, $element_id, $wpmlType);

        if (! $trid) {
            return ResponseFormatter::toJson([
                'element_id'   => $element_id,
                'element_type' => $wpmlType,
                'language'     => $language,
                'trid'         => null,
                'message'      => 'Element is not registered in any WPML translation group.',
            ]);
        }

        $rows = $this->readTranslationGroup((int) $trid, $wpmlType);
        $sources = array_values(array_filter($rows, static fn ($r) => $r['is_source']));

        return ResponseFormatter::toJson([
            'element_id'    => $element_id,
            'element_type'  => $wpmlType,
            'trid'          => (int) $trid,
            'source_count'  => count($sources),
            'source_language' => $sources[0]['language_code'] ?? null,
            'is_healthy'    => count($sources) === 1,
            'rows'          => $rows,
        ]);
    }

    #[McpTool(name: 'wp_set_translation_source', description: 'Re-root a WPML translation group: make the given element the source/original (the row with source_language_code = NULL) and re-point every sibling to it. Use this to repair inverted structures where the wrong language is marked as the original. This writes icl_translations directly and flushes WPML caches afterwards. Inspect the group first with wp_inspect_translation_group.')]
    public function setTranslationSource(
        #[Schema(description: 'Element ID (post ID or term ID) that should become the source/original of its translation group')]
        int $element_id,
        #[Schema(description: 'Element type: "post" for posts/pages/CPTs, or a taxonomy name like "category" for terms')]
        string $element_type,
    ): string {
        $this->requireWpml();

        global $wpdb;

        [$wpmlType, $language] = $this->resolveWpmlElement($element_id, $element_type);
        if ($language === '') {
            throw new \RuntimeException("Element {$element_id} has no WPML language assigned; cannot set it as source.");
        }

        $trid = apply_filters('wpml_element_trid', null, $element_id, $wpmlType);
        if (! $trid) {
            throw new \RuntimeException("Element {$element_id} is not in any translation group; nothing to re-root.");
        }
        $trid = (int) $trid;

        $before = $this->readTranslationGroup($trid, $wpmlType);

        $table = $wpdb->prefix . 'icl_translations';

        // The new source row gets source_language_code = NULL. Bail before the
        // second UPDATE if this one errors, so we never leave the group with two
        // sources from a half-applied flip.
        $wpdb->last_error = '';
        $firstResult = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source_language_code = NULL
             WHERE trid = %d AND element_type = %s AND language_code = %s",
            $trid,
            $wpmlType,
            $language
        ));
        if ($firstResult === false || $wpdb->last_error !== '') {
            $this->flushWpmlTranslationCaches();
            throw new \RuntimeException(
                'Failed to set the new source row; group left unchanged. DB error: ' . ($wpdb->last_error ?: 'unknown')
            );
        }

        // Every other row in the group points back at the new source language.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source_language_code = %s
             WHERE trid = %d AND element_type = %s AND language_code <> %s",
            $language,
            $trid,
            $wpmlType,
            $language
        ));

        $this->flushWpmlTranslationCaches();

        // Re-read straight from the DB to confirm the new shape.
        $after = $this->readTranslationGroup($trid, $wpmlType);
        $sources = array_values(array_filter($after, static fn ($r) => $r['is_source']));
        $ok = count($sources) === 1 && ($sources[0]['language_code'] ?? null) === $language;

        return ResponseFormatter::toJson([
            'element_id'    => $element_id,
            'element_type'  => $wpmlType,
            'trid'          => $trid,
            'new_source_language' => $language,
            'success'       => $ok,
            'before'        => $before,
            'after'         => $after,
            'message'       => $ok
                ? "Element {$element_id} ({$language}) is now the source of trid {$trid}."
                : 'Re-root completed but verification did not find exactly one source row; inspect the group.',
        ]);
    }

    #[McpTool(name: 'wp_move_to_translation_group', description: "Move an element into another element's WPML translation group (merge trids). Links the moved element as the target's translation in its own language, keeping WPML's element-translation cache coherent. Fails if the target group already contains the moved element's language. Use to repair split/duplicate translation groups.")]
    public function moveToTranslationGroup(
        #[Schema(description: 'Element ID to move into the target group')]
        int $element_id,
        #[Schema(description: "Target element ID whose translation group (trid) the element should join")]
        int $target_id,
        #[Schema(description: 'Element type: "post" for posts/pages/CPTs, or a taxonomy name like "category" for terms')]
        string $element_type,
    ): string {
        $this->requireWpml();

        [$wpmlType, $language] = $this->resolveWpmlElement($element_id, $element_type);
        [$targetWpmlType, $targetLanguage] = $this->resolveWpmlElement($target_id, $element_type);

        if ($wpmlType !== $targetWpmlType) {
            throw new \RuntimeException("Element types differ ({$wpmlType} vs {$targetWpmlType}); both must be the same post type or taxonomy.");
        }
        if ($language === '') {
            throw new \RuntimeException("Element {$element_id} has no WPML language assigned.");
        }

        // Resolve (or create) the target group.
        $targetTrid = apply_filters('wpml_element_trid', null, $target_id, $wpmlType);
        if (! $targetTrid) {
            $defaultLanguage = apply_filters('wpml_default_language', null);
            do_action('wpml_set_element_language_details', [
                'element_id'           => $target_id,
                'element_type'         => $wpmlType,
                'trid'                 => false,
                'language_code'        => $targetLanguage !== '' ? $targetLanguage : $defaultLanguage,
                'source_language_code' => null,
            ]);
            $targetTrid = apply_filters('wpml_element_trid', null, $target_id, $wpmlType);
        }
        if (! $targetTrid) {
            throw new \RuntimeException("Could not resolve a translation group for target element {$target_id}.");
        }
        $targetTrid = (int) $targetTrid;

        // Guard against a language collision within the target group.
        $existing = $this->readTranslationGroup($targetTrid, $wpmlType);
        foreach ($existing as $row) {
            if ($row['language_code'] === $language && (int) $row['element_id'] !== $element_id) {
                throw new \RuntimeException(
                    "Target group (trid {$targetTrid}) already has a '{$language}' element (#{$row['element_id']}). Resolve the conflict first."
                );
            }
        }

        // Pass an empty source language and let WPML resolve it from the target
        // trid's existing source — keeps the element-translation cache coherent.
        $sourceLanguage = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $target_id,
            'element_type' => $wpmlType,
        ]);
        do_action('wpml_set_element_language_details', [
            'element_id'           => $element_id,
            'element_type'         => $wpmlType,
            'trid'                 => $targetTrid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage ?: null,
        ]);

        $newTrid = (int) apply_filters('wpml_element_trid', null, $element_id, $wpmlType);

        return ResponseFormatter::toJson([
            'element_id'   => $element_id,
            'target_id'    => $target_id,
            'element_type' => $wpmlType,
            'trid'         => $newTrid,
            'success'      => $newTrid === $targetTrid,
            'group'        => $this->readTranslationGroup($targetTrid, $wpmlType),
            'message'      => $newTrid === $targetTrid
                ? "Element {$element_id} ({$language}) moved into trid {$targetTrid}."
                : 'Move completed but the element is not in the expected group; inspect it.',
        ]);
    }

    #[McpTool(name: 'wp_disconnect_translation', description: 'Remove an element from its WPML translation group, giving it a fresh standalone trid (it becomes its own source/original). Use to detach an element that was wrongly linked. Uses the WPML API so caches stay coherent.')]
    public function disconnectTranslation(
        #[Schema(description: 'Element ID (post ID or term ID) to detach from its translation group')]
        int $element_id,
        #[Schema(description: 'Element type: "post" for posts/pages/CPTs, or a taxonomy name like "category" for terms')]
        string $element_type,
    ): string {
        $this->requireWpml();

        [$wpmlType, $language] = $this->resolveWpmlElement($element_id, $element_type);
        if ($language === '') {
            $language = apply_filters('wpml_default_language', null);
        }

        $oldTrid = apply_filters('wpml_element_trid', null, $element_id, $wpmlType);

        // trid = false → WPML assigns a brand-new trid with this element as source.
        do_action('wpml_set_element_language_details', [
            'element_id'           => $element_id,
            'element_type'         => $wpmlType,
            'trid'                 => false,
            'language_code'        => $language,
            'source_language_code' => null,
        ]);

        $newTrid = (int) apply_filters('wpml_element_trid', null, $element_id, $wpmlType);

        return ResponseFormatter::toJson([
            'element_id'   => $element_id,
            'element_type' => $wpmlType,
            'old_trid'     => $oldTrid ? (int) $oldTrid : null,
            'new_trid'     => $newTrid,
            'success'      => $newTrid > 0 && (int) $oldTrid !== $newTrid,
            'message'      => "Element {$element_id} detached into a new standalone group (trid {$newTrid}).",
        ]);
    }

    #[McpTool(name: 'wp_get_post_type_translation_modes', description: 'Read the WPML translation mode for every post type: 0 = not translatable, 1 = translatable, 2 = display as translated (read-only / duplicated). Returns both the raw stored setting and the effective is_translated flag.')]
    public function getPostTypeTranslationModes(): string
    {
        $this->requireWpml();

        global $sitepress;
        if (! is_object($sitepress) || ! method_exists($sitepress, 'get_setting')) {
            throw new \RuntimeException('SitePress is not available; cannot read translation modes.');
        }

        $syncOption = $sitepress->get_setting('custom_posts_sync_option', []);
        if (! is_array($syncOption)) {
            $syncOption = [];
        }

        $modeLabels = [
            0 => 'not_translatable',
            1 => 'translatable',
            2 => 'display_as_translated',
        ];

        $postTypes = get_post_types([], 'objects');
        $result = [];
        foreach ($postTypes as $name => $object) {
            $mode = isset($syncOption[$name]) ? (int) $syncOption[$name] : 0;
            $result[$name] = [
                'label'          => $object->labels->singular_name ?? $name,
                'mode'           => $mode,
                'mode_label'     => $modeLabels[$mode] ?? 'unknown',
                'is_translated'  => method_exists($sitepress, 'is_translated_post_type')
                    ? (bool) $sitepress->is_translated_post_type($name)
                    : ($mode === 1),
            ];
        }

        return ResponseFormatter::toJson([
            'modes'      => $modeLabels,
            'post_types' => $result,
        ]);
    }

    #[McpTool(name: 'wp_set_post_type_translation_mode', description: 'Set the WPML translation mode for a post type. mode: 0 = not translatable, 1 = translatable, 2 = display as translated. Persists to WPML settings.')]
    public function setPostTypeTranslationMode(
        #[Schema(description: 'Post type slug (e.g. "post", "page", "apartment")')]
        string $post_type,
        #[Schema(description: 'Translation mode: 0 = not translatable, 1 = translatable, 2 = display as translated', minimum: 0, maximum: 2)]
        int $mode,
    ): string {
        $this->requireWpml();

        global $sitepress;
        if (! is_object($sitepress) || ! method_exists($sitepress, 'get_setting') || ! method_exists($sitepress, 'set_setting')) {
            throw new \RuntimeException('SitePress is not available; cannot set translation modes.');
        }

        $post_type = $this->sanitizeText($post_type);
        if (! post_type_exists($post_type)) {
            throw new \RuntimeException("Post type does not exist: {$post_type}");
        }
        if (! in_array($mode, [0, 1, 2], true)) {
            throw new \RuntimeException('mode must be 0 (not translatable), 1 (translatable) or 2 (display as translated).');
        }

        $syncOption = $sitepress->get_setting('custom_posts_sync_option', []);
        if (! is_array($syncOption)) {
            $syncOption = [];
        }
        $previous = isset($syncOption[$post_type]) ? (int) $syncOption[$post_type] : null;
        $syncOption[$post_type] = $mode;
        $sitepress->set_setting('custom_posts_sync_option', $syncOption, true);

        $modeLabels = [0 => 'not_translatable', 1 => 'translatable', 2 => 'display_as_translated'];

        return ResponseFormatter::toJson([
            'post_type'      => $post_type,
            'previous_mode'  => $previous,
            'mode'           => $mode,
            'mode_label'     => $modeLabels[$mode],
            'message'        => "Translation mode for '{$post_type}' set to {$mode} ({$modeLabels[$mode]}).",
        ]);
    }

    #[McpTool(name: 'wp_get_custom_field_translation_preference', description: 'Read WPML custom-field (meta key) translation preferences: 0 = ignore (don\'t translate), 1 = copy from original, 2 = translate, 3 = copy once. Optionally filter by a search string (e.g. an ACF field name). Useful to verify ACF fields will translate as expected.')]
    public function getCustomFieldTranslationPreference(
        #[Schema(description: 'Optional substring to filter meta keys (e.g. "subtitle"). Empty returns all configured keys.')]
        string $search = '',
    ): string {
        $this->requireWpml();

        $prefs = $this->getCustomFieldPrefs();
        $search = $this->sanitizeText($search);

        $optionLabels = [
            0 => 'ignore',
            1 => 'copy',
            2 => 'translate',
            3 => 'copy_once',
        ];

        $result = [];
        foreach ($prefs as $key => $value) {
            if ($search !== '' && stripos((string) $key, $search) === false) {
                continue;
            }
            $value = (int) $value;
            $result[$key] = [
                'preference'       => $value,
                'preference_label' => $optionLabels[$value] ?? 'unknown',
            ];
        }

        ksort($result);

        return ResponseFormatter::toJson([
            'options' => $optionLabels,
            'fields'  => $result,
            'total'   => count($result),
        ]);
    }

    #[McpTool(name: 'wp_set_custom_field_translation_preference', description: 'Set the WPML translation preference for a custom field / meta key. preference: 0 = ignore, 1 = copy, 2 = translate, 3 = copy once. For ACF fields, the companion "_"-prefixed meta key (which stores the ACF field-key reference) is automatically set to copy (1) so translations do not break — unless manage_acf_reference is false.')]
    public function setCustomFieldTranslationPreference(
        #[Schema(description: 'Meta key / custom field name (e.g. "subtitle", "hero_text")')]
        string $meta_key,
        #[Schema(description: 'Translation preference: 0 = ignore, 1 = copy, 2 = translate, 3 = copy once', minimum: 0, maximum: 3)]
        int $preference,
        #[Schema(description: 'When true (default) and meta_key is an ACF field, also set the companion "_meta_key" reference to copy (1). Set false to manage it manually.')]
        bool $manage_acf_reference = true,
    ): string {
        $this->requireWpml();

        global $wpdb;

        $meta_key = $this->sanitizeText($meta_key);
        if ($meta_key === '') {
            throw new \RuntimeException('meta_key is required.');
        }
        if (! in_array($preference, [0, 1, 2, 3], true)) {
            throw new \RuntimeException('preference must be 0 (ignore), 1 (copy), 2 (translate) or 3 (copy once).');
        }

        $prefs = $this->getCustomFieldPrefs();
        $optionLabels = [0 => 'ignore', 1 => 'copy', 2 => 'translate', 3 => 'copy_once'];

        $previous = isset($prefs[$meta_key]) ? (int) $prefs[$meta_key] : null;
        $prefs[$meta_key] = $preference;

        $companionApplied = null;
        // ACF stores a "_<field>" companion meta holding the field-key reference.
        // That reference must be COPIED (not translated) or translations break.
        if ($manage_acf_reference && strpos($meta_key, '_') !== 0) {
            $companion = '_' . $meta_key;
            $companionExists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
                $companion
            ));
            if ($companionExists) {
                $prefs[$companion] = WPML_COPY_CUSTOM_FIELD;
                $companionApplied = $companion;
            }
        }

        $this->saveCustomFieldPrefs($prefs);

        $response = [
            'meta_key'          => $meta_key,
            'previous'          => $previous,
            'preference'        => $preference,
            'preference_label'  => $optionLabels[$preference],
            'message'           => "Translation preference for '{$meta_key}' set to {$preference} ({$optionLabels[$preference]}).",
        ];
        if ($companionApplied !== null) {
            $response['acf_reference_key'] = $companionApplied;
            $response['acf_reference_preference'] = WPML_COPY_CUSTOM_FIELD;
            $response['message'] .= " ACF reference '{$companionApplied}' set to copy.";
        }

        return ResponseFormatter::toJson($response);
    }

    /**
     * Read the WPML custom-field translation preference map (meta_key => option).
     *
     * @return array<string, int>
     */
    private function getCustomFieldPrefs(): array
    {
        global $sitepress;
        if (! is_object($sitepress) || ! method_exists($sitepress, 'get_setting')) {
            throw new \RuntimeException('SitePress is not available; cannot read custom-field preferences.');
        }

        $tm = $sitepress->get_setting('translation-management', []);
        $prefs = is_array($tm) && isset($tm['custom_fields_translation']) && is_array($tm['custom_fields_translation'])
            ? $tm['custom_fields_translation']
            : [];

        $out = [];
        foreach ($prefs as $key => $value) {
            $out[(string) $key] = (int) $value;
        }
        return $out;
    }

    /**
     * Persist the WPML custom-field translation preference map.
     *
     * @param array<string, int> $prefs
     */
    private function saveCustomFieldPrefs(array $prefs): void
    {
        global $sitepress;
        if (! method_exists($sitepress, 'set_setting')) {
            throw new \RuntimeException('SitePress is not available; cannot save custom-field preferences.');
        }

        $tm = $sitepress->get_setting('translation-management', []);
        if (! is_array($tm)) {
            $tm = [];
        }
        $tm['custom_fields_translation'] = $prefs;
        $sitepress->set_setting('translation-management', $tm, true);
    }
}
