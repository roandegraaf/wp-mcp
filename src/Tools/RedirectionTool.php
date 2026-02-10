<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class RedirectionTool extends AbstractTool
{
    private function requireRedirection(): void
    {
        if (! class_exists('Red_Item')) {
            throw new \RuntimeException('Redirection plugin is required but not active.');
        }
    }

    /**
     * List all redirects with optional search and pagination.
     */
    #[McpTool(name: 'wp_list_redirects', description: 'List all redirects managed by the Redirection plugin with optional search and pagination.')]
    public function listRedirects(
        #[Schema(description: 'Search term to filter redirects by source or target URL')]
        string $search = '',
        #[Schema(description: 'Results per page', minimum: 1, maximum: 100)]
        int $per_page = 50,
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
    ): string {
        $this->requireRedirection();

        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';

        $where = '';
        $params = [];

        if ($search !== '') {
            $search = $this->sanitizeText($search);
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = 'WHERE url LIKE %s OR action_data LIKE %s';
            $params[] = $like;
            $params[] = $like;
        }

        $countQuery = "SELECT COUNT(*) FROM {$table} {$where}";
        $total = $where !== ''
            ? (int) $wpdb->get_var($wpdb->prepare($countQuery, ...$params))
            : (int) $wpdb->get_var($countQuery);

        $offset = ($page - 1) * $per_page;
        $query = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $queryParams = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$queryParams), ARRAY_A);

        $redirects = [];
        foreach ($rows as $row) {
            $redirects[] = [
                'id'            => (int) $row['id'],
                'source_url'    => $row['url'],
                'target_url'    => $row['action_data'],
                'http_code'     => (int) $row['action_code'],
                'group_id'      => (int) $row['group_id'],
                'hits'          => (int) $row['last_count'],
                'last_accessed' => $row['last_access'],
                'status'        => $row['status'] === 'enabled' ? 'enabled' : 'disabled',
            ];
        }

        return ResponseFormatter::toJson([
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
            'redirects'  => $redirects,
        ]);
    }

    /**
     * Create a new redirect.
     */
    #[McpTool(name: 'wp_create_redirect', description: 'Create a new URL redirect via the Redirection plugin.')]
    public function createRedirect(
        #[Schema(description: 'Source URL path to redirect from')]
        string $source_url,
        #[Schema(description: 'Target URL to redirect to')]
        string $target_url,
        #[Schema(description: 'HTTP status code for the redirect', enum: [301, 302, 307, 308])]
        int $http_code = 301,
        #[Schema(description: 'Match type for the redirect')]
        string $match_type = 'url',
    ): string {
        $this->requireRedirection();

        if (! in_array($http_code, [301, 302, 307, 308], true)) {
            throw new \RuntimeException("Invalid HTTP code: {$http_code}. Must be one of: 301, 302, 307, 308.");
        }

        $source_url = $this->sanitizeText($source_url);
        $target_url = $this->sanitizeText($target_url);
        $match_type = $this->sanitizeText($match_type);

        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';

        $wpdb->insert($table, [
            'url'         => $source_url,
            'action_data' => $target_url,
            'action_code' => $http_code,
            'action_type' => 'url',
            'match_type'  => $match_type,
            'group_id'    => 1,
            'status'      => 'enabled',
        ]);

        if (! $wpdb->insert_id) {
            throw new \RuntimeException('Failed to create redirect.');
        }

        return ResponseFormatter::toJson([
            'id'         => (int) $wpdb->insert_id,
            'source_url' => $source_url,
            'target_url' => $target_url,
            'http_code'  => $http_code,
            'message'    => 'Redirect created successfully.',
        ]);
    }

    /**
     * Delete a redirect by ID.
     */
    #[McpTool(name: 'wp_delete_redirect', description: 'Delete a redirect by its ID from the Redirection plugin.')]
    public function deleteRedirect(
        #[Schema(description: 'The ID of the redirect to delete')]
        int $redirect_id,
    ): string {
        $this->requireRedirection();

        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $redirect_id));
        if (! $exists) {
            throw new \RuntimeException("Redirect not found: {$redirect_id}");
        }

        $wpdb->delete($table, ['id' => $redirect_id], ['%d']);

        return ResponseFormatter::toJson([
            'id'      => $redirect_id,
            'deleted' => true,
            'message' => 'Redirect deleted successfully.',
        ]);
    }
}
