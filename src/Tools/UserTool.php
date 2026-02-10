<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class UserTool extends AbstractTool
{
    /**
     * List WordPress users with roles.
     */
    #[McpTool(name: 'wp_list_users', description: 'List WordPress users with their roles, names, and emails.')]
    public function listUsers(
        #[Schema(description: 'Filter by role (e.g. administrator, editor, author, subscriber)')]
        string $role = '',
        #[Schema(description: 'Search by name or email')]
        string $search = '',
        #[Schema(description: 'Number of users to return', minimum: 1, maximum: 100)]
        int $number = 50,
        #[Schema(description: 'Order by: display_name, login, email, registered, ID')]
        string $orderby = 'display_name',
    ): string {
        $args = [
            'number'  => min($number, 100),
            'orderby' => $this->sanitizeText($orderby),
            'order'   => 'ASC',
        ];

        if ($role !== '') {
            $args['role'] = $this->sanitizeText($role);
        }
        if ($search !== '') {
            $args['search'] = '*' . $this->sanitizeText($search) . '*';
        }

        $users = get_users($args);

        $formatted = array_map(function ($user) {
            return ResponseFormatter::formatUser($user);
        }, $users);

        return ResponseFormatter::toJson([
            'count' => count($formatted),
            'users' => $formatted,
        ]);
    }
}
