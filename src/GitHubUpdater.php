<?php

declare(strict_types=1);

namespace WpMcp;

class GitHubUpdater
{
    private string $repo;
    private string $pluginFile;
    private string $pluginSlug;
    private string $transientKey = 'wp_mcp_github_update';

    public function __construct(string $repo, string $pluginFile)
    {
        $this->repo = $repo;
        $this->pluginFile = $pluginFile;
        $this->pluginSlug = dirname($pluginFile);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'afterInstall'], 10, 3);
    }

    /**
     * Check GitHub for a newer release and inject into the update transient.
     */
    public function checkForUpdate(object $transient): object
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->getLatestRelease();
        if ($release === null) {
            return $transient;
        }

        $remoteVersion = ltrim($release['tag_name'], 'v');
        $currentVersion = WP_MCP_VERSION;

        if (version_compare($remoteVersion, $currentVersion, '>')) {
            $downloadUrl = $this->getDownloadUrl($release);
            if ($downloadUrl === null) {
                return $transient;
            }

            $transient->response[$this->pluginFile] = (object) [
                'slug'        => $this->pluginSlug,
                'plugin'      => $this->pluginFile,
                'new_version' => $remoteVersion,
                'url'         => "https://github.com/{$this->repo}",
                'package'     => $downloadUrl,
                'icons'       => [],
                'banners'     => [],
                'tested'      => '',
                'requires_php' => '8.2',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" modal.
     */
    public function pluginInfo(false|object $result, string $action, object $args): false|object
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->pluginSlug) {
            return $result;
        }

        $release = $this->getLatestRelease();
        if ($release === null) {
            return $result;
        }

        $remoteVersion = ltrim($release['tag_name'], 'v');

        return (object) [
            'name'          => 'WP MCP',
            'slug'          => $this->pluginSlug,
            'version'       => $remoteVersion,
            'author'        => '<a href="https://github.com/' . esc_attr($this->repo) . '">WP MCP</a>',
            'homepage'      => "https://github.com/{$this->repo}",
            'requires_php'  => '8.1',
            'downloaded'    => 0,
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => [
                'description'  => 'Exposes WordPress as an MCP (Model Context Protocol) server for AI agents.',
                'changelog'    => nl2br(esc_html($release['body'] ?? 'No changelog provided.')),
            ],
            'download_link' => $this->getDownloadUrl($release),
        ];
    }

    /**
     * Ensure the plugin directory name is correct after install.
     *
     * WordPress extracts the zip to a temp name; we rename it back to wp-mcp.
     */
    public function afterInstall(bool $response, array $hookExtra, array $result): array
    {
        global $wp_filesystem;

        if (! isset($hookExtra['plugin']) || $hookExtra['plugin'] !== $this->pluginFile) {
            return $result;
        }

        $properDir = WP_PLUGIN_DIR . '/' . $this->pluginSlug;
        $wp_filesystem->move($result['destination'], $properDir);
        $result['destination'] = $properDir;
        $result['destination_name'] = $this->pluginSlug;

        activate_plugin($this->pluginFile);

        return $result;
    }

    /**
     * Fetch the latest release from GitHub, with caching.
     */
    private function getLatestRelease(): ?array
    {
        $cached = get_transient($this->transientKey);
        if ($cached !== false) {
            return $cached;
        }

        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WP-MCP-Updater',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || ! isset($body['tag_name'])) {
            return null;
        }

        set_transient($this->transientKey, $body, 12 * HOUR_IN_SECS);

        return $body;
    }

    /**
     * Get the download URL for the release zip asset.
     *
     * Looks for a .zip asset attached to the release. Falls back to the
     * GitHub-generated zipball if no asset is found.
     */
    private function getDownloadUrl(array $release): ?string
    {
        if (! empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (str_ends_with($asset['name'], '.zip')) {
                    return $asset['browser_download_url'];
                }
            }
        }

        return $release['zipball_url'] ?? null;
    }
}
