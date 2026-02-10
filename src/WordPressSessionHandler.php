<?php

declare(strict_types=1);

namespace WpMcp;

use PhpMcp\Server\Contracts\SessionHandlerInterface;

/**
 * Persists MCP sessions to WordPress transients so they survive across HTTP requests.
 */
class WordPressSessionHandler implements SessionHandlerInterface
{
    private const PREFIX = 'mcp_sess_';

    public function __construct(private int $ttl = 3600)
    {
    }

    public function read(string $id): string|false
    {
        $data = get_transient(self::PREFIX . $id);

        return $data !== false ? $data : false;
    }

    public function write(string $id, string $data): bool
    {
        return set_transient(self::PREFIX . $id, $data, $this->ttl);
    }

    public function destroy(string $id): bool
    {
        return delete_transient(self::PREFIX . $id);
    }

    public function gc(int $maxLifetime): array
    {
        // WordPress handles transient expiry automatically.
        return [];
    }
}
