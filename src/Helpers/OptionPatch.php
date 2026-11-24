<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Applies a JSON payload to an option that is stored as an array or object,
 * keeping its type. Merging recurses into associative arrays; lists, scalars
 * and empty objects replace the stored value at their key.
 */
final class OptionPatch
{
    /**
     * @return array{value: array<mixed>|object, changed_keys: list<string>}
     */
    public static function apply(array|object $current, string $json, bool $merge): array
    {
        $patch = json_decode($json, true);
        if (! is_array($patch)) {
            throw new \InvalidArgumentException(
                'This option is stored as ' . (is_object($current) ? 'an object' : 'an array')
                . '. Pass a JSON object (or array with merge=false) instead of a plain value; a string would overwrite the whole option.'
            );
        }

        if ($merge && $patch !== [] && array_is_list($patch)) {
            throw new \InvalidArgumentException(
                'merge=true needs a JSON object of keys to change. To replace the whole option with a list, pass merge=false.'
            );
        }

        $base = is_object($current) ? get_object_vars($current) : $current;
        $result = $merge ? self::mergeRecursive($base, $patch) : $patch;

        return [
            'value'        => is_object($current) ? (object) $result : $result,
            'changed_keys' => self::changedKeys($base, $result),
        ];
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    public static function mergeRecursive(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $existing = $base[$key] ?? null;
            $base[$key] = self::isAssoc($value) && self::isAssoc($existing)
                ? self::mergeRecursive($existing, $value)
                : $value;
        }
        return $base;
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     * @return list<string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $changed[] = (string) $key;
            }
        }
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                $changed[] = (string) $key;
            }
        }
        return $changed;
    }

    private static function isAssoc(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
