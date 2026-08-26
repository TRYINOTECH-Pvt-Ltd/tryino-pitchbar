<p>
    Connect an MCP server to a specific agent. The agent will be
    able to call tools exposed by that server during visitor
    conversations once you whitelist them.
</p>

<h2>Prerequisites</h2>

<ul>
    <li>An MCP server URL reachable from the public internet (Pitchbar is a SaaS — it cannot
        reach <code>localhost</code> or VPN-only hosts). The validator rejects private addresses.</li>
    <li>An API key (for bearer auth) or OAuth client credentials. Phase 1 ships bearer auth;
        OAuth flows are in a follow-up release.</li>
</ul>

<h2>Steps</h2>

<ol>
    <li>Open <code>/app/agents/&#123;agent&#125;/mcp</code>.</li>
    <li>Click <strong>Add server</strong>.</li>
    <li>Enter a label (e.g. "Linear"), the server URL, and the auth method.</li>
    <li>Submit. Pitchbar runs an <code>initialize</code> + <code>tools/list</code>
        handshake against the server and discovers the catalogue.</li>
    <li>Open the tool list (Manage tools button on the server card).</li>
    <li>Enable each tool you want the agent to use. Destructive tools require an
        explicit confirmation showing the input schema.</li>
</ol>

<h2>Verifying the connection</h2>

<p>
    On the server card, the <strong>Test</strong> button runs a fresh
    <code>initialize</code> + <code>ping</code> round trip and records the result.
    The <strong>Refresh tools</strong> button re-discovers the catalogue — useful
    after the server has been updated.
</p>

<h2>Same server attached to multiple agents</h2>

<p>
    Workspaces have one row per server URL (deduplicated at the database layer).
    Attaching the same URL to a second agent reuses the existing row but creates
    a new set of per-agent tool grants. Disabling a tool for one agent does not
    affect the other.
</p>
