<p>
    A handful of ideas you'll see on every page. If you only read one
    documentation page, make it this one.
</p>

<h2>Workspace</h2>

<p>
    Every piece of data in Pitchbar belongs to exactly one workspace. Users
    can be members of multiple workspaces and switch between them; agents,
    conversations, leads, sources, and analytics never cross the boundary.
</p>

<p>
    Multi-tenancy is enforced by the <code>BelongsToWorkspace</code> Eloquent
    trait, which adds a global scope filtering every query by the current
    workspace. There's a regression test (<code>MultiTenancyTest</code>) that
    fails the build if a tenant-scoped model is queried without it.
</p>

<h2>Agent</h2>

<p>
    An agent is the unit you embed. It owns a persona, system prompt, theme,
    starter prompts, allowed origins, behavior rules, and — importantly — its
    own knowledge sources. One workspace can have many agents (e.g. one for
    your marketing site, one for your help center).
</p>

<p>
    Agents have a <strong>draft</strong> and a <strong>published</strong> state.
    The widget runtime always reads the published version, so you can edit
    freely without affecting live visitors. Each publish creates an immutable
    <code>agent_version</code> row you can roll back to.
</p>

<h2>Knowledge source</h2>

<p>
    A source is anything you want the agent to learn from: a URL, a sitemap,
    a feed, pasted text, a Notion page, a Google Doc, or — when the
    auto-index toggle is on — pages visitors land on. Each source produces
    one or more <strong>documents</strong>; documents get chunked into ~500-token
    pieces, embedded, and upserted into the vector store.
</p>

<p>
    See <a href="/documentation/knowledge">Knowledge sources</a> for the
    ingestion flow and <a href="/documentation/rag-pipeline">RAG pipeline</a>
    for how retrieval works.
</p>

<h2>Conversation</h2>

<p>
    A conversation is a thread between one visitor and one agent. It survives
    page reloads — when the widget re-inits within 24 hours, we resume the
    most recent conversation instead of starting a new one. Messages have
    roles: <code>user</code>, <code>assistant</code>, and <code>human-agent</code>
    (when an operator takes over from the inbox).
</p>

<h2>Lead</h2>

<p>
    A lead is a visitor whose contact info you've captured (name, email,
    phone, plus any custom fields you defined). The widget asks for these
    inline when the conversation reaches a threshold of intent — see
    <a href="/documentation/widget-features">Voice, leads &amp; persistence</a>.
    Leads dedupe on email per agent so the same person filling out the
    form twice doesn't create two rows.
</p>

<h2>Plan</h2>

<p>
    Plans are platform-admin-managed subscription tiers (Free, Pro, etc.).
    Each plan defines a <strong>monthly conversation quota</strong> and a
    <strong>features blob</strong> — currently <code>features.remove_branding</code>
    is the only flag, but it's an open shape. Plans automatically sync to
    Stripe as Products + Prices, so admins never touch the Stripe dashboard.
</p>

<h2>Hot path</h2>

<p>
    "Hot path" means the visitor-message → first-token path. It has a hard
    1-second p95 latency target — no DB writes, no synchronous webhooks, no
    retries. The pipeline streams tokens out, then dispatches persistence
    and analytics work asynchronously after the stream completes. See
    <a href="/documentation/hot-path">Hot path &amp; latency</a>.
</p>

<h2>Origin allow-list</h2>

<p>
    The widget script is public — anyone who finds your <code>data-agent-id</code>
    can paste it on their own site. The <code>/v1/widget/init</code> endpoint
    enforces a strict origin allow-list per agent: empty list denies
    everywhere, otherwise exact <code>scheme://host</code> matching with no
    subdomain inference. <a href="/documentation/allowed-origins">Allowed origins</a>
    has the rules.
</p>

<h2>Super admin vs. workspace member</h2>

<p>
    A workspace member sees the customer surface (<code>/dashboard</code>,
    <code>/app/agents</code>, <code>/app/inbox</code>, <code>/app/billing</code>). A super-admin
    additionally sees the platform console at <code>/admin</code> for managing
    plans, watching usage, retrying failed jobs, and impersonating customers
    for support. The flag is <code>users.role = SuperAdmin</code> and is gated
    by the <code>EnsureSuperAdmin</code> middleware (which returns 404, not 403,
    so the panel doesn't reveal its existence).
</p>
