<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\OptionPatch;
use WpMcp\Helpers\ResponseFormatter;

class OptionTool extends AbstractTool
{
    private const BLOCKED_OPTIONS = [
        'active_plugins',
        'siteurl',
        'home',
        'template',
        'stylesheet',
        'admin_email',
        'db_version',
        'initial_db_version',
        'users_can_register',
        'default_role',
        'wp_user_roles',
        'auth_key',
        'auth_salt',
        'logged_in_key',
        'logged_in_salt',
        'nonce_key',
        'nonce_salt',
        'secure_auth_key',
        'secure_auth_salt',
        'wp_mcp_password_hash',
        'wp_mcp_api_keys',
    ];

    private const MERGE_ONLY_OPTIONS = [
        'icl_sitepress_settings',
    ];

    /**
     * Read a WordPress option by name.
     */
    #[McpTool(name: 'wp_get_option', description: 'Read any WordPress option by name. Returns the option value and its type.')]
    public function getOption(
        #[Schema(description: 'The option name to retrieve')]
        string $option_name,
        #[Schema(description: 'Default value if option does not exist')]
        string $default = '',
    ): string {
        $option_name = $this->sanitizeText($option_name);

        if (in_array($option_name, self::BLOCKED_OPTIONS, true)) {
            throw new \RuntimeException("Option '{$option_name}' is blocked from being read for safety.");
        }

        $value = get_option($option_name, $default);

        return ResponseFormatter::toJson([
            'option_name' => $option_name,
            'value'       => $value,
            'type'        => gettype($value),
        ]);
    }

    #[McpTool(name: 'wp_update_option', description: 'Update a WordPress option. Some critical options are blocked for safety. Options stored as an array/object (e.g. icl_sitepress_settings, widget settings) only accept a JSON object/array: with merge=true (default) only the passed keys change (nested objects merge recursively; lists and scalars replace), with merge=false the whole value is replaced. A plain string is rejected for such options so it cannot overwrite them. icl_sitepress_settings (WPML) can only be merged. Scalar options are saved as sanitized text.')]
    public function updateOption(
        #[Schema(description: 'The option name to update')]
        string $option_name,
        #[Schema(description: 'The new value. For array/object options: a JSON object (merge) or JSON array/object (merge=false).')]
        string $value,
        #[Schema(description: 'Array/object options only: merge the passed keys into the stored value (true) or replace it entirely (false)')]
        bool $merge = true,
    ): string {
        $option_name = $this->sanitizeText($option_name);

        if (in_array($option_name, self::BLOCKED_OPTIONS, true)) {
            throw new \RuntimeException("Option '{$option_name}' is blocked from being updated for safety.");
        }

        $current = get_option($option_name, null);

        if (! is_array($current) && ! is_object($current)) {
            update_option($option_name, $this->sanitizeText($value));

            return ResponseFormatter::toJson([
                'option_name' => $option_name,
                'updated'     => true,
                'type'        => 'string',
                'message'     => 'Option updated successfully.',
            ]);
        }

        if (! $merge && in_array($option_name, self::MERGE_ONLY_OPTIONS, true)) {
            throw new \RuntimeException("Option '{$option_name}' can only be merged (merge=true). Replacing it entirely is blocked for safety.");
        }

        try {
            $patched = OptionPatch::apply($current, $value, $merge);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("Option '{$option_name}': " . $e->getMessage());
        }

        if ($patched['changed_keys'] !== []) {
            $this->saveStructuredOption($option_name, $patched['value'], $patched['changed_keys']);
        }

        return ResponseFormatter::toJson([
            'option_name'  => $option_name,
            'updated'      => $patched['changed_keys'] !== [],
            'mode'         => $merge ? 'merge' : 'replace',
            'type'         => gettype($patched['value']),
            'changed_keys' => $patched['changed_keys'],
            'message'      => $patched['changed_keys'] === []
                ? 'No changes: the stored value already matches.'
                : 'Option updated (' . count($patched['changed_keys']) . ' top-level key(s) changed).',
        ]);
    }

    /**
     * @param list<string> $changedKeys
     */
    private function saveStructuredOption(string $optionName, array|object $value, array $changedKeys): void
    {
        update_option($optionName, $value);

        // SitePress keeps icl_sitepress_settings in memory by reference and writes
        // that copy back on save_settings(); without this a later save in the same
        // request would undo the update.
        if ($optionName === 'icl_sitepress_settings' && is_array($value) && function_exists('icl_set_setting')) {
            foreach ($changedKeys as $key) {
                if (array_key_exists($key, $value)) {
                    icl_set_setting($key, $value[$key]);
                }
            }
        }
    }
}
