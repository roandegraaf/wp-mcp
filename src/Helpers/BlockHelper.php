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
    public static function parseBlocks(int $postId): array
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

            $formatted = [
                'index'     => $index,
                'name'      => $block['blockName'],
                'attrs'     => $block['attrs'] ?? [],
            ];

            // For ACF blocks, extract clean field data
            if (str_starts_with($block['blockName'], 'acf/')) {
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
            $blocks[$targetRawIndex]['attrs']['data'] = array_merge($existingData, self::prepareAcfBlockData($newData));
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
            $newBlock['attrs']['data'] = self::prepareAcfBlockData($blockData);
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
     * Prepare ACF data for block storage.
     * Adds field key mappings where possible.
     */
    private static function prepareAcfBlockData(array $data): array
    {
        $prepared = [];

        foreach ($data as $key => $value) {
            $prepared[$key] = $value;

            // Try to find the field key for this field name
            if (function_exists('acf_get_field')) {
                $field = acf_get_field($key);
                if ($field) {
                    $prepared['_' . $key] = $field['key'];
                }
            }
        }

        return $prepared;
    }
}
