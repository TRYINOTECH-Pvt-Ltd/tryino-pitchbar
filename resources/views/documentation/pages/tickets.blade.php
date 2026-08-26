<p>
    Tickets are durable support records. Conversations expire after 24
    hours; tickets persist forever. Use them for issues that need a
    human follow-up beyond the live chat window — billing disputes,
    bug reports, account problems, feature complaints.
</p>

<h2>How tickets get created</h2>

<ol>
    <li>
        <strong>The bot opens one mid-chat</strong> via the
        <code>open_ticket</code> LLM tool. Auto-enabled for agents whose
        vertical is <code>help_center</code> (the
        <code>HelpCenterPreset</code> ships the <code>ticketing</code>
        capability the tool requires). Other verticals can opt in by
        adding <code>ticketing</code> to the agent's
        <code>vertical_overrides.capabilities</code>.
    </li>
    <li>
        <strong>An operator-configured CTA</strong> of kind
        <code>open_ticket</code> renders a button inside the chat that
        opens a ticket directly when the visitor clicks it. Useful when
        you want a deterministic escalation path that doesn't depend on
        the LLM choosing the tool.
    </li>
    <li>
        <strong>Inbox human-handoff</strong> — operators can convert a
        live conversation into a ticket from the Inbox UI when they
        realise it needs follow-up after the visitor leaves the page.
    </li>
</ol>

<h2>Ticket lifecycle</h2>

<p>
    Status moves through four values backed by constants on
    <code>App\Models\Ticket</code>:
</p>

<table>
    <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><code>open</code></td><td>Created, not yet picked up. Default on insert.</td></tr>
        <tr><td><code>pending</code></td><td>Assigned to an operator and being worked.</td></tr>
        <tr><td><code>resolved</code></td><td>Operator marked done. <code>resolved_at</code> stamped.</td></tr>
        <tr><td><code>closed</code></td><td>Archived. Hidden from default views.</td></tr>
    </tbody>
</table>

<p>Priority is one of <code>low</code>, <code>normal</code>, <code>high</code>, <code>urgent</code> — set by the LLM at create time, mutable by operators.</p>

<h2>Admin surface</h2>

<p>
    Operators see workspace tickets at <code>/app/tickets</code>:
</p>

<ul>
    <li><code>GET /app/tickets</code> — paginated list, filter by status / priority / assignee. <a href="/documentation/multi-tenancy">Workspace-scoped</a> via the <code>BelongsToWorkspace</code> trait.</li>
    <li><code>GET /app/tickets/{ticket}</code> — single ticket with the linked conversation history if one exists.</li>
    <li><code>PATCH /app/tickets/{ticket}</code> — change status, priority, assignee, or notes.</li>
</ul>

<h2>Schema</h2>

<p>The <code>tickets</code> table (migration <code>2026_05_13_092105_create_tickets_table.php</code>):</p>

<ul>
    <li><code>workspace_id</code>, <code>agent_id</code>, <code>conversation_id</code> (nullable — operator-opened tickets needn't be tied to a chat).</li>
    <li><code>subject</code> (≤ 120 chars), <code>body</code>.</li>
    <li><code>status</code> (open / pending / resolved / closed), <code>priority</code> (low / normal / high / urgent).</li>
    <li><code>assigned_to_user_id</code> — nullable.</li>
    <li><code>metadata</code> JSON — free-form bag for source-specific context (the <code>open_ticket</code> tool stores <code>source: 'open_ticket_tool'</code>; the CTA path stores the rule id; the human-escalation path stores the operator's id).</li>
    <li><code>resolved_at</code> stamped when status flips to resolved.</li>
    <li>Timestamps.</li>
</ul>

<h2>Linking back to chat history</h2>

<p>
    Every ticket carries <code>conversation_id</code> when one is
    available. The admin page renders the full chat thread side-by-side
    with the ticket body so the operator can answer with the visitor's
    own words for context — no "what did they actually ask?" hunt.
</p>

<h2>How the open_ticket tool works</h2>

<p>
    See <a href="/documentation/tool-calling">Tools &amp; rich messages</a>
    for the registry shape. The tool's input schema:
</p>

<ul>
    <li><code>subject</code> — required, ≤ 120 chars.</li>
    <li><code>body</code> — required. The LLM is prompted to include the visitor's account / order references, what they tried, and what went wrong.</li>
    <li><code>priority</code> — optional, defaults to <code>normal</code>.</li>
</ul>

<p>
    On invoke the tool inserts a <code>Ticket</code> row tied to the
    current conversation and returns a confirmation the LLM weaves into
    its final reply ("I've opened ticket #ABC-1234 for our team…").
    Operators get a notification via the same channel they receive
    new-lead pings.
</p>
