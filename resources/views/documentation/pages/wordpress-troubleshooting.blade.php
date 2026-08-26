<p>
    Symptom-first guide for the most common WordPress plugin issues.
    Every fix is grounded in the actual code path — no aspirational
    advice.
</p>

<h2>Widget doesn't appear on the site</h2>

<p>Check, in order:</p>

<ol>
    <li><strong>Plugin configured?</strong> Open <strong>Settings → Pitchbar</strong>. All three fields (base URL, API token, agent) must be filled. The <code>Enabled</code> toggle must be on.</li>
    <li><strong>Right post type?</strong> The "Post types" checkboxes gate which singular pages load the widget. By default only <code>post</code> + <code>page</code> are checked. WooCommerce <code>product</code> pages need an explicit opt-in.</li>
    <li><strong>Theme calls <code>wp_footer()</code>?</strong> The plugin injects the script via <code>add_action('wp_footer', …, 99)</code>. A theme that omits <code>wp_footer()</code> from <code>footer.php</code> won't load the widget. Most modern themes call it; legacy custom themes occasionally don't.</li>
    <li><strong>Caching plugin serving a stale snapshot?</strong> WP Rocket, W3 Total Cache, LiteSpeed, and Cloudflare APO can all keep a pre-widget HTML version cached for days. Flush the page cache for the affected URLs.</li>
    <li><strong>Browser blocking the widget script?</strong> Open DevTools → Network. The request to <code>{base_url}/widget/widget.js</code> should return 200. If you see <code>connect-src</code> violations in the console, your CSP needs an allowlist entry. See <a href="/documentation/allowed-origins">Allowed origins</a>.</li>
</ol>

<h2>"Test connection" fails</h2>

<table>
    <thead><tr><th>Error message</th><th>Diagnosis</th></tr></thead>
    <tbody>
        <tr>
            <td>"Connection failed."</td>
            <td>Network-level — Pitchbar host unreachable from WP. Run <code>wp shell</code>, try <code>wp_remote_get('https://app.pitchbar.example/up')</code>. Common cause: outbound firewall blocking the Pitchbar host.</td>
        </tr>
        <tr>
            <td>HTTP 401 invalid_token</td>
            <td>Token plaintext is wrong (typo on paste) or the token was revoked in <code>/settings/api-tokens</code>. Reissue a new one.</td>
        </tr>
        <tr>
            <td>HTTP 403 insufficient_ability</td>
            <td>Token wasn't created with the <code>wp:integration</code> ability. Reissue with the right scope.</td>
        </tr>
        <tr>
            <td>"Enter both a base URL and an API token, then try again."</td>
            <td>One of the form fields is empty. The plugin defends client-side to avoid burning a request.</td>
        </tr>
        <tr>
            <td>"Base URL must start with http:// or https://."</td>
            <td><code>SettingsValidator</code> rejects URLs without a scheme. Paste the full base URL with <code>https://</code>.</td>
        </tr>
        <tr>
            <td>"API token format looks wrong"</td>
            <td>Plugin expects <code>pbar_</code> + 48 alphanumeric characters. Either the wrong string was pasted, or the token was truncated on copy.</td>
        </tr>
    </tbody>
</table>

<h2>"Sync now" finishes but Pitchbar doesn't see new content</h2>

<p>
    The sync POST returned successfully but the Pitchbar admin's
    Sources page shows no posts. Possibilities:
</p>

<ol>
    <li><strong>Sync is still resuming.</strong> On a large site (500+ posts) the first pass only does what fits in 20s. The rest finishes on a WP-Cron tick 30 seconds later. The admin shows a soft notice: <em>"Pitchbar is finishing a large-site sync in the background."</em> Wait, refresh, the count climbs.</li>
    <li><strong>Indexing is queued, not synchronous.</strong> Pitchbar accepts the upload and queues an <code>IndexDocumentJob</code> on Horizon. On a busy worker this can run 30-60s behind. Check <code>/admin/system</code> → Failed jobs for any errors.</li>
    <li><strong>Vector store is provisioning.</strong> A brand-new Cloudflare Vectorize index takes about 2 minutes before queries return results. Upserts succeed immediately; reads return 0. First batch of upserts post-create can also drop silently — re-run the sync.</li>
    <li><strong>Content hash matched.</strong> If you previously synced the post and nothing changed, the response's <code>skipped_unchanged</code> count increments without re-indexing. The Document already exists; that's working as intended.</li>
</ol>

<h2>Sync resumed but never finishes</h2>

<p>
    A resume transient (<code>pitchbar_post_sync_resume</code> or
    <code>pitchbar_product_sync_resume</code>) sits in
    <code>wp_options</code> but the WP-Cron continuation never
    fires. Most likely:
</p>

