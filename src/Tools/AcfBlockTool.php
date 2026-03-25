<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\BlockHelper;
use WpMcp\Helpers\ResponseFormatter;

class AcfBlockTool extends AbstractTool
{
    /**
     * Parse post content and list all blocks with their data.
     * For ACF blocks, includes field data from block attributes.
     */
    #[McpTool(name: 'wp_list_post_blocks', description: 'Parse post content and list all Gutenberg blocks with ACF data. Returns block name, index, attributes, and ACF field values.')]
    public function listPostBlocks(
        #[Schema(description: 'Post ID')]
        int $post_id,
    ): string {
        $this->getPostOrFail($post_id);

        $blocks = BlockHelper::parseBlocks($post_id);

        return ResponseFormatter::toJson([
            'post_id'     => $post_id,
            'block_count' => count($blocks),
            'blocks'      => $blocks,
        ]);
    }

    /**
     * Update a specific block's data by its index in the post.
     */
    #[McpTool(name: 'wp_update_post_block', description: 'Update a specific block\'s field data by index. For ACF blocks, updates the ACF field values.')]
    public function updatePostBlock(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Block index (0-based, from wp_list_post_blocks)')]
        int $block_index,
        #[Schema(description: 'JSON object of field data to update')]
        string $data,
    ): string {
        $this->getPostOrFail($post_id);

        $newData = json_decode($data, true);
        if (! is_array($newData)) {
            throw new \RuntimeException('Invalid data JSON. Provide an object like {"field_name": "value"}.');
        }

        $result = BlockHelper::updateBlock($post_id, $block_index, $newData);

        return ResponseFormatter::toJson($result);
    }

    /**
     * Insert a new block at a specified position in the post content.
     */
    #[McpTool(name: 'wp_insert_post_block', description: 'Insert a new Gutenberg/ACF block at a specified position. For ACF blocks, use block name like "acf/hero" and pass field data.')]
    public function insertPostBlock(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Block name (e.g. "acf/hero", "core/paragraph")')]
        string $block_name,
        #[Schema(description: 'JSON object of block/field data')]
        string $data = '{}',
        #[Schema(description: 'Position to insert at (0-based). -1 for end.')]
        int $position = -1,
    ): string {
        $this->getPostOrFail($post_id);

        $blockData = json_decode($data, true);
        if (! is_array($blockData)) {
            throw new \RuntimeException('Invalid data JSON.');
        }

        $result = BlockHelper::insertBlock($post_id, $block_name, $blockData, $position);

        return ResponseFormatter::toJson($result);
    }

    /**
     * Delete a block at a specified position in the post content.
     */
    #[McpTool(name: 'wp_delete_post_block', description: 'Delete a Gutenberg/ACF block at a specified index. Use wp_list_post_blocks to find the block index first.')]
    public function deletePostBlock(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Block index (0-based, from wp_list_post_blocks)')]
        int $block_index,
    ): string {
        $this->getPostOrFail($post_id);

        $result = BlockHelper::deleteBlock($post_id, $block_index);

        return ResponseFormatter::toJson($result);
    }

    /**
     * Move a block from one position to another in the post content.
     */
    #[McpTool(name: 'wp_move_post_block', description: 'Move a Gutenberg/ACF block from one position to another. Use wp_list_post_blocks to find block indices.')]
    public function movePostBlock(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Current block index (0-based)')]
        int $from_index,
        #[Schema(description: 'Target block index (0-based)')]
        int $to_index,
    ): string {
        $this->getPostOrFail($post_id);

        $result = BlockHelper::moveBlock($post_id, $from_index, $to_index);

        return ResponseFormatter::toJson($result);
    }
}
