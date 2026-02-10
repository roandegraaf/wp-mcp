<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

class AcfHelper
{
    /**
     * Get all ACF fields for a post with rich metadata (type, label, instructions).
     * Uses get_field_objects() for both values AND field config.
     */
    public static function getFieldsWithSchema(int|string $postId): array
    {
        if (! function_exists('get_field_objects')) {
            return [];
        }

        $fieldObjects = get_field_objects($postId);
        if (! $fieldObjects) {
            return [];
        }

        $result = [];
        foreach ($fieldObjects as $name => $field) {
            $result[$name] = self::formatFieldObject($field);
        }

        return $result;
    }

    /**
     * Format a single field object with its value and metadata.
     */
    private static function formatFieldObject(array $field): array
    {
        $formatted = [
            'value'        => self::formatFieldValue($field),
            'type'         => $field['type'],
            'label'        => $field['label'] ?? $field['name'],
        ];

        if (! empty($field['instructions'])) {
            $formatted['instructions'] = $field['instructions'];
        }

        // Include choices for select/radio/checkbox fields
        if (! empty($field['choices'])) {
            $formatted['choices'] = $field['choices'];
        }

        // Include sub_fields info for repeaters, groups, flex content
        if (! empty($field['sub_fields'])) {
            $formatted['sub_fields'] = array_map(function ($subField) {
                return [
                    'name' => $subField['name'],
                    'type' => $subField['type'],
                    'label' => $subField['label'] ?? $subField['name'],
                ];
            }, $field['sub_fields']);
        }

        // Include layouts for flexible content
        if (! empty($field['layouts'])) {
            $formatted['layouts'] = array_map(function ($layout) {
                return [
                    'name'       => $layout['name'],
                    'label'      => $layout['label'],
                    'sub_fields' => array_map(function ($subField) {
                        return [
                            'name' => $subField['name'],
                            'type' => $subField['type'],
                            'label' => $subField['label'] ?? $subField['name'],
                        ];
                    }, $layout['sub_fields'] ?? []),
                ];
            }, $field['layouts']);
        }

        return $formatted;
    }

    /**
     * Format field value based on type, resolving IDs to useful data.
     */
    private static function formatFieldValue(array $field): mixed
    {
        $value = $field['value'];
        $type = $field['type'];

        return match ($type) {
            'image'        => self::formatImageValue($value),
            'gallery'      => is_array($value) ? array_map([self::class, 'formatImageValue'], $value) : [],
            'file'         => self::formatFileValue($value),
            'post_object', 'relationship' => self::formatRelationshipValue($value),
            'taxonomy'     => self::formatTaxonomyValue($value),
            'link'         => $value, // Already an array with url, title, target
            'repeater'     => self::formatRepeaterValue($field),
            'group'        => self::formatGroupValue($field),
            'flexible_content' => self::formatFlexibleContentValue($field),
            default        => $value,
        };
    }

    /**
     * Format image field value to include URL and metadata.
     */
    private static function formatImageValue(mixed $value): ?array
    {
        if (empty($value)) {
            return null;
        }

        // If it's already an array (return format = array), use it
        if (is_array($value)) {
            return [
                'ID'     => $value['ID'] ?? $value['id'] ?? null,
                'url'    => $value['url'] ?? null,
                'width'  => $value['width'] ?? null,
                'height' => $value['height'] ?? null,
                'alt'    => $value['alt'] ?? '',
                'title'  => $value['title'] ?? '',
            ];
        }

        // If it's an ID, resolve it
        if (is_numeric($value)) {
            $id = (int) $value;
            $url = wp_get_attachment_url($id);
            $meta = wp_get_attachment_metadata($id);
            return [
                'ID'     => $id,
                'url'    => $url ?: null,
                'width'  => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'alt'    => get_post_meta($id, '_wp_attachment_image_alt', true),
                'title'  => get_the_title($id),
            ];
        }

        return null;
    }

