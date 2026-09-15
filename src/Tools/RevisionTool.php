<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\BlockHelper;
use WpMcp\Helpers\ResponseFormatter;

class RevisionTool extends AbstractTool
{
    #[McpTool(name: 'wp_list_post_revisions', description: 'List revisions and autosaves of a post (newest first): ID, type (revision/autosave), author, date, content length, and whether the content matches the current post. Revision IDs can be passed to wp_list_post_blocks and wp_diff_post_revisions.')]
    public function listPostRevisions(
        #[Schema(description: 'Post ID')]
        int $post_id,
        #[Schema(description: 'Maximum revisions to return', minimum: 1, maximum: 200)]
        int $limit = 50,
    ): string {
        $post = $this->getPostOrFail($post_id);
        $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => $limit, 'check_enabled' => false]);

        $rows = array_map(function (\WP_Post $revision) use ($post): array {
            return [
                'id'                    => $revision->ID,
                'type'                  => str_contains($revision->post_name, 'autosave') ? 'autosave' : 'revision',
                'author'                => ResponseFormatter::formatAuthor((int) $revision->post_author),
                'date'                  => $revision->post_date,
                'modified'              => $revision->post_modified,
                'title'                 => $revision->post_title,
                'content_length'        => strlen($revision->post_content),
                'matches_current_content' => $revision->post_content === $post->post_content,
            ];
        }, array_values($revisions));

        return ResponseFormatter::toJson([
            'post_id'        => $post_id,
            'post_modified'  => $post->post_modified,
            'content_length' => strlen($post->post_content),
            'revisions_enabled' => wp_revisions_enabled($post),
            'count'          => count($rows),
            'revisions'      => $rows,
        ]);
    }

    #[McpTool(name: 'wp_diff_post_revisions', description: 'Per-block diff between two versions of a post (the post itself or any of its revision/autosave IDs). Blocks are aligned by index; for ACF blocks the ACF field data is compared and added/removed/changed fields are reported with before/after values, for other blocks attributes and inner HTML are compared. Much easier than reading raw post_content JSON.')]
    public function diffPostRevisions(
        #[Schema(description: 'Post ID that owns the revisions')]
        int $post_id,
        #[Schema(description: 'Older version: the post ID itself or a revision/autosave ID')]
        int $from_id,
        #[Schema(description: 'Newer version: the post ID itself or a revision/autosave ID')]
        int $to_id,
    ): string {
        $this->getPostOrFail($post_id);
        $from = $this->getVersionOrFail($post_id, $from_id);
        $to = $this->getVersionOrFail($post_id, $to_id);

        $diff = BlockHelper::diffParsedBlocks(parse_blocks($from->post_content), parse_blocks($to->post_content));

        return ResponseFormatter::toJson([
            'post_id' => $post_id,
            'from'    => $this->describeVersion($from),
            'to'      => $this->describeVersion($to),
            'summary' => $diff['summary'],
            'blocks'  => $diff['blocks'],
        ]);
    }

    private function getVersionOrFail(int $postId, int $versionId): \WP_Post
    {
        $version = $this->getPostOrFail($versionId);
        if ($version->ID !== $postId && ! ($version->post_type === 'revision' && (int) $version->post_parent === $postId)) {
            throw new \RuntimeException("Post {$versionId} is not post {$postId} or one of its revisions.");
        }
        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeVersion(\WP_Post $version): array
    {
        $isRevision = $version->post_type === 'revision';
        return [
            'id'             => $version->ID,
            'type'           => ! $isRevision ? 'post' : (str_contains($version->post_name, 'autosave') ? 'autosave' : 'revision'),
            'author'         => ResponseFormatter::formatAuthor((int) $version->post_author),
            'modified'       => $version->post_modified,
            'content_length' => strlen($version->post_content),
        ];
    }
}
