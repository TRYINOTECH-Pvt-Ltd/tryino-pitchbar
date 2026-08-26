<p>
    Plans are the only piece of customer-facing data that admins create
    directly. The Plan CRUD page (<code>/admin/plans</code>) is paired with
    Stripe so you never touch the Stripe dashboard to provision Products
    and Prices — every save here syncs to Stripe automatically.
</p>

<h2>The plans table</h2>

<p>
    <code>/admin/plans</code> lists every plan with its core attributes,
    the workspace count using it, and a sync status pill (green = in sync
    with Stripe, amber = pending, gray = local-only / free).
</p>

<table>
    <thead><tr><th>Column</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td>Name</td><td>Display name. Editable.</td></tr>
        <tr><td>Slug</td><td>Stable identifier. <strong>Locked after creation</strong> — workspaces.plan_id resolves by slug indirectly through the Plan table, and changing it would break invoices.</td></tr>
        <tr><td>Monthly conversations</td><td>Quota.</td></tr>
        <tr><td>Price</td><td>Monthly price. Changing it archives the old Stripe Price + creates a new one.</td></tr>
        <tr><td>Workspaces</td><td>How many workspaces are on this plan today.</td></tr>
        <tr><td>Stripe IDs</td><td>Product + Price IDs after sync. Free / custom plans show "—".</td></tr>
        <tr><td>Active</td><td>Toggle. Inactive plans aren't selectable on the customer side.</td></tr>
    </tbody>
</table>

<h2>Creating a plan</h2>

<p>
    <strong>New plan</strong> opens the form. Fields:
</p>

<ul>
    <li><strong>Name</strong> — required.</li>
    <li><strong>Monthly conversations</strong> — required. <code>0</code> = unlimited.</li>
    <li><strong>Monthly messages</strong> — optional. Caps every visitor message across the workspace for the calendar month. Leave blank for no extra cap.</li>
    <li><strong>Max tokens per response</strong> — optional. Hard ceiling on LLM reply length. Min 100, max 8000.</li>
    <li><strong>Price (cents)</strong> — required. <code>0</code> = free / custom (skips Stripe).</li>
    <li><strong>Features</strong> — toggles: <code>remove_branding</code> (and future flags).</li>
    <li><strong>Active</strong> — defaults to true.</li>
</ul>

<h2>Resource limits (per-workspace caps)</h2>

<p>
    The <strong>Resource limits</strong> card lets admins differentiate plan
    tiers beyond AI rate quotas. Every field accepts a positive integer, the
    literal <code>0</code>, or blank:
</p>

<ul>
    <li><strong>Blank</strong> = unlimited. Every pre-1.3 plan was migrated to NULL on all six columns, so existing customers are never retroactively capped.</li>
    <li><strong>0</strong> = hard block. Useful for the Free tier ("no integrations on this plan").</li>
    <li><strong>Positive integer</strong> = absolute cap. Counting honours soft-deletes (a trashed agent does not count) and pending invitations DO count toward the member cap (otherwise a workspace could queue 100 invites and accept them all later).</li>
</ul>

<table>
    <thead><tr><th>Field</th><th>What it caps</th><th>How it's counted</th></tr></thead>
    <tbody>
        <tr><td><code>agents_limit</code></td><td>Agents per workspace</td><td><code>Agent::where('workspace_id', X)</code> — every non-trashed agent.</td></tr>
        <tr><td><code>sources_limit</code></td><td>Knowledge sources across all agents in the workspace</td><td>Sum of <code>Source</code> rows whose agent is owned by the workspace. The <code>/app/agents</code> Knowledge column renders this as <code>used/limit</code> (or just <code>used</code> when the plan is unlimited) — see [[agent-index-knowledge-column]].</td></tr>
        <tr><td><code>workflows_limit</code></td><td>Workflows per workspace</td><td><code>Workflow::where('workspace_id', X)</code></td></tr>
        <tr><td><code>integrations_limit</code></td><td>Slack/etc connections + outbound webhook subscriptions</td><td><code>IntegrationConnection + WebhookSubscription</code> rows, summed.</td></tr>
        <tr><td><code>members_limit</code></td><td>Seats per workspace</td><td>Accepted <code>workspace_users</code> rows + non-expired pending <code>invitations</code>.</td></tr>
        <tr><td><code>api_access</code></td><td>Whether workspaces on this plan can mint API tokens</td><td>Checkbox. Defaults to ON for back-compat.</td></tr>
    </tbody>
