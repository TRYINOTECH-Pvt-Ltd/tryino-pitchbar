<p>
    When a buyer reports "the bot asked for an email instead of opening a
    ticket" or "it just stopped", reproducing the moment is usually
    impossible — the visitor is gone, the context was unique. The turn
    debugger removes the need to reproduce: every widget turn silently
    records what the agent <em>actually did</em>, and the platform admin
    can replay the evidence per conversation.
</p>

<h2>Where</h2>

<p>
    <strong>Super-admin → <code>/admin/conversations</code> → open a
    conversation.</strong> Under every assistant reply sits a collapsible
    trace card (error traces start expanded). Turns that never persisted a
    message — provider failures, human-takeover short-circuits — appear as
    standalone cards at the end of the thread, because failures are
    exactly when forensics matter.
</p>

<h2>What a trace shows</h2>

<table>
    <thead><tr><th>Section</th><th>Answers</th></tr></thead>
    <tbody>
        <tr>
            <td><strong>Route</strong></td>
            <td>Fast-router decision: <code>knowledge (no_signal)</code> or
                <code>tool_loop (keyword · score 0.91 → open_ticket)</code>.
                "Why didn't it run the tool?" starts here.</td>
        </tr>
        <tr>
            <td><strong>History</strong></td>
            <td>How many prior messages were in the LLM context — stale or
                unexpected history is the classic "bot continued the wrong
                thread" cause.</td>
        </tr>
        <tr>
            <td><strong>Retrieval</strong></td>
            <td>Cache hit/miss, rerank skipped, low-confidence flag, and
                each chunk's URL + scores + snippet. "0 chunks passed the
                threshold" explains an "I'm not sure" answer instantly.</td>
        </tr>
        <tr>
            <td><strong>Tool loop</strong></td>
            <td>Hop by hop: which tools the model requested, the exact
                arguments, the (truncated) result or error, duplicate calls
                that were dropped — or "skipped (fast router)" / "stopped
                without calling anything".</td>
        </tr>
        <tr>
            <td><strong>Outcome</strong></td>
            <td>Latency, token estimate, blocks emitted, CTAs, lead-form
                offer, model — or the exact exception class + message on a
                failed turn.</td>
        </tr>
    </tbody>
</table>

<h2>Trace kinds</h2>

<p>
    <code>llm</code> (normal turn), <code>error</code> (provider/stream
    failure — starts expanded), <code>curated</code> (pinned answer
    short-circuit), <code>workflow</code> (scripted flow),
    <code>human_shortcut</code> (explicit "talk to a human" phrase),
    <code>human_pending</code> / <code>takeover</code> (operator owns the
    conversation; the bot stayed silent by design).
</p>

<h2>Cost & retention</h2>

<p>
    Traces are assembled in memory during the turn and persisted by a
    queued job <em>after</em> the SSE stream closes — the visitor never
    waits on them. If the database write fails (classic case: code
    deployed, <code>php artisan migrate</code> run later), the trace is
    stashed in a capped cache buffer and automatically drained into the
    table — original timestamps intact — as soon as the next write
    succeeds. Traces survive the outage instead of vanishing into a log
    line. Chunk snippets and tool results are truncated hard;
    traces are breadcrumbs, not a copy of the knowledge base. The daily
    <code>turn-traces:prune</code> scheduler deletes traces older than
    the retention window.
</p>

<pre><code>TURN_TRACES_ENABLED=true        # kill switch (default on)
TURN_TRACE_RETENTION_DAYS=14    # prune window

php artisan turn-traces:prune            # manual sweep
php artisan turn-traces:prune --days=7   # override window once</code></pre>
