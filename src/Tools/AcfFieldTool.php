<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\AcfHelper;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\WpmlHelper;

class AcfFieldTool extends AbstractTool
{
    #[McpTool(name: 'wp_get_acf_fields', description: 'Get all ACF field values for a post, taxonomy term, or user. Handles repeaters, flex content, groups, images (URLs not IDs). Use "option" for options pages, "term_123" for taxonomy terms, "user_1" for users. With WPML, "language" selects the per-language options storage (options pages are stored per language); an empty language means the default language for options, and the request language for posts.')]
    public function getAcfFields(
        #[Schema(description: 'Post ID (numeric), "option" for options pages, "term_123" for taxonomy terms, or "user_1" for users')]
        string $post_id,
        #[Schema(description: 'WPML language code (e.g. "de"). Empty = default language for options pages. Requires WPML.')]
        string $language = '',
    ): string {
        $this->requireAcf();

        [$fields, $resolved] = $this->inLanguage($post_id, $language, fn(int|string $acfPostId) => AcfHelper::getFieldsWithSchema($acfPostId));

        return ResponseFormatter::toJson([
            'post_id'     => $post_id,
            'language'    => $resolved['language'],
            'acf_post_id' => $resolved['acf_post_id'],
            'fields'      => $fields,
        ]);
    }

    #[McpTool(name: 'wp_update_acf_fields', description: 'Update ACF fields on a post, taxonomy term, or user. Pass field name => value pairs. Images: use attachment ID. Repeaters: array of rows. Flex content: array with acf_fc_layout key. Use "option" for options pages, "term_123" for taxonomy terms, "user_1" for users. With WPML, "language" writes the per-language options storage (e.g. language="de" writes the German options without touching the default-language values); an empty language means the default language for options, and the request language for posts.')]
    public function updateAcfFields(
        #[Schema(description: 'Post ID (numeric), "option" for options pages, "term_123" for taxonomy terms, or "user_1" for users')]
        string $post_id,
        #[Schema(description: 'JSON object of field name => value pairs to update')]
        string $fields,
        #[Schema(description: 'WPML language code (e.g. "de"). Empty = default language for options pages. Requires WPML.')]
        string $language = '',
    ): string {
        $this->requireAcf();

        $fieldData = json_decode($fields, true);
        if (! is_array($fieldData)) {
            throw new \RuntimeException('Invalid fields JSON. Provide an object like {"field_name": "value"}.');
        }

        [$result, $resolved] = $this->inLanguage($post_id, $language, fn(int|string $acfPostId) => AcfHelper::updateFields($acfPostId, $fieldData));

        return ResponseFormatter::toJson([
            'post_id'     => $post_id,
            'language'    => $resolved['language'],
            'acf_post_id' => $resolved['acf_post_id'],
            'updated'     => $result['updated'],
            'errors'      => $result['errors'],
            'message'     => count($result['errors']) === 0
                ? 'All fields updated successfully.'
                : 'Some fields had errors. Check the errors array.',
        ]);
    }

    /**
     * @return array{0: mixed, 1: array{language: ?string, acf_post_id: int|string}}
     */
    private function inLanguage(string $postId, string $language, callable $fn): array
    {
        $language = trim($this->sanitizeText($language));
        $acfPostId = $this->resolveAcfPostId($postId);
        $isOptions = $acfPostId === 'option';

        if (! WpmlHelper::isActive()) {
            if ($language !== '') {
                throw new \RuntimeException('The language parameter requires WPML, which is not active.');
            }
            return [$fn($acfPostId), ['language' => null, 'acf_post_id' => $acfPostId]];
        }

        if ($language === '' && ! $isOptions) {
            return [$fn($acfPostId), ['language' => null, 'acf_post_id' => $acfPostId]];
        }

        $default = WpmlHelper::defaultLanguage();
        $language = $language === '' ? (string) $default : WpmlHelper::requireEnabledLanguage($language);

        // ACF captures WPML's current language once at init and appends it to
        // "options" itself, so switching WPML's language mid-request does not
        // reach it. Resolve the per-language options id explicitly instead.
        if ($isOptions) {
            $acfPostId = WpmlHelper::acfOptionsPostId($language, $default);
        }

        $result = WpmlHelper::withLanguage($language, fn() => $this->withAcfLanguage($language, fn() => $fn($acfPostId)));

        return [$result, ['language' => $language, 'acf_post_id' => $acfPostId]];
    }

    private function withAcfLanguage(string $language, callable $fn): mixed
    {
        if (! function_exists('acf_get_setting') || ! function_exists('acf_update_setting')) {
            return $fn();
        }

        $previous = acf_get_setting('current_language');
        acf_update_setting('current_language', $language);
        try {
            return $fn();
        } finally {
            acf_update_setting('current_language', $previous);
        }
    }

    /**
     * Resolve post_id parameter: "option"/"options" for ACF options pages, numeric for posts.
     */
    private function resolveAcfPostId(string $postId): int|string
    {
        if (in_array($postId, ['option', 'options'], true)) {
            return 'option';
        }

        // ACF taxonomy term format: "term_123" or legacy "{taxonomy}_{id}"
        if (preg_match('/^(?:term|\w+)_(\d+)$/', $postId, $matches)) {
            $termId = (int) $matches[1];
            $term = get_term($termId);
            if (! $term || is_wp_error($term)) {
                throw new \RuntimeException("Term not found: {$termId}");
            }
            // ACF uses "term_ID" format internally
            return "term_{$termId}";
        }

        // ACF user format: "user_123"
        if (preg_match('/^user_(\d+)$/', $postId, $matches)) {
            $userId = (int) $matches[1];
            $user = get_user_by('id', $userId);
            if (! $user) {
                throw new \RuntimeException("User not found: {$userId}");
            }
            return "user_{$userId}";
        }

        $intId = (int) $postId;
        if ($intId <= 0) {
            throw new \RuntimeException('Invalid post_id. Use a numeric post ID, "option", "term_123", or "user_1".');
        }

        $this->getPostOrFail($intId);

        return $intId;
    }
}