<ul>
    <li><strong>WP-Cron is disabled.</strong> Check <code>wp-config.php</code> for <code>define('DISABLE_WP_CRON', true);</code>. Some hosts disable it and run the cron via a real system cron — confirm with your host. If WP-Cron is fully disabled, manually run "Sync now" again from the Settings page; the syncer reads the resume marker on its first call and picks up where it stopped.</li>
    <li><strong>WP-Cron is silently failing.</strong> Run <code>wp cron event list</code> on the command line. The <code>pitchbar_run_full_sync_event</code> / <code>pitchbar_run_product_sync_event</code> entries should appear with a next-run timestamp in the past. <code>wp cron event run --due-now</code> forces them.</li>
    <li><strong>The site never receives traffic.</strong> WP-Cron is opportunistic — it runs on the next pageview after the scheduled time. A staging site with no visitors doesn't tick the cron. Either visit any front-end page, or trigger the hook manually: <code>wp eval '(new \Pitchbar\Sync\PostSyncer)-&gt;runFullSync();'</code>.</li>
</ul>

<h2>Force-clearing a stuck resume marker</h2>

<p>If you want to start the next sync from page 1:</p>

<pre><code>wp transient delete pitchbar_post_sync_resume
wp transient delete pitchbar_product_sync_resume</code></pre>

<p>
    The next "Sync now" click then enumerates from page 1 again.
    Re-syncing is cheap — Pitchbar's content_hash short-circuit
    avoids re-embedding unchanged posts.
</p>

<h2>Elementor / Bricks / Oxygen pages sync as empty content</h2>

<p>Three possibilities:</p>

<ol>
    <li><strong>The page builder version changed its renderer signature.</strong> The plugin reflects into <code>\Elementor\Plugin::$instance-&gt;frontend-&gt;get_builder_content_for_display</code>, <code>FLBuilder::render_content_by_id</code>, and <code>\Bricks\Frontend::render_content</code>. A major version that renames these returns an empty string and the plugin falls through to the vanilla path. Confirm with: <code>wp eval '$post = get_post(YOUR_ID); echo (new \Pitchbar\Sync\PageBuilderContent)-&gt;detectBuilder($post);'</code>.</li>
    <li><strong>The page genuinely is empty in the builder.</strong> Open the post in the WP admin and edit in the builder — sometimes a migration corrupts the postmeta and the builder shows a "Default content" placeholder. Fix at the builder level; sync picks up the next save.</li>
    <li><strong>You're behind a custom <code>pitchbar_post_content_html</code> filter.</strong> If your code returns an empty string from the filter, no content is sent. Check <code>functions.php</code> / mu-plugins / any custom plugin.</li>
</ol>

<h2>Coupon Apply button does nothing on the cart page</h2>

<ol>
    <li><strong>The visitor doesn't have the <code>pitchbar_conv_id</code> cookie.</strong> The widget writes it at init; check DevTools → Application → Cookies for the WP site origin. If absent, the widget probably never loaded on the chat page (see "Widget doesn't appear" above).</li>
    <li><strong>The conversation already has a different code applied.</strong> The plugin's <code>woocommerce_load_cart_from_session</code> hook calls <code>WC()-&gt;cart-&gt;has_discount($code)</code> first and skips if already applied. WooCommerce only allows one of each unique code; switching codes requires removing the previous one.</li>
    <li><strong>The transient expired.</strong> Pending coupons live 15 minutes. If the visitor staged a code, then closed the browser for an hour before opening the cart, the transient is gone. Click Apply again in chat.</li>
    <li><strong>The coupon was removed in WC admin.</strong> The plugin verifies coupon validity at apply time via <code>new WC_Coupon($code)-&gt;get_id()</code>. If the code no longer maps to a coupon, the apply call returns 400 <code>invalid_coupon</code>.</li>
</ol>

<h2>Abandoned cart trigger never fires</h2>

