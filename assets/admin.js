(function () {
    'use strict';

    // Copy to clipboard
    document.querySelectorAll('.wp-mcp-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var target = document.getElementById(targetId);
            if (!target) return;

            var text = target.textContent;
            navigator.clipboard.writeText(text).then(function () {
                var original = btn.textContent;
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = original;
                    btn.classList.remove('copied');
                }, 2000);
            });
        });
    });

    // Revoke confirmation
    document.querySelectorAll('.wp-mcp-revoke-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!confirm('Are you sure you want to revoke this API key? Any client using it will lose access.')) {
                e.preventDefault();
            }
        });
    });

    // Status polling
    if (typeof wpMcp === 'undefined') return;

    function checkStatus() {
        fetch(wpMcp.statusUrl, {
            headers: { 'X-WP-Nonce': wpMcp.nonce },
            credentials: 'same-origin',
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var dot = document.getElementById('wp-mcp-status-dot');
                var text = document.getElementById('wp-mcp-status-text');
                var activity = document.getElementById('wp-mcp-last-activity');

                if (!dot || !text) return;

                if (data.connected) {
                    dot.className = 'wp-mcp-status-dot connected';
                    text.textContent = 'Connected';
                } else {
                    dot.className = 'wp-mcp-status-dot disconnected';
                    text.textContent = 'Not connected';
                }

                if (activity && data.last_activity_human) {
                    activity.textContent = 'Last activity: ' + data.last_activity_human;
                }
            })
            .catch(function () {});
    }

    setInterval(checkStatus, 30000);

    // Update plugin
    var updateBtn = document.getElementById('wp-mcp-update-btn');
    if (updateBtn) {
        updateBtn.addEventListener('click', function () {
            if (!confirm('Update WP MCP to the latest version?')) return;

            updateBtn.disabled = true;
            updateBtn.textContent = 'Updating...';

            var formData = new FormData();
            formData.append('action', 'wp_mcp_update_plugin');
            formData.append('_ajax_nonce', wpMcp.ajaxNonce);

            fetch(wpMcp.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        var banner = document.getElementById('wp-mcp-update-banner');
                        banner.className = 'wp-mcp-update-banner up-to-date';
                        banner.innerHTML =
                            '<div class="wp-mcp-update-content"><strong>Updated successfully!</strong> Reload the page to use v' +
                            data.data.version + '.</div>' +
                            '<div class="wp-mcp-update-actions"><button type="button" class="button button-primary" onclick="location.reload()">Reload</button></div>';
                    } else {
                        updateBtn.disabled = false;
                        updateBtn.textContent = 'Update now';
                        alert('Update failed: ' + (data.data || 'Unknown error'));
                    }
                })
                .catch(function () {
                    updateBtn.disabled = false;
                    updateBtn.textContent = 'Update now';
                    alert('Update failed: network error.');
                });
        });
    }

    // Check for updates
    var checkBtn = document.getElementById('wp-mcp-check-update-btn');
    if (checkBtn) {
        checkBtn.addEventListener('click', function () {
            checkBtn.disabled = true;
            checkBtn.textContent = 'Checking...';

            var formData = new FormData();
            formData.append('action', 'wp_mcp_check_update');
            formData.append('_ajax_nonce', wpMcp.ajaxNonce);

            fetch(wpMcp.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.data.version && !data.data.up_to_date) {
                        var banner = document.getElementById('wp-mcp-update-banner');
                        banner.className = 'wp-mcp-update-banner has-update';
                        banner.innerHTML =
                            '<div class="wp-mcp-update-content"><strong>WP MCP v' + data.data.version + ' is available.</strong></div>' +
                            '<div class="wp-mcp-update-actions"><button type="button" class="button button-primary" onclick="location.reload()">Reload to update</button></div>';
                    } else {
                        checkBtn.disabled = false;
                        checkBtn.textContent = 'Check for updates';
                        if (data.success) {
                            alert('You are running the latest version.');
                        }
                    }
                })
                .catch(function () {
                    checkBtn.disabled = false;
                    checkBtn.textContent = 'Check for updates';
                });
        });
    }
})();
