<?php

declare(strict_types=1);

namespace WpMcp;

class Connection
{
    public static function endpointUrl(): string
    {
        $url = rest_url('wp-mcp/v1/mcp');

        if (! self::isLocalDomain()) {
            return $url;
        }

        $parsed = wp_parse_url($url);
        if (! $parsed || ($parsed['scheme'] ?? '') !== 'https') {
            return $url;
        }

        // Local dev with HTTPS: MCP clients (Node.js) reject self-signed certs.
        // Laravel Herd/Valet exposes secured sites on port 60 over plain HTTP.
        $httpUrl = 'http://' . $parsed['host'] . ':60';
        if (! empty($parsed['path'])) {
            $httpUrl .= $parsed['path'];
        }
        if (! empty($parsed['query'])) {
            $httpUrl .= '?' . $parsed['query'];
        }

        $test = @fsockopen($parsed['host'], 60, $errno, $errstr, 1);
        if ($test) {
            fclose($test);
            return $httpUrl;
        }

        return $url;
    }

    public static function isLocalDomain(): bool
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?? '';
        $localTlds = ['.test', '.local', '.localhost', '.invalid', '.example'];

        foreach ($localTlds as $tld) {
            if (str_ends_with($host, $tld)) {
                return true;
            }
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function claudeCommand(string $url, string $token, string $serverName = 'wordpress'): string
    {
        return sprintf(
            'claude mcp add %s \'%s\' -t http -H "Authorization: Bearer %s"',
            $serverName,
            $url,
            $token,
        );
    }

    public static function configJson(string $url, string $token, string $serverName = 'wordpress'): string
    {
        $config = [
            'mcpServers' => [
                $serverName => [
                    'type'    => 'streamable-http',
                    'url'     => $url,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ],
            ],
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
