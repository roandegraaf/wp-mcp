<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

class WpmlHelper
{
    public static function isActive(): bool
    {
        return function_exists('icl_get_languages') || defined('ICL_SITEPRESS_VERSION');
    }

    /**
     * Language code, trid and sibling translation IDs for a post or term.
     *
     * @return array<string, mixed>|null
     */
    public static function elementInfo(\WP_Post|\WP_Term $element): ?array
    {
        if (! self::isActive()) {
            return null;
        }

        $isTerm = $element instanceof \WP_Term;
        $wpmlType = $isTerm ? 'tax_' . $element->taxonomy : 'post_' . $element->post_type;
        $elementId = $isTerm ? (int) $element->term_taxonomy_id : $element->ID;

        $details = apply_filters('wpml_element_language_details', null, [
            'element_id'   => $elementId,
            'element_type' => $wpmlType,
        ]);

        if (! is_object($details) || empty($details->trid)) {
            return null;
        }

        $translations = apply_filters('wpml_get_element_translations', null, (int) $details->trid, $wpmlType);
        $ids = [];
        foreach ((array) $translations as $code => $translation) {
            $ids[(string) $code] = $isTerm && isset($translation->term_id)
                ? (int) $translation->term_id
                : (int) $translation->element_id;
        }

        return [
            'language_code'        => $details->language_code ?? null,
            'source_language_code' => $details->source_language_code ?? null,
            'trid'                 => (int) $details->trid,
            'translations'         => $ids,
        ];
    }

    /**
     * Run a callable with WPML switched to the given language ("all" for every
     * language), restoring the original language afterwards.
     */
    public static function withLanguage(string $language, callable $fn): mixed
    {
        $language = trim($language);
        if ($language === '' || ! self::isActive()) {
            return $fn();
        }

        $previous = apply_filters('wpml_current_language', null);
        do_action('wpml_switch_language', $language);
        try {
            return $fn();
        } finally {
            do_action('wpml_switch_language', $previous);
        }
    }

    public static function currentLanguage(): ?string
    {
        if (! self::isActive()) {
            return null;
        }
        $code = apply_filters('wpml_current_language', null);
        return is_string($code) ? $code : null;
    }

    /**
     * Permalink of a post in its own language. WPML's ID adjustment otherwise
     * maps the post to its translation in the request language, and the request
     * cannot switch to a hidden language when the caller has no user.
     */
    public static function permalink(int $postId): string|false
    {
        global $sitepress;

        if (self::isActive() && is_object($sitepress) && class_exists(\WPML\Utils\AutoAdjustIds::class)) {
            return (new \WPML\Utils\AutoAdjustIds($sitepress))->runWithout(fn() => get_permalink($postId));
        }
        return get_permalink($postId);
    }

    public static function requireActive(): void
    {
        if (! self::isActive()) {
            throw new \RuntimeException('WPML is required but not active.');
        }
    }

    public static function defaultLanguage(): ?string
    {
        if (! self::isActive()) {
            return null;
        }
        $code = apply_filters('wpml_default_language', null);
        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Codes of every language enabled on the site, hidden languages included.
     * Null when SitePress cannot be reached.
     *
     * @return list<string>|null
     */
    public static function enabledLanguageCodes(): ?array
    {
        global $sitepress;

        if (! is_object($sitepress) || ! method_exists($sitepress, 'get_active_languages')) {
            return null;
        }

        $active = $sitepress->get_active_languages();
        $codes = is_array($active) ? array_keys($active) : [];

        // get_active_languages() drops hidden languages unless the current user
        // opted in to seeing them, which API-key requests never have.
        if (method_exists($sitepress, 'get_setting')) {
            $hidden = $sitepress->get_setting('hidden_languages', []);
            if (is_array($hidden)) {
                $codes = array_merge($codes, $hidden);
            }
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    public static function requireEnabledLanguage(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new \RuntimeException('A WPML language code is required.');
        }
        $enabled = self::enabledLanguageCodes();
        if ($enabled !== null && ! in_array($code, $enabled, true)) {
            throw new \RuntimeException("Language '{$code}' is not enabled in WPML. Enabled: " . implode(', ', $enabled));
        }
        return $code;
    }

    /**
     * ACF stores options per language as "options" (default language) and
     * "options_<code>" (others).
     */
    public static function acfOptionsPostId(string $language, ?string $defaultLanguage): string
    {
        return $language === '' || $language === $defaultLanguage ? 'options' : 'options_' . $language;
    }

    /**
     * Copy custom fields from a post to its translation the way WPML does on
     * save: every field set to "copy", plus "copy once" fields the target does
     * not have yet. Fields set to "translate" or "ignore" are left alone.
     *
     * @return list<string>|null Meta keys added to the target; null when the WPML API is missing.
     */
    public static function syncCustomFields(int $fromId, int $toId): ?array
    {
        global $sitepress;

        if (! is_object($sitepress) || ! method_exists($sitepress, 'copy_custom_fields')) {
            return null;
        }

        $before = array_keys(get_post_meta($toId));

        $sitepress->copy_custom_fields($fromId, $toId);

        if (defined('WPML_COPY_ONCE_CUSTOM_FIELD')
            && method_exists($sitepress, 'get_custom_fields_translation_settings')
            && method_exists($sitepress, 'sync_custom_field')
        ) {
            foreach ((array) $sitepress->get_custom_fields_translation_settings(WPML_COPY_ONCE_CUSTOM_FIELD) as $metaKey) {
                if (get_post_meta($toId, (string) $metaKey) === []) {
                    $sitepress->sync_custom_field($fromId, $toId, (string) $metaKey);
                }
            }
        }

        return array_values(array_diff(array_keys(get_post_meta($toId)), $before));
    }
}
