<p>
    Auto-index is a per-agent toggle that grows your knowledge base
    automatically as visitors browse your site. When a visitor lands on a
    page the agent has never seen, that page goes into the crawl queue —
    silently, in the background.
</p>

<h2>How to enable</h2>

<p>
    Open the agent's settings page (<code>/app/agents/{id}/settings</code>) and
    flip <strong>Auto-index visited pages</strong>. The change takes effect
    immediately — there's no separate publish step for this toggle.
</p>

<h2>What it does</h2>

<p>
    On every <code>/v1/widget/init</code> call, after the agent passes the
    origin and quota checks, <code>AutoIndexPageVisit::attempt()</code> runs
    a chain of seven guards. All seven must pass for a crawl to be queued:
</p>

<ol>
    <li>The agent has <code>auto_index_visited_pages = true</code>.</li>
    <li>The page URL is a valid http/https URL.</li>
    <li>The host is not private — RFC1918 (<code>10.x</code>, <code>172.16-31.x</code>, <code>192.168.x</code>), loopback (<code>localhost</code>, <code>127.x</code>, <code>::1</code>), link-local (<code>169.254.x</code>, <code>fe80:</code>), <code>0.x</code>, IPv6 ULA (<code>fc00:</code>), and <code>.local</code> / <code>.internal</code> domains are blocked.</li>
    <li>The path doesn't look private — <code>/admin</code>, <code>/login</code>, <code>/checkout</code>, <code>/profile</code>, <code>/account</code>, <code>/settings</code>, <code>/cart</code>, <code>/api</code> are skipped.</li>
    <li>The URL hasn't already been indexed for this agent.</li>
    <li>We're under the rate limit — 30 crawls per agent per hour, tracked in Redis.</li>
    <li>The visitor's actual <code>Origin</code> header matches the agent's <code>allowed_origins</code> (or matches the page URL's origin when allowed_origins is <code>*</code>).</li>
</ol>

<p>
    If everything passes, we lazy-create a <code>type=auto</code> source and
    dispatch a <code>CrawlPageJob</code> on the <code>crawl</code> queue. The
    visitor's request returns immediately — auto-index never blocks the hot
    path.
</p>

<h2>What it skips</h2>

<p>
    The path blocklist exists because authenticated pages are noisy and
    risky to index — a logged-in <code>/profile</code> or
    <code>/account/orders</code> page leaks the visitor's data into your
    knowledge base. The full list lives in
    <code>AutoIndexPageVisit::SKIP_PATH_PATTERNS</code> (case-insensitive,
    matches the path segment with optional trailing slash):
</p>

<ul>
    <li><code>/account</code>, <code>/my-account</code>, <code>/profile</code>, <code>/settings</code></li>
    <li><code>/admin</code></li>
    <li><code>/login</code>, <code>/signin</code>, <code>/signup</code>, <code>/register</code>, <code>/logout</code>, <code>/auth</code>, <code>/password</code></li>
    <li><code>/checkout</code>, <code>/cart</code>, <code>/order</code>, <code>/orders</code></li>
</ul>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Don't index logged-in surfaces</div>
        If your app's auth-gated pages live under unusual paths (e.g.
        <code>/portal</code>, <code>/customer</code>), the default blocklist
        won't catch them. Disable auto-index, or pre-list the exact public
        URLs you want crawled and skip the toggle entirely.
    </div>
</div>

<h2>Rate limiting</h2>

<p>
    The 30-crawls-per-agent-per-hour cap is a token bucket keyed in Redis as
    <code>auto-index:agent:{id}:hour:{YmdH}</code>. If a popular page on your
    site is getting hammered the limit will quickly throttle, but normal
    traffic patterns rarely hit it.
</p>

<h2>What gets indexed</h2>

<p>
    Auto-indexed pages become <code>type=auto</code> sources. They show up
    in the regular <strong>Sources</strong> list with a small "auto" pill so
    you can see what's been picked up. You can preview, reindex, or delete
    them like any other source.
</p>

<p>
    The auto-source's title is the page <code>&lt;title&gt;</code> if available,
    otherwise the URL. Path-based deduplication means the same URL crawled
    twice doesn't create two sources.
</p>

<h2>Disabling and pruning</h2>

<p>
    Turn the toggle off and no new pages will be queued, but existing
    auto-sources stay. To clean them up, filter the sources list by
    <code>type = auto</code> and delete in bulk.
</p>