    /**
     * Format file field value.
     */
    private static function formatFileValue(mixed $value): ?array
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            return [
                'ID'       => $value['ID'] ?? $value['id'] ?? null,
                'url'      => $value['url'] ?? null,
                'filename' => $value['filename'] ?? null,
                'title'    => $value['title'] ?? '',
            ];
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            return [
                'ID'       => $id,
                'url'      => wp_get_attachment_url($id) ?: null,
                'filename' => basename(get_attached_file($id) ?: ''),
                'title'    => get_the_title($id),
            ];
        }

        return null;
    }

    /**
     * Format post_object/relationship value.
     */
    private static function formatRelationshipValue(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }

        $posts = is_array($value) ? $value : [$value];

        return array_map(function ($post) {
            if ($post instanceof \WP_Post) {
                return [
                    'ID'    => $post->ID,
                    'title' => $post->post_title,
                    'type'  => $post->post_type,
                    'url'   => get_permalink($post->ID),
                ];
            }
            if (is_numeric($post)) {
                $p = get_post((int) $post);
                if ($p) {
                    return [
                        'ID'    => $p->ID,
                        'title' => $p->post_title,
                        'type'  => $p->post_type,
                        'url'   => get_permalink($p->ID),
                    ];
                }
            }
            return null;
        }, $posts);
    }

    /**
     * Format taxonomy field value.
     */
    private static function formatTaxonomyValue(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }

        $terms = is_array($value) ? $value : [$value];

        return array_map(function ($term) {
            if ($term instanceof \WP_Term) {
                return [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
            if (is_numeric($term)) {
                $t = get_term((int) $term);
                if ($t instanceof \WP_Term) {
                    return [
                        'id'   => $t->term_id,
                        'name' => $t->name,
                        'slug' => $t->slug,
                    ];
                }
            }
            return null;
        }, $terms);
    }

    /**
     * Format repeater field value with sub_field metadata.
     */
    private static function formatRepeaterValue(array $field): array
    {
        $rows = $field['value'];
        if (! is_array($rows)) {
            return [];
        }

        $subFields = $field['sub_fields'] ?? [];

        return array_map(function ($row) use ($subFields) {
            $formattedRow = [];
            foreach ($row as $key => $val) {
                // Find sub_field config for this key
                $subFieldConfig = self::findSubField($subFields, $key);
                if ($subFieldConfig) {
                    $mockField = array_merge($subFieldConfig, ['value' => $val]);
                    $formattedRow[$key] = self::formatFieldValue($mockField);
                } else {
                    $formattedRow[$key] = $val;
                }
            }
            return $formattedRow;
        }, $rows);
    }

    /**
     * Format group field value.
     */
    private static function formatGroupValue(array $field): array
    {
        $value = $field['value'];
        if (! is_array($value)) {
            return [];
        }

        $subFields = $field['sub_fields'] ?? [];
        $formatted = [];

        foreach ($value as $key => $val) {
            $subFieldConfig = self::findSubField($subFields, $key);
            if ($subFieldConfig) {
                $mockField = array_merge($subFieldConfig, ['value' => $val]);
                $formatted[$key] = self::formatFieldValue($mockField);
            } else {
                $formatted[$key] = $val;
            }
        }

        return $formatted;
    }

    /**
     * Format flexible content field value.
     */
    private static function formatFlexibleContentValue(array $field): array
    {
        $rows = $field['value'];
        if (! is_array($rows)) {
            return [];
        }

        $layouts = $field['layouts'] ?? [];

        return array_map(function ($row) use ($layouts) {
            $layoutName = $row['acf_fc_layout'] ?? '';
            $layout = self::findLayout($layouts, $layoutName);
            $subFields = $layout['sub_fields'] ?? [];

            $formattedRow = ['acf_fc_layout' => $layoutName];
            foreach ($row as $key => $val) {
                if ($key === 'acf_fc_layout') {
                    continue;
                }
                $subFieldConfig = self::findSubField($subFields, $key);
                if ($subFieldConfig) {
                    $mockField = array_merge($subFieldConfig, ['value' => $val]);
                    $formattedRow[$key] = self::formatFieldValue($mockField);
                } else {
                    $formattedRow[$key] = $val;
                }
            }
            return $formattedRow;
        }, $rows);
    }

    /**
     * Find a sub_field definition by name.
     */
    private static function findSubField(array $subFields, string $name): ?array
    {
        foreach ($subFields as $subField) {
            if (($subField['name'] ?? '') === $name) {
                return $subField;
            }
        }
        return null;
    }

    /**
     * Find a layout definition by name.
     */
    private static function findLayout(array $layouts, string $name): ?array
    {
        foreach ($layouts as $layout) {
            if (($layout['name'] ?? '') === $name) {
                return $layout;
            }
        }
        return null;
    }

    /**
     * Update ACF fields on a post.
     */
    public static function updateFields(int|string $postId, array $fields): array
    {
        if (! function_exists('update_field')) {
            throw new \RuntimeException('ACF PRO is required but not active.');
        }

        $updated = [];
        $errors = [];

        foreach ($fields as $fieldName => $value) {
            try {
                $result = update_field($fieldName, $value, $postId);
                if ($result === false) {
                    $errors[] = "Failed to update field: {$fieldName}";
                } else {
                    $updated[] = $fieldName;
                }
            } catch (\Throwable $e) {
                $errors[] = "Error updating field '{$fieldName}': {$e->getMessage()}";
            }
        }

        return [
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }

    /**
     * Get ACF field group schema with all field definitions.
     */
    public static function getFieldGroupSchema(string $groupKey): ?array
    {
        if (! function_exists('acf_get_field_group') || ! function_exists('acf_get_fields')) {
            return null;
        }

        $group = acf_get_field_group($groupKey);
        if (! $group) {
            return null;
        }

        $fields = acf_get_fields($group);

        return [
            'key'       => $group['key'],
            'title'     => $group['title'],
            'active'    => $group['active'],
            'location'  => $group['location'] ?? [],
            'fields'    => self::formatFieldDefinitions($fields ?: []),
        ];
    }

    /**
     * Format field definitions recursively (for schema output).
     */
    private static function formatFieldDefinitions(array $fields): array
    {
        return array_map(function ($field) {
            $def = [
                'key'          => $field['key'],
                'name'         => $field['name'],
                'label'        => $field['label'],
                'type'         => $field['type'],
                'required'     => (bool) ($field['required'] ?? false),
            ];

            if (! empty($field['instructions'])) {
                $def['instructions'] = $field['instructions'];
            }
            if (! empty($field['default_value'])) {
                $def['default_value'] = $field['default_value'];
            }
            if (! empty($field['choices'])) {
                $def['choices'] = $field['choices'];
            }
            if (! empty($field['min'])) {
                $def['min'] = $field['min'];
            }
            if (! empty($field['max'])) {
                $def['max'] = $field['max'];
            }
            if (! empty($field['conditional_logic'])) {
                $def['conditional_logic'] = $field['conditional_logic'];
            }

            // Recurse for nested field types
            if (! empty($field['sub_fields'])) {
                $def['sub_fields'] = self::formatFieldDefinitions($field['sub_fields']);
            }
            if (! empty($field['layouts'])) {
                $def['layouts'] = array_map(function ($layout) {
                    return [
                        'key'        => $layout['key'],
                        'name'       => $layout['name'],
                        'label'      => $layout['label'],
                        'sub_fields' => self::formatFieldDefinitions($layout['sub_fields'] ?? []),
                    ];
                }, $field['layouts']);
            }

            return $def;
        }, $fields);
    }
}
