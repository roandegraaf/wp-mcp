<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Records write requests (POST/PUT/PATCH/DELETE) that fail, so "did the
 * editor's save fail, and why" can be answered after the fact.
 *
 * Sources: REST responses >= 400 or WP_Error, admin-ajax responses that are
 * an HTTP error or a wp_send_json_error payload, and PHP fatals during either.
 */
class FailedWriteLog
{
    public const OPTION = 'wp_mcp_failed_writes';
    private const MAX_ENTRIES = 500;
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private static ?string $via = null;
    private static ?array $deferred = null;
    private static bool $ajaxCapturing = false;

    public static function register(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'captureRest'], PHP_INT_MAX, 3);

        if (wp_doing_ajax() && self::isWriteMethod()) {
            self::$ajaxCapturing = true;
            ob_start();
            // Must run before wp_ob_end_flush_all() (shutdown action, priority 1)
            // discards every output buffer, or the JSON payload is gone.
            add_action('shutdown', [self::class, 'captureAjax'], 0);
        }

        register_shutdown_function([self::class, 'captureFatal']);
    }

    /**
     * Tag entries recorded while an impersonated request runs via wp_rest_request.
     */
    public static function setVia(?string $via): void
    {
        self::$via = $via;
    }

    /**
     * Buffer entries instead of persisting them, so a dry-run transaction
     * rollback does not also erase what it logged.
     */
    public static function beginDeferred(): void
    {
        self::$deferred = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function endDeferred(): array
    {
        $entries = self::$deferred ?? [];
        self::$deferred = null;
        foreach ($entries as $entry) {
            self::persist($entry);
        }
        return $entries;
    }

    public static function captureRest(mixed $response, mixed $handler, \WP_REST_Request $request): mixed
    {
        $method = strtoupper($request->get_method());
        $route = $request->get_route();

        if (! in_array($method, self::WRITE_METHODS, true) || str_starts_with($route, '/wp-mcp/')) {
            return $response;
        }

        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            $code = (string) $response->get_error_code();
            $message = $response->get_error_message();
            $errorData = $data;
        } elseif ($response instanceof \WP_HTTP_Response) {
            $status = $response->get_status();
            if ($status < 400) {
                return $response;
            }
            $body = $response->get_data();
            $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
            $errorData = is_array($body) ? ($body['data'] ?? null) : null;
        } else {
            return $response;
        }

        self::record([
            'source'        => 'rest',
            'method'        => $method,
            'route'         => $route,
            'status'        => $status,
            'error_code'    => $code,
            'error_message' => $message,
            'error_data'    => self::truncate($errorData),
            'post_id'       => self::postIdFromRest($route, $request),
            'request_bytes' => strlen((string) $request->get_body()),
        ]);

        return $response;
    }

    public static function captureFatal(): void
    {
        $isRest = defined('REST_REQUEST') && REST_REQUEST;
        $isAjax = wp_doing_ajax();

        $error = error_get_last();
        if (! $error || ! in_array($error['type'], self::FATAL_TYPES, true) || ! ($isRest || $isAjax)) {
            return;
        }

        self::record([
            'source'        => 'fatal',
            'method'        => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'route'         => self::currentRoute(),
            'status'        => (int) http_response_code() ?: 500,
            'error_code'    => 'php_fatal',
            'error_message' => $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
            'error_data'    => null,
            'post_id'       => self::postIdFromRequestVars(),
            'request_bytes' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
        ]);
    }

    public static function captureAjax(): void
    {
        if (! self::$ajaxCapturing) {
            return;
        }
        self::$ajaxCapturing = false;

        // ponytail: only the topmost output buffer is inspected; a plugin that
        // opens its own buffer after ours hides the JSON payload from this check.
        $body = (string) ob_get_contents();
        $status = (int) http_response_code();
        $failed = $status >= 400
            || preg_match('/^\s*\{"success"\s*:\s*false/', $body) === 1
            || in_array(trim($body), ['-1', '0'], true);

        if (! $failed) {
            return;
        }

        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $message = '';
        if (is_array($data)) {
            $message = (string) ($data['message'] ?? ($data[0]['message'] ?? ''));
        } elseif (is_string($data)) {
            $message = $data;
        } elseif (trim($body) === '-1') {
            $message = 'Nonce/referer check failed (-1)';
        } elseif (trim($body) === '0') {
            $message = 'No handler for this ajax action (0)';
        }

        self::record([
            'source'        => 'ajax',
            'method'        => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'route'         => 'admin-ajax.php?action=' . preg_replace('/[^A-Za-z0-9_\/\-]/', '', (string) ($_REQUEST['action'] ?? '')),
            'status'        => $status ?: 200,
            'error_code'    => is_array($data) ? (string) ($data['code'] ?? ($data[0]['code'] ?? '')) : '',
            'error_message' => $message,
            'error_data'    => self::truncate(is_array($decoded) ? $decoded : mb_strcut($body, 0, 500)),
            'post_id'       => self::postIdFromRequestVars(),
            'request_bytes' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function query(string $since = '', string $user = '', int $postId = 0, string $route = '', int $limit = 50): array
    {
        $entries = get_option(self::OPTION, []);
        if (! is_array($entries)) {
            return [];
        }

        $sinceTs = $since !== '' ? strtotime($since) : false;

        $entries = array_filter($entries, function (array $entry) use ($sinceTs, $user, $postId, $route): bool {
            if ($sinceTs !== false && strtotime((string) $entry['time']) < $sinceTs) {
                return false;
            }
            if ($user !== '' && (string) $entry['user_id'] !== $user && $entry['user_login'] !== $user) {
                return false;
            }
            if ($postId > 0 && (int) ($entry['post_id'] ?? 0) !== $postId) {
                return false;
            }
            if ($route !== '' && stripos((string) $entry['route'], $route) === false) {
                return false;
            }
            return true;
        });

        return array_slice(array_reverse(array_values($entries)), 0, max(1, $limit));
    }

    private static function record(array $entry): void
    {
        $userId = get_current_user_id();
        $user = $userId ? get_userdata($userId) : null;

        $entry = [
            'time'       => gmdate('c'),
            'user_id'    => $userId,
            'user_login' => $user ? $user->user_login : null,
            'via'        => self::$via,
        ] + $entry;

        if (self::$deferred !== null) {
            self::$deferred[] = $entry;
            return;
        }

        self::persist($entry);
    }

    private static function persist(array $entry): void
    {
        $entries = get_option(self::OPTION, null);
        if (! is_array($entries)) {
            add_option(self::OPTION, [$entry], '', 'no');
            return;
        }
        $entries[] = $entry;
        update_option(self::OPTION, array_slice($entries, -self::MAX_ENTRIES), false);
    }

    private static function isWriteMethod(): bool
    {
        return in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), self::WRITE_METHODS, true);
    }

    private static function currentRoute(): string
    {
        if (defined('REST_REQUEST') && REST_REQUEST && ! empty($GLOBALS['wp']->query_vars['rest_route'])) {
            return (string) $GLOBALS['wp']->query_vars['rest_route'];
        }
        return (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    private static function postIdFromRest(string $route, \WP_REST_Request $request): ?int
    {
        if (preg_match('#/(\d+)(?:/|$)#', $route, $m)) {
            return (int) $m[1];
        }
        foreach (['id', 'post', 'post_id'] as $key) {
            $value = $request->get_param($key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        return null;
    }

    private static function postIdFromRequestVars(): ?int
    {
        foreach (['post_id', 'post_ID', 'post', 'id'] as $key) {
            if (isset($_REQUEST[$key]) && is_numeric($_REQUEST[$key])) {
                return (int) $_REQUEST[$key];
            }
        }
        if (preg_match('#/(\d+)(?:/|\?|$)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private static function truncate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        return strlen($encoded) > 2000 ? mb_strcut($encoded, 0, 2000, 'UTF-8') . '…' : $value;
    }
}
