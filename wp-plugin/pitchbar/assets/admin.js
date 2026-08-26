(function () {
    'use strict';

    if (typeof window.PitchbarAdmin === 'undefined') {
        return;
    }

    var cfg = window.PitchbarAdmin;

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('pitchbar-test-connection');
        var resultEl = document.getElementById('pitchbar-test-result');
        var baseUrlInput = document.getElementById('pitchbar_base_url');
        var tokenInput = document.getElementById('pitchbar_api_token');
        var agentSelect = document.getElementById('pitchbar_agent_id');
        var workspaceIdInput = document.getElementById('pitchbar_workspace_id');
        var workspaceNameInput = document.getElementById('pitchbar_workspace_name');
        var shopperSecretInput = document.getElementById('pitchbar_shopper_signing_secret');

        if (!btn || !resultEl) {
            return;
        }

        btn.addEventListener('click', function () {
            var baseUrl = (baseUrlInput && baseUrlInput.value) || '';
            var token = (tokenInput && tokenInput.value) || '';

            resultEl.className = 'pitchbar-test-result is-busy';
            resultEl.textContent = cfg.i18n.testing;
            btn.disabled = true;

            var body = new URLSearchParams();
            body.append('action', cfg.action);
            body.append('nonce', cfg.nonce);
            body.append('base_url', baseUrl);
            body.append('api_token', token);

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: body.toString(),
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { status: response.status, json: json };
                    });
                })
                .then(function (out) {
                    btn.disabled = false;

                    if (!out.json || !out.json.success) {
                        renderTestFailure(resultEl, out);

                        return;
                    }

                    var data = out.json.data || {};
                    var workspace = data.workspace || {};
                    var agents = Array.isArray(data.agents) ? data.agents : [];

                    if (workspaceIdInput) {
                        workspaceIdInput.value = workspace.id || '';
                    }

                    if (workspaceNameInput) {
                        workspaceNameInput.value = workspace.name || '';
                    }

                    if (shopperSecretInput && data.shopper_signing_secret) {
                        shopperSecretInput.value = data.shopper_signing_secret;
                    }

                    if (agentSelect) {
                        // Wipe existing options before repopulating.
                        agentSelect.innerHTML = '';

                        if (agents.length === 0) {
                            var empty = document.createElement('option');
                            empty.value = '';
                            empty.textContent = cfg.i18n.noAgents;
                            agentSelect.appendChild(empty);
                        } else {
                            agents.forEach(function (agent) {
                                var opt = document.createElement('option');
                                opt.value = agent.id;
                                opt.textContent = agent.name + (agent.site_type ? ' (' + agent.site_type + ')' : '');
                                agentSelect.appendChild(opt);
                            });
                        }
                    }

                    resultEl.className = 'pitchbar-test-result is-success';
                    resultEl.textContent = cfg.i18n.success + (workspace.name ? ' — ' + workspace.name : '');
                })
                .catch(function (err) {
                    btn.disabled = false;
                    resultEl.className = 'pitchbar-test-result is-error';
                    resultEl.textContent =
                        (err && err.message ? 'JS: ' + err.message : '') ||
                        cfg.i18n.failure;
                });
        });

        /**
         * Render the full diagnostic block on a Test-connection
         * failure. We surface: the human summary line, the HTTP
         * status, the URL we tried, the transport code (cURL error
         * code on a network failure), and an excerpt of the raw
         * upstream response body. The admin can copy/paste the lot
         * into an issue without re-running the request.
         */
        function renderTestFailure(resultEl, out) {
            var data = (out && out.json && out.json.data) || {};
            var summary = data.message || cfg.i18n.failure;
            var diag = data.diag || {};

            resultEl.className = 'pitchbar-test-result is-error pitchbar-diag';
            resultEl.textContent = '';

            var summaryEl = document.createElement('div');
            summaryEl.className = 'pitchbar-diag-summary';
            summaryEl.textContent = summary;
            resultEl.appendChild(summaryEl);

            if (!diag || Object.keys(diag).length === 0) {
                return;
            }

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'pitchbar-diag-toggle';
            toggle.textContent = cfg.i18n.showDetails || 'Show details';
            resultEl.appendChild(toggle);

            var details = document.createElement('pre');
            details.className = 'pitchbar-diag-details';
            details.style.display = 'none';
            details.textContent = formatDiag(diag);
            resultEl.appendChild(details);

            toggle.addEventListener('click', function () {
                var open = details.style.display !== 'none';

                details.style.display = open ? 'none' : 'block';
                toggle.textContent = open
                    ? cfg.i18n.showDetails || 'Show details'
                    : cfg.i18n.hideDetails || 'Hide details';
            });
        }

        function formatDiag(diag) {
            var lines = [];

            if (diag.url) {
                lines.push('URL:           ' + diag.url);
            }

            if (typeof diag.status !== 'undefined') {
                lines.push(
                    'HTTP status:   ' + (diag.status === 0 ? '(never connected)' : diag.status),
                );
            }

            if (diag.transport_code) {
                lines.push('Transport:     ' + diag.transport_code);
            }

            if (diag.error) {
                lines.push('Error:         ' + diag.error);
            }

            if (diag.plugin_version) {
                lines.push('Plugin:        v' + diag.plugin_version);
            }

            if (diag.body_excerpt) {
                lines.push('');
                lines.push('--- Upstream response body (first 800 chars) ---');
                lines.push(diag.body_excerpt);
            }

            return lines.join('\n');
        }

        function wireSyncButton(btnId, resultId, actionKey, busyKey, doneKey, failedKey, summaryKey) {
            var btn = document.getElementById(btnId);
            var resultEl = document.getElementById(resultId);

            if (!btn || !resultEl) {
                return;
            }

            btn.addEventListener('click', function () {
                resultEl.className = 'pitchbar-test-result is-busy';
                resultEl.textContent = cfg.i18n[busyKey];
                btn.disabled = true;

                var body = new URLSearchParams();
                body.append('action', cfg[actionKey]);
                body.append('nonce', cfg.nonce);

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString(),
                })
                    .then(function (response) {
                        return response.json().then(function (json) {
                            return { status: response.status, json: json };
                        });
                    })
                    .then(function (out) {
                        btn.disabled = false;

                        if (!out.json || !out.json.success) {
                            var msg = (out.json && out.json.data && out.json.data.message) || cfg.i18n[failedKey];
                            resultEl.className = 'pitchbar-test-result is-error';
                            resultEl.textContent = msg;

                            return;
                        }

                        var data = out.json.data || {};
                        var unit = summaryKey === 'productSyncDone' ? (data.products || 0) + ' products' : (data.posts || 0) + ' posts';
                        var summary = cfg.i18n[doneKey] +
                            ' (' + unit + ', ' +
                            (data.queued || 0) + ' queued, ' +
                            (data.skipped || 0) + ' skipped)';

                        if (data.more) {
                            summary += ' — ' + (cfg.i18n.continuesInBackground || 'large site detected, the rest is continuing in the background.');
                        }

                        resultEl.className = 'pitchbar-test-result is-success';
                        resultEl.textContent = summary;
                    })
                    .catch(function () {
                        btn.disabled = false;
                        resultEl.className = 'pitchbar-test-result is-error';
                        resultEl.textContent = cfg.i18n[failedKey];
                    });
            });
        }

        wireSyncButton(
            'pitchbar-run-sync',
            'pitchbar-sync-result',
            'syncAction',
            'syncing',
            'syncDone',
            'syncFailed',
            'syncDone'
        );

        if (cfg.wooActive) {
            wireSyncButton(
                'pitchbar-run-product-sync',
                'pitchbar-product-sync-result',
                'productSyncAction',
                'syncingProducts',
                'productSyncDone',
                'productSyncFailed',
                'productSyncDone'
            );
        }

    });
})();
