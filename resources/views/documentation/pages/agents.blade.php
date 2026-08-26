<p>
    An agent is the embeddable unit — persona, prompt, knowledge, theme,
    rules — that runs on a customer's site. This page covers creating,
    configuring, publishing, and rolling back agents.
</p>

<h2>Create an agent</h2>

<p>
    From the customer app: <code>/app/agents</code> → <strong>New agent</strong>. You'll be
    asked for a name and default language. Everything else has sensible
    defaults you can refine later.
</p>

<p>
    On signup we also auto-create a starter agent named after your domain.
    You can rename, replicate, or delete it freely.
</p>

<h2>The agent record</h2>

<p>
    Every agent has these editable fields:
</p>

<table>
    <thead>
        <tr><th>Field</th><th>What it does</th></tr>
    </thead>
    <tbody>
        <tr><td><code>name</code></td><td>Display name in the dashboard. Not shown to visitors.</td></tr>
        <tr><td><code>language_default</code></td><td>BCP-47 / ISO 639 code that pins the agent's reply language. Picks any locale auto-discovered from <code>lang/*.json</code> (132 ship out of the box; en/es/fr/tr are fully translated, others have UI chrome translated). Used when the visitor's <code>Accept-Language</code> doesn't match — the agent can also override per-conversation. Empty = follow the visitor's browser locale.</td></tr>
        <tr><td><code>persona</code></td><td>JSON: <code>{ name, tone }</code>. Tone goes into the system prompt verbatim.</td></tr>
        <tr><td><code>theme</code></td><td>Widget colors, radius, position, launcher label.</td></tr>
        <tr><td><code>system_prompt</code></td><td>Optional override. Appended to the built-in prompt — doesn't replace the safety / RAG instructions.</td></tr>
        <tr><td><code>guardrails</code></td><td>JSON: <code>{ avoid: [topics], max_chars }</code>.</td></tr>
        <tr><td><code>starter_prompts</code></td><td>Up to 6 chips shown above the input on first open. 80 chars each.</td></tr>
        <tr><td><code>confidence_threshold</code></td><td>0–1. Below this score (after rerank) the agent says "I don't know" instead of guessing.</td></tr>
        <tr><td><code>allowed_origins</code></td><td>Strict list of <code>scheme://host</code> origins where the widget may load.</td></tr>
        <tr><td><code>restricted_paths</code></td><td>Per-origin path blocklist — paths the widget refuses to render on even when the origin is in <code>allowed_origins</code>. Set to suppress the bar on checkout, account, or admin paths where a sales widget would feel wrong. See <a href="/documentation/allowed-origins">Allowed origins</a>.</td></tr>
        <tr><td><code>auto_index_visited_pages</code></td><td>If on, pages visitors land on get queued for crawl + index.</td></tr>
        <tr><td><code>lead_prompt_strategy</code></td><td>When the bot offers to capture a lead. Today: <code>after_n_turns</code> (default), <code>on_intent</code>, <code>off</code>. Lets you tune capture aggressiveness per agent.</td></tr>
        <tr><td><code>wp_integration</code> (JSON, encrypted)</td><td>WordPress / WooCommerce companion-plugin context. Stores the shopper-signing secret and any per-store config the LLM tools (e.g. <code>lookup_order</code>) need. Populated automatically when the WordPress plugin connects via API token; not edited by hand.</td></tr>
    </tbody>
</table>

<h2>Confidence threshold</h2>

<p>
    The retriever scores every chunk against the visitor's question. Anything
    below <code>confidence_threshold</code> is dropped. If fewer than two
    chunks survive, the answer is flagged <code>low_confidence</code> and the
    agent answers honestly that it doesn't know — and the system queues a
    "knowledge gap" entry you can review.
</p>

<p>
    Defaults are tuned per provider:
</p>

<ul>
    <li><strong>Cloudflare bge-base-en-v1.5</strong> embeddings — default <code>0.5</code>. Cosine scores run lower than OpenAI's, so the bar is lower.</li>
    <li><strong>OpenAI text-embedding-3-small</strong> — default <code>0.78</code>. Set this on signup if you switch providers.</li>
</ul>

<h2>Draft → Published</h2>

<p>
    Editing an agent never affects live visitors. The widget runtime reads
    from a snapshot called <code>agent_version</code>. Clicking
    <strong>Publish</strong> writes the current state to a new version and
    points the agent's <code>published_version_id</code> at it.
</p>

<p>
    Versions are immutable. To revert, open the agent's history and click
    <strong>Roll back</strong> on a previous version — that just updates the
    pointer. Nothing is deleted.
</p>

<h2>Embed the agent</h2>

<p>
    The agent's settings page shows a one-line install snippet with your
    <code>data-agent-id</code> baked in. The widget URL includes a
    cache-busting hash that mutates whenever the bundle is rebuilt, so
    customers don't get stuck on stale versions. See
    <a href="/documentation/embed">Install snippet</a> for details.
