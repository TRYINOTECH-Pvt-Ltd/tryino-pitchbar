<p>
    This page walks through installing the Pitchbar WordPress plugin
    on a fresh WP site, connecting it to your Pitchbar workspace, and
    verifying that the widget actually loads on the front end.
</p>

<p>
    Total time: about 3 minutes on a vanilla WordPress + WooCommerce
    install. The plugin is fully self-contained — no composer install,
    no build step, no external CDN dependency.
</p>

<h2>Prerequisites</h2>

<ul>
    <li>A Pitchbar workspace (sign up at your workspace URL, or ask your platform admin to create one).</li>
    <li>WordPress 6.4 or newer running on PHP 7.4 or newer.</li>
    <li>Workspace admin or owner role in Pitchbar (the API tokens page is admin-gated; viewer/editor are blocked).</li>
    <li>The <code>pitchbar-{version}.zip</code> archive — see "Where do I get the zip?" below.</li>
</ul>

<h2>Where do I get the zip?</h2>

<p>Three paths, pick whichever matches your setup:</p>

<ol>
    <li><strong>From your Pitchbar workspace</strong> — a super_admin opens <code>/admin/integrations/wordpress</code>, clicks <strong>Build latest</strong>, then <strong>Download</strong>. The archive lives at <code>storage/app/private/wp-plugin-builds/pitchbar-{version}.zip</code> server-side.</li>
    <li><strong>From the CLI</strong> on the Pitchbar host: <code>php artisan pitchbar:build-wp-plugin</code> writes the zip and prints its path.</li>
    <li><strong>From an existing tenant install</strong> — if your platform owner already distributed the archive to you, that's the zip; no need to rebuild.</li>
</ol>

<h2>Step 1. Upload &amp; activate</h2>

<ol>
    <li>In WordPress admin, open <strong>Plugins → Add New → Upload Plugin</strong>.</li>
    <li>Pick <code>pitchbar-{version}.zip</code> and click <strong>Install Now</strong>.</li>
    <li>Click <strong>Activate Plugin</strong>.</li>
    <li>A blue admin notice appears at the top of every admin page: <em>"Pitchbar is installed but not configured yet. Open Settings → Pitchbar to connect."</em></li>
</ol>

<p>
    The plugin registers itself with no front-end behaviour until step
    3 — until you save a workspace URL + token, the widget never
    renders.
</p>

<h2>Step 2. Create a workspace API token in Pitchbar</h2>

<ol>
    <li>Sign into your Pitchbar workspace.</li>
    <li>Open <strong>Settings → API tokens</strong>.</li>
    <li>Click <strong>Create token</strong>. Name it after the WordPress site (e.g. <code>shop.example.com</code>).</li>
    <li>Grant the <code>wp:integration</code> ability.</li>
    <li>Pitchbar displays the plaintext token <strong>exactly once</strong>. Format: <code>pbar_</code> + 48 alphanumeric characters. Copy it now — only the SHA-256 hash is persisted, so if you lose the plaintext you have to revoke and reissue.</li>
</ol>

<p>
    The same screen also generates a <code>shopper_signing_secret</code>
    on token create, which the plugin picks up automatically on its
    first handshake (you never have to paste it). See the
    <a href="/documentation/wordpress-rest-api">REST API reference</a>
    for what the secret is used for.
</p>

<h2>Step 3. Connect WordPress to Pitchbar</h2>

