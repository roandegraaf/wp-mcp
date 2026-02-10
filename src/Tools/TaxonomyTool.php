<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class TaxonomyTool extends AbstractTool
{
    /**
     * List terms for any taxonomy with search and parent filtering.
     */
    #[McpTool(name: 'wp_list_terms', description: 'List terms for any taxonomy (categories, tags, custom) with search and parent filtering.')]
    public function listTerms(
        #[Schema(description: 'Taxonomy slug (e.g. category, post_tag, or custom taxonomy)')]
        string $taxonomy = 'category',
        #[Schema(description: 'Search string')]
        string $search = '',
        #[Schema(description: 'Parent term ID (for hierarchical taxonomies)')]
        int $parent = -1,
        #[Schema(description: 'Include empty terms')]
        bool $hide_empty = false,
        #[Schema(description: 'Number of terms to return', minimum: 1, maximum: 200)]
        int $number = 100,
        #[Schema(description: 'Order by: name, count, id, slug')]
        string $orderby = 'name',
    ): string {
        $args = [
            'taxonomy'   => $this->sanitizeText($taxonomy),
            'hide_empty' => $hide_empty,
            'number'     => min($number, 200),
            'orderby'    => $this->sanitizeText($orderby),
        ];

        if ($search !== '') {
            $args['search'] = $this->sanitizeText($search);
        }
        if ($parent >= 0) {
            $args['parent'] = $parent;
        }

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            throw new \RuntimeException('Failed to list terms: ' . $terms->get_error_message());
        }

        $formatted = array_map(function ($term) {
            return ResponseFormatter::formatTerm($term);
        }, $terms);

        return ResponseFormatter::toJson([
            'taxonomy' => $taxonomy,
            'count'    => count($formatted),
            'terms'    => $formatted,
        ]);
    }

    /**
     * Get a single term with ACF fields.
     */
    #[McpTool(name: 'wp_get_term', description: 'Get single term by ID with ACF fields if available.')]
    public function getTerm(
        #[Schema(description: 'Term ID')]
        int $term_id,
        #[Schema(description: 'Taxonomy slug')]
        string $taxonomy = '',
    ): string {
        $term = get_term($term_id, $taxonomy ?: null);

        if (is_wp_error($term) || ! $term instanceof \WP_Term) {
            throw new \RuntimeException("Term not found: {$term_id}");
        }

        $data = ResponseFormatter::formatTerm($term, true);

        return ResponseFormatter::toJson($data);
    }

    /**
     * Create a term in any taxonomy.
     */
    #[McpTool(name: 'wp_create_term', description: 'Create a new term in any taxonomy.')]
    public function createTerm(
        #[Schema(description: 'Term name')]
        string $name,
        #[Schema(description: 'Taxonomy slug')]
        string $taxonomy = 'category',
        #[Schema(description: 'Term slug (auto-generated if empty)')]
        string $slug = '',
        #[Schema(description: 'Term description')]
        string $description = '',
        #[Schema(description: 'Parent term ID')]
        int $parent = 0,
        #[Schema(description: 'ACF fields as JSON: {"field_name": "value", ...}')]
        string $acf_fields = '',
    ): string {
        $args = [];
        if ($slug !== '') {
            $args['slug'] = sanitize_title($slug);
        }
        if ($description !== '') {
            $args['description'] = $this->sanitizeText($description);
        }
        if ($parent > 0) {
            $args['parent'] = $parent;
        }

        $result = wp_insert_term($this->sanitizeText($name), $this->sanitizeText($taxonomy), $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to create term: ' . $result->get_error_message());
        }

        $termId = $result['term_id'];

        // Update ACF fields if provided
        if ($acf_fields !== '' && function_exists('update_field')) {
            $fields = json_decode($acf_fields, true);
            if (is_array($fields)) {
                foreach ($fields as $fieldName => $value) {
                    update_field($fieldName, $value, 'term_' . $termId);
                }
            }
        }

        $term = get_term($termId);
        $data = ResponseFormatter::formatTerm($term, true);
        $data['message'] = "Term created successfully with ID {$termId}.";

        return ResponseFormatter::toJson($data);
    }

    /**
     * Update a term's name, slug, description, or parent.
     */
    #[McpTool(name: 'wp_update_term', description: 'Update term name, slug, description, or parent.')]
    public function updateTerm(
        #[Schema(description: 'Term ID')]
        int $term_id,
        #[Schema(description: 'Taxonomy slug')]
        string $taxonomy,
        #[Schema(description: 'New name')]
        string $name = '',
        #[Schema(description: 'New slug')]
        string $slug = '',
        #[Schema(description: 'New description')]
        string $description = '',
        #[Schema(description: 'New parent term ID')]
        int $parent = -1,
    ): string {
        $args = [];
        if ($name !== '') {
            $args['name'] = $this->sanitizeText($name);
        }
        if ($slug !== '') {
            $args['slug'] = sanitize_title($slug);
        }
        if ($description !== '') {
            $args['description'] = $this->sanitizeText($description);
        }
        if ($parent >= 0) {
            $args['parent'] = $parent;
        }

        if (empty($args)) {
            throw new \RuntimeException('No fields to update. Provide at least one of: name, slug, description, parent.');
        }

        $result = wp_update_term($term_id, $this->sanitizeText($taxonomy), $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to update term: ' . $result->get_error_message());
        }

        $term = get_term($result['term_id']);
        $data = ResponseFormatter::formatTerm($term);
        $data['message'] = "Term {$term_id} updated successfully.";

        return ResponseFormatter::toJson($data);
    }
}
