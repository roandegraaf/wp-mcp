<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\AcfFieldKeyResolver;
use WpMcp\Helpers\RenderAsUser;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\WpmlHelper;

class RenderTool extends AbstractTool
{
    private const PREVIEW_BLOCK_ID = 'block_wpmcp_preview';

    #[McpTool(name: 'wp_render_post_block', description: 'Render one ACF block server-side with supplied field data — without saving — in a given WPML language and in the context of a given post (global $post / is_singular are set up as on the front end). Returns the HTML, the ACF field values as the template resolved them, and every WP_Query the template ran with its query vars and resulting post IDs (e.g. which FAQ posts a category filter returned). Pass block_index to start from that block\'s stored data in the post and overlay "data" on top.')]
    public function renderPostBlock(
        #[Schema(description: 'Post ID providing the rendering context')]
        int $post_id,
        #[Schema(description: 'Block name, e.g. "acf/faq-list"')]
        string $block_name,
        #[Schema(description: 'JSON object of ACF field values keyed by field name (repeaters flattened as for wp_update_post_block). Empty uses only the stored block data when block_index is given.')]
        string $data = '{}',
        #[Schema(description: 'WPML language code to render in (empty = current/default)')]
        string $language = '',
        #[Schema(description: 'Index of an existing block in the post (from wp_list_post_blocks) whose stored data is the base; -1 to render purely from "data"')]
        int $block_index = -1,
        #[Schema(description: 'Truncate HTML beyond this many characters', minimum: 1000, maximum: 500000)]
        int $max_html_chars = 50000,
    ): string {
        $this->requireAcf();
        $post = $this->getPostOrFail($post_id);

        $block_name = trim($this->sanitizeText($block_name));
        if (! \WP_Block_Type_Registry::get_instance()->is_registered($block_name)) {
            throw new \RuntimeException("Block not registered: {$block_name}");
        }

        $overlay = json_decode($data === '' ? '{}' : $data, true);
        if (! is_array($overlay)) {
            throw new \RuntimeException('data must be a JSON object.');
        }

        $base = [];
        if ($block_index >= 0) {
            $base = $this->storedBlockData($post, $block_index, $block_name);
        }

        $blockData = $base;
        foreach ($overlay as $key => $value) {
            $blockData[(string) $key] = $value;
        }
        $blockData = $this->attachFieldKeys($blockData, $block_name);

        // ACF caches resolved values per block id for the request, so the id
        // must change with the data or a second preview reads the first one's values.
        $blockId = self::PREVIEW_BLOCK_ID . '_' . substr(md5($block_name . json_encode($blockData)), 0, 12);

        $parsed = [
            'blockName'    => $block_name,
            'attrs'        => ['name' => $block_name, 'data' => $blockData, 'id' => $blockId, 'mode' => 'preview'],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ];

        $result = WpmlHelper::withLanguage($language, function () use ($post, $parsed, $blockData, $blockId) {
            return $this->withPostContext($post, function () use ($parsed, $blockData, $blockId) {
                $queries = [];
                $capture = function (\WP_Query $query) use (&$queries): void {
                    $queries[] = $query;
                };
                add_action('pre_get_posts', $capture, PHP_INT_MAX);

                $error = null;
                $html = '';
                try {
                    $html = (string) render_block($parsed);
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                } finally {
                    remove_action('pre_get_posts', $capture, PHP_INT_MAX);
                }

                return [
                    'html'            => $html,
                    'error'           => $error,
                    'queries'         => array_map([$this, 'describeQuery'], $queries),
                    'resolved_fields' => $this->resolveFields($blockData, $blockId),
                ];
            });
        });

        $html = $result['html'];
        $truncated = strlen($html) > $max_html_chars;

        return ResponseFormatter::toJson([
            'post_id'         => $post_id,
            'block_name'      => $block_name,
            'language'        => $language !== '' ? $language : WpmlHelper::currentLanguage(),
            'data_used'       => $blockData,
            'error'           => $result['error'],
            'html_length'     => strlen($html),
            'html_truncated'  => $truncated,
            'html'            => $truncated ? mb_strcut($html, 0, $max_html_chars, 'UTF-8') . '…' : $html,
            'queries'         => $result['queries'],
            'resolved_fields' => $result['resolved_fields'],
        ]);
    }

