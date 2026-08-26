<p>
    When a buyer says "the bot is slow", the first question is
    <em>which stage</em> is slow. A visitor turn crosses four network
    round-trips before the first character appears, and they fail
    independently:
</p>

<table>
    <thead><tr><th>Stage</th><th>What it is</th><th>Typical</th><th>Fix when slow</th></tr></thead>
    <tbody>
        <tr>
            <td><code>embed_ms</code></td>
            <td>Embedding the visitor's question (provider API call)</td>
            <td>80–250ms</td>
            <td>Provider region; retrieve cache absorbs repeats</td>
        </tr>
        <tr>
            <td><code>ann_ms</code></td>
            <td>Vector similarity search (Vectorize or Qdrant)</td>
            <td>80–300ms remote / &lt;10ms local Qdrant</td>
            <td>Run Qdrant on the app server (<code>VECTOR_PROVIDER=qdrant</code>)</td>
        </tr>
        <tr>
            <td><code>rerank_ms</code></td>
            <td>Cross-encoder reranking of candidates</td>
            <td>120–400ms</td>
            <td>Lower <code>RAG_RERANK_FAN_OUT</code>; accept ANN order</td>
        </tr>
        <tr>
            <td><code>first_token_ms</code></td>
            <td>Wait from turn start until the LLM's first streamed token</td>
            <td>200–900ms</td>
            <td><strong>Switch to a faster chat model</strong> — biggest single lever</td>
        </tr>
    </tbody>
</table>

<h2>The dashboard</h2>

<p>
    <strong>Super-admin → <code>/settings/system/hotpath-latency</code>.</strong>
    Shows the last 100 visitor turns with one column per stage,
    p50 / p95 / max aggregate cards, and a plain-English verdict line
    naming the dominant cost with its remedy. Colour coding: green
    &lt; 300ms, amber &lt; 800ms, red above.
</p>

<p>
    Data comes from a cache-backed ring buffer the stream handler
    appends to <em>after</em> the reply finishes streaming — visitors
    pay zero latency for the bookkeeping. Buffer survives 7 days or
    100 turns, whichever ends first. No database table involved, so
    it works identically on Redis and database cache drivers.
</p>

<h2>Reading the table</h2>

<ul>
    <li>
        <strong>First token high, retrieval low</strong> — the LLM is
        the bottleneck. Open Settings → System → AI providers and pick
        a faster model (the dropdown shows expected TTFT per model).
    </li>
    <li>
        <strong>Retrieval high</strong> — check which sub-column grew.
        <code>Search</code> high → vector store round-trip; running
        Qdrant locally takes it under 10ms. <code>Rerank</code> high →
        reduce fan-out. <code>Embed</code> high → provider region.
    </li>
    <li>
        <strong>"retrieve cache hit" rows</strong> — repeat questions
        skip embed/search/rerank entirely (30-minute cache). These rows
        show what your pipeline costs when retrieval is free.
    </li>
    <li>
        <strong>Everything green but visitors still complain</strong> —
        the problem is between the visitor's browser and your server:
        proxy buffering (see the SSE heartbeat notes in Architecture →
        Hot path), TLS setup time, or plain geography.
    </li>
</ul>

<h2>The fast router</h2>

<p>
    When an agent has tools enabled, every legacy turn pays a
    <em>tool-check completion</em> — a full non-streaming LLM call (1–3
    of them, 5–15s each on Workers AI 70B) before the streamed answer
    starts. Measured in production this put first-token p50 at ~14s
    even on the fastest model, while ~90% of visitor questions never
    needed a tool.
</p>

<p>
    The fast router decides per turn whether the tool check is worth
    running, in &lt;2ms with zero extra network:
</p>

<ol>
    <li><strong>Keyword gate</strong> — per-tool phrase lists ("open a
        ticket", "where is my order", the human-handoff phrases).</li>
    <li><strong>Embedding gate</strong> — the RAG query embedding
        (already computed for retrieval) is compared against per-tool
        exemplar centroids by cosine similarity. Centroids are
        embedded off the hot path (queued job / deploy command) and
        cached 30 days, keyed by embed model + dimension + exemplar
        text so they auto-invalidate on any change.</li>
    <li>No signal → <strong>knowledge route</strong>: RAG + stream
        only, tool check skipped.</li>
</ol>

<p>Enable it:</p>

<pre><code>FAST_ROUTER_ENABLED=true        # .env (default off)
php artisan router:warm         # embed tool centroids (also run on deploy)</code></pre>

<p>
    Safety: explicit "talk to a human" messages are caught by the
    keyword shortcut <em>before</em> the router and always escalate;
    escalation also gets the lowest embedding threshold
    (<code>FAST_ROUTER_ESCALATE_THRESHOLD</code>) so it stays the
    easiest tool to trigger. A per-agent kill switch lives in
    <code>vertical_overrides.fast_router</code> (true/false beats the
    global flag). The dashboard's Notes column and
    <code>perf:hotpath</code>'s route split line show every decision
    (<code>knowledge (no_signal)</code>,
    <code>tool_loop (keyword)</code>, …) so misroutes are auditable,
    never mysterious.
</p>

<h2>Adaptive rerank skip</h2>

<p>
    The cross-encoder reranker is the slowest retrieval stage (500–1,200ms
    on Workers AI). Its real job is choosing <em>which</em> top-K of the
    fan-out candidates survive — but when the vector search already
    returned K candidates that all score above
    <code>RAG_RERANK_SKIP_SCORE</code>, that choice is already made and
    the round-trip buys nothing. With <code>RAG_RERANK_SKIP=true</code>
    those turns keep ANN order and skip the reranker entirely; weak or
    sparse candidate sets always rerank (precision matters most exactly
    when recall was shaky).
</p>

<pre><code>RAG_RERANK_SKIP=true          # default off
RAG_RERANK_SKIP_SCORE=0.68    # ANN cosine bar; 0.80 default on OpenAI embeddings
RAG_RERANK_FAN_OUT=3          # candidates = topK × fan_out; lower = faster rerank</code></pre>

<p>
    Skipped turns show <em>rerank skipped</em> in the dashboard's Rerank
    column and Notes, and <code>perf:hotpath</code> prints a
    <code>Rerank skipped (ANN decisive): N/M turns</code> line — so you
    can see exactly how often the skip fires before trusting it.
</p>

<h2>CLI: <code>perf:hotpath</code></h2>

<p>
    Same measurement, scriptable. Run after any change you hope made
    things faster (model switch, Qdrant migration, cache driver) and
    compare runs:
</p>

<pre><code>php artisan perf:hotpath                # 10 turns, first published agent
php artisan perf:hotpath --turns=25     # bigger sample
php artisan perf:hotpath --agent=&lt;id&gt;   # specific agent</code></pre>

<p>
    The command sends real widget turns (same JWT + SSE path the
    embedded widget uses), prints wall-clock TTFB per turn, the same
    per-stage p50/p95 table as the dashboard, and the same verdict
    line. A unique suffix per message defeats the 30-minute retrieve
    cache so every turn exercises the full pipeline. When no provider
    keys are configured it warns that FakeOpenAi is bound — those runs
    measure pipeline overhead only.
</p>

<h2>Raw logs</h2>

<p>
    Every turn also writes a structured <code>rag.turn</code> line to
    the Laravel log with the same stage breakdown plus
    <code>retrieve_timings</code>. For historical analysis beyond the
    100-turn buffer:
</p>

<pre><code>grep 'rag.turn' storage/logs/laravel.log | tail -50</code></pre>
