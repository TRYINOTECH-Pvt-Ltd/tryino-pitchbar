<p>
    Production telemetry runs on three legs: Sentry for errors,
    OpenTelemetry traces for the hot path, Horizon for queue health. The
    admin Site Health pill (see <a href="/documentation/admin-health">Site
    health &amp; failed jobs</a>) summarizes them at a glance.
</p>

<h2>Sentry</h2>

<p>
    Set <code>SENTRY_DSN</code> and unhandled exceptions across the app
    flow into Sentry with stack traces, request context, and user/workspace
    metadata. The breadcrumb trail captures the last 100 log lines for
    every error. Integrated via <code>sentry-laravel</code>.
</p>

<p>
    <strong>Useful filters in Sentry:</strong>
</p>

<ul>
    <li>Tag <code>workspace_id</code> to scope errors to a tenant.</li>
    <li>Tag <code>agent_id</code> when the error originates in a widget request.</li>
    <li>Release tags match deploy commit SHA — easy to bisect when a regression appears.</li>
</ul>

<h2>OpenTelemetry traces</h2>

<p>
    The OTEL exporter ships traces to <code>OTEL_EXPORTER_OTLP_ENDPOINT</code>
    — typically Honeycomb or Grafana Cloud Tempo. Spans wrap the hot path:
</p>

<ul>
    <li><code>widget.message.receive</code> — incoming HTTP, validation, JWT verify.</li>
    <li><code>rag.curated.match</code> — short-circuit check.</li>
    <li><code>rag.embed</code> — query embedding call.</li>
    <li><code>rag.vector.search</code> — ANN search.</li>
    <li><code>rag.rerank</code> — cross-encoder.</li>
    <li><code>rag.prompt.assemble</code> — local CPU work.</li>
    <li><code>rag.llm.first_token</code> — time-to-first-token (the headline metric).</li>
    <li><code>rag.llm.stream</code> — full stream duration.</li>
    <li><code>rag.persist.async</code> — post-stream save.</li>
</ul>

<p>
    Each span is tagged with workspace_id, agent_id, conversation_id,
    provider (cloudflare / openai), and any cache-hit flags. The big one
    is <strong>p95 of <code>rag.llm.first_token</code></strong> — that's
    your hot-path SLO.
</p>

<h2>Horizon</h2>

<p>
    <code>/horizon</code> is the queue dashboard. Required for production —
    without it, you're blind to backlogs. Watch:
</p>

<ul>
    <li><strong>Wait time</strong> — how long jobs sit before being picked up. Healthy is &lt; 1s; investigate &gt; 10s.</li>
    <li><strong>Throughput</strong> — jobs/min by queue.</li>
    <li><strong>Failed jobs</strong> — anything that lands in <code>failed_jobs</code> shows here too.</li>
</ul>

<p>
    Queues to monitor:
</p>

<table>
    <thead><tr><th>Queue</th><th>What's on it</th></tr></thead>
    <tbody>
        <tr><td><code>default</code></td><td>Misc: usage events, gap detection, audit logs, webhook deliveries.</td></tr>
        <tr><td><code>crawl</code></td><td>CrawlSourceJob, CrawlPageJob, IngestNotionPageJob, IngestGoogleDocJob. Tends to be the longest queue depth.</td></tr>
        <tr><td><code>index</code></td><td>IndexDocumentJob, IndexTextSourceJob. Embedding-heavy.</td></tr>
    </tbody>
</table>

<h2>Logs</h2>

<p>
    Standard Laravel logging. Default channels:
</p>

<ul>
    <li><code>stdout</code> — captured by Laravel Cloud / Docker.</li>
    <li><code>sentry</code> — error level and above.</li>
    <li><code>slack</code> — critical level, posts to ops channel.</li>
</ul>

<p>
    Tail logs locally with <code>php artisan pail</code>.
</p>

<h2>Health endpoint</h2>

<p>
    <code>GET /up</code> is the readiness probe — returns 200 with a small
    JSON body if the app boots. Use it for load balancer health checks.
    For deeper checks, <code>App\Support\PlatformAdminHeader</code>
    runs the multi-step health check and exposes the result via the
    Inertia shared prop on every admin page.
</p>

<h2>Metrics to watch</h2>

<p>
    The handful of metrics that matter most:
</p>

<ul>
    <li><strong>p95 first-token latency</strong> — &lt; 1s.</li>
    <li><strong>p95 full-response latency</strong> — &lt; 5s for short answers.</li>
    <li><strong>Crawl queue depth</strong> — should drain within minutes.</li>
    <li><strong>Index queue depth</strong> — should drain within minutes.</li>
    <li><strong>Failed-jobs count</strong> — 0 in steady state. Anything &gt; 50 is alarm-worthy.</li>
    <li><strong>LLM provider error rate</strong> — &lt; 1% of streams.</li>
    <li><strong>Vector store query latency</strong> — p95 &lt; 100ms.</li>
</ul>

<h2>Alerts</h2>

<p>
    Recommended PagerDuty / Slack alerts:
</p>

<ul>
    <li>Sentry — new release-blocking error.</li>
    <li>Honeycomb — first-token p95 &gt; 1.5s for 5 minutes.</li>
    <li>Horizon — failed-jobs delta &gt; 10 in 5 minutes.</li>
    <li>Stripe webhook — &gt; 5 consecutive verification failures (signing key mismatch).</li>
    <li>Reverb — process down.</li>
</ul>

<h2>Site Health pill</h2>

<p>
    The header pill in the admin panel is a quick visual check that
    everything is configured. Green is the steady state; if it goes amber,
    the dropdown tells you exactly which check failed and links to the
    settings page to fix it. See
    <a href="/documentation/admin-health">Site health &amp; failed jobs</a>.
</p>
