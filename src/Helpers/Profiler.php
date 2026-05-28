<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Captures PHP/DB profiling data for a single front-end request.
 *
 * The PerformanceTool fires a loopback request to a same-site URL carrying a
 * one-time token (?wp_mcp_profile=<token>). When that request is served,
 * maybeInstrument() recognises the token, turns on query logging, and on
 * shutdown stores the collected metrics in a short-lived transient that the
 * tool reads back.
 */
class Profiler
{
    private const TOKEN_TRANSIENT  = 'wp_mcp_profile_token_';
    private const RESULT_TRANSIENT = 'wp_mcp_profile_result_';
    private const RESULT_TTL       = 60;

    private static ?string $token = null;
    private static float $startMem = 0.0;

    /**
     * Call as early as possible (during plugin load) so query logging is
     * enabled before the theme/template runs its queries.
     */
    public static function maybeInstrument(): void
    {
        if (empty($_GET['wp_mcp_profile'])) {
            return;
        }

        $token = (string) $_GET['wp_mcp_profile'];
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }

        // Token must have been issued by the tool and is single-use.
        if (get_transient(self::TOKEN_TRANSIENT . $token) === false) {
            return;
        }
        delete_transient(self::TOKEN_TRANSIENT . $token);

        global $wpdb;
        if (isset($wpdb)) {
            // Toggle directly: SAVEQUERIES is read in wpdb's constructor,
            // which already ran by the time plugins load.
            $wpdb->save_queries = true;
        }

        self::$token = $token;
        self::$startMem = (float) memory_get_usage();

        add_action('shutdown', [self::class, 'captureShutdown'], PHP_INT_MAX);
    }

    public static function captureShutdown(): void
    {
        if (self::$token === null) {
            return;
        }

        global $wpdb;

        $requestStart = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : null;
        $phpTimeMs = $requestStart !== null
            ? (microtime(true) - $requestStart) * 1000
            : null;

        $queries = (isset($wpdb) && is_array($wpdb->queries)) ? $wpdb->queries : [];

        $totalQueryTime = 0.0;
        $byCaller = [];
        $detailed = [];

        foreach ($queries as $q) {
            $sql    = (string) ($q[0] ?? '');
            $time   = (float) ($q[1] ?? 0);
            $caller = (string) ($q[2] ?? '');
            $totalQueryTime += $time;

            $immediate = self::immediateCaller($caller);
            if (! isset($byCaller[$immediate])) {
                $byCaller[$immediate] = ['caller' => $immediate, 'count' => 0, 'time_ms' => 0.0];
            }
            $byCaller[$immediate]['count']++;
            $byCaller[$immediate]['time_ms'] += $time * 1000;

            $detailed[] = [
                'sql'     => self::truncate($sql, 300),
                'time_ms' => round($time * 1000, 2),
                'caller'  => $immediate,
            ];
        }

        // Top callers by aggregate time.
        usort($byCaller, fn($a, $b) => $b['time_ms'] <=> $a['time_ms']);
        $byCaller = array_slice($byCaller, 0, 10);
        foreach ($byCaller as &$c) {
            $c['time_ms'] = round($c['time_ms'], 2);
        }
        unset($c);

        // Slowest individual queries.
        usort($detailed, fn($a, $b) => $b['time_ms'] <=> $a['time_ms']);
        $slowest = array_slice($detailed, 0, 10);

        $result = [
            'php_time_ms'        => $phpTimeMs !== null ? round($phpTimeMs, 2) : null,
            'peak_memory_mb'     => round(memory_get_peak_usage(true) / 1048576, 2),
            'query_count'        => count($queries),
            'query_time_ms'      => round($totalQueryTime * 1000, 2),
            'queries_logged'     => ! empty($queries),
            'slowest_queries'    => $slowest,
            'time_by_caller'     => array_values($byCaller),
            'object_cache'       => wp_using_ext_object_cache(),
        ];

        set_transient(self::RESULT_TRANSIENT . self::$token, $result, self::RESULT_TTL);
    }

    public static function issueToken(): string
    {
        $token = bin2hex(random_bytes(16));
        set_transient(self::TOKEN_TRANSIENT . $token, 1, self::RESULT_TTL);
        return $token;
    }

    public static function readResult(string $token): ?array
    {
        $key = self::RESULT_TRANSIENT . $token;
        $result = get_transient($key);
        if ($result === false) {
            return null;
        }
        delete_transient($key);
        return is_array($result) ? $result : null;
    }

    /**
     * Extract the most specific function from wpdb's caller chain.
     */
    private static function immediateCaller(string $caller): string
    {
        if ($caller === '') {
            return 'unknown';
        }
        $parts = array_map('trim', explode(',', $caller));
        $last = end($parts);
        return $last !== false && $last !== '' ? $last : 'unknown';
    }

    private static function truncate(string $value, int $length): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }
}
