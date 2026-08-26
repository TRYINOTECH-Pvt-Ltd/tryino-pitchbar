<p>
    The Postgres schema, summarized. Every table that holds tenant data has
    a <code>workspace_id</code> column (directly or transitively via
    <code>agent_id</code>) and the corresponding model uses
    <code>BelongsToWorkspace</code> or <code>BelongsToAgent</code>.
</p>

<h2>Identity</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>users</code></td><td>Email + password (bcrypt). 2FA fields. <code>role</code>: <code>customer</code> or <code>super_admin</code> (the <code>PlatformRole</code> enum). <code>default_workspace_id</code> for switcher pinning.</td></tr>
        <tr><td><code>workspaces</code></td><td>Name + Stripe customer/subscription IDs + <code>plan_id</code>. <code>app_settings</code>-style overrides live in <code>settings</code> JSON column.</td></tr>
        <tr><td><code>workspace_users</code></td><td>Pivot. (user_id, workspace_id, role) where role is <code>owner</code>/<code>admin</code>/<code>member</code>.</td></tr>
        <tr><td><code>invitations</code></td><td>Email + role + token + 7-day expiry. Belongs to a workspace.</td></tr>
    </tbody>
</table>

<h2>Agents &amp; their config</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>agents</code></td><td>Persona, theme, system_prompt, guardrails, starter_prompts, allowed_origins, confidence_threshold, language_default, auto_index_visited_pages, is_published, published_version_id.</td></tr>
        <tr><td><code>agent_versions</code></td><td>Immutable snapshots created on every Publish. The runtime reads from these, not the agent row.</td></tr>
        <tr><td><code>behavior_rules</code></td><td>(agent_id, kind, conditions JSON, action JSON, enabled, priority).</td></tr>
        <tr><td><code>cta_rules</code></td><td>Specialized behavior rules for clickable calls-to-action.</td></tr>
        <tr><td><code>curated_answers</code></td><td>(agent_id, triggers[], answer_md, citation_url).</td></tr>
        <tr><td><code>experiments</code></td><td>A/B configurations on behavior rules.</td></tr>
    </tbody>
</table>

<h2>Knowledge</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>sources</code></td><td>(agent_id, type, status, config JSON, credentials_encrypted TEXT AES-256-GCM, last_synced_at, error). type: url / sitemap / feed / text / notion / google_doc / google_sheet / sql / file / woocommerce_products / auto. credentials_encrypted stores host/port/db/user/password for SQL sources via Laravel's encrypted:array cast — other types leave it null.</td></tr>
        <tr><td><code>documents</code></td><td>(source_id, url, title, content, lang). One per crawled page or pasted text.</td></tr>
        <tr><td><code>chunks</code></td><td>(document_id, idx, content, token_count). The unit that's embedded.</td></tr>
        <tr><td><code>integration_connections</code></td><td>(workspace_id, provider, encrypted_token, scope). Notion, Google Drive, Slack.</td></tr>
    </tbody>
</table>

<p>
    Chunk embeddings live <strong>only in the vector store</strong>
    (Vectorize / Qdrant) — the Postgres <code>chunks</code> table holds
    text + metadata, not vectors. Metadata is mirrored as labels on the
    vector point so retrieval can filter by <code>agent_id</code>.
</p>

<h2>Conversations &amp; messages</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>visitors</code></td><td>(agent_id, anonymous_id, ip_hash, ua, first_seen_at, last_seen_at, visit_count). The anon_id ties widget reloads to the same row.</td></tr>
        <tr><td><code>conversations</code></td><td>(agent_id, visitor_id, page_url, started_at, lang, claimed_by_user_id, claimed_at, cleared_at, is_playground).</td></tr>
        <tr><td><code>messages</code></td><td>(conversation_id, role, content, citations JSON, latency_ms, created_at). Roles: user / assistant / human-agent.</td></tr>
        <tr><td><code>leads</code></td><td>(agent_id, conversation_id, name, email, phone, fields JSON). Unique on (agent_id, email) for dedup.</td></tr>
        <tr><td><code>content_gaps</code></td><td>(agent_id, sample_question, occurrence_count, sample_conversations[]). Created by DetectGapJob when low-confidence is flagged.</td></tr>
    </tbody>
</table>

<h2>Billing</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>plans</code></td><td>(name, slug, monthly_conversations, price_cents, features JSON, is_active, stripe_product_id, stripe_price_id).</td></tr>
        <tr><td><code>plan_subscriptions</code></td><td>Cashier subscription mirror. (workspace_id, stripe_subscription_id, stripe_status, ends_at).</td></tr>
        <tr><td><code>usage_events</code></td><td>(workspace_id, kind, quantity, occurred_at). Aggregate by month for the quota gate.</td></tr>
        <tr><td><code>app_settings</code></td><td>Singleton row. Stripe / mail / branding overrides + LLM / vector / crawler config not in env. Encrypted casts on every secret.</td></tr>
    </tbody>
</table>

<h2>Operations</h2>

<table>
    <thead><tr><th>Table</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><code>audit_logs</code></td><td>(actor_id, workspace_id, action, target_type, target_id, metadata, created_at). Every privileged action.</td></tr>
        <tr><td><code>jobs</code> / <code>failed_jobs</code></td><td>Standard Laravel queue tables. <code>failed_jobs</code> drives <code>/admin/jobs/failed</code>.</td></tr>
        <tr><td><code>notifications</code></td><td>Standard Laravel notifications.</td></tr>
    </tbody>
</table>

<h2>Indexes that matter</h2>

<ul>
    <li><code>conversations(agent_id, visitor_id, started_at desc)</code> — visitor resume lookup runs every init.</li>
    <li><code>messages(conversation_id, created_at)</code> — chat history hydration.</li>
    <li><code>chunks(document_id)</code> — fast deletes on source removal.</li>
    <li><code>usage_events(workspace_id, occurred_at)</code> — quota gate.</li>
    <li><code>leads(agent_id, email)</code> — dedup constraint, unique.</li>
    <li><code>workspace_users(user_id, workspace_id)</code> — unique pivot.</li>
</ul>

<h2>Encryption at rest</h2>

<p>
    Sensitive columns use Laravel's <code>encrypted</code> cast: integration
    OAuth tokens, Stripe secrets in <code>app_settings</code>, mail
    passwords, custom LLM API keys. The encryption key is the standard
    <code>APP_KEY</code> — back it up the same way you back up the database.
</p>

<h2>Soft deletes</h2>

<p>
    Most tables hard-delete on cascade. The exceptions:
</p>

<ul>
    <li><code>plans</code> — never destructively deleted (FK from workspaces, FK from invoices). Soft via <code>is_active</code>.</li>
    <li><code>invitations</code> — cleared by a daily cleanup job after expiry.</li>
</ul>
