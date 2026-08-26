<p>
    Tools are server-side helpers your agent can <em>invoke</em> mid-turn.
    The visitor types something, the LLM decides "I need to call a tool
    for this", the tool runs on the server, and its result either feeds
    back into the LLM's final answer or surfaces directly in the widget
    as a structured block (e.g. a "Connect me with a human" button).
</p>

<p>
    Tools are gated by the agent's <a href="/documentation/site-types">vertical capabilities</a>.
    Each tool declares which capability it requires; the registry only
    exposes a tool to agents whose capability list contains that slug.
    Admins can further narrow the list with <code>vertical_overrides.enabled_tools</code>.
</p>

<h2>Shipping tools</h2>

<table>
    <thead>
        <tr><th>Tool</th><th>Required capability</th><th>Auto-enabled on</th><th>What it does</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><code>escalate_to_human</code></td>
            <td><code>ticket_escalation</code></td>
            <td>Every vertical (capability ships on every preset)</td>
            <td>Surfaces a "Connect me with a human" button (block type <code>escalation_button</code>). Click triggers the existing lead-capture flow so an operator can claim the conversation. Args: <code>reason</code>.</td>
        </tr>
        <tr>
            <td><code>lookup_order</code></td>
            <td><code>order_status</code></td>
            <td>ecommerce</td>
            <td>Calls the workspace's WooCommerce REST endpoint via <code>LookupOrderClient</code>, fetches the visitor's recent orders (status + items + tracking). Hard 5s timeout. Requires a signed-in shopper (<code>shopper.wp_user_id</code> JWT claim) and a <code>woocommerce_products</code> Source attached to the agent — degrades gracefully when either is missing. Args: <code>limit</code> (default 5, max 10), optional <code>status</code>.</td>
        </tr>
        <tr>
            <td><code>open_ticket</code></td>
            <td><code>ticketing</code></td>
            <td>help_center (capability auto-included)</td>
            <td>Opens a durable support ticket from inside the conversation — billing disputes, account issues, bug reports. Persists to the <code>tickets</code> table linked to the source conversation. Args: <code>subject</code> (≤ 120 chars), <code>body</code>, optional <code>priority</code> (low / normal / high / urgent).</td>
        </tr>
        <tr>
            <td><code>send_kb_article</code></td>
            <td><code>kb_article_card</code></td>
            <td>help_center</td>
            <td>Surfaces a card linking to a published Knowledge Base article. Looks the slug up against the workspace's curated answers; the visitor sees a card with title + excerpt + click-through. Args: <code>slug</code>.</td>
        </tr>
    </tbody>
</table>

<p>
    Tool gating is the intersection of (a) the agent's vertical capabilities,
    (b) each tool's required capability, and (c) the admin's
    <code>vertical_overrides.enabled_tools</code> allowlist. Any tool whose
    required capability isn't in the agent's set is silently dropped before
    the LLM sees it.
</p>

<h2>How the hot path resolves tool calls</h2>

<p>
    For every visitor turn on a tool-enabled agent,
    <code>MessageStreamController</code> runs a small tool-resolution
    loop <em>before</em> the streaming final answer:
</p>

<ol>
    <li>
        Build the OpenAI-style <code>tools</code> array from the
        registry's <code>forAgent($agent)</code> result.
    </li>
    <li>
        Call <code>llm-&gt;chatWithTools(messages, tools)</code>
        non-streaming. The model either returns
        <code>tool_calls</code> (it wants to invoke one or more tools)
        or <code>content</code> (it's ready to answer).
    </li>
    <li>
        If <code>tool_calls</code>: emit a <code>tool_call</code> SSE
        event for each invocation, run the tool's
        <code>execute()</code>, append the tool result to the message
        history as a <code>{role: 'tool'}</code> message, and loop.
    </li>
    <li>
        Once the model returns <code>content</code> (or after 3 hops,
        whichever comes first), fall through to the existing
        <code>streamChat</code> path. The visitor still gets
        token-by-token streaming for the final answer, so TTFT is
        preserved.
    </li>
    <li>
        Any <code>block</code> payloads the tools produced (e.g.
        <code>escalation_button</code>) are emitted as
        <code>block</code> SSE events for the widget to render inline.
    </li>
</ol>

<h2>Provider compatibility</h2>

<table>
    <thead>
        <tr><th>Provider</th><th>Tool calling</th><th>Notes</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>OpenAI (gpt-4o-mini, gpt-4o)</td>
            <td>Native</td>
            <td>Full OpenAI <code>tools</code> array support via the SDK.</td>
        </tr>
        <tr>
            <td>OpenRouter</td>
            <td>Model-dependent</td>
            <td>Tool-capable models (Claude 3.5, Llama 3.3 70B Hermes, etc.) work via the same OpenAI-compatible surface.</td>
        </tr>
        <tr>
            <td>Cloudflare Workers AI</td>
            <td>Model-dependent</td>
            <td>Llama 3.3 70B Hermes and a handful of other models support function calling. Models without tool support gracefully degrade — they'll ignore the tools array and return content directly, so the loop simply exits.</td>
        </tr>
    </tbody>
</table>

<h2>Widget rendering of blocks</h2>

<p>
    The widget receives <code>block</code> SSE events during a turn and
    attaches each block to the in-flight assistant message. The
    renderer registry in <code>resources/widget/src/ui/blocks.tsx</code>
    maps block <code>type</code> → Preact component. Unknown block types
    are silently dropped (forward-compat for newer servers).
</p>

<p>
    The widget's <code>canRender(capability, agent)</code> helper now
    returns <code>true</code> when:
</p>

<ol>
    <li>
        the bundle ships a renderer for that capability, AND
    </li>
    <li>
        the agent's server-resolved <code>capabilities</code> array
        opted in.
    </li>
</ol>

<p>
    Both are required — the widget never enables a capability the
    server didn't authorize, and never tries to render a block whose
    renderer isn't in the bundle.
</p>

<h2>Adding a new tool</h2>

<ol>
    <li>
        Implement <code>App\Services\Tools\Contracts\Tool</code> in
        <code>app/Services/Tools/Tools/YourTool.php</code>. Pick a
        unique <code>name()</code>, write a clear
        <code>description()</code> (the LLM uses it to decide when to
        invoke), declare the <code>capability()</code> slug it
        requires, and define the <code>schema()</code> JSON.
    </li>
    <li>
        Register the tool in <code>ToolRegistry::__construct</code>.
    </li>
    <li>
        If your tool's <code>execute()</code> returns a <code>block</code>
        payload, ship a renderer for it in <code>ui/blocks.tsx</code>
        and add the relevant capability slug to the
        <code>RENDERABLE</code> set in <code>capabilities.ts</code>.
    </li>
    <li>
        Add a Pest unit test for the tool and a feature test for the
        end-to-end flow using <code>FakeOpenAi::pushToolCall</code>.
    </li>
</ol>

<h2>Latency considerations</h2>

<p>
    The tool loop adds one non-streaming round-trip per hop before the
    streaming final answer kicks in. For a typical "needs one tool"
    turn that's roughly +200–500 ms of latency before the visitor sees
    the first token. The 99% case (no tools used) is unchanged because
    the registry returns an empty tool list for agents whose
    capabilities don't match any registered tool.
</p>

<p>
    To keep latency manageable, write tool descriptions tightly so the
    LLM only invokes a tool when it really needs one. The hop limit
    (3) is a safety net — well-written tool descriptions should
    converge in 1 hop.
</p>
