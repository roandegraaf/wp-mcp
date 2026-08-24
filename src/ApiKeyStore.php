<?php

declare(strict_types=1);

namespace WpMcp;

class ApiKeyStore
{
    public const OPTION = 'wp_mcp_api_keys';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $keys = get_option(self::OPTION, []);

        return is_array($keys) ? $keys : [];
    }

    /**
     * Create a key and return the plaintext token. The token is not recoverable afterwards.
     */
    public static function create(string $name): string
    {
        $token = wp_generate_password(32, false);

        $keys = self::all();
        $keys[] = [
            'id'           => wp_generate_password(12, false),
            'name'         => $name,
            'hash'         => wp_hash_password($token),
            'created_at'   => time(),
            'last_used_at' => null,
        ];

        update_option(self::OPTION, $keys);

        return $token;
    }

    public static function revokeById(string $id): void
    {
        $keys = array_values(array_filter(
            self::all(),
            fn(array $key): bool => ($key['id'] ?? '') !== $id,
        ));

        update_option(self::OPTION, $keys);
    }

    /**
     * Revoke every key with the given name and return how many were removed.
     */
    public static function revokeByName(string $name): int
    {
        $keys = self::all();
        $remaining = array_values(array_filter(
            $keys,
            fn(array $key): bool => ($key['name'] ?? '') !== $name,
        ));

        $removed = count($keys) - count($remaining);
        if ($removed > 0) {
            update_option(self::OPTION, $remaining);
        }

        return $removed;
    }
}
