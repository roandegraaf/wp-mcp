<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class MediaTool extends AbstractTool
{
    /**
     * List media library items with filtering.
     */
    #[McpTool(name: 'wp_list_media', description: 'List media library items with filtering by mime type, search, and pagination.')]
    public function listMedia(
        #[Schema(description: 'Filter by MIME type (e.g. image, image/jpeg, application/pdf)')]
        string $mime_type = '',
        #[Schema(description: 'Search query')]
        string $search = '',
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
        #[Schema(description: 'Items per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
    ): string {
        $args = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];

        $args = array_merge($args, $this->paginationArgs($page, $per_page));

        if ($mime_type !== '') {
            $args['post_mime_type'] = $this->sanitizeText($mime_type);
        }
        if ($search !== '') {
            $args['s'] = $this->sanitizeText($search);
        }

        $query = new \WP_Query($args);

        $items = array_map(function ($post) {
            return ResponseFormatter::formatAttachment($post->ID);
        }, $query->posts);

        return ResponseFormatter::toJson([
            'media'      => array_filter($items),
            'pagination' => [
                'page'        => max(1, $page),
                'per_page'    => min($per_page, 100),
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ]);
    }

    /**
     * Get detailed media item information with all sizes, alt text, and metadata.
     */
    #[McpTool(name: 'wp_get_media', description: 'Get media item details with all image sizes, alt text, dimensions, and metadata.')]
    public function getMedia(
        #[Schema(description: 'Attachment ID')]
        int $attachment_id,
    ): string {
        $attachment = ResponseFormatter::formatAttachment($attachment_id);
        if (! $attachment) {
            throw new \RuntimeException("Media item not found: {$attachment_id}");
        }

        return ResponseFormatter::toJson($attachment);
    }

    /**
     * Get the direct file upload endpoint and a ready-to-use bash script for uploading local files.
     */
    #[McpTool(name: 'wp_upload_local_media', description: 'Upload local files to the WordPress media library. Returns a bash/curl command you can execute to upload files from the local filesystem. Use this instead of wp_upload_media when files are local and the WordPress site is remote.')]
    public function uploadLocalMedia(
        #[Schema(description: 'Absolute path to a local file or directory of files to upload (e.g. /path/to/photo.jpg or /path/to/photos/)')]
        string $path,
        #[Schema(description: 'Title for the media item (only used for single file uploads, ignored for directories)')]
        string $title = '',
        #[Schema(description: 'Alt text for images (only used for single file uploads)')]
        string $alt = '',
        #[Schema(description: 'Caption (only used for single file uploads)')]
        string $caption = '',
        #[Schema(description: 'Post ID to attach media to (0 for unattached)')]
        int $post_id = 0,
    ): string {
        $siteUrl = get_rest_url(null, 'wp-mcp/v1/upload');

        // Get the current API key from the request context
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_ireplace('Bearer ', '', $authHeader);

        // Build curl flags for optional metadata
        $metaFlags = '';
        if ($title !== '') {
            $metaFlags .= ' -F ' . escapeshellarg("title={$title}");
        }
        if ($alt !== '') {
            $metaFlags .= ' -F ' . escapeshellarg("alt={$alt}");
        }
        if ($caption !== '') {
            $metaFlags .= ' -F ' . escapeshellarg("caption={$caption}");
        }
        if ($post_id > 0) {
            $metaFlags .= ' -F ' . escapeshellarg("post_id={$post_id}");
        }

        $escapedUrl = escapeshellarg($siteUrl);
        $escapedToken = escapeshellarg("Authorization: Bearer {$token}");

        // Check if path looks like a directory (ends with / or *)
        $isDirectory = str_ends_with($path, '/') || str_contains($path, '*');

        if ($isDirectory) {
            $cleanPath = rtrim($path, '/');
            $script = <<<BASH
#!/usr/bin/env bash
set -euo pipefail

UPLOAD_URL={$escapedUrl}
AUTH_HEADER={$escapedToken}
DIR="{$cleanPath}"

uploaded=0
failed=0

for file in "\$DIR"/*.{jpg,jpeg,png,gif,webp,svg,mp4,pdf,JPG,JPEG,PNG,HEIC}; do
  [ -f "\$file" ] || continue
  filename=\$(basename "\$file")
  title="\${filename%.*}"

  echo -n "Uploading: \$filename ... "
  response=\$(curl -s -w "\\n%{http_code}" -X POST "\$UPLOAD_URL" \\
    -H "\$AUTH_HEADER" \\
    -F "file=@\$file" \\
    -F "title=\$title")

  http_code=\$(echo "\$response" | tail -1)
  body=\$(echo "\$response" | head -n -1)

  if [ "\$http_code" = "201" ]; then
    echo "OK (ID: \$(echo "\$body" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2))"
    ((uploaded++))
  else
    echo "FAILED (\$http_code)"
    ((failed++))
  fi
done

echo ""
echo "Done: \$uploaded uploaded, \$failed failed"
BASH;
        } else {
            $escapedPath = escapeshellarg($path);
            $script = <<<BASH
curl -s -X POST {$escapedUrl} \
  -H {$escapedToken} \
  -F "file=@{$escapedPath}"{$metaFlags}
BASH;
        }

        return ResponseFormatter::toJson([
            'endpoint'     => $siteUrl,
            'instructions' => 'Execute the bash script below to upload. Run it using the Bash tool.',
            'script'       => $script,
        ]);
    }

    /**
     * Upload media from a URL. Downloads the file and adds it to the media library.
     */
    #[McpTool(name: 'wp_upload_media', description: 'Upload media from a URL to the WordPress media library. Returns the new attachment ID and URL. For local files, use wp_upload_local_media instead.')]
    public function uploadMedia(
        #[Schema(description: 'URL of the file to download and upload')]
        string $url,
        #[Schema(description: 'Title for the media item')]
        string $title = '',
        #[Schema(description: 'Alt text for images')]
        string $alt = '',
        #[Schema(description: 'Caption')]
        string $caption = '',
        #[Schema(description: 'Post ID to attach media to (0 for unattached)')]
        int $post_id = 0,
    ): string {
        // Require media functions
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download and sideload
        $attachmentId = media_sideload_image(
            esc_url_raw($url),
            $post_id > 0 ? $post_id : 0,
            $title !== '' ? $this->sanitizeText($title) : null,
            'id'
        );

        if (is_wp_error($attachmentId)) {
            throw new \RuntimeException('Upload failed: ' . $attachmentId->get_error_message());
        }

        // Update metadata
        if ($title !== '') {
            wp_update_post([
                'ID'         => $attachmentId,
                'post_title' => $this->sanitizeText($title),
            ]);
        }
        if ($alt !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $this->sanitizeText($alt));
        }
        if ($caption !== '') {
            wp_update_post([
                'ID'           => $attachmentId,
                'post_excerpt' => $this->sanitizeText($caption),
            ]);
        }

        $attachment = ResponseFormatter::formatAttachment($attachmentId);
        $attachment['message'] = "Media uploaded successfully with ID {$attachmentId}.";

        return ResponseFormatter::toJson($attachment);
    }

    /**
     * Update media metadata (title, alt text, caption, description).
     */
    #[McpTool(name: 'wp_update_media', description: 'Update media metadata: title, alt text, caption, description.')]
    public function updateMedia(
        #[Schema(description: 'Attachment ID')]
        int $attachment_id,
        #[Schema(description: 'New title')]
        string $title = '',
        #[Schema(description: 'New alt text')]
        string $alt = '',
        #[Schema(description: 'New caption')]
        string $caption = '',
        #[Schema(description: 'New description')]
        string $description = '',
    ): string {
        $post = get_post($attachment_id);
        if (! $post || $post->post_type !== 'attachment') {
            throw new \RuntimeException("Media item not found: {$attachment_id}");
        }

        $postData = ['ID' => $attachment_id];

        if ($title !== '') {
            $postData['post_title'] = $this->sanitizeText($title);
        }
        if ($caption !== '') {
            $postData['post_excerpt'] = $this->sanitizeText($caption);
        }
        if ($description !== '') {
            $postData['post_content'] = $this->sanitizeHtml($description);
        }

        if (count($postData) > 1) {
            $result = wp_update_post($postData, true);
            if (is_wp_error($result)) {
                throw new \RuntimeException('Failed to update media: ' . $result->get_error_message());
            }
        }

        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $this->sanitizeText($alt));
        }

        $attachment = ResponseFormatter::formatAttachment($attachment_id);
        $attachment['message'] = "Media item {$attachment_id} updated successfully.";

        return ResponseFormatter::toJson($attachment);
    }
}
