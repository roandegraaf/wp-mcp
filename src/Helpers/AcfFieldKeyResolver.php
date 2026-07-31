<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Resolves ACF field names to field keys within the scope of a single block.
 *
 * ACF blocks store every value alongside a companion `_<name>` entry holding the
 * field key. Resolving those names globally (acf_get_field('subtitle')) returns
 * whichever field group happens to define that name first, which silently writes
 * keys belonging to entirely unrelated blocks. Every lookup here is therefore
 * constrained to the field groups whose location rules target this block.
 *
 * Keys are always taken verbatim from acf_get_fields(). ACF splices seamless
 * clone fields into their parent group and rewrites their keys to the composite
 * `field_<cloneKey>_field_<originalKey>` form, so reading the resolved tree
 * preserves that format for free; it must never be reconstructed by hand.
 */
class AcfFieldKeyResolver
{
    /**
     * Resolved field trees, keyed by block name.
     *
     * @var array<string, array<int, array>>
     */
    private static array $cache = [];

    /**
     * Whether ACF exposes the APIs needed to scope a lookup to a block.
     */
    public static function isAvailable(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields');
    }

    /**
     * Whether any field group's location rules target this block.
     */
    public static function hasFieldGroups(string $blockName): bool
    {
        return self::getFields($blockName) !== [];
    }

    /**
     * Resolve a stored data key to its field key, or null when it does not
     * belong to any of this block's own field groups.
     */
    public static function resolveKey(string $blockName, string $dataKey): ?string
    {
        $field = self::resolveField($blockName, $dataKey);

        $key = $field['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Resolve a stored data key to its full field definition.
     *
     * Handles ACF's flattened block storage, where nested values are stored as
     * `<repeater>_<index>_<subfield>` at arbitrary depth.
     */
    public static function resolveField(string $blockName, string $dataKey): ?array
    {
        return self::lookup($dataKey, self::getFields($blockName));
    }

    /**
     * Build an actionable error for a name that could not be resolved.
     */
    public static function describeFailure(string $blockName, string $dataKey): string
    {
        if (! self::hasFieldGroups($blockName)) {
            return sprintf(
                'No ACF field group targets block "%s", so the field key for "%s" cannot be resolved. '
                . 'Refusing to write a field key borrowed from another block\'s field group. '
                . 'Check that the block is registered and its field group is active in this request '
                . '(ACF Composer blocks may need "wp acorn cache:clear"), or pass the key explicitly as "_%s".',
                $blockName,
                $dataKey,
                $dataKey,
            );
        }

        $known = self::topLevelNames($blockName);

        return sprintf(
            'Field "%s" is not defined in any field group targeting block "%s", so its field key cannot be resolved. '
            . 'Refusing to write a field key borrowed from another block\'s field group. '
            . 'Top-level fields for this block: %s. '
            . 'Sub-fields are addressed flattened, e.g. "repeater_0_subfield". '
            . 'To bypass resolution, pass the key explicitly as "_%s".',
            $dataKey,
            $blockName,
            $known === [] ? '(none)' : implode(', ', $known),
            $dataKey,
        );
    }

    /**
     * Top-level field names defined for this block, for error messages.
     *
     * @return array<int, string>
     */
    public static function topLevelNames(string $blockName): array
    {
        $names = [];
        foreach (self::getFields($blockName) as $field) {
            $name = $field['name'] ?? '';
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Drop cached field trees. Exposed for tests and long-running processes.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Load and cache the merged field tree for every group targeting this block.
     *
     * @return array<int, array>
     */
    private static function getFields(string $blockName): array
    {
        if (isset(self::$cache[$blockName])) {
            return self::$cache[$blockName];
        }

        self::$cache[$blockName] = self::loadFields($blockName);

        return self::$cache[$blockName];
    }

    /**
     * @return array<int, array>
     */
    private static function loadFields(string $blockName): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        // ACF's block location type matches on $screen['block'], and treats a
        // rule value of "all" as matching every block, so catch-all groups are
        // included here exactly as they are in the editor.
        $groups = acf_get_field_groups(['block' => $blockName]);
        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $fields = [];
        foreach ($groups as $group) {
            $groupFields = acf_get_fields($group);
            if (is_array($groupFields) && $groupFields !== []) {
                $fields = array_merge($fields, $groupFields);
            }
        }

        return $fields;
    }

    /**
     * Walk the field tree looking for the definition behind a flattened data key.
     *
     * Exact matches win at every level before any prefix descent is attempted,
     * so a field literally named "buttons_0_color" is preferred over descending
     * into a "buttons" repeater.
     *
     * @param array<int, array> $fields
     */
    private static function lookup(string $dataKey, array $fields): ?array
    {
        if ($dataKey === '' || $fields === []) {
            return null;
        }

        foreach ($fields as $field) {
            if (($field['name'] ?? '') === $dataKey) {
                return $field;
            }
        }

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if (! is_string($name) || $name === '' || ! str_starts_with($dataKey, $name . '_')) {
                continue;
            }

            $children = self::childFields($field);
            if ($children === []) {
                continue;
            }

            $remainder = substr($dataKey, strlen($name) + 1);

            // Repeater and flexible-content rows are stored as <name>_<index>_<sub>.
            if (preg_match('/^\d+_(.+)$/', $remainder, $matches) === 1) {
                $found = self::lookup($matches[1], $children);
                if ($found !== null) {
                    return $found;
                }
            }

            // Group fields carry no row index.
            $found = self::lookup($remainder, $children);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Sub-fields of a field, including every flexible-content layout.
     *
     * @return array<int, array>
     */
    private static function childFields(array $field): array
    {
        $children = [];

        if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            $children = $field['sub_fields'];
        }

        if (! empty($field['layouts']) && is_array($field['layouts'])) {
            foreach ($field['layouts'] as $layout) {
                if (! empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                    $children = array_merge($children, $layout['sub_fields']);
                }
            }
        }

        return $children;
    }
}
