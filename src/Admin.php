<?php

declare(strict_types=1);

namespace WpMcp;

class Admin
{
    private string $optionHash = 'wp_mcp_password_hash';
    private string $transientActivity = 'wp_mcp_last_activity';
    private string $nonceAction = 'wp_mcp_settings';

    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            'WP MCP',
            'WP MCP',
            'manage_options',
            'wp-mcp',
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wp-mcp') {
            return;
        }

        wp_enqueue_style(
            'wp-mcp-admin',
            WP_MCP_PLUGIN_URL . 'assets/admin.css',
            [],
            WP_MCP_VERSION,
        );

        wp_enqueue_script(
            'wp-mcp-admin',
            WP_MCP_PLUGIN_URL . 'assets/admin.js',
            [],
            WP_MCP_VERSION,
            true,
        );

        wp_localize_script('wp-mcp-admin', 'wpMcp', [
            'statusUrl' => rest_url('wp-mcp/v1/status'),
            'nonce'     => wp_create_nonce('wp_rest'),
        ]);
    }

    public function handleFormSubmission(): void
    {
        if (! isset($_POST['wp_mcp_action'])) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer($this->nonceAction);

        $action = sanitize_text_field($_POST['wp_mcp_action']);

        if ($action === 'generate') {
            $token = wp_generate_password(32, false);
            $hash = wp_hash_password($token);
            update_option($this->optionHash, $hash);

            set_transient('wp_mcp_new_token', $token, 60);

            add_settings_error('wp-mcp', 'token-generated', 'New MCP password generated. Copy it now — it won\'t be shown again.', 'success');
        }
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $hasPassword = (bool) get_option($this->optionHash);
        $newToken = get_transient('wp_mcp_new_token');
        if ($newToken) {
            delete_transient('wp_mcp_new_token');
        }

        $siteUrl = $this->getMcpEndpointUrl();
        $lastActivity = get_transient($this->transientActivity);
        $isConnected = $lastActivity && (time() - (int) $lastActivity) < 300;

        ?>
        <div class="wrap wp-mcp-settings">
            <h1>WP MCP Settings</h1>

            <?php settings_errors('wp-mcp'); ?>

            <!-- Connection Status -->
            <div class="wp-mcp-card">
                <h2>Connection Status</h2>
                <div class="wp-mcp-status" id="wp-mcp-status">
                    <span class="wp-mcp-status-dot <?php echo $isConnected ? 'connected' : 'disconnected'; ?>" id="wp-mcp-status-dot"></span>
                    <span id="wp-mcp-status-text"><?php echo $isConnected ? 'Connected' : 'Not connected'; ?></span>
                </div>
                <?php if ($lastActivity): ?>
                    <p class="description" id="wp-mcp-last-activity">Last activity: <?php echo esc_html(human_time_diff((int) $lastActivity)) . ' ago'; ?></p>
                <?php else: ?>
                    <p class="description" id="wp-mcp-last-activity">No activity recorded yet.</p>
                <?php endif; ?>
            </div>

            <!-- MCP Password -->
            <div class="wp-mcp-card">
                <h2>MCP Password</h2>
                <p class="description">Generate a dedicated password for MCP clients. This is separate from your WordPress password.</p>

                <?php if ($newToken): ?>
                    <div class="wp-mcp-token-display">
                        <label>Your new MCP password (copy it now):</label>
                        <div class="wp-mcp-copy-row">
                            <code id="wp-mcp-token"><?php echo esc_html($newToken); ?></code>
                            <button type="button" class="button wp-mcp-copy-btn" data-target="wp-mcp-token">Copy</button>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field($this->nonceAction); ?>
                    <input type="hidden" name="wp_mcp_action" value="generate">
                    <p>
                        <button type="submit" class="button <?php echo $hasPassword ? 'button-secondary' : 'button-primary'; ?>">
                            <?php echo $hasPassword ? 'Regenerate Password' : 'Generate Password'; ?>
                        </button>
                        <?php if ($hasPassword && ! $newToken): ?>
                            <span class="description">A password is set. Regenerating will invalidate the current one.</span>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- Connection Command -->
            <div class="wp-mcp-card">
                <h2>Connection Command</h2>

                <h3>Claude Code (CLI)</h3>
                <p class="description">Run this in your terminal:</p>
                <div class="wp-mcp-copy-row">
                    <pre class="wp-mcp-code-block" id="wp-mcp-cli"><?php echo esc_html($this->getClaudeCodeCommand($siteUrl, $newToken ?: '<your-mcp-password>')); ?></pre>
                    <button type="button" class="button wp-mcp-copy-btn" data-target="wp-mcp-cli">Copy</button>
                </div>

                <h3>JSON Config</h3>
                <p class="description">Or add this to your AI client's MCP configuration (e.g. <code>~/.claude/settings.json</code>):</p>
                <div class="wp-mcp-copy-row">
                    <pre class="wp-mcp-code-block" id="wp-mcp-config"><?php echo esc_html($this->getConnectionJson($siteUrl, $newToken ?: '<your-mcp-password>')); ?></pre>
                    <button type="button" class="button wp-mcp-copy-btn" data-target="wp-mcp-config">Copy</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function getMcpEndpointUrl(): string
    {
        $url = rest_url('wp-mcp/v1/mcp');

        if (! $this->isLocalDomain()) {
            return $url;
        }

        $parsed = wp_parse_url($url);
        if (! $parsed || ($parsed['scheme'] ?? '') !== 'https') {
            return $url;
        }

        // Local dev with HTTPS: MCP clients (Node.js) reject self-signed certs.
        // Laravel Herd/Valet exposes secured sites on port 60 over plain HTTP.
        $httpUrl = 'http://' . $parsed['host'] . ':60';
        if (! empty($parsed['path'])) {
            $httpUrl .= $parsed['path'];
        }

        // Verify port 60 is actually reachable before recommending it.
        $test = @fsockopen($parsed['host'], 60, $errno, $errstr, 1);
        if ($test) {
            fclose($test);
            return $httpUrl;
        }

        // Port 60 not available, fall back to original HTTPS URL.
        return $url;
    }

    private function isLocalDomain(): bool
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?? '';
        $localTlds = ['.test', '.local', '.localhost', '.invalid', '.example'];

        foreach ($localTlds as $tld) {
            if (str_ends_with($host, $tld)) {
                return true;
            }
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function getClaudeCodeCommand(string $url, string $token): string
    {
        return sprintf(
            'claude mcp add wordpress %s -t http -H "Authorization: Bearer %s"',
            $url,
            $token,
        );
    }

    private function getConnectionJson(string $url, string $token): string
    {
        $config = [
            'mcpServers' => [
                'wordpress' => [
                    'type'    => 'streamable-http',
                    'url'     => $url,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ],
            ],
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