    #[McpTool(name: 'wp_render_post', description: 'Fetch the front-end HTML of a post exactly as the server generates it right now, bypassing page caches (loopback request with a random query string and no-cache headers, which WP Rocket, nginx/fastcgi, Cloudflare and similar treat as uncacheable). With WPML, language selects that translation\'s URL. Logged out, only publicly viewable posts render (drafts and WPML hidden languages return the front-end 404/redirect); pass as_user to render as that user instead, e.g. for QA of drafts or hidden languages (the request carries a short-lived session that is destroyed afterwards, and WPML hidden languages are shown to that user for that request only). NOTE: loopback requests can hang on hosts with a single PHP-FPM worker.')]
    public function renderPost(
        #[Schema(description: 'Post ID (any language; the translation is resolved when language is given)')]
        int $post_id,
        #[Schema(description: 'WPML language code; empty renders the post\'s own language')]
        string $language = '',
        #[Schema(description: 'Truncate HTML beyond this many characters', minimum: 1000, maximum: 1000000)]
        int $max_html_chars = 100000,
        #[Schema(description: 'Request timeout in seconds', minimum: 1, maximum: 60)]
        int $timeout = 30,
        #[Schema(description: 'Render logged in as this user (ID, login or email). Empty = logged out.')]
        string $as_user = '',
    ): string {
        $post = $this->getPostOrFail($post_id);
        $renderUser = trim($as_user) !== '' ? $this->resolveUser($as_user) : null;
        $language = trim($this->sanitizeText($language));

        $targetId = $post_id;
        if ($language !== '' && WpmlHelper::isActive()) {
            $translated = apply_filters('wpml_object_id', $post_id, $post->post_type, false, $language);
            if (! $translated) {
                throw new \RuntimeException("Post {$post_id} has no translation in language '{$language}'.");
            }
            $targetId = (int) $translated;
        }

        $url = WpmlHelper::withLanguage($language, fn() => get_permalink($targetId));
        if (! is_string($url) || $url === '') {
            throw new \RuntimeException("No permalink for post {$targetId}.");
        }

        $bypass = bin2hex(random_bytes(6));
        $fetchUrl = add_query_arg('wp_mcp_nocache', $bypass, $url);

        $auth = $renderUser ? RenderAsUser::issue($renderUser) : null;
        $started = microtime(true);
        try {
            $response = wp_remote_get($fetchUrl, [
                'timeout'     => min(max(1, $timeout), 60),
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => 'WP-MCP-Renderer/' . WP_MCP_VERSION,
                'headers'     => ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'] + ($auth['headers'] ?? []),
                'cookies'     => $auth['cookies'] ?? [],
            ]);
        } finally {
            if ($auth) {
                RenderAsUser::revoke($auth);
            }
        }
        $durationMs = round((microtime(true) - $started) * 1000, 1);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Loopback request failed: ' . $response->get_error_message());
        }

        $html = (string) wp_remote_retrieve_body($response);
        $headers = [];
        foreach (wp_remote_retrieve_headers($response)->getAll() as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : $value;
        }
        $interesting = array_intersect_key($headers, array_flip([
            'content-type', 'cache-control', 'age', 'x-cache', 'x-cache-enabled', 'cf-cache-status',
            'x-litespeed-cache', 'x-rocket-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-powered-by',
            'link', 'content-language', 'x-redirect-by', 'location',
        ]));
        $truncated = strlen($html) > $max_html_chars;

