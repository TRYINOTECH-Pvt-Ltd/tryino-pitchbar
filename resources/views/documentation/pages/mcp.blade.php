<p>
    MCP (Model Context Protocol) is the open standard for connecting
    LLMs to external tools and data. Pitchbar acts as an <strong>MCP
    client</strong>: buyers connect their MCP servers
    (CRMs, calendars, inventory, internal APIs) and the agent calls
    tools mid-conversation when the visitor's question needs live
    data the crawl can't provide.
</p>

<h2>What it unlocks</h2>

<ul>
    <li>The bot looks up an order status in Shopify before answering "where is my package?"</li>
    <li>The bot books a demo on Cal.com without leaving the chat.</li>
    <li>The bot fetches the visitor's open tickets from HubSpot to give context-aware replies.</li>
    <li>The bot queries the buyer's own internal API via their custom MCP server.</li>
</ul>

<h2>Architecture in one paragraph</h2>

<p>
    Each agent has its own set of attached MCP servers. The admin
    explicitly enables each tool per agent — destructive tools (writes
    to the external system) require a confirmation dialog. At
    runtime, the agent's tool-call loop merges built-in tools
    (escalate_to_human, etc.) with MCP-discovered tools. When the
    LLM decides to call an MCP tool, the request goes through a
    rate-limited, circuit-breakered executor that wraps the tool
    output in <code>&lt;tool-result trusted="false"&gt;</code> tags
    so the system prompt can treat it as data, not instructions.
</p>

<h2>Hard rules</h2>

<ul>
    <li><strong>Whitelist by default</strong>. Discovered tools land in the catalogue
        as disabled. An admin explicitly turns each one on per agent.</li>
    <li><strong>Schema drift = re-approval</strong>. When a tool's input shape changes
        between catalogue refreshes, every existing grant is auto-disabled so the
        admin re-acks the new parameters.</li>
    <li><strong>SSRF guard</strong>. Server URLs that resolve to private / loopback /
        cloud-metadata addresses are rejected at validation time AND at every call.</li>
    <li><strong>Token budget</strong>. Tool outputs are truncated to 1200 tokens by
        default (per-tool override allowed) so a chatty MCP server can't blow the
        model's context window.</li>
    <li><strong>Audit trail</strong>. Every call is logged with status, latency, args
        preview, output preview, and a ULID request id for cross-system correlation.</li>
</ul>

<h2>Next steps</h2>

<ul>
    <li><a href="/documentation/mcp-setup">Attach an MCP server</a></li>
    <li><a href="/documentation/mcp-tool-whitelisting">Tool whitelist &amp; destructive actions</a></li>
    <li><a href="/documentation/mcp-security">Security model</a></li>
    <li><a href="/documentation/mcp-troubleshooting">Troubleshooting</a></li>
</ul>
