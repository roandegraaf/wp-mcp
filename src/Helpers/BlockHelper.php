<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

class BlockHelper
{
    /**
     * Get the fully rendered HTML for a post, including ACF blocks.
     * Uses WordPress's the_content filter to render all block types.
     */
    public static function getRenderedContent(int $postId): string
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        return apply_filters('the_content', $post->post_content);
    }

    /**
     * Parse post content into structured block array.
     * For ACF blocks, extracts field data from block attributes.
     */
    public static function parseBlocks(int $postId, bool $compact = false): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        $blocks = parse_blocks($post->post_content);
        $result = [];
        $index = 0;

        foreach ($blocks as $block) {
            // Skip empty/whitespace-only blocks
            if (empty($block['blockName'])) {
                continue;
            }

            $isAcf = str_starts_with($block['blockName'], 'acf/');

            $formatted = [
                'index'     => $index,
                'name'      => $block['blockName'],
            ];

            // Compact mode drops attrs.data for ACF blocks: it duplicates
            // acf_data with field-key companions and dominates the payload.
            if (! ($compact && $isAcf)) {
                $formatted['attrs'] = $block['attrs'] ?? [];
            }

            // For ACF blocks, extract clean field data
            if ($isAcf) {
                $formatted['acf_data'] = self::extractAcfBlockData($block);
            }

            // Include inner blocks if present
            if (! empty($block['innerBlocks'])) {
                $formatted['inner_blocks'] = array_map(function ($innerBlock) {
                    return [
                        'name'  => $innerBlock['blockName'],
                        'attrs' => $innerBlock['attrs'] ?? [],
                    ];
                }, $block['innerBlocks']);
            }

            $result[] = $formatted;
            $index++;
        }

        return $result;
    }

    /**
     * Extract ACF field data from a block's attributes.
     */
    private static function extractAcfBlockData(array $block): array
    {
        $data = $block['attrs']['data'] ?? [];
        if (empty($data)) {
            return [];
        }

        // ACF blocks store data with field key prefixes.
        // Clean up to show only field name => value pairs.
        $cleaned = [];
        foreach ($data as $key => $value) {
            // Skip field key references (start with _ and map to field_xxx)
            if (str_starts_with($key, '_') && is_string($value) && str_starts_with($value, 'field_')) {
                continue;
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    /**
     * Per-block diff between two parse_blocks() results, aligned by index.
     * ACF blocks compare their cleaned field data; other blocks compare attrs
     * and inner HTML.
     *
     * ponytail: index alignment — an inserted block shifts every later block
     * into "replaced/changed"; add LCS alignment by block name if that bites.
     *
     * @return array{summary: array<string, int>, blocks: list<array<string, mixed>>}
     */
    public static function diffParsedBlocks(array $from, array $to): array
    {
        $from = array_values(array_filter($from, fn($b) => ! empty($b['blockName'])));
        $to = array_values(array_filter($to, fn($b) => ! empty($b['blockName'])));

        $summary = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'replaced' => 0];
        $rows = [];
        $count = max(count($from), count($to));

        for ($i = 0; $i < $count; $i++) {
            $a = $from[$i] ?? null;
            $b = $to[$i] ?? null;

            if ($a === null) {
                $summary['added']++;
                $rows[] = ['index' => $i, 'status' => 'added', 'name' => $b['blockName'], 'data' => self::blockPayload($b)];
                continue;
            }
            if ($b === null) {
                $summary['removed']++;
                $rows[] = ['index' => $i, 'status' => 'removed', 'name' => $a['blockName'], 'data' => self::blockPayload($a)];
                continue;
            }
            if ($a['blockName'] !== $b['blockName']) {
                $summary['replaced']++;
                $rows[] = [
                    'index'  => $i,
                    'status' => 'replaced',
                    'from'   => ['name' => $a['blockName'], 'data' => self::blockPayload($a)],
                    'to'     => ['name' => $b['blockName'], 'data' => self::blockPayload($b)],
                ];
                continue;
            }

            $fields = self::diffAssoc(self::blockPayload($a), self::blockPayload($b));
            $innerChanged = trim((string) ($a['innerHTML'] ?? '')) !== trim((string) ($b['innerHTML'] ?? ''));
            $innerBlocksChanged = serialize($a['innerBlocks'] ?? []) !== serialize($b['innerBlocks'] ?? []);

            if ($fields === [] && ! $innerChanged && ! $innerBlocksChanged) {
                $summary['unchanged']++;
                continue;
            }

            $summary['changed']++;
            $row = ['index' => $i, 'status' => 'changed', 'name' => $a['blockName']];
            if ($fields !== []) {
                $row['fields'] = $fields;
            }
            if ($innerChanged) {
                $row['inner_html'] = [
                    'from' => self::clip((string) ($a['innerHTML'] ?? '')),
                    'to'   => self::clip((string) ($b['innerHTML'] ?? '')),
                ];
            }
            if ($innerBlocksChanged) {
                $row['inner_blocks_changed'] = true;
            }
            $rows[] = $row;
        }

        return ['summary' => $summary, 'blocks' => $rows];
    }

    /**
     * The comparable payload of a parsed block: cleaned ACF data, or attrs.
     */
    private static function blockPayload(array $block): array
    {
        if (str_starts_with((string) $block['blockName'], 'acf/')) {
            return self::extractAcfBlockData($block);
        }
        return $block['attrs'] ?? [];
    }

    /**
     * @return array<string, array{status: string, from?: mixed, to?: mixed}>
     */
    private static function diffAssoc(array $from, array $to): array
    {
        $diff = [];
        foreach ($to as $key => $value) {
            if (! array_key_exists($key, $from)) {
                $diff[$key] = ['status' => 'added', 'to' => self::clip($value)];
            } elseif ($from[$key] != $value || gettype($from[$key]) !== gettype($value) && (string) $from[$key] !== (string) $value) {
                $diff[$key] = ['status' => 'changed', 'from' => self::clip($from[$key]), 'to' => self::clip($value)];
            }
        }
        foreach ($from as $key => $value) {
            if (! array_key_exists($key, $to)) {
                $diff[$key] = ['status' => 'removed', 'from' => self::clip($value)];
            }
        }
        return $diff;
    }

    private static function clip(mixed $value, int $max = 500): mixed
    {
        if (is_string($value) && strlen($value) > $max) {
            return mb_strcut($value, 0, $max, 'UTF-8') . sprintf('…[%d chars]', strlen($value));
        }
        return $value;
    }

    /**
     * Update a block at a specific index and save the post.
     */
    public static function updateBlock(int $postId, int $blockIndex, array $newData): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        $blocks = parse_blocks($post->post_content);

        // Filter to real blocks (with names) and find the target
        $realBlocks = [];
        $rawIndex = 0;
        $targetRawIndex = null;

        foreach ($blocks as $i => $block) {
            if (! empty($block['blockName'])) {
                if ($rawIndex === $blockIndex) {
                    $targetRawIndex = $i;
                }
                $rawIndex++;
            }
        }

        if ($targetRawIndex === null) {
            throw new \RuntimeException("Block index {$blockIndex} not found. Post has {$rawIndex} blocks.");
        }

        $targetBlock = $blocks[$targetRawIndex];

        // For ACF blocks, merge data into attrs.data
        if (str_starts_with($targetBlock['blockName'] ?? '', 'acf/')) {
            $existingData = $targetBlock['attrs']['data'] ?? [];
            $prepared     = self::prepareAcfBlockData($newData, $targetBlock['blockName'], $existingData);

            // Overlay rather than array_merge: the latter renumbers integer-like
            // keys, and appending in place keeps untouched entries in their
            // original order so the serialised attrs stay diff-clean.
            $merged = $existingData;
            foreach ($prepared as $preparedKey => $preparedValue) {
                $merged[$preparedKey] = $preparedValue;
            }

            $blocks[$targetRawIndex]['attrs']['data'] = $merged;
        } else {
            // For regular blocks, merge into attrs
            $blocks[$targetRawIndex]['attrs'] = array_merge(
                $blocks[$targetRawIndex]['attrs'] ?? [],
                $newData
            );
        }

        $newContent = serialize_blocks($blocks);

        self::savePostContent($postId, $newContent);

        return [
            'success'     => true,
            'block_index' => $blockIndex,
            'block_name'  => $targetBlock['blockName'],
        ];
    }

    /**
     * Insert a new block at a specified position.
     */
    public static function insertBlock(int $postId, string $blockName, array $blockData, int $position = -1): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        $blocks = parse_blocks($post->post_content);

        // Build the new block
        $newBlock = [
            'blockName'    => $blockName,
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ];

        // For ACF blocks, set data in attrs
        if (str_starts_with($blockName, 'acf/')) {
            $newBlock['attrs']['data'] = self::prepareAcfBlockData($blockData, $blockName);
            $newBlock['attrs']['name'] = $blockName;
        } else {
            $newBlock['attrs'] = $blockData;
        }

        // Calculate real position in raw blocks array
        if ($position < 0) {
            $blocks[] = $newBlock;
        } else {
            $realIndex = 0;
            $insertAt = count($blocks);

            foreach ($blocks as $i => $block) {
                if (! empty($block['blockName'])) {
                    if ($realIndex === $position) {
                        $insertAt = $i;
                        break;
                    }
                    $realIndex++;
                }
            }

            array_splice($blocks, $insertAt, 0, [$newBlock]);
        }

        $newContent = serialize_blocks($blocks);

        self::savePostContent($postId, $newContent);

        return [
            'success'    => true,
            'block_name' => $blockName,
            'position'   => $position < 0 ? 'end' : $position,
        ];
    }

    /**
     * Delete a block at a specific index.
     */
    public static function deleteBlock(int $postId, int $blockIndex): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        $blocks = parse_blocks($post->post_content);

        $rawIndex = 0;
        $targetRawIndex = null;
        $blockName = null;

        foreach ($blocks as $i => $block) {
            if (! empty($block['blockName'])) {
                if ($rawIndex === $blockIndex) {
                    $targetRawIndex = $i;
                    $blockName = $block['blockName'];
                }
                $rawIndex++;
            }
        }

        if ($targetRawIndex === null) {
            throw new \RuntimeException("Block index {$blockIndex} not found. Post has {$rawIndex} blocks.");
        }

        array_splice($blocks, $targetRawIndex, 1);

        $newContent = serialize_blocks($blocks);
        self::savePostContent($postId, $newContent);

        return [
            'success'     => true,
            'deleted'     => $blockName,
            'block_index' => $blockIndex,
            'remaining'   => $rawIndex - 1,
        ];
    }

    /**
     * Move a block from one position to another.
     */
    public static function moveBlock(int $postId, int $fromIndex, int $toIndex): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new \RuntimeException("Post not found: {$postId}");
        }

        $blocks = parse_blocks($post->post_content);

        // Map logical indices to raw indices
        $indexMap = [];
        $realIndex = 0;
        foreach ($blocks as $i => $block) {
            if (! empty($block['blockName'])) {
                $indexMap[$realIndex] = $i;
                $realIndex++;
            }
        }

        $totalBlocks = $realIndex;

        if (! isset($indexMap[$fromIndex])) {
            throw new \RuntimeException("From index {$fromIndex} not found. Post has {$totalBlocks} blocks.");
        }
        if ($toIndex < 0 || $toIndex >= $totalBlocks) {
            throw new \RuntimeException("To index {$toIndex} out of range. Post has {$totalBlocks} blocks (0-" . ($totalBlocks - 1) . ').');
        }
        if ($fromIndex === $toIndex) {
            return [
                'success' => true,
                'message' => 'Block is already at the target position.',
            ];
        }

        // Extract the block from its current position
        $fromRaw = $indexMap[$fromIndex];
        $movedBlock = $blocks[$fromRaw];
        $blockName = $movedBlock['blockName'];
        array_splice($blocks, $fromRaw, 1);

        // Recalculate raw index for target position after removal
        $newIndexMap = [];
        $ri = 0;
        foreach ($blocks as $i => $block) {
            if (! empty($block['blockName'])) {
                $newIndexMap[$ri] = $i;
                $ri++;
            }
        }

        if ($toIndex >= $ri) {
            // Insert at end
            $blocks[] = $movedBlock;
        } else {
            $toRaw = $newIndexMap[$toIndex];
            array_splice($blocks, $toRaw, 0, [$movedBlock]);
        }

        $newContent = serialize_blocks($blocks);
        self::savePostContent($postId, $newContent);

        return [
            'success'    => true,
            'block_name' => $blockName,
            'from'       => $fromIndex,
            'to'         => $toIndex,
        ];
    }

    /**
     * Save post content directly via $wpdb to avoid wp_update_post's
     * slash-stripping which corrupts JSON escape sequences in block attributes.
     */
    private static function savePostContent(int $postId, string $content): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->posts,
            ['post_content' => $content],
            ['ID' => $postId],
            ['%s'],
            ['%d'],
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to update post content: ' . $wpdb->last_error);
        }

        clean_post_cache($postId);
    }

    /**
     * Prepare ACF data for block storage, attaching the `_<name>` companion
     * entries that hold each value's field key.
     *
     * Companion keys are decided in strict precedence order:
     *   1. a key the caller passed explicitly,
     *   2. the key already stored on this block — never re-derived,
     *   3. a key resolved within this block's own field groups,
     *   4. otherwise fail, rather than borrow a key from another block.
     *
     * Rule 2 is what makes a read/modify/write round trip lossless: callers
     * work with plain field names, so re-deriving a key on every write is both
     * unnecessary and the only way a wrong key can creep in.
     */
    private static function prepareAcfBlockData(array $data, string $blockName, array $existingData = []): array
    {
        $prepared = [];

        // Companion keys supplied by the caller take precedence over everything.
        $explicitKeys = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                $explicitKeys[$key] = $value;
            }
        }

        foreach ($data as $key => $value) {
            $key = (string) $key;

            // Companion keys are written alongside their value below.
            if (isset($explicitKeys[$key])) {
                continue;
            }

            self::assertNotNestedRows($blockName, $key, $value);

            $prepared[$key] = $value;

            $companion = '_' . $key;

            if (array_key_exists($companion, $explicitKeys)) {
                $prepared[$companion] = $explicitKeys[$companion];
                continue;
            }

            if (array_key_exists($companion, $existingData)) {
                $prepared[$companion] = $existingData[$companion];
                continue;
            }

            // Without ACF there is no way to know the key. Storing the value
            // alone is recoverable; storing a guessed key is not.
            if (! AcfFieldKeyResolver::isAvailable()) {
                continue;
            }

            $fieldKey = AcfFieldKeyResolver::resolveKey($blockName, $key);
            if ($fieldKey === null) {
                throw new \RuntimeException(AcfFieldKeyResolver::describeFailure($blockName, $key));
            }

            $prepared[$companion] = $fieldKey;
        }

        // Carry through companion keys that had no value counterpart in $data.
        foreach ($explicitKeys as $key => $value) {
            if (! array_key_exists($key, $prepared)) {
                $prepared[$key] = $value;
            }
        }

        return $prepared;
    }

    /**
     * Reject repeater/flexible-content values passed as nested row arrays.
     *
     * ACF stores these flattened: an integer row count under the field name,
     * plus one `<field>_<index>_<subfield>` entry per cell. Writing the nested
     * form instead puts an array where the render path expects a count, which
     * fatals the front end on a production site with WP_DEBUG off. Normalising
     * would mean guessing at each sub-value's type, so this rejects with the
     * exact shape to send instead.
     */
    private static function assertNotNestedRows(string $blockName, string $key, mixed $value): void
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return;
        }

        // Row-shaped means a list whose entries are themselves arrays. Galleries
        // and relationships are lists of IDs; link/image values are associative.
        if (! is_array($value[0])) {
            return;
        }

        if (! AcfFieldKeyResolver::isAvailable()) {
            return;
        }

        $field = AcfFieldKeyResolver::resolveField($blockName, $key);
        $type  = $field['type'] ?? null;

        if ($type !== 'repeater' && $type !== 'flexible_content') {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Field "%s" on block "%s" is a %s and cannot be written as a nested array — '
            . 'ACF stores it flattened, and the nested form fatals the page on render. '
            . 'Send the row count under "%s" plus one entry per cell instead, e.g. %s',
            $key,
            $blockName,
            $type,
            $key,
            self::describeFlattenedExample($key, $value),
        ));
    }

    /**
     * Render a concrete flattened example from the rows the caller supplied.
     */
    private static function describeFlattenedExample(string $key, array $rows): string
    {
        $example = [$key => count($rows)];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $subKey => $subValue) {
                $example[$key . '_' . $index . '_' . $subKey] = $subValue;
            }
        }

        $encoded = json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{"' . $key . '": ' . count($rows) . ', ...}' : $encoded;
    }
}
