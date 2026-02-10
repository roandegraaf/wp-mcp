<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
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

        $value = get_option($option_name, $default);

        return ResponseFormatter::toJson([
            'option_name' => $option_name,
            'value'       => $value,
            'type'        => gettype($value),
        ]);
    }

    /**
     * Update a WordPress option.
     */
    #[McpTool(name: 'wp_update_option', description: 'Update a WordPress option. Some critical options are blocked for safety.')]
    public function updateOption(
        #[Schema(description: 'The option name to update')]
        string $option_name,
        #[Schema(description: 'The new value for the option')]
        string $value,
    ): string {
        $option_name = $this->sanitizeText($option_name);
        $value = $this->sanitizeText($value);

        if (in_array($option_name, self::BLOCKED_OPTIONS, true)) {
            throw new \RuntimeException("Option '{$option_name}' is blocked from being updated for safety.");
        }

        update_option($option_name, $value);

        return ResponseFormatter::toJson([
            'option_name' => $option_name,
            'updated'     => true,
            'message'     => 'Option updated successfully.',
        ]);
    }
}
