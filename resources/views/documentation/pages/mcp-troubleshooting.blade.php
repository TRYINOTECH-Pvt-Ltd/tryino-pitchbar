<p>
    Common MCP failure modes and how to diagnose them. When a buyer reports the
    integration "isn't working", the first stop is the Activity tab on the server
    card — it shows the last 100 tool calls with their status, latency, and error
    summary.
</p>

<h2>Server is in "degraded" state</h2>

<p>
    Means the last discovery refresh failed. Click the server card → the error is
    shown inline. Common causes: server returned 5xx, the endpoint is unreachable,
    SSL certificate problem, the API key was revoked. Re-run "Test connection" once
    the underlying issue is fixed; the next successful call returns the status to
    "active".
</p>

<h2>Tool grants flipped off after a refresh</h2>

<p>
    A schema-drift detection. The tool's <code>input_schema</code> changed since the
    last refresh. Open the tool's row in Manage tools, inspect the new schema,
    re-enable if appropriate.
</p>

<h2>"Workspace MCP rate limit reached"</h2>

<p>
    More than 60 MCP calls in a minute across the workspace. Either the agent is
    looping on the same tool (check the audit log for repeats — the runtime dedupes
    by tool name per turn, but multiple turns can still pile up) or there's
    genuine high traffic. Per-plan limits can be raised in
    <code>config/mcp.php</code> if you need higher throughput.
</p>

<h2>"External integration is temporarily unavailable"</h2>

<p>
    Circuit breaker is open. The server failed 5 times in 60 seconds. Pitchbar
    waits 60 seconds before retrying. Check the Activity view for the underlying
    error pattern.
</p>

<h2>Visitor messages get no MCP-powered answers</h2>

<p>
    Walk the layers from the top:
</p>

<ol>
    <li>Is the server's status <strong>active</strong> on the server card?</li>
    <li>Are any tools <strong>enabled</strong> for the agent (Manage tools)?</li>
    <li>Does the visitor's question include a clear intent the LLM can map to a tool?
        The LLM is biased toward calling a tool only when description and schema match
        the visitor's request. Improve the server's tool descriptions if the LLM is
        guessing wrong.</li>
    <li>Run the Test connection button on the server card — if Test fails, no tool
        will ever fire.</li>
</ol>

<h2>OAuth token expired</h2>

<p>
    The server's status flips to <strong>pending_auth</strong> on the next call
    that returns 401. Reconnect from the admin UI (Phase 3 follow-up). Until then,
    tool calls for that server return "External integration unavailable" to the LLM
    and the visitor gets a normal answer based on RAG only.
</p>
