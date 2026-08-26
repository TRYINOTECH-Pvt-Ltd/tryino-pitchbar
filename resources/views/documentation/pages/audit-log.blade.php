<p>
    Every workspace ships with an immutable audit trail at
    <code>/app/audit</code>. Admin and Owner roles can browse, filter,
    and inspect entries; Editor and Viewer are blocked.
</p>

<h2>What gets logged</h2>

<p>
    The application writes to <code>audit_logs</code> for any action
    that materially changes workspace state or surfaces compliance
    exposure. Examples include:
</p>

<ul>
    <li>Member invitations, role changes, removals.</li>
    <li>Integration connect / disconnect (Slack, Teams, webhooks).</li>
    <li>Integration webhook delivery failures (so broken Slack URLs
        are visible without tailing logs).</li>
    <li>GDPR data-subject-request exports
        (<code>dsr.exported</code>) and erasures
        (<code>dsr.erased</code>).</li>
    <li>Billing-plan changes initiated from the admin UI.</li>
</ul>

<h2>Action vocabulary</h2>

<p>
    The application writes the following audit actions today. Filter
    on the <strong>Action</strong> toolbar field to surface a specific
    slug (substring match — <code>agent</code> shows every
    agent.* row).
</p>

<ul>
    <li><code>agent.created</code> / <code>agent.updated</code> /
        <code>agent.deleted</code> / <code>agent.bulk_deleted</code>
        — workspace owner creates / edits / removes an AI agent.
        Bulk-delete fires one row per affected agent so cross-tenant
        scoping is visible.</li>
    <li><code>agent.published</code> / <code>agent.republished</code>
        / <code>agent.rolled_back</code> — the publish lifecycle.
        Republished fires when a draft change is re-published on top
        of an existing live version.</li>
    <li><code>source.created</code> / <code>source.deleted</code> —
        knowledge sources attached to an agent (URL crawls, files,
        Notion, Google Docs, etc.).</li>
    <li><code>workflow.created</code> / <code>workflow.updated</code>
        / <code>workflow.deleted</code> /
        <code>workflow.bulk_deleted</code> — scripted-reaction
        workflows.</li>
    <li><code>member.invited</code> / <code>member.added</code> /
        <code>member.removed</code> — invitation, direct-add (when the
        invitee already had a Pitchbar account), and seat removal.</li>
    <li><code>api_token.created</code> /
        <code>api_token.revoked</code> /
        <code>api_token.forgotten</code> /
        <code>api_token.purged</code> — workspace API token lifecycle.
        Forgotten = hard-deleted (single row). Purged = bulk
        hard-delete of every revoked row for the workspace.</li>
    <li><code>platform_settings.updated</code> — super-admin saved a
        section of <code>/settings/system</code>. The <code>after</code>
        column records the section name + a list of changed
        non-sensitive keys; secret values (API keys, webhook secrets,
        SMTP passwords) are stripped before write so the audit row
        cannot leak credentials.</li>
    <li><code>integration.webhook_secret_rotated</code> — admin
        rotated a webhook delivery secret.</li>
    <li><code>dsr.exported</code> / <code>dsr.erased</code> — GDPR
        data-subject-request fulfillment.</li>
    <li><code>impersonation.started</code> /
        <code>impersonation.stopped</code> — super-admin acted on
        behalf of a workspace member.</li>
</ul>

<p>
    When an action fires from a context with no authenticated user
    (queue worker, scheduled command, webhook callback), the row's
    <code>after.system_origin</code> field carries a marker:
    <code>console</code>, <code>webhook_or_queue</code>, or
    <code>background</code>. Use this to distinguish "system did this"
    from "we forgot to capture the actor".
</p>

<p>
    Each row carries: action slug, entity type / id, actor user (or
    <em>System</em> when triggered by a queue job), originating IP,
    user agent, and a <code>before</code> / <code>after</code> JSON
    snapshot.
</p>

<h2>Filtering</h2>

<p>
    The toolbar accepts:
</p>

<ul>
    <li><strong>Action</strong> — substring match (case-insensitive),
        e.g. <code>dsr</code> shows both exported and erased rows.</li>
    <li><strong>Entity type</strong> — dropdown populated with the
        distinct entity types currently in your workspace's log, so
        you only see options that actually have history.</li>
    <li><strong>Actor email</strong> — exact match on the user's
        email; pass an unknown email to surface only
        system-initiated rows.</li>
    <li><strong>From / To</strong> — bracket
        <code>created_at</code>. Either bound is optional.</li>
</ul>

<p>
    Filters compose; pagination respects the active filter set.
</p>

<h2>Retention</h2>

<p>
    Audit rows are pruned daily via the scheduled command
    <code>audit:prune --days=365</code>. Default retention is one
    year — long enough to investigate "what changed two quarters
    ago?" but bounded so the table doesn't grow without limit.
    Customers with stricter compliance requirements (SOC2, HIPAA,
    long-tail GDPR holds) should export their audit log via the
    artisan command on their own cadence before this prune fires.
    Override the window by passing a different <code>--days</code>
    value in your <code>routes/console.php</code>.
</p>

<pre><code># Dry-run: report what would be pruned, change nothing.
php artisan audit:prune --days=365 --dry-run

# Force a longer retention window.
php artisan audit:prune --days=730</code></pre>

<h2>Why no edit / delete</h2>

<p>
    Audit rows are append-only. There is no UI to edit a row, and
    the database has no <code>updated_at</code> column on
    <code>audit_logs</code>. This is intentional — a compliance log
    that can be rewritten is not a compliance log.
</p>

<h2>Performance</h2>

<p>
    The table is indexed on <code>(workspace_id, created_at)</code>
    and <code>(entity_type, entity_id)</code>. A workspace with
    tens of thousands of rows still serves the first page in under
    50ms. If you need to bulk-export, query the table directly via
    the <code>workspace:audit-export</code> Artisan command (planned)
    rather than scraping the UI.
</p>
