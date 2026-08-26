<p>
    The MCP integration sits on the visitor-facing hot path and reaches third-party
    servers. The security model below is the defence-in-depth applied at every layer.
</p>

<h2>SSRF protection</h2>

<ul>
    <li>The server URL is rejected at form-validation time if it points at a private,
        loopback, link-local, or cloud-metadata address.</li>
    <li>The same guard runs again at every outbound call with DNS rebind protection —
        an attacker who controls DNS for a public hostname they own cannot redirect
        the call to <code>127.0.0.1</code>.</li>
    <li>Only <code>http://</code> and <code>https://</code> schemes are accepted.</li>
</ul>

<h2>Credential isolation</h2>

<ul>
    <li>Stored credentials are encrypted at rest via Laravel's <code>encrypted:array</code> cast.</li>
    <li>The credential resolver is the only code path that touches decrypted credentials.
        Every other layer works with an assembled <code>Authorization</code> header string
        on a per-call DTO.</li>
    <li>Octane workers never hold credentials between requests — the DTO is constructed
        per call.</li>
</ul>

<h2>Prompt-injection defence</h2>

<p>
    Every tool result is wrapped in <code>&lt;tool-result trusted="false"&gt;</code>
    tags. The system prompt explicitly tells the LLM that anything inside such tags
    is data, not instructions, and not to follow links or commands found inside.
    Same pattern as the existing <code>&lt;source&gt;</code> RAG wrapping.
</p>

<h2>Output budget enforcement</h2>

<p>
    Tool outputs are truncated to a per-tool token budget (default 1200) before being
    fed back to the LLM. A misbehaving server returning multi-megabyte responses is
    capped at 1MB by the transport layer; the token truncator caps oversized but
    legitimate responses.
</p>

<h2>Rate limit + circuit breaker</h2>

<ul>
    <li>Per-workspace rate limit: 60 MCP calls per minute by default. Excess attempts
        return a structured "rate_limited" tool result to the LLM.</li>
    <li>Per-server circuit breaker: 5 failures in 60s open the breaker for 60s.
        While open, calls return a structured "unavailable" tool result without
        hitting the server.</li>
</ul>

<h2>Tenancy</h2>

<p>
    Servers, tools, grants, and call logs are scoped by <code>workspace_id</code>
    via the <code>BelongsToWorkspace</code> trait. The widget hot path resolves the
    agent from the signed JWT, then queries through the registry which never reads
    <code>CurrentWorkspace</code> directly. Cross-tenant attempts return 404 on
    admin routes (existence-leak-safe).
</p>
