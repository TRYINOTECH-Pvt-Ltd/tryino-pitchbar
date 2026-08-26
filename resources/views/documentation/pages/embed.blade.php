<p>
    The widget is a single <code>&lt;script&gt;</code> tag. Drop it on any page,
    pass an agent ID, and you're live. The bundle is ≤50KB gzipped, runs in
    a Shadow DOM so it can't be styled by your site, and never blocks page
    load (it's <code>async</code>).
</p>

<h2>The snippet</h2>

<p>
    Paste this just before <code>&lt;/body&gt;</code>:
</p>

<pre><code>&lt;script
    src="https://your-app.test/widget/widget.js?v=ab12cd34"
    data-agent-id="01HXY..."
    async&gt;&lt;/script&gt;</code></pre>

<p>
    The full snippet (with your agent ID and the current cache-bust hash) is
    on every agent's <strong>Settings</strong> page next to a copy button.
</p>

<h2>What's in the URL</h2>

<ul>
    <li><strong><code>src</code></strong> — points at <code>/widget/widget.js</code> on your Pitchbar deployment. The <code>?v=&lt;hash&gt;</code> suffix is the bundle's content hash; it changes whenever the widget is rebuilt, so customers can't get stuck on stale versions cached by a CDN.</li>
    <li><strong><code>data-agent-id</code></strong> — the published agent's ULID. The widget's loader reads this attribute and uses it on every <code>/v1/widget/init</code> call.</li>
    <li><strong><code>async</code></strong> — non-blocking. The widget appears once the bundle finishes downloading; your page's load metrics are unaffected.</li>
    <li><strong><code>data-locale</code></strong> <em>(optional)</em> — force the widget's language (e.g. <code>data-locale="nl"</code>). Omit it and the widget follows your page's <code>&lt;html lang&gt;</code> automatically. See <a href="#language">Language</a> below.</li>
</ul>

<h2 id="language">Language</h2>

<p>
    The widget renders its UI — launcher label, starter chips, buttons, and
    system messages — in the visitor's language, resolved at <code>init</code>
    in this order:
</p>

<ol>
    <li><strong><code>data-locale</code> on the script tag</strong>, if set — an explicit override (e.g. <code>data-locale="de"</code>).</li>
    <li><strong>The host page's <code>&lt;html lang&gt;</code></strong> — so the widget matches whatever language your site is currently rendering. On our own marketing site this is what makes the demo follow the language switcher.</li>
    <li><strong>The agent's default language</strong> (Settings → the agent's language) when the page declares none.</li>
    <li><strong>The browser's <code>Accept-Language</code></strong>, then English.</li>
</ol>

<p>
    Regional tags degrade gracefully: <code>en-GB</code> → <code>en</code>,
    <code>pt-BR</code> stays <code>pt-BR</code> when that file ships. The page
    language wins over the agent default, so a single agent embedded on a
    multilingual site speaks each page's language. Translate the launcher
    label and starter chips per language in
    <a href="/documentation/admin-translations">Translations</a>; anything
    without a translation falls back to the original text.
</p>

<h2>What it injects</h2>

<p>
    On boot, the loader:
</p>

<ol>
    <li>Creates a <code>&lt;div&gt;</code> at the bottom of <code>&lt;body&gt;</code> and attaches a Shadow DOM to it.</li>
    <li>Renders the launcher (small button) inside the shadow root.</li>
    <li>Calls <code>POST /v1/widget/init</code> to get an agent config + JWT + recent history.</li>
    <li>Wires up trigger listeners (scroll, idle, exit-intent) per the agent's behavior rules.</li>
    <li>Persists a small <code>anon_id</code> in <code>localStorage</code> so the same browser keeps the same conversation across reloads.</li>
</ol>

<h2>Customizing the launcher</h2>

<p>
    Theme is fully agent-driven — see <a href="/documentation/customize">Persona, theme &amp; prompts</a>.
    The widget reads <code>theme.position</code>, <code>theme.primary</code>,
    <code>theme.accent</code>, <code>theme.radius</code>, and
    <code>theme.launcher_label</code> from the init response and renders
    accordingly.
</p>

<p>
    There's no per-page customization — the launcher always reads from the
    agent. If you need a different look on different pages, embed two
    different agents.
</p>

<h2>Programmatic control</h2>

<p>
    The widget exposes a single global, <code>window.Pitchbar.mount()</code>,
    used by the loader to boot. The script tag's <code>async</code>
    attribute and auto-mount handle the common case for you, so you don't
    typically call this directly.
</p>

<pre><code>// Mount the widget into a custom host element (rare).
window.Pitchbar.mount(document.getElementById('chat-host'));</code></pre>

<p>
    There's no public <code>open</code> / <code>close</code> /
    <code>send</code> / <code>on</code> API yet — those are deferred. If
    you need to fire conversion analytics on lead capture today, subscribe
    to the <code>lead.captured</code> webhook instead (see
    <a href="/documentation/webhooks">Outgoing webhooks</a>).
</p>

<h2>Single-page apps</h2>

<p>
    The widget loads once per page-load, but the conversation persists
    across in-app navigations as long as the script tag stays in the DOM.
    You don't need to re-mount it when your router changes routes — the
    Shadow DOM and JWT survive.
</p>

<p>
    If your SPA fully re-mounts on route changes (e.g. you tear down the
    body), the widget will re-init and resume the visitor's conversation
    from the last 24 hours of history.
</p>

<h2>What gets sent on every init</h2>

<p>
    A <code>POST /v1/widget/init</code> includes:
</p>

<ul>
    <li><code>agent_id</code> — the value from <code>data-agent-id</code>.</li>
    <li><code>page_url</code> — the current <code>location.href</code>, used for the "current page" boost in retrieval.</li>
    <li><code>anon_id</code> — the visitor's persistent ID from <code>localStorage</code>, generated on first visit.</li>
    <li><code>locale</code> — the host page's language (<code>data-locale</code>, else <code>&lt;html lang&gt;</code>), so the widget UI follows the page. Omitted when neither is present.</li>
</ul>

<p>
    The server also reads the <code>Origin</code>, <code>Referer</code>, and
    <code>Accept-Language</code> headers — Origin for the
    <a href="/documentation/allowed-origins">allowed-origin check</a>,
    Accept-Language for default language detection.
</p>

<h2>Versioning &amp; caching</h2>

<p>
    The path component <code>/widget/widget.js</code> is <strong>stable across
    every deploy</strong>. Customers paste the snippet once and never need to
    touch it again — bundle updates ship transparently. The
    <code>?v=&lt;hash&gt;</code> query mutates on every build, which busts
    browser caches on existing pages without changing the URL the customer
    pasted on their site.
</p>

<p>
    Internally <code>/widget/widget.js</code> is served by
    <code>WidgetBundleController</code>, which streams the latest hashed
    bundle named in <code>public/widget/manifest.json</code> with
    <code>Cache-Control: no-cache, must-revalidate</code> and a strong
    ETag. The hashed file (<code>widget.&lt;hash&gt;.js</code>) is the on-
    disk artifact; the stable URL is the public contract.
</p>

<p>
    Pre-fix (2026-05-29) the embed snippet exposed the hashed filename
    directly (e.g. <code>widget.abc123.js</code>); every new build rotated
    the URL and customers' pasted <code>&lt;script&gt;</code> tags
    404'd. The fix landed in <code>AgentController::widgetUrl()</code>
    and <code>OnboardingController::buildWidgetSrc()</code>.
</p>
