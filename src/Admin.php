<?php

declare(strict_types=1);

namespace WpMcp;

class Admin
{
    private string $optionKeys = 'wp_mcp_api_keys';
    private string $transientActivity = 'wp_mcp_last_activity';
    private string $nonceAction = 'wp_mcp_settings';

    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
        add_action('wp_ajax_wp_mcp_update_plugin', [$this, 'ajaxUpdatePlugin']);
        add_action('wp_ajax_wp_mcp_check_update', [$this, 'ajaxCheckUpdate']);
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
            'statusUrl'  => rest_url('wp-mcp/v1/status'),
            'nonce'      => wp_create_nonce('wp_rest'),
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'ajaxNonce'  => wp_create_nonce('wp_mcp_update'),
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

        if ($action === 'create_key') {
            $name = sanitize_text_field($_POST['wp_mcp_key_name'] ?? '');
            if (empty($name)) {
                add_settings_error('wp-mcp', 'name-required', 'Please enter a name for the API key.', 'error');
                return;
            }

            $token = wp_generate_password(32, false);
            $hash = wp_hash_password($token);

            $keys = get_option($this->optionKeys, []);
            $keys[] = [
                'id'           => wp_generate_password(12, false),
                'name'         => $name,
                'hash'         => $hash,
                'created_at'   => time(),
                'last_used_at' => null,
            ];
            update_option($this->optionKeys, $keys);

            set_transient('wp_mcp_new_token', $token, 60);
            set_transient('wp_mcp_new_token_name', $name, 60);

            add_settings_error('wp-mcp', 'key-created', 'API key created. Copy it now — it won\'t be shown again.', 'success');
        }