<ul>
    <li><strong><code>cart-state.js</code> isn't loaded.</strong> Open DevTools → Sources, search for <code>pitchbar-cart-state</code>. The script only enqueues when WooCommerce is detected AND the plugin is configured.</li>
    <li><strong>localStorage is disabled.</strong> Private browsing, Safari ITP, or a hardened browser profile may block <code>localStorage.setItem</code>. The script catches the exception silently — there's no error UI. The trigger requires localStorage; degrade gracefully by adding a different proactive rule (e.g. <code>time</code> or <code>idle</code>).</li>
    <li><strong>WC fires non-jQuery events.</strong> Some custom WC themes (Flatsome's quick-view, AJAX add-to-cart plugins) skip the standard jQuery <code>added_to_cart</code> event. The script's DOM-click fallback covers buttons with <code>.add_to_cart_button</code> / <code>.single_add_to_cart_button</code> classnames — if your theme uses different classnames, the cart state isn't recorded. Add an inline shim or open an issue.</li>
    <li><strong>The trigger's <code>idle_minutes</code> threshold isn't met.</strong> The widget polls every 30s and fires when <code>now - timestamp &gt; idle_minutes * 60_000 ms</code>. A 5-minute threshold means at least 5 minutes of zero cart events must elapse before the trigger considers the cart abandoned. Double-check the rule's <code>conditions.idle_minutes</code>.</li>
    <li><strong>Global trigger cooldown.</strong> The widget enforces a 5-minute cooldown across all proactive rules so the visitor isn't ambushed at every page transition. Fire one rule, none of the others fire for 5 minutes.</li>
</ul>

<h2>HMAC verification failures (plugin REST)</h2>

<p>
    Pitchbar → plugin call returns 401 with
    <code>{ "error": { "code": "signature_mismatch" } }</code>. The
    plugin logs <em>"Plugin REST HMAC mismatch"</em> via
    <code>error_log</code> when this happens. Causes, in order:
</p>

<ol>
    <li><strong>WP server clock is more than 5 minutes off UTC.</strong> Run <code>date -u</code>. Compare to <code>https://time.is</code>. If skew &gt; 5 minutes, NTP isn't running or is broken on the host. Fix at the OS level.</li>
    <li><strong>Plugin's <code>shopper_signing_secret</code> doesn't match Pitchbar's.</strong> This happens if you regenerated the API token in Pitchbar (which rolls a new <code>shopper_signing_secret</code>) without re-running "Test connection" in WP. Re-click <strong>Test connection</strong> — the plugin captures the new secret silently.</li>
    <li><strong>The signing secret is empty on the WP side.</strong> Returned as <code>plugin_unconfigured</code> rather than <code>signature_mismatch</code>. Indicates the plugin was upgraded from a pre-1.1.0 install where the secret didn't exist yet. Re-click Test connection.</li>
    <li><strong>A reverse proxy is mutating the request body.</strong> Cloudflare's HTML rewrites, security plugins like Wordfence inline scanning, and certain mod_security rules can alter the JSON body in transit. The HMAC is computed over the raw bytes, so any mutation breaks the signature. Exclude <code>/wp-json/pitchbar/v1/*</code> from rewrite/scan rules.</li>
</ol>

<h2>RTL site shows widget on the wrong edge</h2>

<p>
    The plugin emits <code>data-page-dir="rtl"</code> and
    <code>data-page-locale</code> on the widget script tag. The
    widget reads them and mirrors the bar to the right edge of the
    viewport on Arabic, Hebrew, Persian, and Urdu locales.
</p>

<ul>
    <li><strong>Wrong locale detected?</strong> Check WP's Settings → General → Site Language. The plugin honours <code>determine_locale()</code> (which respects per-user overrides) and falls back to <code>get_locale()</code>.</li>
    <li><strong>Custom widget skin?</strong> If you've overridden the widget's CSS via <code>Customize</code>, your custom rules may not have RTL variants. Inspect <code>.pitchbar-bar</code> in DevTools — it should have <code>dir="rtl"</code> set when the page is RTL.</li>
</ul>

<h2>Plugins admin shows the "WooCommerce not detected" warning even though Woo is active</h2>

<p>
    On v2.0.0+ this is impossible — the plugin defers to
    <code>woocommerce_loaded</code>. If you're seeing this on an
    older install, upgrade to v2.0.0. Symptoms of the v1.x bug:
</p>

<ul>
    <li>Product sync button missing from Settings page.</li>
    <li>Coupon emission empty (no codes ever sync).</li>
    <li>Cart coupon REST endpoint returns 400 <code>woocommerce_inactive</code> even though Woo runs fine in <code>/shop</code>.</li>
</ul>

<h2>Reading plugin logs</h2>

<p>
    The plugin writes to <code>error_log</code> via
    <code>Pitchbar\Support\Logger</code>. To see them on a typical
    WordPress install:
</p>

<ol>
    <li>Set <code>WP_DEBUG_LOG</code> to <code>true</code> in <code>wp-config.php</code>.</li>
    <li>The plugin's events show up in <code>wp-content/debug.log</code> prefixed with <code>[pitchbar]</code>.</li>
    <li>Notable lines: <em>"Plugin REST HMAC mismatch"</em>, <em>"PostSyncer batch failed"</em>, <em>"CouponSyncer hydrate failed"</em>, <em>"Lead user create failed"</em>.</li>
</ol>

<h2>Where to file an issue</h2>

<p>
    If none of the above resolves your problem, open an issue with:
</p>

<ul>
    <li>Plugin version (visible at top of <code>wp-plugin/pitchbar/pitchbar.php</code> or in Plugins admin).</li>
    <li>WordPress version + PHP version (visible in <em>Tools → Site Health → Info → WordPress</em>).</li>
    <li>WooCommerce version (if applicable).</li>
    <li>Active page builder + its version (if applicable).</li>
    <li>The relevant <code>wp-content/debug.log</code> tail.</li>
    <li>A minimal reproduction: which button you clicked, what you expected, what happened.</li>
</ul>
