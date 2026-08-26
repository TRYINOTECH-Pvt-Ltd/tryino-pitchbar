<p>
    Workflows let you script how an agent responds to common visitor
    questions <em>before</em> the LLM is involved. Useful for canned
    answers (refund FAQ, hours, store policies), lead-qualifying
    questionnaires, branching qualification flows, and direct human
    handoff on specific keywords.
</p>

<h2>How it fits</h2>

<p>
    Every visitor message runs through this pipeline:
</p>

<ol>
    <li>Curated answer match — admin-pinned exact-question answer.</li>
    <li><strong>Workflow match</strong> ← this page.</li>
    <li>RAG retrieval + LLM generation.</li>
</ol>

<p>
    A workflow short-circuits the LLM call when it matches: the
    visitor receives the scripted bubbles, no token cost is incurred,
    and the conversation is tagged with the workflow run for analytics.
</p>

<h2>How a workflow connects to an agent</h2>

<p>
    A workflow only fires for a visitor turn when <strong>three</strong>
    conditions all line up:
</p>

<ol>
    <li>
        <strong>Status</strong> is <code>active</code>. New workflows
        start in <code>draft</code> and the runtime ignores them
        entirely until you flip the status — that's how you stage
        edits without breaking live traffic. <code>disabled</code>
        is a third state for "paused but not deleted".
    </li>
    <li>
        <strong>Agent scope</strong> matches. On the workflow's
        edit page, the <em>Scoped to agent</em> select gives you
        two shapes:
        <ul>
            <li>
                <strong>Workspace-wide (every agent)</strong> —
                <code>agent_id IS NULL</code>. The workflow is
                eligible for every agent in your workspace. Good for
                cross-cutting flows like "the word 'human' anywhere
                triggers handoff".
            </li>
            <li>
                <strong>Pinned to one agent</strong> — pick a
                specific agent from the dropdown. The workflow is
                eligible only for conversations on that agent. Good
                for shop-specific FAQ ("returns" only matters on the
                Shopify-store agent, not on the docs agent).
            </li>
        </ul>
    </li>
    <li>
        <strong>Trigger fires</strong>. The visitor's message has
        to actually match the workflow's keyword set (under the
        configured <code>any</code> / <code>all</code> /
        <code>exact</code> match mode).
    </li>
</ol>

<p>
    Concretely, for every visitor turn the engine runs one query:
</p>

<pre><code>SELECT * FROM workflows
 WHERE status = 'active'
   AND workspace_id = :current_workspace
   AND (agent_id IS NULL OR agent_id = :current_agent)</code></pre>

<p>
    …and walks the matching rows in order, returning the first whose
    keywords match the message. Order is the
    insertion order from the database (i.e. older workflows
    win ties); use status <code>disabled</code> to deactivate a
    workflow without losing its keywords. There's no priority
    column today — if you need stricter precedence, narrow the
    keyword sets so two workflows can't both match the same turn.
</p>

<h3>Quick recipe — wire a fresh workflow to an agent</h3>

<ol>
    <li>Go to <code>/app/workflows</code> → <strong>New workflow</strong>.</li>
    <li>
        Give it a name. Pick the agent from <em>Scoped to agent</em>
        (or leave on <em>Workspace-wide</em>).
    </li>
    <li>
        Status: pick <strong>Active</strong> if you're ready to ship it
        immediately, or <strong>Draft</strong> while you're still
        editing.
    </li>
    <li>
        Add at least one keyword in the trigger config (e.g.
        <code>refund</code>) and pick a match mode. To add several, type
        them <strong>comma-separated</strong> in the one field —
        <code>refund, refunds, money back</code> — and each becomes its
        own keyword.
    </li>
    <li>Add at least one step — a <code>message</code> bubble works.</li>
    <li>Save.</li>
    <li>
        On the targeted agent's public widget, type a message
        containing the keyword. The workflow runs instead of the LLM,
        and you'll see the scripted reply.
    </li>
</ol>

<p>
    To stop a workflow without deleting it, flip its status to
    <code>disabled</code>. To re-target it from one agent to another,
    just change <em>Scoped to agent</em> on the same workflow row —
    no need to clone the steps.
</p>

<h2>Capabilities</h2>

