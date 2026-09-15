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
}
