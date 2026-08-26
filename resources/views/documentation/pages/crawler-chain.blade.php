<p>
    Every URL-based source (<code>url</code>, <code>sitemap</code>,
    <code>feed</code>, <code>auto</code>) flows through the same multi-tier
    crawler chain. Each tier tries a different way to get readable text out
    of the page. The first tier whose extracted text clears the
    200-character threshold wins; failures cascade to the next tier.
</p>

<h2>The chain (highest priority → last resort)</h2>

<table>
    <thead>
        <tr><th>#</th><th>Tier</th><th>What it does</th><th>When it wins</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>1</td>
            <td><code>plain_http</code></td>
            <td>Plain Guzzle <code>GET</code> with a Mozilla user-agent.
                Runs through <code>UrlSafetyGuard</code> so private IPs and
                cloud metadata services are blocked.</td>
            <td>Server-rendered pages (Blade apps, classic blogs, marketing
                sites with SSR or static export).</td>
        </tr>
        <tr>
            <td>2</td>
            <td><code>cloudflare_browser_markdown</code></td>
            <td><code>POST /browser-rendering/markdown</code>. Cloudflare
                spins up headless Chrome, waits for JS to settle, and emits
                clean markdown. We wrap it in a minimal HTML envelope so
                the extractor and chunker stay unchanged.</td>
            <td>JS-heavy SPAs whose final DOM is text-shaped (most React /
                Vue / Svelte sites).</td>
        </tr>
        <tr>
            <td>3</td>
            <td><code>cloudflare_browser</code></td>
            <td><code>POST /browser-rendering/content</code>. Same headless
                Chrome, but returns raw rendered HTML. Our
                <code>ReadabilityExtractor</code> takes over.</td>
            <td>Atypical layouts where the markdown extractor's heuristics
                drop content but the regex extractor catches it (tables that
                don't map cleanly to GFM, deep nested article structures).</td>
        </tr>
        <tr>
            <td>4</td>
            <td><code>cloudflare_vision</code></td>
            <td>Two-stage:
                <ol>
                    <li>Full-page screenshot via
                        <code>/browser-rendering/screenshot</code> (PNG).</li>
                    <li>Workers AI multimodal call to
                        <code>{{'@cf/meta/llama-3.2-11b-vision-instruct'}}</code> with an OCR-only
                        prompt. Returns extracted text.</li>
                </ol>
                Text is wrapped in <code>&lt;p&gt;</code> elements so the
                chunker treats each paragraph as its own unit.</td>
            <td>Canvas-rendered slide decks, all-image landing pages,
                embedded PDF viewers, or any site whose meaningful content
                lives only in pixels.</td>
        </tr>
    </tbody>
</table>

<h2>How a tier "wins"</h2>

<p>
    The chain measures <strong>extracted text length</strong>, not raw
    HTML length. Each tier's output is passed through
    <code>ReadabilityExtractor</code> (with the same chrome-stripping
    fallback used downstream). If the extracted text is at least 200
    characters, that tier wins and its HTML is cached for 5 minutes
    against the source URL. If not, the chain falls forward.
</p>

<p>
    This used to be a raw-length check, which let JS-only Inertia
    shells "win" with 50KB of empty <code>&lt;div&gt;</code> markup and
    then silently fail extraction downstream. The extraction-aware
    check fires the vision tier exactly when the extractor would have
    failed anyway — no wasted retries, no silent zeros.
</p>

<h2>Cost & safeguards</h2>

<ul>
    <li><code>CLOUDFLARE_BROWSER_DAILY_LIMIT</code> — shared counter for
        <code>/content</code>, <code>/markdown</code>, and
        <code>/screenshot</code> invocations. Default <code>0</code>
        (unlimited). Operators on a fixed Cloudflare bundle should set
        this to a per-day cap that matches their plan.</li>
    <li><code>CLOUDFLARE_VISION_DAILY_LIMIT</code> — separate counter for
        Workers AI vision calls (these consume Neurons, billed separately
        from Browser Rendering). Default <code>0</code>.</li>
    <li><code>CLOUDFLARE_VISION_MODEL</code> — defaults to
        <code>{{'@cf/meta/llama-3.2-11b-vision-instruct'}}</code>. Override only if Cloudflare
        retires the model or you've negotiated a different one.</li>
    <li>Both daily-cap guards throw a recoverable exception when the
        cap is hit, so the chain falls forward to the next tier instead
        of failing the whole crawl. Vision being capped just means the
        page that needed OCR doesn't get indexed — every other URL still
        flows.</li>
</ul>

<h2>What happens when every tier fails</h2>

<p>
    The chain throws a <code>RuntimeException</code> whose message lists
    each tier and why it failed (HTTP code, exception message, or
    "extraction produced N chars under threshold").
    <code>CrawlPageJob::failed()</code> catches this, runs the message
    through <code>SourceErrorPresenter</code>, and stamps a
    customer-readable line onto the source's <code>error</code> column
    (rendered in the admin's <a href="../knowledge">Sources</a> dialog).
</p>

<h2>The <code>www</code> → apex certificate fallback</h2>

<p>
    A very common site misconfiguration is a TLS certificate that covers the
    apex domain (<code>example.com</code>) but <em>not</em> its
    <code>www</code> host. Fetching <code>https://www.example.com/…</code>
    then fails <strong>every tier</strong> with cURL error 60
    (<code>SSL: no alternative certificate subject name matches target host
    name 'www.example.com'</code>) — and because that error is classed as a
    permanent failure, the page would never re-index. Left unhandled, the
    assistant answers from stale or missing content (a live case: a pricing
    page's "3,000 conversations / month" going unindexed).
</p>

<p>
    So before giving up, the chain retries the <strong>apex host once</strong>:
    it strips the leading <code>www.</code> (preserving scheme, port, path and
    query) and re-runs the whole chain. The retry is gated strictly on the
    cert-mismatch signature — a genuinely broken <code>www</code>-only host
    (500, DNS failure, 404) is <em>not</em> handed a pointless second fetch —
    and it is self-terminating, since the apex URL has no <code>www.</code>
    prefix to fall back from. The recovery is logged as
    <code>crawler.chain.www_apex_fallback</code>.
</p>

<h2>Browserless deprecation</h2>

<p>
    The <code>BrowserlessClient</code> tier was removed from the default
    chain in 2026-06 once the vision OCR tier landed. CF
    <code>/markdown</code> + <code>/content</code> + vision now cover
    the same ground free (no third-party billing line). The
    <code>BROWSERLESS_URL</code> / <code>BROWSERLESS_TOKEN</code> env
    vars stay readable for backwards compatibility — they no longer wire
    anything by default. A custom service provider can still bind
    <code>BrowserlessClient</code> manually if a self-hosted Browserless
    is preferred.
</p>