<ul>
    <li>One trigger type: <strong>on keyword</strong>, with three match modes (any / all / exact).</li>
    <li>Six step types: <strong>message</strong>, <strong>question</strong>, <strong>branch</strong>, <strong>tag_lead</strong>, <strong>webhook</strong>, <strong>escalate</strong>.</li>
    <li>Variable interpolation: <strong>@{{var_name}}</strong> in any message text resolves the captured value at runtime.</li>
    <li>Conditional branching on captured answers (5 match operators + a default fallback).</li>
    <li>Side-effect steps for tagging leads and POSTing to external webhooks (queued, fire-and-forget).</li>
</ul>

<h2>Step types</h2>

<table>
    <thead><tr><th>Type</th><th>What it does</th></tr></thead>
    <tbody>
        <tr>
            <td><code>message</code></td>
            <td>Send one chat bubble. Supports <code>@{{var_name}}</code> interpolation. Walks straight to the next step.</td>
        </tr>
        <tr>
            <td><code>question</code></td>
            <td>Send one bubble + pause the run. The visitor's next message is captured into <code>var_name</code> and the run resumes from the next step on the following turn.</td>
        </tr>
        <tr>
            <td><code>branch</code></td>
            <td>Evaluate <code>vars[var]</code> against an ordered list of cases and jump to the first matching <code>go_to</code> step index. The case operators are <code>equals</code>, <code>contains</code>, <code>starts_with</code>, <code>is_empty</code>, <code>not_empty</code>, and <code>default</code> (a fallback fired when no other case hits). Loop-guarded at 32 jumps per turn so a malformed graph can't hang the engine.</td>
        </tr>
        <tr>
            <td><code>tag_lead</code></td>
            <td>Append string tags to the conversation's Lead row (creates a stub Lead if the visitor hasn't submitted the inline form yet). Tags accumulate and dedupe on <code>fields.tags</code>. Typical use: tag a visitor as <code>pricing_intent</code> after a branch lands them on the Pro lane.</td>
        </tr>
        <tr>
            <td><code>webhook</code></td>
            <td>Fire-and-forget POST (or GET) to an external URL with the run's <code>vars</code>, <code>conversation_id</code>, <code>workflow_id</code>, and any <code>extra_payload</code>. Dispatched as a queued <code>DispatchWebhookJob</code> so the visitor's chat surface never waits on a slow integration. Failures land in <code>/admin/jobs/failed</code> after 3 retries.</td>
        </tr>
        <tr>
            <td><code>escalate</code></td>
            <td>Send a goodbye bubble + flag the run as escalated. The conversation is now in the inbox for an operator to claim from the takeover UI.</td>
        </tr>
    </tbody>
</table>

<h2>Trigger match modes</h2>

<table>
    <thead><tr><th>Mode</th><th>Matches when</th></tr></thead>
    <tbody>
        <tr>
            <td><code>any</code> <em>(default)</em></td>
            <td>At least one keyword appears as a case-insensitive substring of the visitor's message. <code>"pricing"</code> matches "tell me about pricing please".</td>
        </tr>
        <tr>
            <td><code>all</code></td>
            <td>Every keyword must appear as a substring. Useful for compound qualifiers — <code>["enterprise", "security"]</code> won't fire on "tell me about pricing", but will on "enterprise security review".</td>
        </tr>
        <tr>
            <td><code>exact</code></td>
            <td>The trimmed lower-cased message equals one of the keywords verbatim. Use for single-word commands ("cancel", "help", "support") that need to NOT fire on substring matches.</td>
        </tr>
    </tbody>
</table>

<h2>Branch routing</h2>

<p>
    A <code>branch</code> step's <code>cases</code> array is walked in
    order. The first case whose <code>match</code> evaluates true wins,
    and execution jumps to <code>go_to</code> (a step index).
    <code>match: default</code> is a special case that always matches
    and should sit last — it's the catch-all when none of the
    preceding operators fire.
</p>

<pre><code>{
  "type": "branch",
  "var": "plan_interest",
  "cases": [
    { "match": "equals",      "value": "free", "go_to": 5 },
    { "match": "contains",    "value": "pro",  "go_to": 8 },
    { "match": "not_empty",                    "go_to": 12 },
    { "match": "default",                      "go_to": 15 }
  ]
}</code></pre>

<p>
    A flow can chain branches — the loop-guard caps execution at 32
    jumps per turn so two branches accidentally pointing at each other
    can't infinite-loop. When the guard trips the run is marked
    <code>failed</code> and whatever bubbles were emitted before the
    loop are still flushed to the visitor.
</p>

<h2>Trigger semantics</h2>

