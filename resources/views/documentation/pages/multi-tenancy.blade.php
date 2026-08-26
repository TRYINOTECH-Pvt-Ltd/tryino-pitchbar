<p>
    Multi-tenancy is the most important invariant in the codebase. A bug
    that leaks data across workspace boundaries is a security incident. The
    enforcement is layered: traits, global scopes, policies, and a
    regression test that fails the build.
</p>

<h2>The trait pattern</h2>

<p>
    Two traits do the heavy lifting:
</p>

<ul>
    <li><code>App\Concerns\BelongsToWorkspace</code> — for models with a direct <code>workspace_id</code> column.</li>
    <li><code>App\Concerns\BelongsToAgent</code> — for models that belong to an agent (and transitively to that agent's workspace). The trait's global scope joins through <code>agents</code> to filter by <code>agents.workspace_id = current</code>.</li>
</ul>

<p>
    Both traits add a <strong>global scope</strong> that's applied to every
    Eloquent query against the model. They also auto-fill
    <code>workspace_id</code> on creating, so you can't accidentally insert
    a row into the wrong workspace.
</p>

<h2>CurrentWorkspace</h2>

<p>
    The current workspace is resolved per request by middleware:
</p>

<ul>
    <li><strong>Authenticated requests</strong> — read <code>users.default_workspace_id</code>, verify membership, set the singleton.</li>
    <li><strong>Widget requests</strong> — derive from the JWT's <code>agent_id</code> claim, look up the agent, set the singleton from <code>agents.workspace_id</code>.</li>
    <li><strong>System / queue jobs</strong> — explicitly set per job, never inherited.</li>
</ul>

<p>
    The resolver lives at <code>App\Support\CurrentWorkspace</code>. After
    the request, the middleware's <code>finally</code> block clears it so
    state can't leak between Octane requests.
</p>

<h2>Bypassing the scope (rare)</h2>

<p>
    Some queries genuinely need to look across workspaces — admin reports,
    impersonation, system jobs. The bypass is explicit:
</p>

<pre><code>// System-level operation: rolling up usage across all workspaces
Workspace::withoutWorkspaceScope()->each(...)</code></pre>

<p>
    The convention is <strong>every <code>withoutWorkspaceScope()</code>
    call must have a justifying comment immediately above it</strong>. Code
    review enforces this; the <code>/tenancy</code> audit slash command
    flags violations.
</p>

<h2>Switching workspaces</h2>

<p>
    A user with multiple memberships uses the workspace switcher in the
    sidebar. Switching POSTs to <code>/workspaces/{id}/select</code>, which
    updates <code>users.default_workspace_id</code> and redirects. The
    next page load resolves the new workspace.
</p>

<p>
    The switcher only renders when the user has 2+ memberships — single-
    workspace users see a quiet label instead of a menu, since "switch"
    among one option is just clutter.
</p>

<h2>Per-workspace data</h2>

<p>
    Tenant-scoped tables (every Eloquent model with the trait):
</p>

<ul>
    <li>Direct <code>workspace_id</code>: <code>agents</code>, <code>integration_connections</code>, <code>plan_subscriptions</code>, <code>usage_events</code>, <code>audit_logs</code>, <code>invitations</code>.</li>
    <li>Via agent: <code>agent_versions</code>, <code>behavior_rules</code>, <code>cta_rules</code>, <code>curated_answers</code>, <code>experiments</code>, <code>sources</code>, <code>documents</code>, <code>chunks</code>, <code>visitors</code>, <code>conversations</code>, <code>messages</code>, <code>leads</code>, <code>content_gaps</code>.</li>
</ul>

<p>
    Cross-workspace tables (no scope):
</p>

<ul>
    <li><code>users</code> — global. Membership in any workspace is in the pivot.</li>
    <li><code>workspaces</code> — global. The workspace itself isn't scoped to a workspace.</li>
    <li><code>plans</code> — global, platform-admin-managed.</li>
    <li><code>jobs</code> / <code>failed_jobs</code> / <code>notifications</code> — Laravel infrastructure.</li>
    <li><code>app_settings</code> — singleton, platform-wide.</li>
</ul>

<h2>Vector store isolation</h2>

<p>
    Vector store metadata mirrors the tenancy contract — every point has
    <code>agent_id</code> and <code>workspace_id</code> labels, and every
    query filters by <code>agent_id</code>. Cloudflare Vectorize and Qdrant
    both support this natively (Qdrant's payload-filter / Vectorize's
    metadata-filter).
</p>

<p>
    A bug that filtered by <code>agent_id</code> alone but not the agent's
    actual workspace would still be safe — agent IDs are ULIDs, globally
    unique. A bug that <em>didn't filter at all</em> would be a leak. The
    <code>Retriever</code> always filters explicitly.
</p>

<h2>Authorization (policies)</h2>

<p>
    Tenancy is "is this row in my workspace?". Authorization is "what can
    I do with rows in my workspace?". Policies live in
    <code>app/Policies/</code>:
</p>

<ul>
    <li><code>WorkspacePolicy</code> — view/update/delete the workspace itself, transfer ownership.</li>
    <li><code>AgentPolicy</code> — viewAny / view / create / update / delete / publish / rollback.</li>
    <li><code>SourcePolicy</code> — manage sources within an agent.</li>
    <li><code>LeadPolicy</code> — read / update / delete leads.</li>
    <li><code>IntegrationConnectionPolicy</code> — connect / disconnect.</li>
</ul>

<p>
    The check pattern is consistent: <code>$user-&gt;can('update', $agent)</code>
    or <code>abort_if(! $user-&gt;can(...))</code>. Policies use the
    <code>Tenancy</code> helper to resolve the user's role in the resource's
    workspace and then call capability methods on the
    <code>WorkspaceRole</code> enum.
</p>

<h2>Tests</h2>

<p>
    The regression test is <code>MultiTenancyTest</code> under
    <code>tests/Feature/</code>. It seeds two workspaces with overlapping
    data and asserts that queries from each can only see their own rows.
    It also walks every model in <code>app/Models/</code> to confirm any
    model with <code>workspace_id</code> uses the trait.
</p>

<p>
    Run:
</p>

<pre><code>php artisan test --filter=MultiTenancyTest</code></pre>

<p>
    Required to pass on every CI build. PRs that break it can't merge.
</p>
