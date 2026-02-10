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
})();
