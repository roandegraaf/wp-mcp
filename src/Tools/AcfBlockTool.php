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

    /**
     * List every block type registered in the current WP runtime.
     */
    #[McpTool(name: 'wp_list_registered_blocks', description: 'List every block type registered in the WordPress block registry, with optional namespace filter (e.g. "acf", "core"). Useful to verify ACF Composer blocks have actually been registered after wp acorn cache:clear.')]
    public function listRegisteredBlocks(
        #[Schema(description: 'Filter by namespace prefix without the slash, e.g. "acf" matches acf/hero, acf/tickets. Empty returns all.')]
        string $namespace = '',
    ): string {
        if (! class_exists('WP_Block_Type_Registry')) {
            throw new \RuntimeException('WordPress block registry is not available.');
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $all = $registry->get_all_registered();

        $prefix = $namespace !== '' ? rtrim($this->sanitizeText($namespace), '/') . '/' : '';

        $blocks = [];
        foreach ($all as $name => $blockType) {
            if ($prefix !== '' && strpos($name, $prefix) !== 0) {
                continue;
            }
            $blocks[] = [
                'name'        => $name,
                'title'       => $blockType->title ?? '',
                'category'    => $blockType->category ?? '',
                'description' => $blockType->description ?? '',
                'render'      => ! empty($blockType->render_callback),
            ];
        }

        sort($blocks);

        return ResponseFormatter::toJson([
            'total'     => count($blocks),
            'namespace' => $namespace,
            'blocks'    => $blocks,
        ]);
    }

    /**
     * Check whether a specific block name is registered right now.
     */
    #[McpTool(name: 'wp_is_block_registered', description: 'Check whether a specific block is currently registered (e.g. "acf/tickets"). Returns registered=true/false plus the registered block type details when present.')]
    public function isBlockRegistered(
        #[Schema(description: 'Full block name including namespace, e.g. "acf/tickets" or "core/paragraph"')]
        string $name,
    ): string {
        if (! class_exists('WP_Block_Type_Registry')) {
            throw new \RuntimeException('WordPress block registry is not available.');
        }

        $name = trim($this->sanitizeText($name));
        if ($name === '') {
            throw new \RuntimeException('Block name is required.');
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $registered = $registry->is_registered($name);

        $result = [
            'name'       => $name,
            'registered' => $registered,
        ];

        if ($registered) {
            $blockType = $registry->get_registered($name);
            $result['details'] = [
                'title'       => $blockType->title ?? '',
                'category'    => $blockType->category ?? '',
                'description' => $blockType->description ?? '',
                'render'      => ! empty($blockType->render_callback),
                'attributes'  => array_keys((array) ($blockType->attributes ?? [])),
            ];
        }

        return ResponseFormatter::toJson($result);
    }
}