<ol>
    <li>In WordPress admin, open <strong>Settings → Pitchbar</strong>.</li>
    <li>Paste your Pitchbar workspace base URL (e.g. <code>https://app.pitchbar.example</code>) into <strong>Pitchbar base URL</strong>. Trailing slashes are trimmed automatically.</li>
    <li>Paste the API token you just created into <strong>API token</strong>.</li>
    <li>Click <strong>Test connection</strong>. The button disables, the status line shows "Testing connection…", and within a couple of seconds you either get a green confirmation or a red error.</li>
    <li>On success: the empty agent dropdown swaps for a populated list of every agent in your workspace, and the workspace name is shown underneath ("Connected to workspace: Acme Storefront"). The plugin also captured the shopper signing secret silently and stashed it in <code>wp_options</code>.</li>
    <li>Pick which agent should run on this site. Each agent gets its own dropdown entry with its site type appended (e.g. <em>Storefront bot (ecommerce)</em>).</li>
    <li>Confirm the <strong>Enabled</strong> toggle is checked under "Widget display".</li>
    <li>Tick the <strong>Post types</strong> you want the widget to load on. Defaults: <code>post</code> + <code>page</code>. Custom post types and (when WooCommerce is active) <code>product</code> appear as additional checkboxes.</li>
    <li>Click <strong>Save Changes</strong>.</li>
</ol>

<h2>Step 4. Verify the front-end embed</h2>

<ol>
    <li>Open a public page on your WordPress site (homepage, blog post, product page — anything matching the post types you enabled).</li>
    <li>The Pitchbar bar appears in the footer area. By default it animates in from the bottom edge.</li>
    <li>Click the bar to open the chat panel. Type a question — the agent streams a response.</li>
</ol>

<p>
    If the widget never appears, check
    <a href="/documentation/wordpress-troubleshooting">Troubleshooting</a>
    — the most common cause is a theme that omits <code>wp_footer()</code>,
    or a caching plugin that's serving a stale HTML snapshot.
</p>

<h2>What's saved server-side</h2>

<p>
    Two pieces live in WordPress' <code>wp_options</code> table under
    the key <code>pitchbar_settings</code> (plaintext, as WordPress
    options aren't encrypted at rest):
</p>

<ul>
    <li><strong>API token</strong> — full plaintext value. Trust level: same as a <code>wp-config.php</code> secret. Revoke from your Pitchbar workspace anytime to instantly invalidate it.</li>
    <li><strong><code>shopper_signing_secret</code></strong> — the per-token plaintext secret Pitchbar uses to HMAC-sign callbacks to your WP site (order lookup, coupon apply, lead push). Captured automatically on the first <strong>Test connection</strong>.</li>
    <li><strong>Selected agent, workspace ID, workspace name, enabled post types, enabled flag</strong> — small config blob.</li>
</ul>

<p>
    Uninstalling the plugin via the WordPress dashboard deletes both
    options. Deactivating only leaves them in place so re-activating
    doesn't lose the connection.
</p>

<h2>What happens on (re-)activation</h2>

<p>
    If the plugin is already configured at activation time (a
    deactivate → activate cycle on an existing install), a one-off
    full sync is scheduled 30 seconds out via
    <code>wp_schedule_single_event</code> on the <code>pitchbar_run_full_sync_event</code>
    hook. When WooCommerce is active, a second event for products is
    scheduled 60 seconds out (so posts finish first). This means
    uploading a new plugin version doesn't strand stale content; the
    next wp-cron tick refreshes the agent's knowledge base.
</p>

<p>
    Deactivating the plugin clears both scheduled hooks via
    <code>wp_clear_scheduled_hook</code> so nothing fires after the
    plugin is off.
</p>

<h2>Where the plugin shows up in WP admin</h2>

<ul>
    <li><strong>Settings → Pitchbar</strong> — the single configuration screen for everything (connection, agent picker, post-type toggles, sync buttons).</li>
    <li><strong>Plugins admin notice</strong> — the soft blue banner while the plugin is unconfigured, or while a chunked sync is finishing in the background.</li>
    <li><strong>Users admin</strong> — pushed leads land as WP users (subscriber role on non-Woo, WC customer on Woo) with <code>pitchbar_lead_id</code> + <code>pitchbar_conversation_id</code> in user meta.</li>
</ul>

<h2>Permissions</h2>

<p>
    The Settings page and all AJAX actions require the
    <code>manage_options</code> capability — the same one WordPress
    uses for "General Settings". Editors, authors, and contributors
    cannot reach it. Multisite super_admins also pass the check.
</p>

<p>
    REST endpoints (<code>/wp-json/pitchbar/v1/*</code>) are public on
    purpose (<code>permission_callback => __return_true</code>) and
    instead authenticate by HMAC signature on every request. See
    <a href="/documentation/wordpress-rest-api">REST API reference</a>.
</p>

<h2>Uninstalling cleanly</h2>

<ol>
    <li>Open <strong>Plugins</strong> in the WP admin.</li>
    <li>Click <strong>Deactivate</strong> next to Pitchbar. Scheduled sync events are cleared.</li>
    <li>Click <strong>Delete</strong>. WordPress invokes <code>uninstall.php</code> which removes the <code>pitchbar_settings</code> option and the <code>pitchbar_activation_flag</code> flag.</li>
    <li>Revoke the WordPress API token in <code>/settings/api-tokens</code> on Pitchbar so the plaintext that lived in <code>wp_options</code> is dead immediately.</li>
</ol>

<p>
    Knowledge that the plugin already pushed to Pitchbar stays in the
    knowledge base — uninstalling the plugin doesn't delete the
    agent's Sources or Documents. Remove those from the Pitchbar admin
    if you want a full teardown.
</p>
