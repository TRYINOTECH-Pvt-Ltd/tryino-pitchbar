<p>
    Widget isn't showing on your site, or shows only for logged-in users,
    or shows blank when you open it. This page is the symptom → cause →
    fix flowchart. Run the checks in order; most installs hit the first
    one and skip the rest.
</p>

<h2>Browser support — Chrome, Safari, Firefox, Edge</h2>

<p>
    Every release is tested against <strong>Chromium (Chrome/Edge), WebKit
    (Safari) and Gecko (Firefox)</strong> by an automated cross-browser suite
    that mounts the widget, types a message and asserts a streamed reply in
    each engine — so the widget behaves identically across all of them, with
    no Chrome-only behaviour. It renders inside an isolated Shadow DOM and
    degrades gracefully on older engines: voice input, the Web-Audio chime,
    the async Clipboard API and <code>ResizeObserver</code> are all
    feature-detected and fall back rather than failing.
</p>

<p>
    If the widget works in one browser but not another, the cause is almost
    always environmental rather than the widget itself — a
    Content-Security-Policy on your site blocking the script, an ad/tracker
    blocker, or a stale cached build. Hard-reload (Cmd/Ctrl+Shift+R) and open
    the browser console (F12) to check for a blocked-resource or CSP error.
</p>

<h2>Symptom: widget shows for logged-in users only</h2>

<p>
    <strong>Cause:</strong> page cache. WordPress caching plugins (W3
    Total Cache, WP Rocket, LiteSpeed Cache, WP Super Cache), hosting-
    level page cache (Hostinger, SiteGround, Cloudways, Kinsta), or
    Cloudflare APO all serve anonymous visitors a cached HTML snapshot
    taken BEFORE you installed Pitchbar. Logged-in users bypass page
    cache, so they always hit fresh PHP and see the widget.
</p>

<p>
    <strong>Fix:</strong>
</p>

<ol>
    <li>WP admin → your caching plugin → click <strong>Purge All Cache</strong>.</li>
    <li>If you're on Hostinger / SiteGround / Cloudways / Kinsta: control panel → Page Cache → Purge.</li>
    <li>If Cloudflare is proxying your domain: Cloudflare dashboard → Caching → Configuration → <strong>Purge Everything</strong>.</li>
    <li>Open your site in incognito (new private window) → right-click → View Source → search for <code>widget.js</code>. Present = fixed.</li>
</ol>

<h2>Symptom: widget doesn't show at all</h2>

<p>
    <strong>Walkthrough:</strong>
</p>

<ol>
    <li>
        <strong>Confirm the Pitchbar WP plugin is configured.</strong>
        WP admin → Pitchbar (left sidebar). All three fields must be set:
        <code>Base URL</code>, <code>API Token</code>, <code>Agent ID</code>.
        Missing any one of them = the plugin's <code>shouldRender()</code>
        gate returns false and nothing renders.
    </li>
    <li>
        <strong>Confirm the widget is enabled.</strong> Same Pitchbar
        settings page → checkbox "Widget enabled". Default on, but worth
        confirming.
    </li>
    <li>
        <strong>Confirm post-type scoping.</strong> Settings page →
        "Show on these post types". Defaults to <code>post</code> +
        <code>page</code>. If your front page is a custom post type
        (e.g. <code>landing</code>, <code>product</code>) and you
        haven't ticked it, the widget skips that page. Tick the box.
    </li>
    <li>
        <strong>Open browser DevTools → Network tab</strong> on your
        front-end page. Search for <code>widget.js</code>:
        <ul>
            <li><strong>Present + 200 OK</strong>: the script loaded. Check Console for JS errors.</li>
            <li><strong>Present + 404</strong>: your <code>Base URL</code> setting points at a host that doesn't serve <code>widget.js</code>. Should be your Pitchbar install root (e.g. <code>https://pitchbar.your-domain.com</code>).</li>
            <li><strong>Missing entirely</strong>: the plugin isn't emitting the tag. Confirm via View Source — look for <code>&lt;script async src=…widget.js…&gt;</code>. Missing tag = the <code>shouldRender()</code> gate failed; re-check the previous 3 items.</li>
        </ul>
    </li>
</ol>

<h2>Symptom: widget shows but says "Sorry — something went wrong"</h2>

<p>
    Widget retries the SSE stream 3 times then surfaces this generic
    bubble. The real error is logged server-side; the bubble is
    intentionally vague so visitors don't see raw provider errors.
</p>

<p>
    Tail your Laravel logs while reproducing in the widget:
</p>

<pre><code>tail -f storage/logs/laravel.log</code></pre>

<p>
    Common patterns and their fixes:
</p>

<ul>
    <li>
        <strong><code>Workers AI 401: ...</code></strong> — Cloudflare API token rejected.
        See <a href="/documentation/troubleshooting-cloudflare-401">Cloudflare 401 troubleshooting</a>.
    </li>
    <li>
        <strong><code>Vectorize ... expected N dimensions, got M</code></strong> — embed model and index dim mismatch.
        See <a href="/documentation/troubleshooting-vector-dim">Vector dim recovery</a>.
    </li>
    <li>
        <strong><code>Workers AI timeout</code></strong> — Cloudflare flaky. Retry; if persistent, switch
        <code>CLOUDFLARE_CHAT_MODEL</code> to a smaller variant.
    </li>
    <li>
        <strong><code>message_quota_exceeded</code></strong> — workspace plan cap reached. Upgrade workspace plan.
    </li>
    <li>
        <strong><code>conversation_not_found</code></strong> — widget JWT is older than the
        <code>conversations.cleared_at</code> on its conversation row. Visitor needs to refresh the page so the widget reissues
        a fresh JWT via the next <code>/api/v1/widget/init</code>.
    </li>
</ul>

<h2>Symptom: widget shows but the launcher pill never appears</h2>

<p>
    Theme conflict. Some themes set <code>iframe { display: none !important }</code> or use a global
    z-index reset. The Pitchbar widget renders inside a Shadow DOM but its mount point is a
    <code>&lt;div&gt;</code> on the host page.
</p>

<p>
    <strong>Fix:</strong> add this CSS to your theme's <em>Additional CSS</em>:
</p>

<pre><code>#pitchbar-root,
#pitchbar-root * {
    display: revert !important;
    visibility: visible !important;
    z-index: 2147483647 !important;
}</code></pre>

<h2>Still stuck?</h2>

<p>
    Reach out at <a href="mailto:support@pitchbar.app">support@pitchbar.app</a> with:
</p>

<ul>
    <li>One screenshot of the front-end page where the widget isn't showing.</li>
    <li>Output of <code>tail -200 storage/logs/laravel.log</code> (or browser Console output if the script never reached your server).</li>
    <li>The URL of an affected page (if public).</li>
</ul>
