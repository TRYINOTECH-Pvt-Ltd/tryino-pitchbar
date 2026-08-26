<p>
    The platform admin console is the operator-only surface at
    <code>/admin</code>. It's where you manage plans, watch usage across
    every workspace, retry failed jobs, and impersonate customers for
    support. Only users with <code>role = super_admin</code> can see it —
    everyone else gets a 404 (not 403) so the panel doesn't even reveal it
    exists.
</p>

<h2>Becoming a super admin</h2>

<p>
    The flag is the <code>users.role</code> column, cast to the
    <code>PlatformRole</code> enum which has two cases:
    <code>customer</code> (the default for every signup) and
    <code>super_admin</code>. There's no UI to grant it — it's set by hand
    via tinker or a one-shot migration on a new deployment:
</p>

<pre><code>php artisan tinker --execute 'User::where("email", "you@company.com")->update(["role" => "super_admin"]);'</code></pre>

<p>
    Demoting works the same way (<code>role = "customer"</code>). There's no
    notification — the next page load will show or hide the admin nav.
</p>

<h2>Layout</h2>

<p>
    The admin sidebar lists ten sections:
</p>

<ul>
    <li><strong>Dashboard</strong> — KPIs across all workspaces, system health summary.</li>
    <li><strong>Workspaces</strong> — every workspace, their plan, owner, member count, conversation usage.</li>
    <li><strong>Users</strong> — every user across the platform, their workspaces, last seen.</li>
    <li><strong>Agents</strong> — every agent on the platform, with workspace + publish state. Inline rename, publish toggle, and <em>Delete</em> — destroy bypasses <code>WorkspaceScope</code> so super_admin can clean up phantom agents whose <code>workspace_id</code> points at a workspace the original customer no longer has as their current context (those agents 404 from the customer-side editor).</li>
    <li><strong>Conversations</strong> — every conversation. Useful for support.</li>
    <li><strong>Leads</strong> — every lead.</li>
    <li><strong>Plans</strong> — Stripe-synced plan CRUD. See <a href="/documentation/admin-plans">Plans &amp; Stripe sync</a>.</li>
    <li><strong>Subscriptions</strong> — every active subscription. Reverse-lookup workspaces by Stripe subscription ID.</li>
    <li><strong>Usage</strong> — month-over-month conversation count by workspace.</li>
    <li><strong>Queue Failures</strong> — failed jobs with retry / forget controls.</li>
</ul>

<h2>Header actions</h2>

<p>
    The admin header (top bar of every admin page) has:
</p>

<ul>
    <li><strong>Site Health pill</strong> — green / amber / red. Hover for the breakdown — failed jobs, Stripe config, LLM provider, vector store, mail driver, Reverb keys, cache. See <a href="/documentation/admin-health">Site health &amp; failed jobs</a>.</li>
    <li><strong>Notifications dropdown</strong> — unread alerts from two streams:
        <ul>
            <li><strong>Health failures</strong> (critical / warning severity) — failed jobs, missing Stripe key, missing LLM provider, mail not configured, etc. These paint the bell red and increment the <code>site_health.issues_count</code>.</li>
            <li><strong>Business events</strong> (info severity, last 24 hours) — new users signed up, new workspaces created, new paid subscriptions, new leads captured platform-wide. Info severity only; the bell stays neutral but you see what just happened.</li>
        </ul>
        Cached 60 seconds. Both streams merge into one dropdown — health failures appear first, biz events below.
    </li>
    <li><strong>Global search</strong> — searches workspaces, users, agents, conversations, leads with one keystroke.</li>
</ul>

<h2>Daily admin digest</h2>

<p>
    Opt-in via <strong>Settings → System → Mail → Daily admin digest</strong>
    (checkbox). When enabled, every super_admin receives an email at
    09:00 UTC summarising the previous 24 hours:
</p>

<ul>
    <li>New users signed up</li>
    <li>New workspaces created</li>
    <li>New paid subscriptions</li>
    <li>New leads captured</li>
    <li>Conversations active right now</li>
</ul>

<p>
    The scheduled command is <code>php artisan admin:send-daily-digest</code>;
    it short-circuits with an "info" log line when the setting is off,
    so you can leave the schedule wired without spamming installs that
    opted out. <code>--force</code> bypasses the flag for one-off previews;
    <code>--dry-run</code> computes the digest and prints it without sending
    mail.
</p>

<h2>Impersonation</h2>

<p>
    From the Workspaces or Users page, click <strong>Impersonate</strong>.
    A few things happen:
</p>

<ol>
    <li>Your admin session is preserved.</li>
    <li>You become the target user inside their workspace.</li>
    <li>A persistent <code>You're impersonating X</code> banner appears at the top of every page until you stop.</li>
    <li>A row is logged in <code>audit_logs</code> so the action is traceable.</li>
</ol>

<p>
    Click <strong>Stop impersonating</strong> in the banner to drop back to
    your admin session. The banner is intentionally hard to miss — there's
    no shortcut to dismiss it without ending the impersonation.
</p>

<h2>Search</h2>

<p>
    Press <code>/</code> from anywhere in the admin panel. The global
    search dropdown opens with sections:
</p>

<ul>
    <li>Workspaces (by name).</li>
    <li>Users (by email).</li>
    <li>Agents (by name + workspace).</li>
    <li>Conversations (by message text).</li>
    <li>Leads (by name + email).</li>
</ul>

<p>
    Each result links straight to the resource. The customer surface has the
    same search but scoped to the current workspace.
</p>

<h2>Audit log</h2>

<p>
    Every admin action — plan create/update/delete, impersonation start/stop,
    workspace changes — writes to the <code>audit_logs</code> table for
    forensic traceability. There's no UI page for browsing audit logs in
    v1; query the table directly when you need to investigate.
</p>
