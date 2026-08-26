<p>
    The platform admin header runs seven health checks on every page load.
    A green dot means the platform is fully configured; amber or red means
    something needs attention. The <code>/admin/jobs/failed</code> page
    surfaces the failed-job side of the same picture.
</p>

<h2>The seven checks</h2>

<table>
    <thead><tr><th>Check</th><th>Severity</th><th>What it verifies</th></tr></thead>
    <tbody>
        <tr><td><strong>Failed jobs</strong></td><td>Critical</td><td>Count of rows in <code>failed_jobs</code>. Anything &gt; 0 turns the pill amber; &gt; 50 turns it red.</td></tr>
        <tr><td><strong>Stripe</strong></td><td>Warning</td><td><code>cashier.secret</code> is set. Without it, no plan syncs and no checkout works.</td></tr>
        <tr><td><strong>LLM provider</strong></td><td>Warning</td><td>At least one of: Cloudflare account + token, OpenAI key, OpenRouter key.</td></tr>
        <tr><td><strong>Vector store</strong></td><td>Warning</td><td>Cloudflare Vectorize index name OR Qdrant URL.</td></tr>
        <tr><td><strong>Mail</strong></td><td>Warning</td><td>Mail driver + sender configured. Needed for invitations, password reset, lead notifications.</td></tr>
        <tr><td><strong>Reverb</strong></td><td>Warning</td><td>Reverb app key + secret set. Without it, the inbox doesn't update live.</td></tr>
        <tr><td><strong>Cache</strong></td><td>Warning</td><td>Redis cache reachable. The hot path caches retrieval results here; a slow cache means slower responses.</td></tr>
    </tbody>
</table>

<h2>Score &amp; label</h2>

<p>
    The header pill aggregates: <code>score</code> = % of checks passing.
    <code>label</code> goes from <strong>Strong</strong> (≥ 90%) to
    <strong>Stable</strong> (≥ 70%) to <strong>Watchlist</strong> (≥ 50%)
    to <strong>Critical</strong> (anything below 50%). The pill color
    follows: green for Strong, amber for Stable / Watchlist, red for
    Critical.
</p>

<p>
    Each notification in the dropdown has a deep link to the relevant
    config page so you can fix it in two clicks.
</p>

<h2>Failed jobs</h2>

<p>
    Open <code>/admin/jobs/failed</code> for the full list. Each row shows:
</p>

<ul>
    <li><strong>Job name</strong> — the queue + class.</li>
    <li><strong>Connection</strong>.</li>
    <li><strong>Failed at</strong> — when the last attempt blew up.</li>
    <li><strong>Exception</strong> — first line of the trace, expandable.</li>
    <li><strong>Retry</strong> — re-queue the job with the same payload.</li>
    <li><strong>Forget</strong> — drop the row.</li>
</ul>

<p>
    The page also has <strong>Retry all</strong> and <strong>Flush
    all</strong> buttons. Use Retry all after fixing a transient outage
    (the LLM provider went down; jobs failed; provider is back). Use
    Flush only when you've decided the failures are unrecoverable.
</p>

<h2>Common failure shapes</h2>

<p>
    By queue:
</p>

<ul>
    <li><strong>crawl</strong> — usually a 4xx/5xx from the upstream site or a Browserless rate-limit. Retry once; if it persists, the source URL is dead.</li>
    <li><strong>index</strong> — usually an LLM embedding rate-limit or a Vectorize quota issue. Retry after the rate window resets.</li>
    <li><strong>default</strong> — anything else (usage events, gap detection, webhook delivery). Look at the exception.</li>
</ul>

<h2>Broadcasting failures (Reverb / Pusher cURL errors)</h2>

<p>
    If <code>BROADCAST_CONNECTION=reverb</code> is set but the Reverb
    server isn't running (or the host/port is wrong), every
    <code>ShouldBroadcast</code> event blows up with
    <code>cURL error 7: Failed to connect to <em>host:port</em></code>
    and lands in <code>failed_jobs</code>. To prevent the noise, all
    broadcast events use the
    <code>App\Events\Concerns\BroadcastsWhenConfigured</code> trait
    which short-circuits when the configured driver is <code>log</code>
    or <code>null</code> — broadcasting is a no-op without a real
    socket consumer.
</p>

<p>
    Operator action when you see these failures:
</p>

<ul>
    <li>Set <code>BROADCAST_CONNECTION=log</code> in <code>.env</code> if you don't want real-time delivery.</li>
    <li>Or start the Reverb server (<code>php artisan reverb:start</code>) and verify <code>REVERB_HOST</code> + <code>REVERB_PORT</code> match what the broadcaster is pointed at.</li>
    <li>Run <code>php artisan queue:flush</code> to clear the stale rows after fixing the root cause.</li>
</ul>

<h2>Webhook delivery failures</h2>

<p>
    The lead-captured dispatcher (<code>SignedDispatcher</code>) is
    single-attempt — the lead is already persisted, so a failed
    delivery surfaces in the workflow run log rather than blocking the
    visitor. Workflow-step webhooks (<code>DispatchWebhookJob</code>)
    retry up to 3 times via Laravel's queue retry mechanism; after the
    third failure the job lands in <code>failed_jobs</code> with the
    destination URL + signature in the payload, so you can replay
    manually after the receiver is fixed.
</p>

<h2>Horizon</h2>

<p>
    If you've enabled Laravel Horizon, <code>/horizon</code> shows real-time
    queue throughput, wait times, failed-job rates, and per-job-class
    histograms. Recommended to keep open in a tab during deploys.
</p>

<h2>Site Health pill — what each color means</h2>

<table>
    <thead><tr><th>Color</th><th>Score</th><th>Meaning</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td>Green</td><td>≥ 90% (Strong)</td><td>Everything healthy.</td><td>Nothing.</td></tr>
        <tr><td>Amber</td><td>50% – 89% (Stable / Watchlist)</td><td>One or more warnings; product still functional.</td><td>Click the pill, fix what's broken when you have time.</td></tr>
        <tr><td>Red</td><td>&lt; 50% (Critical)</td><td>Multiple checks failing or a critical-severity check (e.g. failed-jobs surge).</td><td>Drop everything. The product may be visibly broken for some customers.</td></tr>
    </tbody>
</table>