<p>
    The runtime walks every <strong>active</strong> workflow whose
    workspace matches the conversation's agent and whose
    <code>agent_id</code> is either NULL (workspace-wide) or matches
    the conversation's agent. Within those rows, the first workflow
    whose keywords match (per its <code>match_mode</code>) wins.
    Order on the admin index page is by <code>updated_at</code>
    descending. <strong>The runtime matching loop itself has no
    <code>ORDER BY</code></strong> — rows come back in the database's
    default order (typically insertion order). If two workflows
    could match the same input, tighten one of them so only one
    wins, rather than relying on insertion-order coincidence.
</p>

<h2>Editing — visual canvas (default) and linear form (alt)</h2>

<p>
    Two ways to edit a workflow, both backed by the same JSON shape:
</p>

<ul>
    <li>
        <strong>Visual canvas</strong> at <code>/app/workflows/{id}/canvas</code>
        — the <strong>default</strong> editor. Clicking a workflow on
        the index page or saving a freshly-created flow lands you
        here. React-Flow editor with one node per step, draggable
        connections between handles, and a end-side inspector
        panel. Branch nodes have one handle per case (plus an optional
        <code>default</code> handle); wire each handle to the next step.
    </li>
    <li>
        <strong>Linear form</strong> at <code>/app/workflows/{id}/edit</code>
        — the alternate. A stack of step cards with type-specific
        fields. Reach it from the canvas page's "Linear edit" button
        when a flow is simple enough that a flat list reads cleaner
        than a graph.
    </li>
</ul>

<p>
    The canvas persists the visual layout (node positions + edge
    connections) under <code>definition.canvas</code>, alongside the
    runtime-consumed <code>definition.steps</code> array — so flows
    authored in the canvas re-open with the same arrangement next time.
</p>

<p>
    Saving from the canvas runs a topological walk of the graph from
    the trigger node and serialises the steps array in BFS order.
    Branch <code>cases[*].go_to</code> are populated from the edge
    target indices, so re-saving a flow you only opened to read
    leaves the steps unchanged.
</p>

<h2>Canvas builder features</h2>

<ul>
    <li><strong>Canvas-first creation</strong> — the "New in canvas"
        button (or <code>/app/workflows/create?canvas=1</code>) opens the
        builder on an unsaved draft; the first Save creates the workflow
        and keeps you on the canvas. Saving from the canvas always
        returns to the canvas.</li>
    <li><strong>Live validation</strong> — the toolbar shows a problem
        count; broken nodes get a red ring. Checks: trigger without
        keywords or without a first step, unreachable steps, empty
        message/question text, unwired branch cases, missing/non-http
        webhook URLs, tag steps without tags. Problems block
        <em>activating</em> a flow, never saving a draft. The server
        additionally refuses <code>status: active</code> with an empty
        keyword list.</li>
    <li><strong>Test run</strong> — dry-runs the current (even unsaved)
        graph through the exact engine semantics via
        <code>POST /app/workflows/simulate</code>: trigger matching,
        branch evaluation, <code>@{{var}}</code> interpolation and
        the 32-jump loop guard all behave identically, but nothing is
        persisted — webhooks are traced instead of sent, tags traced
        instead of applied. Question steps pause for a typed reply, and
        the executed path lights up green on the canvas.</li>
    <li><strong>Editor polish</strong> — undo/redo
        (<kbd>Ctrl/Cmd+Z</kbd>, <kbd>Shift</kbd> to redo), Delete-key
        removal (the trigger is protected), node duplication, drag a
        palette entry onto the canvas to place it exactly, "Tidy layout"
        auto-arrangement, and an unsaved-changes warning before
        navigating away.</li>
</ul>

<h2>Status</h2>

<ul>
    <li><strong>Draft</strong> — never matched. Use this while editing.</li>
    <li><strong>Active</strong> — matched on every visitor turn.</li>
    <li><strong>Disabled</strong> — kept for history but never matched. Useful for seasonal flows you'll re-enable later.</li>
</ul>

<h2>Phase 3 outlook</h2>

<p>
    The persistence shape stays forwards-compatible. Phase 3 will likely add:
</p>

<ul>
    <li>A/B testing — multiple workflow variants share a trigger; the runtime picks one per visitor and records which one converted.</li>
    <li>Per-flow analytics — drop-off rates per step, branch-arm distributions, time-to-handoff.</li>
    <li>Visitor-segment triggers (in addition to keywords) — page URL pattern, returning vs new, GeoIP.</li>
</ul>

<p>
    Older runtimes reading a Phase-3 definition will silently skip
    step types they don't recognise, so a future admin can save a
    definition that gracefully degrades on an older deployment.
</p>
