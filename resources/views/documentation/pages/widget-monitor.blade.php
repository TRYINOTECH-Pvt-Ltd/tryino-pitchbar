<p>
    The <strong>Widget Monitor</strong> at
    <code>/settings/system/widget-monitor</code> (super-admin only) is the
    error counterpart to the <a href="/documentation/hotpath-latency">hot-path
    latency</a> dashboard. Latency answers <em>“why is the bot slow?”</em>; the
    Widget Monitor answers <em>“what's breaking?”</em> — stream failures,
    LLM-provider outages, and visitor-reported freezes, all in one feed you can
    triage and mark resolved as you fix each one.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">Recorded off the hot path</div>
        Every event is written by a queued job, never a synchronous database
        write inside the live stream. Capturing a failure never slows a working
        chat — the first-token latency contract is untouched.
    </div>
</div>

<h2>What it tracks</h2>

<p>Each row is one failure or anomaly on the visitor path:</p>

<ul>
    <li><strong>Provider down</strong> — every configured LLM provider failed
        for a turn. This is the one to act on first: a real outage your visitors
        felt.</li>
    <li><strong>Stream failed</strong> — an unhandled exception killed a reply
        mid-flight. Open it to read the exception class and trace the cause.</li>
    <li><strong>Failover</strong> — the primary provider stumbled but a backup
        picked the turn up. Informational: the visitor saw nothing wrong, but a
        rising count means the primary is unhealthy.</li>
    <li><strong>Client freeze</strong> — the visitor's browser saw the stream
        stall (no data for 35s, or the 120s ceiling) and gave up. Reported by
        the widget itself, so you catch freezes even when the server looks
        fine.</li>
    <li><strong>Tool-loop timeout</strong> / <strong>Retrieval failed</strong>
        — slower-path failures in tool calling or knowledge retrieval.</li>
</ul>

<p>
    Filter by time window (24h / 7d / 30d), type, severity, and open-vs-resolved.
    The banner at the top gives a plain-English verdict (“provider outage in
    progress…”, “healthy with hiccups…”, “all clear…”) so you can read the
    state at a glance. Click <strong>Resolve</strong> on a row once you've dealt
    with it — or <strong>Reopen</strong> if it comes back.
</p>

<h2>Provider failover — why a single outage no longer kills every chat</h2>

<p>
    The reliability backbone behind the monitor is <strong>runtime provider
    failover</strong>. Pitchbar can be configured with more than one LLM
    provider (Cloudflare Workers AI, OpenAI, OpenRouter). When two or more are
    configured, every visitor turn runs through a failover chain: if the
    primary provider is slow, returns a 5xx, is rate-limited (429), or has run
    out of credits, Pitchbar transparently retries the next provider — same
    turn, before the visitor sees an error.
</p>

<ul>
    <li><strong>Retryable</strong> failures (timeouts, 429s, 5xx, connection
        errors) trigger failover — the provider is the problem, so another may
        succeed.</li>
    <li><strong>Client errors</strong> (a 4xx “bad request”) are <em>not</em>
        retried — every provider would reject them identically, so the error
        surfaces immediately instead of doubling the latency.</li>
    <li><strong>Streaming</strong> only fails over <em>before the first token</em>.
        Once the visitor has seen text, a mid-stream drop can't be silently
        restarted on another provider — it surfaces as a stream failure.</li>
</ul>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Configure a backup provider</div>
        Failover only helps if there's somewhere to fail over <em>to</em>. With a
        single provider configured, an outage still kills every turn — and the
        monitor will say so (<em>“provider outage with NO failover”</em>). Add a
        second provider's key in <strong>System Settings</strong> (an
        <code>OPENAI_API_KEY</code> or <code>OPENROUTER_API_KEY</code> alongside
        your Cloudflare credentials) so a single outage becomes invisible to
        visitors instead of fatal.
    </div>
</div>

<h2>Fail-fast timeouts</h2>

<p>
    Blocking provider calls (tool decisions, embeddings) are capped well below
    the streaming budget so a hung provider is abandoned quickly and failover
    reaches the next one fast, rather than waiting out a full minute. Streaming
    keeps a generous budget because a legitimate long answer holds the
    connection open; a 5-second connect cap still detects a dead host
    immediately.
</p>

<h2>Tuning the freeze detector</h2>

<p>
    “Client freeze” events come from the widget's own stall detector. If you see
    them clustered, cross-reference the hot-path latency monitor — a freeze is
    usually a turn that genuinely took longer than the widget's patience
    (35s without a single byte), which points at provider responsiveness or an
    overloaded tool loop rather than a hard crash.
</p>