</p>

<h2>Delete an agent</h2>

<p>
    Two ways to remove an agent:
</p>

<ul>
    <li>From the Agents <strong>index page</strong> (<code>/app/agents</code>): hover any row, click the trash icon next to the gear in the Settings column. Confirm in the prompt and the agent is gone.</li>
    <li>From the agent <strong>detail page</strong> (<code>/app/agents/{id}</code>): click the destructive <em>Delete</em> button in the danger zone, confirm in the dialog.</li>
</ul>

<p>
    Deletion is a <strong>hard delete</strong>. Buyer report 2026-05-19:
    soft-delete left phantom playground conversations + child DB rows
    visible after the customer "deleted" their agent, so the destroy
    path now calls <code>forceDelete()</code>. Every child row cascades
    via FK at the database layer: <code>conversations</code>,
    <code>messages</code>, <code>leads</code>, <code>sources</code>,
    <code>documents</code>, <code>chunks</code>, <code>experiments</code>,
    <code>visitor_page_views</code>, etc. The widget embed snippet for
    the agent stops resolving immediately.
</p>

<p>
    The vector store (Cloudflare Vectorize / Qdrant) is not on the same
    DB connection, so DB cascade can't reach it. Destroy queues
    <code>PurgeAgentVectorsJob</code> just before
    <code>forceDelete()</code>: the job calls
    <code>QdrantClient::deleteByFilter</code> with
    <code>['agent_id' =&gt; $id]</code> against the
    <code>services.vector_collection</code> index. It's idempotent and
    swallows transport errors so a temporary Vectorize outage doesn't
    block the user-facing delete. The <code>php artisan vectors:audit</code>
    reconciliation pass catches any leftovers from a failed purge.
</p>

<p>
    Because the delete is permanent, there's no
    <code>withTrashed()</code> restore path. The platform-admin
    destroy at <code>/admin/agents/{id}</code> uses the same semantics
    (audit 2026-05-30) — it previously left soft-deleted rows that the
    customer couldn't see, defeating the orphan-cleanup intent the
    super_admin action was added for.
</p>

<p>
    Authorization: requires <strong>owner</strong>, <strong>admin</strong>,
    or <strong>editor</strong> role on the workspace. Viewers and members
    of other workspaces hit a 403.
</p>

<h2>Bulk delete agents</h2>

<p>
    Select multiple rows on the Agents index page (<code>/app/agents</code>)
    via the checkbox column. The header checkbox toggles all rows on
    the current page; shift-click a row checkbox to range-select between
    the last clicked row and the current one. Selection persists across
    pages until you click <strong>Clear</strong>.
</p>

<p>
    A sticky bar at the bottom of the page surfaces the count and a
    <strong>Delete</strong> action. Confirm in the prompt — every
    selected agent is hard-deleted (same semantics as single-agent
    destroy: DB cascade reaches child rows;
    <code>PurgeAgentVectorsJob</code> queued per agent to clean
    Vectorize). Cross-workspace ids are silently dropped from the
    result (the per-row policy gate rejects them without leaking
    existence).
</p>

<p>
    The same <code>useBulkSelection</code> hook + <code>BulkActionsBar</code>
    component now power bulk delete on every index page across the app:
</p>

<ul>
    <li><code>/app/agents</code> — bulk delete agents (this page).</li>
    <li><code>/app/workflows</code> — bulk delete workflows.</li>
    <li><code>/app/conversations</code> — bulk delete conversations.</li>
    <li><code>/app/inbox</code> — bulk delete captured leads.</li>
    <li><code>/admin/agents</code> — super_admin bulk delete agents across workspaces.</li>
    <li><code>/admin/leads</code> — super_admin bulk delete leads.</li>
    <li><code>/admin/conversations</code> — super_admin bulk delete conversations.</li>
    <li><code>/admin/workspaces</code> — super_admin bulk soft-delete workspaces (cannot delete the workspace you're currently signed into).</li>
    <li><code>/admin/users</code> — super_admin bulk soft-delete users (skips self, last super_admin, and workspace owners).</li>
</ul>

<p>
    Selection model is identical on every page: header tri-state checkbox,
    shift-click range select on row checkboxes, persistent selection across
    pagination, sticky bar with count + Delete + Clear. Each bulk endpoint
    accepts <code>{ ids: string[] }</code> (max 100 per request) and runs
    every id through its resource's policy gate.
</p>

<h2>Multiple agents per workspace</h2>

<p>
    You can run as many agents as you want — one for marketing, one for the
    help center, one for the in-app upsell flow, etc. Each has its own
    knowledge base, persona, and embed snippet. Conversations and leads stay
    scoped to the agent that handled them.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <strong>Quota note:</strong> the monthly conversation quota is per
        workspace, not per agent. Adding agents doesn't multiply your limit —
        upgrade your plan if you need more headroom.
    </div>
</div>
