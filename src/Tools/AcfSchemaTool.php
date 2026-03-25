<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\AcfHelper;
use WpMcp\Helpers\ResponseFormatter;

class AcfSchemaTool extends AbstractTool
{
    /**
     * List all ACF field groups and what post types/locations they're assigned to.
     */
    #[McpTool(name: 'wp_list_field_groups', description: 'List all ACF field groups with their assignments (post types, taxonomies, etc).')]
    public function listFieldGroups(): string
    {
        $this->requireAcf();

        $groups = acf_get_field_groups();

        $data = array_map(function ($group) {
            $result = [
                'key'       => $group['key'],
                'title'     => $group['title'],
                'active'    => $group['active'],
            ];

            // Parse location rules to show where group appears
            if (! empty($group['location'])) {
                $result['location_rules'] = self::formatLocationRules($group['location']);
            }

            return $result;
        }, $groups);

        return ResponseFormatter::toJson(['field_groups' => $data]);
    }

    /**
     * Get complete field definitions for a field group: types, choices, conditional logic, validation.
     */
    #[McpTool(name: 'wp_get_field_group_schema', description: 'Get complete field definitions for an ACF field group: types, choices, sub_fields, conditional logic, validation rules.')]
    public function getFieldGroupSchema(
        #[Schema(description: 'Field group key (e.g. group_abc123)')]
        string $group_key,
    ): string {
        $this->requireAcf();

        $schema = AcfHelper::getFieldGroupSchema($group_key);
        if (! $schema) {
            throw new \RuntimeException("Field group not found: {$group_key}");
        }

        return ResponseFormatter::toJson($schema);
    }

    /**
     * Create a new ACF field group with fields and location rules.
     */
    #[McpTool(name: 'wp_create_field_group', description: 'Create a new ACF field group with fields and location rules. Fields need key, name, label, type. Supports all ACF field types including repeaters (with sub_fields), flexible content (with layouts), groups, images, galleries, selects, etc.')]
    public function createFieldGroup(
        #[Schema(description: 'Field group title')]
        string $title,
        #[Schema(description: 'JSON array of field definitions. Each field needs: key (e.g. field_abc123), name, label, type. Optional: required, instructions, default_value, choices, sub_fields, min, max, etc.')]
        string $fields,
        #[Schema(description: 'JSON array of location rule groups. Example: [[{"param":"post_type","operator":"==","value":"apartment"}]]. Multiple groups are OR, rules within a group are AND.')]
        string $location,
        #[Schema(description: 'Position: normal, acf_after_title, side', enum: ['normal', 'acf_after_title', 'side'])]
        string $position = 'normal',
        #[Schema(description: 'Style: default or seamless', enum: ['default', 'seamless'])]
        string $style = 'default',
        #[Schema(description: 'Label placement: top or left', enum: ['top', 'left'])]
        string $label_placement = 'top',
        #[Schema(description: 'Display order (lower = first)')]
        int $menu_order = 0,
    ): string {
        $this->requireAcf();

        $fieldData = json_decode($fields, true);
        if (! is_array($fieldData)) {
            throw new \RuntimeException('Invalid fields JSON. Provide an array of field definitions.');
        }

        $locationData = json_decode($location, true);
        if (! is_array($locationData)) {
            throw new \RuntimeException('Invalid location JSON. Provide an array of location rule groups.');
        }

        // Generate group key
        $groupKey = 'group_' . uniqid();

        // Ensure all fields have unique keys
        $fieldData = $this->ensureFieldKeys($fieldData);

        $group = [
            'key'             => $groupKey,
            'title'           => $this->sanitizeText($title),
            'fields'          => $fieldData,
            'location'        => $locationData,
            'position'        => $position,
            'style'           => $style,
            'label_placement' => $label_placement,
            'menu_order'      => $menu_order,
            'active'          => true,
        ];

        $result = acf_import_field_group($group);

        if (empty($result)) {
            throw new \RuntimeException('Failed to create field group.');
        }

        return ResponseFormatter::toJson([
            'group_key'   => $groupKey,
            'title'       => $title,
            'field_count' => count($fieldData),
            'message'     => "Field group '{$title}' created successfully with key {$groupKey}.",
        ]);
    }

    /**
     * Recursively ensure all fields and sub_fields have unique keys.
     */
    private function ensureFieldKeys(array $fields): array
    {
        foreach ($fields as &$field) {
            if (empty($field['key'])) {
                $field['key'] = 'field_' . uniqid();
            }
            if (! empty($field['sub_fields'])) {
                $field['sub_fields'] = $this->ensureFieldKeys($field['sub_fields']);
            }
            if (! empty($field['layouts'])) {
                foreach ($field['layouts'] as &$layout) {
                    if (empty($layout['key'])) {
                        $layout['key'] = 'layout_' . uniqid();
                    }
                    if (! empty($layout['sub_fields'])) {
                        $layout['sub_fields'] = $this->ensureFieldKeys($layout['sub_fields']);
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Format ACF location rules into a readable summary.
     */
    private static function formatLocationRules(array $locationGroups): array
    {
        $rules = [];

        foreach ($locationGroups as $group) {
            $groupRules = [];
            foreach ($group as $rule) {
                $param = $rule['param'] ?? '';
                $operator = $rule['operator'] ?? '==';
                $value = $rule['value'] ?? '';

                $groupRules[] = "{$param} {$operator} {$value}";
            }
            $rules[] = implode(' AND ', $groupRules);
        }

        return $rules;
    }
}
