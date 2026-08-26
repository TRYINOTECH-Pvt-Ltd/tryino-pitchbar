<p>
    The hot path is the visitor-message → first-token pipeline. It has a
    hard <strong>1 second p95</strong> contract — beyond that, the
    perceived "is this thing alive?" tension breaks. Everything that doesn't
    have to happen on the hot path is pushed off it.
</p>

<h2>Latency budget</h2>

<p>
    Target breakdown for first-token at p95:
</p>

<table>
    <thead><tr><th>Phase</th><th>p95 target</th></tr></thead>
    <tbody>
        <tr><td>HTTP receive + auth</td><td>30 ms</td></tr>
        <tr><td>Curated short-circuit check</td><td>5 ms</td></tr>
        <tr><td>Embed query</td><td>120 ms</td></tr>
        <tr><td>Vector search (ANN)</td><td>80 ms</td></tr>
        <tr><td>Rerank</td><td>120 ms</td></tr>
        <tr><td>Prompt assembly</td><td>10 ms</td></tr>
        <tr><td>LLM time-to-first-token</td><td>500 ms</td></tr>
        <tr><td><strong>Total</strong></td><td><strong>~865 ms</strong></td></tr>
    </tbody>
</table>

<p>
    Headroom for the rest is &lt; 150ms. Anything beyond first-token streams
    out incrementally — full-response time is bounded by token rate, not
    the budget.
</p>

<h2>Hard rules</h2>

<p>
    These are enforced by code review and by tests:
</p>

<ol>
    <li><strong>No DB writes on the hot path.</strong> Persistence is async after the stream ends.</li>
    <li><strong>No synchronous webhooks.</strong> Outgoing webhooks are dispatched as queue jobs.</li>
    <li><strong>No retries.</strong> If a provider fails mid-stream, the user sees a graceful error and the widget auto-retries client-side. Server doesn't loop.</li>
    <li><strong>No N+1 queries.</strong> All reads are batched. Recent history comes from Redis (<code>conv:{id}:history</code>), not Postgres.</li>
    <li><strong>One LLM call per turn.</strong> No multi-step agent reasoning that fans out into multiple model calls.</li>
    <li><strong>Short-circuit DB writes ride below <code>emit('done')</code>.</strong> The human-takeover, human-pending, and human-intent short-circuits used to persist the visitor's <code>Message</code> + stamp <code>conversation.attribution</code> before the SSE close event reached the client. Audit 2026-05-30 moved these below <code>emit('done')</code> via <code>persistShortCircuitVisitorTurn()</code> so the stream closes immediately and the writes run after. The <code>escalation_offered_at</code> stamp emitted by the main LLM path is deferred to the same post-emit slot — next turn's tool loop re-reads the row from DB so the lag doesn't change tool gating semantics.</li>
</ol>

<h2>What's off the hot path</h2>

<p>
    Everything below is dispatched <em>after</em> the stream completes.
    None of it blocks the visitor:
</p>

<ul>
    <li><strong>PersistTurnJob</strong> — save user + assistant messages.</li>
    <li><strong>IncrementUsageJob</strong> — bump the workspace's monthly conversation counter.</li>
    <li><strong>DetectGapJob</strong> — cluster low-confidence questions for the gap report.</li>
    <li><strong>DispatchWebhookJob</strong> — fan out to subscribed customer endpoints (workflow side; the lead-captured webhook fires inline via <code>SignedDispatcher</code> when a visitor submits the lead form).</li>
    <li><strong>AutoIndexPageVisit</strong> — synchronous service triggered at <code>/init</code> time that queues a <code>CrawlPageJob</code> for the visited URL when auto-indexing is enabled. Not a hot-path job, but worth knowing where auto-index runs.</li>
</ul>

<h2>Caching</h2>

<p>
    Two caches keep the hot path tight:
</p>

<ul>
    <li><strong>Retrieval cache</strong> — Redis, <code>rag:retrieve:{agentId}:{hash(query|currentPageUrl)}</code>, 30-minute TTL. Same question on the same page hits cache. Invalidated when sources change.</li>
    <li><strong>The search query is not always the raw message.</strong> Mid-conversation a visitor drops the subject — they answer the assistant ("Hoge rand, ik draag werklaarzen") or ask a bare follow-up ("en de prijs?"). Embedded literally, those carry no product noun and retrieve nothing. <code>RetrievalQueryBuilder</code> restores the missing subject from the conversation: the previous user turn, plus — when the assistant's own last turn ended in a question — the proper nouns it named, so "Maat 39, wat kost dat?" still searches for the product under discussion. A complete question that opens with an interrogative ("wat is uw adres") is left exactly as typed; it is a change of subject, not a follow-up.</li>
    <li><strong>Conversation history cache</strong> — Redis, <code>conv:{convId}:history</code>, 2-hour TTL, capped at 12 messages (6 turns). Reads from this on every turn instead of Postgres.</li>
</ul>

<h2>Streaming mechanics</h2>

<p>
    SSE is dead simple — keep-alive HTTP, write
    <code>data: {...}\n\n</code> per token, flush. The widget reads via
    <code>EventSource</code> (or fetch + reader for older browsers without
    <code>EventSource</code> on POST).
</p>

<p>
    Critically, the SSE response is constructed before any RAG work runs.
    We start writing headers <em>immediately</em> on request receipt so any
    proxy in front of us (Cloudflare, load balancer) commits to streaming
    early. By the time tokens arrive, the connection is already open.
</p>

<h2>Where the spans live</h2>

<p>
    OpenTelemetry spans wrap each phase:
</p>

<ul>
    <li><code>widget.message.receive</code></li>
    <li><code>rag.curated.match</code></li>
    <li><code>rag.embed</code></li>
    <li><code>rag.vector.search</code></li>
    <li><code>rag.rerank</code></li>
    <li><code>rag.prompt.assemble</code></li>
    <li><code>rag.llm.first_token</code></li>
    <li><code>rag.llm.stream</code></li>
    <li><code>rag.persist.async</code></li>
</ul>

<p>
    Honeycomb / Grafana shows the p95 of each. When the budget breaks, the
    span heatmap usually points right at the offender.
</p>

<h2>Failure modes</h2>

<table>
    <thead><tr><th>Failure</th><th>Behavior</th></tr></thead>
    <tbody>
        <tr><td>LLM provider 5xx mid-stream</td><td>Stream emits an <code>error</code> event. Widget auto-retries up to 3 times.</td></tr>
        <tr><td>Vector store unreachable</td><td>Pipeline returns the question with no grounding. Confidence is 0.3 → low_confidence flag → "I don't know" answer.</td></tr>
        <tr><td>Embed call times out</td><td>Same — proceed with no grounding, flag low_confidence.</td></tr>
        <tr><td>Quota exceeded</td><td>Caught at <code>/init</code>, never reaches messages. 429 returned.</td></tr>
    </tbody>
</table>

<p>
    The principle: <strong>the visitor always gets a response</strong>, even
    if it's "I'm not sure". The agent is allowed to be ignorant; it isn't
    allowed to silently break.
</p>
