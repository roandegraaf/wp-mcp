<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\AcfHelper;
use WpMcp\Helpers\ResponseFormatter;

class AcfFieldTool extends AbstractTool
{
    /**
     * Get all ACF field values for a post, annotated with field type/label.
     * Handles repeaters, flexible content, groups, images (returns URLs not just IDs).
     */
    #[McpTool(name: 'wp_get_acf_fields', description: 'Get all ACF field values for a post with type annotations. Handles repeaters, flex content, groups, images (URLs not IDs). Use post_id "option" for ACF options pages.')]
    public function getAcfFields(
        #[Schema(description: 'Post ID, or "option" for ACF options pages')]
        string $post_id,
    ): string {
        $this->requireAcf();
        $acfPostId = $this->resolveAcfPostId($post_id);

        $fields = AcfHelper::getFieldsWithSchema($acfPostId);

        return ResponseFormatter::toJson([
            'post_id' => $post_id,
            'fields'  => $fields,
        ]);
    }

    /**
     * Update ACF fields on a post. Supports all field types including repeaters,
     * flexible content, groups, images (pass attachment ID), relationships (pass post IDs).
     */
    #[McpTool(name: 'wp_update_acf_fields', description: 'Update ACF fields on a post. Pass field name => value pairs. Images: use attachment ID. Repeaters: array of rows. Flex content: array with acf_fc_layout key. Use post_id "option" for ACF options pages.')]
    public function updateAcfFields(
        #[Schema(description: 'Post ID, or "option" for ACF options pages')]
        string $post_id,
        #[Schema(description: 'JSON object of field name => value pairs to update')]
        string $fields,
    ): string {
        $this->requireAcf();
        $acfPostId = $this->resolveAcfPostId($post_id);

        $fieldData = json_decode($fields, true);
        if (! is_array($fieldData)) {
            throw new \RuntimeException('Invalid fields JSON. Provide an object like {"field_name": "value"}.');
        }

        $result = AcfHelper::updateFields($acfPostId, $fieldData);

        return ResponseFormatter::toJson([
            'post_id' => $post_id,
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'message' => count($result['errors']) === 0
                ? 'All fields updated successfully.'
                : 'Some fields had errors. Check the errors array.',
        ]);
    }

    /**
     * Resolve post_id parameter: "option"/"options" for ACF options pages, numeric for posts.
     */
    private function resolveAcfPostId(string $postId): int|string
    {
        if (in_array($postId, ['option', 'options'], true)) {
            return 'option';
        }

        $intId = (int) $postId;
        if ($intId <= 0) {
            throw new \RuntimeException('Invalid post_id. Use a numeric post ID or "option" for ACF options pages.');
        }

        $this->getPostOrFail($intId);

        return $intId;
    }
}
