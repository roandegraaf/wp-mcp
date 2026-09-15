<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\FailedWriteLog;
use WpMcp\Helpers\LoginLink;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\WpmlHelper;

class DiagnosticsTool extends AbstractTool
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    #[McpTool(name: 'wp_create_login_link', description: 'SECURITY-SENSITIVE WRITE. Generate a one-time, short-lived magic login URL for a given user so wp-admin can be opened in a real browser as that user (e.g. to reproduce an editor save problem while watching network/console). The token is stored hashed in a transient, consumed on first use (single_use) and every issued link is audit-logged with the MCP caller identity. Default TTL 300s, max 900s.')]
    public function createLoginLink(
        #[Schema(description: 'User ID, login or email to log in as')]
        string $user,
        #[Schema(description: 'Where to land after login: a path relative to the WordPress root (e.g. "wp-admin/post.php?post=2474&action=edit") or a full URL on this site. Defaults to the dashboard.')]
        string $redirect_to = '',
        #[Schema(description: 'Seconds until the link expires', minimum: 1, maximum: 900)]
        int $ttl_seconds = 300,
        #[Schema(description: 'Invalidate the link after its first use')]
        bool $single_use = true,
    ): string {
        $target = $this->resolveUser($user);
        $link = LoginLink::issue($target, $redirect_to, $ttl_seconds, $single_use, $this->callerIdentity());
        $link['note'] = 'Open the URL in a browser; it sets the auth cookie for that user and redirects. Other administrators can see this in the login-link audit log.';

        return ResponseFormatter::toJson($link);
    }

    #[McpTool(name: 'wp_rest_request', description: 'WRITE-CAPABLE. Execute an internal REST request through the real dispatcher (rest_do_request) as an impersonated user, so capability checks, security plugins and save hooks fire exactly as for Gutenberg. Returns status, headers and the full body including WP_Error code/message/data. dry_run=true (default) wraps the call in a DB transaction and rolls it back afterwards, so e.g. "would this user\'s PUT /wp/v2/pages/2474 succeed" can be tested without changing content (side effects outside the DB — emails, remote calls, external caches — are not rolled back; the object cache is flushed after rollback). Set dry_run=false to apply the request for real.')]
    public function restRequest(
        #[Schema(description: 'HTTP method', enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'])]
        string $method,
        #[Schema(description: 'REST route, e.g. "/wp/v2/pages/2474" (a leading "/wp-json" is stripped)')]
        string $route,
        #[Schema(description: 'Request body as a JSON object string (sent as application/json, like Gutenberg)')]
        string $body = '',
        #[Schema(description: 'Query parameters as a JSON object string, e.g. {"context":"edit"}')]
        string $query = '',
        #[Schema(description: 'User ID, login or email to impersonate. Empty runs as the current MCP session user (no user when authenticated by API key).')]
        string $as_user = '',
        #[Schema(description: 'Run inside a transaction and roll it back so nothing persists')]
        bool $dry_run = true,
        #[Schema(description: 'Truncate the JSON-encoded response body beyond this many characters', minimum: 1000, maximum: 200000)]
        int $max_body_chars = 20000,
    ): string {
        global $wpdb;

        $method = strtoupper(trim($method));
        if (! in_array($method, self::HTTP_METHODS, true)) {
            throw new \RuntimeException('Unsupported method: ' . $method);
        }

        $route = trim($route);
        $prefix = '/' . rest_get_url_prefix();
        if (preg_match('#^https?://#i', $route)) {
            $route = (string) wp_parse_url($route, PHP_URL_PATH);
        }
        if (str_starts_with($route, $prefix . '/')) {
            $route = substr($route, strlen($prefix));
        }
        $route = '/' . ltrim($route, '/');

        $request = new \WP_REST_Request($method, $route);

        if (trim($query) !== '') {
            $params = json_decode($query, true);
            if (! is_array($params)) {
                throw new \RuntimeException('query must be a JSON object.');
            }
            $request->set_query_params($params);
        }

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('body must be a JSON object string.');
            }
            $request->set_header('Content-Type', 'application/json');
            $request->set_body($body);
        }

        $previousUser = get_current_user_id();
        $impersonated = null;
        if (trim($as_user) !== '') {
            $impersonated = $this->resolveUser($as_user);
            wp_set_current_user($impersonated->ID);
        }

        $transactional = false;
        if ($dry_run) {
            $engine = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $wpdb->posts
            ));
            if (strcasecmp($engine, 'InnoDB') !== 0) {
                wp_set_current_user($previousUser);
                throw new \RuntimeException("dry_run needs InnoDB tables; {$wpdb->posts} uses " . ($engine ?: 'unknown') . '.');
            }
            $wpdb->query('START TRANSACTION');
            $transactional = true;
            FailedWriteLog::beginDeferred();
        }

        FailedWriteLog::setVia('wp_rest_request' . ($dry_run ? ' (dry_run)' : ''));
        $started = microtime(true);
        $loggedFailures = [];

        try {
            $response = rest_do_request($request);
        } finally {
            if ($transactional) {
                $wpdb->query('ROLLBACK');
                wp_cache_flush();
                $loggedFailures = FailedWriteLog::endDeferred();
            }
            FailedWriteLog::setVia(null);
            wp_set_current_user($previousUser);
        }

        $durationMs = round((microtime(true) - $started) * 1000, 1);
        $server = rest_get_server();
        $data = $server->response_to_data($response, false);
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
        $truncated = strlen($encoded) > $max_body_chars;

        $result = [
            'method'      => $method,
            'route'       => $route,
            'as_user'     => $impersonated
                ? ['id' => $impersonated->ID, 'login' => $impersonated->user_login, 'roles' => $impersonated->roles]
                : ['id' => $previousUser, 'note' => $previousUser ? 'current session user' : 'no WP user (API-key session)'],
            'dry_run'     => $dry_run,
            'rolled_back' => $transactional,
            'status'      => $response->get_status(),
            'ok'          => $response->get_status() < 400,
            'duration_ms' => $durationMs,
            'headers'     => $response->get_headers(),
            'error'       => null,
            'body'        => $truncated ? mb_strcut($encoded, 0, $max_body_chars, 'UTF-8') . '…' : $data,
            'body_truncated' => $truncated,
        ];

        $error = $response->as_error();
        if ($error instanceof \WP_Error) {
            $result['error'] = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
                'all'     => $error->get_error_messages(),
            ];
        }

        if ($dry_run) {
            $result['failed_write_log_entries'] = $loggedFailures;
        }

        return ResponseFormatter::toJson($result);
    }

    #[McpTool(name: 'wp_list_failed_writes', description: 'List write requests (POST/PUT/PATCH/DELETE) that failed: REST responses >= 400 or WP_Error, admin-ajax errors (ACF/WPML), and PHP fatals during REST/ajax. Each entry has timestamp, user, route, status, error code/message, post_id and request size. The plugin keeps the last 500 entries. Filter by since (ISO date or relative like "-2 hours"), user (ID or login), post_id, route substring.')]
    public function listFailedWrites(
        #[Schema(description: 'Only entries at or after this time (ISO 8601 or strtotime-compatible, e.g. "2026-09-08" or "-1 day")')]
        string $since = '',
        #[Schema(description: 'User ID or login')]
        string $user = '',
        #[Schema(description: 'Post ID')]
        int $post_id = 0,
        #[Schema(description: 'Route substring, e.g. "/wp/v2/pages" or "acf/validate_save_post"')]
        string $route = '',
        #[Schema(description: 'Maximum entries to return (newest first)', minimum: 1, maximum: 500)]
        int $limit = 50,
    ): string {
        $since = $this->sanitizeText($since);
        if ($since !== '' && strtotime($since) === false) {
            throw new \RuntimeException("Could not parse since: {$since}");
        }

        $entries = FailedWriteLog::query($since, $this->sanitizeText($user), $post_id, $this->sanitizeText($route), $limit);
        $all = get_option(FailedWriteLog::OPTION, []);

        return ResponseFormatter::toJson([
            'total_logged' => is_array($all) ? count($all) : 0,
            'returned'     => count($entries),
            'filters'      => ['since' => $since, 'user' => $user, 'post_id' => $post_id, 'route' => $route],
            'entries'      => $entries,
        ]);
    }

    #[McpTool(name: 'wp_get_post_edit_state', description: 'One call for "who touched this post, when, and is it blocked": _edit_lock (user + age + whether the editor lock is currently active), _edit_last, post_modified, latest revision and autosave with author, and — with WPML — language/trid, translation status (needs_update, status, review status, translator), latest translation job editor, and whether this post opens in the native editor or the WPML/ATE editor.')]
    public function getPostEditState(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        global $wpdb;

        $post = $this->getPostOrFail($post_id);

        $lockRaw = (string) get_post_meta($post_id, '_edit_lock', true);
        $lock = null;
        if ($lockRaw !== '') {
            [$lockTime, $lockUser] = array_pad(explode(':', $lockRaw, 2), 2, null);
            $age = time() - (int) $lockTime;
            $window = (int) apply_filters('wp_check_post_lock_window', 150);
            $lock = [
                'raw'         => $lockRaw,
                'user'        => $lockUser ? ResponseFormatter::formatAuthor((int) $lockUser) : null,
                'set_at'      => gmdate('c', (int) $lockTime),
                'age_seconds' => $age,
                'active'      => $lockUser !== null && $age <= $window,
                'window_seconds' => $window,
            ];
        }

        $editLast = (int) get_post_meta($post_id, '_edit_last', true);

        $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => -1, 'check_enabled' => false]);
        $latestRevision = null;
        $latestAutosave = null;
        foreach ($revisions as $revision) {
            $isAutosave = str_contains($revision->post_name, 'autosave');
            $row = [
                'id'             => $revision->ID,
                'author'         => ResponseFormatter::formatAuthor((int) $revision->post_author),
                'modified'       => $revision->post_modified,
                'content_length' => strlen($revision->post_content),
            ];
            if ($isAutosave && $latestAutosave === null) {
                $latestAutosave = $row;
            } elseif (! $isAutosave && $latestRevision === null) {
                $latestRevision = $row;
            }
        }

        $data = [
            'post_id'            => $post_id,
            'title'              => $post->post_title,
            'type'               => $post->post_type,
            'status'             => $post->post_status,
            'post_modified'      => $post->post_modified,
            'post_modified_gmt'  => $post->post_modified_gmt,
            'author'             => ResponseFormatter::formatAuthor((int) $post->post_author),
            'edit_last'          => $editLast ? ResponseFormatter::formatAuthor($editLast) : null,
            'edit_lock'          => $lock,
            'locked_in_editor'   => (bool) ($lock['active'] ?? false),
            'revision_count'     => count($revisions),
            'latest_revision'    => $latestRevision,
            'latest_autosave'    => $latestAutosave,
            'content_length'     => strlen($post->post_content),
            'server_time'        => gmdate('c'),
        ];

        if (WpmlHelper::isActive()) {
            $data['wpml'] = $this->wpmlEditState($post);
        }

        return ResponseFormatter::toJson($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function wpmlEditState(\WP_Post $post): array
    {
        global $wpdb;

        $info = WpmlHelper::elementInfo($post) ?? [];
        $wpmlType = 'post_' . $post->post_type;

        $status = $wpdb->get_row($wpdb->prepare(
            "SELECT ts.rid, ts.status, ts.needs_update, ts.translator_id, ts.review_status, ts.translation_service, ts.timestamp, ts.uuid
             FROM {$wpdb->prefix}icl_translation_status ts
             INNER JOIN {$wpdb->prefix}icl_translations t ON t.translation_id = ts.translation_id
             WHERE t.element_id = %d AND t.element_type = %s",
            $post->ID,
            $wpmlType
        ), ARRAY_A);

        $job = null;
        if ($status && ! empty($status['rid'])) {
            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT job_id, editor, editor_job_id, translated, automatic, translator_id, completed_date, edit_timestamp
                 FROM {$wpdb->prefix}icl_translate_job WHERE rid = %d ORDER BY job_id DESC LIMIT 1",
                (int) $status['rid']
            ), ARRAY_A);
        }

        $editorMode = null;
        if (class_exists('WPML_TM_Post_Edit_TM_Editor_Mode')) {
            $usesTmEditor = (bool) \WPML_TM_Post_Edit_TM_Editor_Mode::is_using_tm_editor(null, $post->ID);
            $usesTmEditor = (bool) apply_filters('wpml_use_tm_editor', $usesTmEditor, $post->ID);
            $ateEnabled = class_exists('WPML_TM_ATE_Status') && \WPML_TM_ATE_Status::is_enabled();
            $editorMode = $usesTmEditor ? ($ateEnabled ? 'ate' : 'wpml') : 'native';
        }

        $originalId = apply_filters('wpml_original_element_id', null, $post->ID, $wpmlType);

        return [
            'language'          => $info,
            'original_post_id'  => $originalId ? (int) $originalId : null,
            'editor_mode'       => $editorMode,
            'native_editor_meta' => get_post_meta($originalId ? (int) $originalId : $post->ID, '_wpml_post_translation_editor_native', true) ?: null,
            'translation_status' => $status ? [
                'status'              => (int) $status['status'],
                'status_label'        => $this->wpmlStatusLabel((int) $status['status']),
                'needs_update'        => (bool) $status['needs_update'],
                'review_status'       => $status['review_status'],
                'translator'          => $status['translator_id'] ? ResponseFormatter::formatAuthor((int) $status['translator_id']) : null,
                'translation_service' => $status['translation_service'],
                'timestamp'           => $status['timestamp'],
                'rid'                 => (int) $status['rid'],
            ] : null,
            'latest_job'        => $job,
        ];
    }

    private function wpmlStatusLabel(int $status): string
    {
        foreach ([
            'ICL_TM_NOT_TRANSLATED', 'ICL_TM_WAITING_FOR_TRANSLATOR', 'ICL_TM_IN_PROGRESS', 'ICL_TM_IN_BASKET',
            'ICL_TM_NEEDS_UPDATE', 'ICL_TM_DUPLICATE', 'ICL_TM_COMPLETE', 'ICL_TM_ATE_NEEDS_RETRY',
            'ICL_TM_ATE_CANCELLED', 'ICL_TM_TRANSLATION_REVIEW',
        ] as $constant) {
            if (defined($constant) && constant($constant) === $status) {
                return strtolower(substr($constant, 7));
            }
        }
        return 'unknown';
    }
}