</table>

<p>
    When a workspace hits a cap, the affected "Create" endpoint redirects
    back with a flash <code>error</code> message:
    <em>"You've reached your plan's limit of N agents. Upgrade to add more."</em>
    Frontends render the flash banner without needing per-resource code.
</p>

<p>
    The enforcement lives in
    <code>App\Services\Billing\PlanLimits</code>. Tests under
    <code>tests/Feature/PlanLimitsTest.php</code> cover every controller +
    every "NULL = unlimited" back-compat path.
</p>

<p>
    On save, the server creates the local row, then triggers
    <code>StripeProductSync::syncPlan()</code>. If the price is &gt; 0, a
    Stripe Product + Price are created and their IDs saved on the plan row.
    If Stripe is unreachable or misconfigured, the local row is kept and a
    flash error explains the failure — you can retry the sync without
    re-saving the form.
</p>

<h2>The Sync button</h2>

<p>
    Each row has a <strong>Sync</strong> action that fires
    <code>StripeProductSync::syncPlan()</code> directly. Returns JSON with
    the result so the UI can show "Synced" / error inline without a page
    reload. Useful when:
</p>

<ul>
    <li>You changed the Stripe key and want to re-bind everything.</li>
    <li>A previous sync failed and you've fixed the underlying issue.</li>
    <li>You want to verify a plan's Stripe state without touching the form.</li>
</ul>

<h2>Editing</h2>

<p>
    Edits behave intuitively except for two subtleties:
</p>

<ul>
    <li><strong>Price changes rotate the Stripe Price.</strong> Stripe Prices are immutable, so we archive the old and create a new one. <em>Existing subscriptions stay on the old Price</em> (grandfathered); only new subscriptions use the new one.</li>
    <li><strong>Slug is locked.</strong> The form input is disabled in edit mode.</li>
</ul>

<h2>Deleting</h2>

<p>
    Plans are <strong>never destructively deleted</strong>. The
    <code>destroy</code> action soft-deletes (<code>is_active = false</code>)
    and archives the Stripe Product. Reasons:
</p>

<ul>
    <li><code>workspaces.plan_id</code> is a real foreign key — deleting would orphan or cascade.</li>
    <li>Historical invoices reference the plan; we need to be able to look it up forever.</li>
    <li>Subscriptions in flight need a stable plan to attach to.</li>
</ul>

<p>
    Reactivating a soft-deleted plan: edit it and toggle Active back on.
    The Stripe Product is unarchived and the plan is selectable again.
</p>

<h2>Free / custom plans</h2>

<p>
    Plans with <code>price_cents = 0</code> never sync to Stripe. They live
    only in Pitchbar — useful for the default Free plan and for hand-rolled
    enterprise deals where you want the quota and feature flags but invoice
    out-of-band.
</p>

<h2>Free trial plans</h2>

<p>
    Toggle <strong>Free trial plan</strong> on a plan to turn it into a
    no-credit-card, time-limited trial, and set a <strong>Trial length</strong>
    in days (commonly 7, 14, or 30; blank defaults to 14). When a new user
    signs up on this plan, their workspace starts a trial that runs for that
    many days — no payment method is collected up front.
</p>

<p>
    Pair it with <strong>Default plan for new signups</strong> to put every
    new account on a trial automatically. The quotas and feature flags you
    set on the trial plan are exactly what the customer gets <em>during</em>
    the trial, so you control how much of the product the trial unlocks.
</p>

<p>
    When the trial period ends and the customer hasn't subscribed to a paid
    plan, the workspace is walled: every customer screen redirects to the
    billing page with an "upgrade to continue" prompt. Their agents, sources,
    and conversations are preserved — nothing is deleted — and access is
    restored the moment they pick a paid plan. The billing page, account
    settings, and sign-out stay reachable so the user is never trapped. A
    slim "X days left in your trial" banner appears across the app while the
    trial is active.
</p>

<p>
    Trial state is computed from the workspace's plan-subscription row
    (status <code>trialing</code> with a <code>current_period_end</code> in
    the future), so there is no scheduler to run — a trial lapses the instant
    its end date passes.
</p>

<h2>Currency</h2>

<p>
    Set globally via <code>CASHIER_CURRENCY</code> in the environment.
    Defaults to USD. Changing the currency mid-flight on a deployment with
    existing Prices is a manual migration — you'd archive every Stripe
    Price, change the env var, then sync each plan to mint new Prices in
    the new currency.
</p>