        return ResponseFormatter::toJson([
            'post_id'        => $targetId,
            'source_post_id' => $post_id,
            'language'       => $language !== '' ? $language : null,
            'url'            => $url,
            'fetched_url'    => $fetchUrl,
            'as_user'        => $renderUser
                ? ['id' => $renderUser->ID, 'login' => $renderUser->user_login, 'roles' => $renderUser->roles]
                : null,
            'status'         => (int) wp_remote_retrieve_response_code($response),
            'duration_ms'    => $durationMs,
            'headers'        => $interesting,
            'title'          => preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? html_entity_decode(trim($m[1])) : null,
            'html_length'    => strlen($html),
            'html_truncated' => $truncated,
            'html'           => $truncated ? mb_strcut($html, 0, $max_html_chars, 'UTF-8') . '…' : $html,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedBlockData(\WP_Post $post, int $blockIndex, string $blockName): array
    {
        $index = 0;
        foreach (parse_blocks($post->post_content) as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            if ($index === $blockIndex) {
                if ($block['blockName'] !== $blockName) {
                    throw new \RuntimeException("Block index {$blockIndex} is {$block['blockName']}, not {$blockName}.");
                }
                return (array) ($block['attrs']['data'] ?? []);
            }
            $index++;
        }
        throw new \RuntimeException("Block index {$blockIndex} not found in post {$post->ID}.");
    }

    /**
     * Add "_<field>" => field_key companions where they are missing so ACF can
     * format values (relationship → posts, image → array, ...). Unresolvable
     * names are left as raw values rather than failing the preview.
     */
    private function attachFieldKeys(array $data, string $blockName): array
    {
        if (! AcfFieldKeyResolver::isAvailable()) {
            return $data;
        }
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '_') || array_key_exists('_' . $key, $data)) {
                continue;
            }
            $fieldKey = AcfFieldKeyResolver::resolveKey($blockName, $key);
            if ($fieldKey !== null) {
                $data['_' . $key] = $fieldKey;
            }
        }
        return $data;
    }

    /**
     * Run a callable with the main query and global $post pointing at the
     * given post, so conditional tags and get_the_ID() behave as on the front end.
     */
    private function withPostContext(\WP_Post $post, callable $fn): mixed
    {
        global $wp_query, $wp_the_query;

        $previousQuery = $wp_query;
        $previousTheQuery = $wp_the_query;
        $previousPost = $GLOBALS['post'] ?? null;

        $wp_query = new \WP_Query([
            'p'           => $post->ID,
            'post_type'   => $post->post_type,
            'post_status' => 'any',
        ]);
        $wp_the_query = $wp_query;
        if ($wp_query->have_posts()) {
            $wp_query->the_post();
        } else {
            $GLOBALS['post'] = $post;
            setup_postdata($post);
        }

        try {
            return $fn();
        } finally {
            $wp_query = $previousQuery;
            $wp_the_query = $previousTheQuery;
            $GLOBALS['post'] = $previousPost;
            if ($previousPost instanceof \WP_Post) {
                setup_postdata($previousPost);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describeQuery(\WP_Query $query): array
    {
        $posts = is_array($query->posts) ? $query->posts : [];
        $ids = array_map(fn($p) => $p instanceof \WP_Post ? $p->ID : (is_object($p) ? ($p->ID ?? null) : $p), $posts);

        return [
            'query_vars'  => array_filter($query->query ?? [], fn($v) => $v !== '' && $v !== null && $v !== []),
            'found_posts' => (int) $query->found_posts,
            'returned'    => count($posts),
            'post_ids'    => array_values(array_filter($ids, fn($id) => $id !== null)),
            'titles'      => array_slice(array_map(fn($p) => $p instanceof \WP_Post ? $p->post_title : null, $posts), 0, 50),
            'request'     => is_string($query->request) ? preg_replace('/\s+/', ' ', trim($query->request)) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFields(array $blockData, string $blockId): ?array
    {
        if (! function_exists('acf_setup_meta') || ! function_exists('get_fields')) {
            return null;
        }
        try {
            acf_setup_meta($blockData, $blockId, true);
            $fields = get_fields($blockId);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        } finally {
            acf_reset_meta($blockId);
        }

        return is_array($fields) ? $this->summarize($fields) : null;
    }

    /**
     * Replace heavy WP objects in resolved ACF values with compact references.
     */
    private function summarize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '…';
        }
        if ($value instanceof \WP_Post) {
            return ['post_id' => $value->ID, 'title' => $value->post_title, 'type' => $value->post_type, 'status' => $value->post_status];
        }
        if ($value instanceof \WP_Term) {
            return ['term_id' => $value->term_id, 'name' => $value->name, 'taxonomy' => $value->taxonomy];
        }
        if ($value instanceof \WP_User) {
            return ['user_id' => $value->ID, 'login' => $value->user_login];
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->summarize($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($value) && strlen($value) > 1000) {
            return mb_strcut($value, 0, 1000, 'UTF-8') . sprintf('…[%d chars]', strlen($value));
        }
        return $value;
    }
}
