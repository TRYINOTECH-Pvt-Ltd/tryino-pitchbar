<p>
    Pitchbar ships a first-party WordPress &amp; WooCommerce plugin that
    drops the streaming chat widget on every public page, syncs the
    site's content as a knowledge source, and (when WooCommerce is
    active) deeply integrates with the store — product cards, coupon
    application, order lookup, lead mirroring, and abandoned-cart
    re-engagement.
</p>

<p>
    The plugin lives in the Pitchbar monorepo at
    <code>wp-plugin/pitchbar/</code> and ships as a versioned zip you
    can install from <strong>Plugins → Add New → Upload Plugin</strong>
    in any WordPress 6.4+ install on PHP 7.4+.
</p>

<div class="docs-cards">
    <a class="docs-card" href="/documentation/wordpress-install">
        <div class="docs-card-title">Install &amp; connect</div>
        <div class="docs-card-body">Upload the zip, paste your workspace base URL + API token, pick the agent the widget should run.</div>
    </a>
    <a class="docs-card" href="/documentation/wordpress-sync">
        <div class="docs-card-title">Content sync</div>
        <div class="docs-card-body">How posts, pages, and Woo products flow from WordPress to your Pitchbar agent. Delta hooks, content hash, resumable sync.</div>
    </a>
    <a class="docs-card" href="/documentation/wordpress-page-builders">
        <div class="docs-card-title">Page builders</div>
        <div class="docs-card-body">Elementor, Divi, Beaver, Oxygen, and Bricks pages are fully rendered before sync. Includes an override filter.</div>
    </a>
    <a class="docs-card" href="/documentation/wordpress-woocommerce">
        <div class="docs-card-title">WooCommerce deep links</div>
        <div class="docs-card-body">Logged-in shopper context, order lookup, coupon emission &amp; apply, lead push, abandoned cart trigger.</div>
    </a>
    <a class="docs-card" href="/documentation/wordpress-rest-api">
        <div class="docs-card-title">REST API reference</div>
        <div class="docs-card-body">Every endpoint on both sides of the wire — Pitchbar's <code>/api/v1/wp/*</code> and the plugin's <code>/wp-json/pitchbar/v1/*</code>.</div>
    </a>
    <a class="docs-card" href="/documentation/wordpress-troubleshooting">
        <div class="docs-card-title">Troubleshooting</div>
        <div class="docs-card-body">Sync stuck mid-run, widget invisible, theme conflicts, RTL mirroring, HMAC mismatches.</div>
    </a>
</div>

<h2>What this plugin does</h2>

<ul>
    <li><strong>Widget embed</strong> — one asynchronous <code>&lt;script&gt;</code> tag injected into <code>wp_footer</code> on every public page, scoped to the post types you opt into. RTL-aware. Hidden on <code>wp-admin</code>, <code>wp-login</code>, AJAX, REST, XML-RPC, and cron requests.</li>
    <li><strong>Content sync</strong> — bulk + delta ingest of posts, pages, custom post types, and WooCommerce products into a Pitchbar knowledge source. Resumable on shared hosting with a 30s exec cap.</li>
    <li><strong>Page-builder rendering</strong> — Elementor, Divi, Beaver Builder, Oxygen, and Bricks pages are rendered via each builder's native API so their visible HTML actually reaches the RAG store (instead of empty <code>post_content</code>).</li>
    <li><strong>Shopper context</strong> — a logged-in WooCommerce customer's <code>wp_user_id</code> + <code>email_hash</code> (never plaintext) are signed and forwarded to the chat session so the agent knows who's talking.</li>
    <li><strong>Order lookup</strong> — the <code>lookup_order</code> tool calls back into <code>/wp-json/pitchbar/v1/orders/lookup</code> over HMAC-signed POST so the agent can answer "where's my order?" without exposing customer data.</li>
    <li><strong>Coupon emission &amp; apply</strong> — the agent renders <code>&lt;coupon/&gt;</code> cards in chat; the Apply button stages the code in a transient and applies it on the visitor's next cart load.</li>
    <li><strong>Lead mirroring</strong> — every Pitchbar lead is pushed back into WordPress as a WC customer (or WP subscriber when Woo is absent) with conversation correlation in user meta.</li>
    <li><strong>Abandoned-cart trigger</strong> — a tiny front-end script mirrors WC cart events into <code>localStorage</code>; the widget proactively engages when the cart sits idle past the configured threshold.</li>
</ul>

<h2>Compatibility matrix</h2>

<table>
    <thead><tr><th>Component</th><th>Tested range</th></tr></thead>
    <tbody>
        <tr><td>WordPress</td><td>6.4 → 6.6</td></tr>
        <tr><td>PHP</td><td>7.4 → 8.4</td></tr>
        <tr><td>WooCommerce</td><td>8.0 → 9.x (optional — plugin core works without WC)</td></tr>
        <tr><td>Multisite</td><td>Supported (per-site activation; each site connects to its own Pitchbar workspace)</td></tr>
        <tr><td>Page builders</td><td>Elementor (Free + Pro), Divi, Beaver Builder, Oxygen, Bricks</td></tr>
        <tr><td>Theme</td><td>Theme-agnostic. Widget renders in a Shadow DOM isolated from theme CSS.</td></tr>
        <tr><td>RTL locales</td><td>Yes (Arabic, Hebrew, Persian, Urdu, etc.). Widget mirrors via <code>data-page-dir</code>.</td></tr>
    </tbody>
</table>

<h2>Distribute the plugin (super_admin)</h2>

<p>
    Super-admins can produce an install-ready <code>.zip</code> of the
    plugin from inside the Pitchbar admin without ssh access. Open
    <code>/admin/integrations/wordpress</code>:
</p>

<ol>
    <li>Click <strong>Build latest</strong>. The server runs <code>php artisan pitchbar:build-wp-plugin</code> against the bundled <code>wp-plugin/pitchbar/</code> source tree and writes a versioned archive to <code>storage/app/private/wp-plugin-builds/pitchbar-{version}.zip</code>.</li>
    <li>Click <strong>Download</strong> on the resulting row. The archive is streamed back with the correct <code>Content-Disposition</code>; it's roughly 45&nbsp;KB and contains a top-level <code>pitchbar/</code> directory.</li>
    <li>The archive excludes dev-only files (<code>.DS_Store</code>, <code>node_modules/</code>, <code>tests/</code>, <code>.git*</code>) so what your tenants upload is exactly what WordPress should install.</li>
</ol>

<p>
    Headless alternative: <code>php artisan pitchbar:build-wp-plugin</code>
    works from the CLI and prints the resulting path. The optional
    <code>--output</code> flag overrides the destination directory.
</p>

<h2>Security model at a glance</h2>

<p>
    The plugin and the Pitchbar server authenticate each other with
    two distinct credentials, in opposite directions:
</p>

<ul>
    <li><strong>Plugin → Pitchbar:</strong> bearer API token. Created in <code>/settings/api-tokens</code>, scope <code>wp:integration</code>. Pitchbar stores only the SHA-256 hash of the plaintext. The plugin keeps the plaintext in <code>wp_options</code> (treat as <code>wp-config.php</code>-level secret).</li>
    <li><strong>Pitchbar → Plugin:</strong> HMAC-SHA256 signature using the <em>per-token</em> <code>shopper_signing_secret</code>. The plugin receives this secret in the handshake response and stores it locally. Pitchbar uses it to sign every callback (order lookup, coupon apply, lead push) so the WordPress REST endpoints can verify the caller is Pitchbar without ever holding the bearer plaintext.</li>
    <li><strong>Replay protection:</strong> both directions enforce a 5-minute timestamp window on the HMAC signature.</li>
</ul>

<p>
    See <a href="/documentation/wordpress-rest-api">REST API reference</a>
    for the exact signature scheme and verification code path.
</p>

<h2>Current release</h2>

<p>
    Plugin version <strong>2.0.5</strong>. Notable since v1.x:
</p>

<h3>2.0.x patch series</h3>

<ul>
    <li><strong>2.0.5 — Simplified Chinese (zh_CN) + standalone bundled documentation.</strong> Buyer-requested zh_CN language pack ships both <code>pitchbar-zh_CN.po</code> (editable) and <code>pitchbar-zh_CN.mo</code> (compiled, what WP loads). <code>pitchbar/documentation.html</code> now ships inside the install with the full Mintlify-style reference — open in any browser, no internet required. <code>php artisan pitchbar:build-wp-plugin</code> compiles every <code>.po</code> to <code>.mo</code> automatically before zipping, so future language packs ship correctly without GNU <code>msgfmt</code> on the build host.</li>
    <li><strong>2.0.4 — Admin column width fix.</strong> On the WooCommerce Products list (and other crowded list tables) the &ldquo;Pitchbar&rdquo; column was rendering one letter per line because WP's auto-width algorithm starved it of pixels. Forced to 110px via an <code>admin_head</code> style block.</li>
    <li><strong>2.0.3 — &ldquo;Indexed&rdquo; admin badges + empty-body fallback.</strong> Posts, Pages, and Products lists now render a green &ldquo;Indexed&rdquo; pill, yellow &ldquo;Out of date&rdquo; pill, or gray &ldquo;Not indexed&rdquo; pill per row based on the sync timestamp + content hash the plugin stamps on every push. Featured-image-only / builder-stub posts whose <code>the_content</code> collapses to empty are synthesised from <code>title + excerpt + taxonomy terms</code> client-side so they always have indexable text.</li>
    <li><strong>2.0.2 — Empty-body posts + every product type.</strong> Pre-fix a post-sync batch died with <code>posts.N.content_html field is required</code> the first time any post had an empty body. Pre-fix the product syncer filtered to <code>[simple, variable, grouped, external]</code> which silently dropped subscriptions, bundles, memberships, bookings, and custom types. Both fixed; a fallback <code>WP_Query</code> covers hosts whose <code>wc_get_products</code> hook chain hides everything.</li>
    <li><strong>2.0.1 — Diagnostic Test connection.</strong> The generic &ldquo;Connection failed&rdquo; message is gone. The plugin now surfaces HTTP status, the URL it tried, the transport-level cURL / DNS code on network failure, and the first 800 chars of the upstream response body. A &ldquo;Show details&rdquo; toggle reveals the full diagnostic block. HTTP-status-aware hints (401 → reissue token, 403 → missing scope, 404 → not deployed, 5xx → server log).</li>
</ul>

<h3>2.0.0 — major release</h3>

<ul>
    <li>Page-builder content actually reaches the RAG store (was empty for Elementor/Bricks/Oxygen).</li>
    <li>WooCommerce load-order race fixed — the plugin's WC-dependent hooks now defer to <code>woocommerce_loaded</code> instead of trusting <code>class_exists('WooCommerce')</code> at <code>plugins_loaded</code> priority 10.</li>
    <li>Sync is resumable across multiple WP-Cron ticks — large catalogs no longer hit the 30s exec cap.</li>
    <li>Coupon enumeration switched from <code>wc_get_coupons()</code> (not public API) to the <code>shop_coupon</code> CPT directly.</li>
    <li><code>abandoned_cart</code> rule kind exposed in the agent Behavior triggers admin.</li>
    <li>RTL mirroring + admin background-sync notice.</li>
</ul>

<p>
    See <a href="/documentation/changelog">application changelog</a> for
    the full release history.
</p>
