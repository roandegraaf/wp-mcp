<?php

declare(strict_types=1);

namespace WpMcp;

class Plugin
{
    private McpHandler $handler;

    public function init(): void
    {
        $this->handler = new McpHandler();

        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_filter('rest_pre_serve_request', [$this, 'addMcpCorsHeaders'], 10, 4);

        if (is_admin()) {
            $admin = new Admin();
            $admin->init();
        }
    }

    public function registerRoutes(): void
    {
        register_rest_route('wp-mcp/v1', '/mcp', [
            [
                'methods'             => ['GET', 'POST', 'DELETE'],
                'callback'            => [$this->handler, 'handleRequest'],
                'permission_callback' => [$this->handler, 'checkPermissions'],
            ],
        ]);

        register_rest_route('wp-mcp/v1', '/upload', [
            [
                'methods'             => 'POST',
                'callback'            => [$this->handler, 'handleUpload'],
                'permission_callback' => [$this->handler, 'checkPermissions'],
            ],
        ]);

        register_rest_route('wp-mcp/v1', '/status', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleStatusRequest'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ],
        ]);
    }

    /**
     * Add MCP-specific CORS headers to wp-mcp REST responses.
     */
    public function addMcpCorsHeaders(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        $route = $request->get_route();
        if (str_starts_with($route, '/wp-mcp/')) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
            header('Access-Control-Expose-Headers: Mcp-Session-Id');
        }

        return $served;
    }

    public function handleStatusRequest(): \WP_REST_Response
    {
        $lastActivity = get_transient('wp_mcp_last_activity');
        $isConnected = $lastActivity && (time() - (int) $lastActivity) < 300;

        $data = [
            'connected'           => $isConnected,
            'last_activity'       => $lastActivity ? (int) $lastActivity : null,
            'last_activity_human' => $lastActivity ? human_time_diff((int) $lastActivity) . ' ago' : null,
        ];

        return new \WP_REST_Response($data, 200);
    }
}
