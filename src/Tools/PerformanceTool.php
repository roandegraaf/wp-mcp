<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\Profiler;
use WpMcp\Helpers\ResponseFormatter;

class PerformanceTool extends AbstractTool
{
    /**
     * Profile a page's network/server timing and, for same-site URLs, its PHP and database internals.
     */
    #[McpTool(
        name: 'wp_profile_url',
        description: 'Profile a page for performance. Returns network/server timing (DNS, connect, SSL, TTFB, total, download size) and header analysis (compression, cache headers, page-cache detection) for any URL. For URLs on this site it also fires a loopback request to capture PHP execution time, peak memory, DB query count/time, the slowest queries and time grouped by calling function. NOTE: same-site loopback profiling can hang on hosts running a single PHP-FPM worker; if so the PHP internals are omitted and a flag is set.'
    )]
    public function profileUrl(
        #[Schema(description: 'URL to profile. Defaults to the site home URL.')]
        string $url = '',
        #[Schema(description: 'Follow redirects before measuring the final page.')]
        bool $follow_redirects = true,
        #[Schema(description: 'Request timeout in seconds.', minimum: 1, maximum: 30)]
        int $timeout = 15,
    ): string {
        $url = trim($url);
        if ($url === '') {
            $url = home_url('/');
        }

        $url = esc_url_raw($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('A valid http(s) URL is required.');
        }

        $timeout = min(max(1, $timeout), 30);
        $sameSite = $this->isSameSite($url);

        $fetchUrl = $url;
        $token = null;
        if ($sameSite) {
            $token = Profiler::issueToken();
            $fetchUrl = add_query_arg('wp_mcp_profile', $token, $url);
        }

        $fetch = $this->fetch($fetchUrl, $follow_redirects, $timeout, $sameSite);

        $data = [
            'url'           => $url,
            'same_site'     => $sameSite,
            'status'        => $fetch['status'],
            'network'       => $fetch['timing'],
            'response'      => $fetch['response'],
        ];

        $flags = $this->buildFlags($fetch);

        if ($sameSite && $token !== null) {
            // The loopback's shutdown hook writes the result; on some stacks it
            // lands just after curl returns, so poll briefly before giving up.
            $php = null;
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $php = Profiler::readResult($token);
                if ($php !== null) {
                    break;
                }
                usleep(150000);
            }
            if ($php !== null) {
                $data['php'] = $php;
                if ($php['php_time_ms'] !== null && $php['php_time_ms'] > 1000) {
                    $flags[] = 'slow_php_time';
                }
                if (! $php['queries_logged']) {
                    $flags[] = 'query_logging_unavailable';
                }
            } else {
                $flags[] = 'php_profiling_unavailable';
                $data['php'] = null;
            }
        }

        $data['flags'] = array_values(array_unique($flags));

        return ResponseFormatter::toJson($data);
    }

    private function isSameSite(string $url): bool
    {
        $target = wp_parse_url($url, PHP_URL_HOST);
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_string($target) && is_string($home) && strcasecmp($target, $home) === 0;
    }

    /**
     * Fetch the URL with detailed timing. Uses curl when available for a
     * per-phase breakdown, falling back to the WP HTTP API otherwise.
     */
    private function fetch(string $url, bool $followRedirects, int $timeout, bool $sameSite): array
    {
        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($url, $followRedirects, $timeout, $sameSite);
        }
        return $this->fetchWithWpHttp($url, $followRedirects, $timeout);
    }

    private function fetchWithCurl(string $url, bool $followRedirects, int $timeout, bool $sameSite): array
    {
        $headers = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_ENCODING       => '', // advertise gzip/br so we can see if the server compresses
            CURLOPT_USERAGENT      => 'WP-MCP-Profiler/1.0',
            // Loopback to our own host may use a local/self-signed cert; don't fail on it.
            CURLOPT_SSL_VERIFYPEER => ! $sameSite,
            CURLOPT_SSL_VERIFYHOST => $sameSite ? 0 : 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $headers = []; // reset on each response (handles redirects)
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $pos)));
                    $headers[$name] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($body === false && $error !== '') {
            throw new \RuntimeException('Request failed: ' . $error);
        }

        $isHttps = stripos($url, 'https://') === 0;

        $timing = [
            'dns_ms'        => $this->ms($info['namelookup_time'] ?? 0),
            'connect_ms'    => $this->ms(($info['connect_time'] ?? 0) - ($info['namelookup_time'] ?? 0)),
            'ssl_ms'        => $isHttps ? $this->ms(($info['appconnect_time'] ?? 0) - ($info['connect_time'] ?? 0)) : 0.0,
            'ttfb_ms'       => $this->ms($info['starttransfer_time'] ?? 0),
            'total_ms'      => $this->ms($info['total_time'] ?? 0),
            'redirect_ms'   => $this->ms($info['redirect_time'] ?? 0),
        ];

        return [
            'status'   => (int) ($info['http_code'] ?? 0),
            'timing'   => $timing,
            'headers'  => $headers,
            'response' => [
                'final_url'      => $info['url'] ?? $url,
                'redirect_count' => (int) ($info['redirect_count'] ?? 0),
                'download_kb'    => round((float) ($info['size_download'] ?? 0) / 1024, 2),
                'speed_kbps'     => round((float) ($info['speed_download'] ?? 0) / 1024, 2),
                'content_type'   => $headers['content-type'] ?? ($info['content_type'] ?? null),
                'content_encoding' => $headers['content-encoding'] ?? null,
                'server'         => $headers['server'] ?? null,
            ],
            'transport' => 'curl',
        ];
    }

    private function fetchWithWpHttp(string $url, bool $followRedirects, int $timeout): array
    {
        $start = microtime(true);
        $response = wp_remote_get($url, [
            'timeout'     => $timeout,
            'redirection' => $followRedirects ? 5 : 0,
            'user-agent'  => 'WP-MCP-Profiler/1.0',
            'sslverify'   => false,
        ]);
        $totalMs = (microtime(true) - $start) * 1000;

        if (is_wp_error($response)) {
            throw new \RuntimeException('Request failed: ' . $response->get_error_message());
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($response)->getAll() as $name => $value) {
            $headers[strtolower($name)] = is_array($value) ? implode(', ', $value) : $value;
        }
        $body = (string) wp_remote_retrieve_body($response);

        return [
            'status'  => (int) wp_remote_retrieve_response_code($response),
            'timing'  => [
                'dns_ms'      => null,
                'connect_ms'  => null,
                'ssl_ms'      => null,
                'ttfb_ms'     => null,
                'total_ms'    => round($totalMs, 2),
                'redirect_ms' => null,
            ],
            'headers'  => $headers,
            'response' => [
                'final_url'        => $url,
                'redirect_count'   => null,
                'download_kb'      => round(strlen($body) / 1024, 2),
                'speed_kbps'       => null,
                'content_type'     => $headers['content-type'] ?? null,
                'content_encoding' => $headers['content-encoding'] ?? null,
                'server'           => $headers['server'] ?? null,
            ],
            'transport' => 'wp_http (no per-phase timing; curl unavailable)',
        ];
    }

    /**
     * Turn raw measurements/headers into actionable performance flags.
     */
    private function buildFlags(array $fetch): array
    {
        $flags = [];
        $headers = $fetch['headers'];
        $timing = $fetch['timing'];

        if ($fetch['status'] >= 400) {
            $flags[] = 'http_error_' . $fetch['status'];
        }

        if (isset($timing['ttfb_ms']) && $timing['ttfb_ms'] !== null && $timing['ttfb_ms'] > 600) {
            $flags[] = 'slow_ttfb';
        }

        $encoding = strtolower((string) ($headers['content-encoding'] ?? ''));
        if (! str_contains($encoding, 'gzip') && ! str_contains($encoding, 'br') && ! str_contains($encoding, 'deflate')) {
            $flags[] = 'no_compression';
        }

        $hasCacheControl = isset($headers['cache-control']) && stripos($headers['cache-control'], 'no-store') === false;
        if (! $hasCacheControl && ! isset($headers['expires'])) {
            $flags[] = 'no_cache_headers';
        }

        $pageCache = $this->detectPageCache($headers);
        if ($pageCache !== null) {
            $flags[] = 'page_cache:' . $pageCache;
        }

        $downloadKb = $fetch['response']['download_kb'] ?? 0;
        if (is_numeric($downloadKb) && $downloadKb > 2048) {
            $flags[] = 'large_payload';
        }

        if (($fetch['response']['redirect_count'] ?? 0) > 1) {
            $flags[] = 'redirect_chain';
        }

        return $flags;
    }

    private function detectPageCache(array $headers): ?string
    {
        $signals = [
            'x-litespeed-cache' => 'litespeed',
            'cf-cache-status'   => 'cloudflare',
            'x-cache'           => 'proxy',
            'x-proxy-cache'     => 'proxy',
            'x-cache-enabled'   => 'wp-rocket',
            'x-fastcgi-cache'   => 'fastcgi',
            'x-nginx-cache'     => 'nginx',
        ];
        foreach ($signals as $header => $label) {
            if (isset($headers[$header])) {
                return $label . ' (' . $headers[$header] . ')';
            }
        }
        if (isset($headers['x-powered-by']) && stripos($headers['x-powered-by'], 'w3-total-cache') !== false) {
            return 'w3-total-cache';
        }
        return null;
    }

    private function ms(float $seconds): float
    {
        return round(max(0.0, $seconds) * 1000, 2);
    }
}
