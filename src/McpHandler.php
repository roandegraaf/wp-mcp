<?php

declare(strict_types=1);

namespace WpMcp;

use PhpMcp\Server\Server;
use React\EventLoop\Loop;

class McpHandler
{
    private ?Server $server = null;

    public function checkPermissions(\WP_REST_Request $request): bool|\WP_Error
    {
        // Try Bearer token auth first
        $authHeader = $request->get_header('authorization');

        // Fallback: some hosts strip the header before WP sees it
        if (! $authHeader && ! empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if ($authHeader && stripos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);

            if ($this->validateApiKey($token)) {
                return true;
            }

            return new \WP_Error(
                'rest_invalid_token',
                'Invalid MCP password.',
                ['status' => 401]
            );
        }

        // Fall back to standard WP auth
        if (! is_user_logged_in()) {
            return new \WP_Error(
                'rest_not_logged_in',
                'Authentication required. Use a Bearer token or Application Passwords with Basic Auth.',
                ['status' => 401]
            );
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error(
                'rest_forbidden',
                'Administrator access required.',
                ['status' => 403]
            );
        }

        return true;
    }

    private function validateApiKey(string $token): bool
    {
        $keys = get_option('wp_mcp_api_keys', []);

        // Migrate legacy single-key format
        if (empty($keys)) {
            $legacyHash = get_option('wp_mcp_password_hash');
            if ($legacyHash) {
                $keys = [[
                    'id'           => wp_generate_password(12, false),
                    'name'         => 'Migrated key',
                    'hash'         => $legacyHash,
                    'created_at'   => time(),
                    'last_used_at' => null,
                ]];
                update_option('wp_mcp_api_keys', $keys);
                delete_option('wp_mcp_password_hash');
            }
        }

        foreach ($keys as $index => $key) {
            if (wp_check_password($token, $key['hash'])) {
                $keys[$index]['last_used_at'] = time();
                update_option('wp_mcp_api_keys', $keys);
                return true;
            }
        }

        return false;
    }

    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            set_transient('wp_mcp_last_activity', time(), 600);

            $server = $this->getServer();

            $body = $request->get_body();
            $method = $request->get_method();
            $headers = $this->normalizeHeaders($request);

            $transport = new WordPressTransport($body, $method, $headers);

            // Bind protocol to our transport
            $protocol = $server->getProtocol();
            $protocol->bindTransport($transport);

            // Start the transport (emits events synchronously)
            $transport->listen();

            // Run the event loop to process the message through the protocol
            Loop::get()->futureTick(function () {});
            Loop::get()->run();

            // Unbind to allow re-use
            $protocol->unbindTransport();

            // Build WP response
            $responseBody = $transport->getResponseBody();
            $sessionId = $transport->getSessionId();

            if ($method === 'DELETE') {
                $response = new \WP_REST_Response(null, 200);
            } elseif ($responseBody === '') {
                $response = new \WP_REST_Response(null, 202);
            } else {
                // Decode as objects (not assoc arrays) so empty {} stays as objects.
                // Then fix any remaining empty arrays to stdClass for MCP spec compliance.
                $decoded = json_decode($responseBody);
                $this->fixEmptyArrays($decoded);
                $response = new \WP_REST_Response($decoded, 200);
            }

            $response->header('Content-Type', 'application/json');

            if ($sessionId) {
                $response->header('Mcp-Session-Id', $sessionId);
            }

            return $response;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'mcp_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function handleUpload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return new \WP_Error('no_file', 'No file provided. Use -F "file=@path/to/file"', ['status' => 400]);
        }

        $file = $files['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error('upload_error', 'File upload error code: ' . $file['error'], ['status' => 400]);
        }

        $filetype = wp_check_filetype($file['name']);
        if (! $filetype['type']) {
            return new \WP_Error('invalid_type', 'File type not allowed: ' . $file['name'], ['status' => 400]);
        }

        // Use wp_handle_upload to move file to uploads directory
        $upload = wp_handle_upload($file, ['test_form' => false]);

        if (isset($upload['error'])) {
            return new \WP_Error('upload_failed', $upload['error'], ['status' => 500]);
        }

        $title = sanitize_text_field($request->get_param('title') ?? '');
        $caption = sanitize_text_field($request->get_param('caption') ?? '');
        $postId = (int) ($request->get_param('post_id') ?? 0);

        $attachment = [
            'post_mime_type' => $upload['type'],
            'post_title'     => $title !== '' ? $title : preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
            'post_content'   => '',
            'post_excerpt'   => $caption,
            'post_status'    => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachment, $upload['file'], $postId > 0 ? $postId : 0);

        if (is_wp_error($attachmentId)) {
            return new \WP_Error('insert_failed', $attachmentId->get_error_message(), ['status' => 500]);
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        wp_update_attachment_metadata($attachmentId, $metadata);

        $alt = sanitize_text_field($request->get_param('alt') ?? '');
        if ($alt !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);
        }

        return new \WP_REST_Response([
            'id'      => $attachmentId,
            'url'     => wp_get_attachment_url($attachmentId),
            'title'   => get_the_title($attachmentId),
            'message' => "Media uploaded successfully with ID {$attachmentId}.",
        ], 201);
    }

    private function getServer(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $server = Server::make()
            ->withServerInfo('WP MCP', WP_MCP_VERSION)
            ->withSessionHandler(new WordPressSessionHandler())
            ->build();

        // Discover tools from our Tools directory
        $server->discover(
            basePath: WP_MCP_PLUGIN_DIR,
            scanDirs: ['src'],
        );

        $this->server = $server;

        return $this->server;
    }

    private function normalizeHeaders(\WP_REST_Request $request): array
    {
        $headers = [];
        $rawHeaders = $request->get_headers();

        foreach ($rawHeaders as $key => $values) {
            $normalized = str_replace('_', '-', $key);
            $headers[$normalized] = (array) $values;
        }

        return $headers;
    }

    /**
     * Recursively convert empty arrays to stdClass for proper JSON {} serialization.
     * The MCP spec requires capabilities values to be objects, not arrays.
     */
    private function fixEmptyArrays(object|array &$data): void
    {
        $items = is_object($data) ? get_object_vars($data) : $data;

        foreach ($items as $key => $value) {
            if (is_array($value) && empty($value)) {
                if (is_object($data)) {
                    $data->$key = new \stdClass();
                } else {
                    $data[$key] = new \stdClass();
                }
            } elseif (is_object($value) || is_array($value)) {
                if (is_object($data)) {
                    $this->fixEmptyArrays($data->$key);
                } else {
                    $this->fixEmptyArrays($data[$key]);
                }
            }
        }
    }
}
