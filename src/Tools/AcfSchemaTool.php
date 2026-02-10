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