        if ($action === 'revoke_key') {
            $keyId = sanitize_text_field($_POST['wp_mcp_key_id'] ?? '');
            if (empty($keyId)) {
                return;
            }

            $keys = get_option($this->optionKeys, []);
            $keys = array_values(array_filter($keys, fn($key) => $key['id'] !== $keyId));
            update_option($this->optionKeys, $keys);

            add_settings_error('wp-mcp', 'key-revoked', 'API key revoked.', 'success');
        }
    }

    public function ajaxCheckUpdate(): void
    {
        check_ajax_referer('wp_mcp_update');

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $updater = $GLOBALS['wp_mcp_updater'] ?? null;
        if (! $updater) {
            wp_send_json_error('Updater not available.');
        }

        $updater->clearCache();
        $update = $updater->getUpdateInfo();

        if ($update) {
            wp_send_json_success($update);
        } else {
            wp_send_json_success(['up_to_date' => true, 'version' => WP_MCP_VERSION]);
        }
    }

    public function ajaxUpdatePlugin(): void
    {
        check_ajax_referer('wp_mcp_update');

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $updater = $GLOBALS['wp_mcp_updater'] ?? null;
        if (! $updater) {
            wp_send_json_error('Updater not available.');
        }

        $update = $updater->getUpdateInfo();
        if (! $update || ! $update['download_url']) {
            wp_send_json_error('No update available.');
        }

        // Ensure the update_plugins transient knows about our update
        $transient = get_site_transient('update_plugins');
        if (! is_object($transient)) {
            $transient = new \stdClass();
        }
        $pluginFile = $updater->getPluginFile();
        $transient->response[$pluginFile] = (object) [
            'slug'        => dirname($pluginFile),
            'plugin'      => $pluginFile,
            'new_version' => $update['version'],
            'package'     => $update['download_url'],
        ];
        set_site_transient('update_plugins', $transient);

        // Temporarily allow file mods if DISALLOW_FILE_MODS is set
        $allowFileMods = function () { return true; };
        add_filter('file_mod_allowed', $allowFileMods);

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($pluginFile);

        remove_filter('file_mod_allowed', $allowFileMods);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        if ($result === false) {
            $errors = $skin->get_errors();
            if ($errors->has_errors()) {
                wp_send_json_error($errors->get_error_message());
            }
            wp_send_json_error('Update failed.');
        }

        wp_send_json_success(['version' => $update['version']]);
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $keys = get_option($this->optionKeys, []);
        $newToken = get_transient('wp_mcp_new_token');
        $newTokenName = get_transient('wp_mcp_new_token_name');
        if ($newToken) {
            delete_transient('wp_mcp_new_token');
            delete_transient('wp_mcp_new_token_name');
        }

        $siteUrl = $this->getMcpEndpointUrl();
        $lastActivity = get_transient($this->transientActivity);
        $isConnected = $lastActivity && (time() - (int) $lastActivity) < 300;

        $updater = $GLOBALS['wp_mcp_updater'] ?? null;
        $updateInfo = $updater ? $updater->getUpdateInfo() : null;

        ?>
        <div class="wrap wp-mcp-settings">
            <h1>WP MCP Settings</h1>

            <?php settings_errors('wp-mcp'); ?>

            <!-- Update Banner -->
            <div class="wp-mcp-update-banner <?php echo $updateInfo ? 'has-update' : 'up-to-date'; ?>" id="wp-mcp-update-banner">
                <?php if ($updateInfo): ?>
                    <div class="wp-mcp-update-content">
                        <strong>WP MCP v<?php echo esc_html($updateInfo['version']); ?> is available.</strong>
                        You are running v<?php echo esc_html(WP_MCP_VERSION); ?>.
                        <?php if ($updateInfo['changelog']): ?>
                            <details>
                                <summary>View changelog</summary>
                                <div class="wp-mcp-changelog"><?php echo wp_kses_post(nl2br(esc_html($updateInfo['changelog']))); ?></div>
                            </details>
                        <?php endif; ?>
                    </div>
                    <div class="wp-mcp-update-actions">
                        <button type="button" class="button button-primary" id="wp-mcp-update-btn">Update now</button>
                    </div>
                <?php else: ?>
                    <div class="wp-mcp-update-content">
                        <strong>Up to date</strong> — v<?php echo esc_html(WP_MCP_VERSION); ?>
                    </div>
                    <div class="wp-mcp-update-actions">
                        <button type="button" class="button button-secondary" id="wp-mcp-check-update-btn">Check for updates</button>
                    </div>
                <?php endif; ?>
            </div>

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

            <!-- New Token Display -->
            <?php if ($newToken): ?>
                <div class="wp-mcp-card">
                    <div class="wp-mcp-token-display">
                        <label>New API key for "<?php echo esc_html($newTokenName); ?>" (copy it now):</label>
                        <div class="wp-mcp-copy-row">
                            <code id="wp-mcp-token"><?php echo esc_html($newToken); ?></code>
                            <button type="button" class="button wp-mcp-copy-btn" data-target="wp-mcp-token">Copy</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- API Keys -->
            <div class="wp-mcp-card">
                <h2>API Keys</h2>
                <p class="description">Each team member can have their own key. Revoking one doesn't affect the others.</p>

                <?php if (! empty($keys)): ?>
                    <table class="wp-mcp-key-table widefat striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Created</th>
                                <th>Last used</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $key): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($key['name']); ?></strong></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), $key['created_at'])); ?></td>
                                    <td><?php echo $key['last_used_at'] ? esc_html(human_time_diff($key['last_used_at']) . ' ago') : 'Never'; ?></td>
                                    <td class="wp-mcp-key-actions">
                                        <form method="post" class="wp-mcp-revoke-form">
                                            <?php wp_nonce_field($this->nonceAction); ?>
                                            <input type="hidden" name="wp_mcp_action" value="revoke_key">
                                            <input type="hidden" name="wp_mcp_key_id" value="<?php echo esc_attr($key['id']); ?>">
                                            <button type="submit" class="button button-link-delete wp-mcp-revoke-btn">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No API keys yet. Create one to get started.</p>
                <?php endif; ?>

                <form method="post" class="wp-mcp-create-key-form">
                    <?php wp_nonce_field($this->nonceAction); ?>
                    <input type="hidden" name="wp_mcp_action" value="create_key">
                    <div class="wp-mcp-create-key-row">
                        <input type="text" name="wp_mcp_key_name" placeholder="e.g. John - Claude Code" class="regular-text" required>
                        <button type="submit" class="button button-primary">Create API Key</button>
                    </div>
                </form>
            </div>

            <!-- Connection Command -->
            <div class="wp-mcp-card">
                <h2>Connection Command</h2>

                <h3>Claude Code (CLI)</h3>
                <p class="description">Run this in your terminal:</p>
                <div class="wp-mcp-copy-row">
                    <pre class="wp-mcp-code-block" id="wp-mcp-cli"><?php echo esc_html($this->getClaudeCodeCommand($siteUrl, $newToken ?: '<your-api-key>')); ?></pre>
                    <button type="button" class="button wp-mcp-copy-btn" data-target="wp-mcp-cli">Copy</button>
                </div>

                <h3>JSON Config</h3>
                <p class="description">Or add this to your AI client's MCP configuration (e.g. <code>~/.claude/settings.json</code>):</p>
                <div class="wp-mcp-copy-row">
                    <pre class="wp-mcp-code-block" id="wp-mcp-config"><?php echo esc_html($this->getConnectionJson($siteUrl, $newToken ?: '<your-api-key>')); ?></pre>
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
