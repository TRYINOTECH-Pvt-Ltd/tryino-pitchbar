<p>
    The board at <code>/admin/board</code> is the platform team's own
    Kanban for tracking shipped work, current effort, and the dormant
    backlog. <strong>Internal-only</strong> — never visible to customers,
    never reachable from a customer admin (super_admin middleware
    returns 404 for any other role).
</p>

<p>
    There is intentionally <strong>no nav entry</strong> in the admin
    sidebar — the board is an internal-team tool, not something we
    want even our own super-admin sidebar advertising. Navigate
    directly via the URL (<code>/admin/board</code>) or this doc page.
</p>

<h2>Why it exists</h2>

<p>
    Backlog items pile up over months — buyer feedback threads,
    design notes, postponed features. Without a single durable home,
    they leak into Slack, scratch files, or memory. The board is the
    durable home. Future engineering sessions read it as their
    starting point.
</p>

<h2>Columns</h2>

<table>
    <thead><tr><th>Column</th><th>Use it for</th></tr></thead>
    <tbody>
        <tr><td><strong>Backlog</strong></td><td>Anything we plan to do but haven't started. Most cards live here.</td></tr>
        <tr><td><strong>In progress</strong></td><td>Cards someone is actively working on. Try to keep this small (1-3 items).</td></tr>
        <tr><td><strong>Review</strong></td><td>Done coding, waiting on cross-check, deployment, or buyer confirmation.</td></tr>
        <tr><td><strong>Done</strong></td><td>Shipped + verified. Stays for forensics; not auto-archived.</td></tr>
    </tbody>
</table>

<h2>Live updates</h2>

<p>
    The board polls itself every 8 seconds via Inertia's partial
    reload (<code>router.reload({ only: ['tasks'] })</code>) so two
    super-admins editing the same board see each other's moves
    without manually reloading. A small "Live · last synced Xs ago"
    pill in the header confirms the polling is alive.
</p>

<p>
    The hook (<code>resources/js/hooks/use-board-live-sync.ts</code>)
    is memory-leak-safe by construction:
</p>

<ul>
    <li>
        <strong>Single-flight guard</strong> — a slow connection
        cannot pile up requests; the next tick is skipped while one
        is still in flight.
    </li>
    <li>
        <strong>Tab-visibility pause</strong> — backgrounded tabs do
        not poll. On focus, the hook fires immediately + re-arms the
        interval, so a returning user sees fresh data without waiting
        a full cycle.
    </li>
    <li>
        <strong>Dialog pause</strong> — when the Create / Edit dialog
        is open the hook stops polling so the form data the user is
        filling in is never replaced under their cursor by an
        incoming refresh. The pill switches to "Paused" while a
        dialog is up.
    </li>
    <li>
        <strong>Cleanup on unmount</strong> — interval ID and
        visibilitychange listener live inside the same effect; both
        are removed in the effect's cleanup so they cannot outlive
        the page.
    </li>
</ul>

<p>
    If we ever wire an Echo client into the admin SPA (it's not there
    today), this hook can be replaced with a Reverb subscription
    on a private workspace channel — the rest of the page stays
    unchanged because the hook returns the same
    <code>{ lastSyncedAt, isSyncing }</code> shape regardless of
    transport.
</p>

<h2>Sort order per column</h2>

<p>
    Each column has its own ordering rule on the page render so the
    column you're scrolling shows the most useful card on top:
</p>

<table>
    <thead><tr><th>Column</th><th>Sort</th><th>Why</th></tr></thead>
    <tbody>
        <tr>
            <td><strong>Backlog</strong></td>
            <td><code>created_at</code> ASC (oldest first)</td>
            <td>FIFO queue feel — items waiting longest float to the top so we don't lose track of them.</td>
        </tr>
        <tr>
            <td><strong>In progress</strong></td>
            <td><code>position</code> ASC (manual)</td>
            <td>Admins drag-drop within Doing; respect the order they set.</td>
        </tr>
        <tr>
            <td><strong>Review</strong></td>
            <td><code>position</code> ASC (manual)</td>
            <td>Same as Doing — manual order wins.</td>
        </tr>
        <tr>
            <td><strong>Done</strong></td>
            <td><code>updated_at</code> DESC (latest-completed first)</td>
            <td>Most recently shipped cards are the most useful at a glance — recent commits, recent wins.</td>
        </tr>
    </tbody>
</table>

<h2>Persistence</h2>

<p>
    Cards live as a single JSON file at
    <code>storage/app/private/kanban-tasks.json</code> (Laravel's local
    disk) — <strong>not</strong> in the database. This is deliberate: it
    protects the board across <code>php artisan migrate:fresh</code>
    during development, which is the most common way teams accidentally
    blow away in-progress tracking. The file survives schema resets,
    factory tear-downs, and test transactions.
</p>

<p>
    No new table, no migration when you want a new label / status /
    property — just edit the JSON shape and bump the controller
    validation. Cards are sorted server-side by (status order, position)
    so the page can render top-to-bottom in each column without
    touching the wire.
</p>

<p>
    To back up or share the board, copy the JSON file. To wipe it,
    delete the file (the next read returns an empty board, lazily
    re-created on the next write). To restore a previous version,
    drop the file back in.
</p>

<h2>Seeding</h2>

<p>
    The artisan command <code>board:seed</code> idempotently fills the
    board with the post-Phase-1 backlog and the items shipped in this
    session's Done column. Re-running it adds nothing — matching is by
    title — so it's safe to bake into deployment steps.
</p>

<pre><code>php artisan board:seed</code></pre>

<h2>Ticket numbers</h2>

<p>
    Every card carries a sequential <strong>#N</strong> assigned at
    creation time (1, 2, 3, ...). Numbers never reuse — even after a
    card is archived, the next new card picks up at
    <code>max(historical) + 1</code>, not the freed slot. This makes
    references stable: "look at #14" works the same way three months
    later as it does today.
</p>

<p>
    The number renders as a small monospace badge next to each card
    title on the board UI. The CLI commands accept the number as a
    short alias for the card — <code>board:start 14</code>,
    <code>board:done "#14"</code>, etc. Exact-title lookup still works
    for backwards compatibility, but numbers are the recommended
    reference style going forward.
</p>

<p>
    Existing boards from before the numbering change get backfilled
    automatically on the first read: legacy cards are assigned
    numbers in <code>created_at</code> order. The persisted file
    converges to the canonical shape on first access; nothing manual
    is needed.
</p>

<h2>CLI workflow</h2>

<p>
    Three artisan commands cover the lifecycle of a card from CLI —
    matching the project CLAUDE.md "Hard rules" #7 (capture) and #8
    (close). Use these from inside Claude Code sessions or any
    terminal; opening the admin UI is optional.
</p>

<h3>Capture a new card</h3>

<p>
    Run before touching source. Every task — postponed or about to be
    done right now — gets a card with a written plan first.
</p>

<pre><code>php artisan board:add "Add Paddle gateway" --body="$(cat &lt;&lt;'EOT'
## Why
EU/UK buyers prefer Paddle for VAT/MoR handling — currently they
have to set up Stripe Tax separately, which several CodeCanyon
buyers have flagged.

## Plan
1. New PaddleProductSync mirroring StripeProductSync.
2. Add paddle_product_id + paddle_plan_id columns to plans.
3. Wire CheckoutController to dispatch on gateway === 'paddle'.
4. Add admin gateway toggle in Settings → System.

## Files
- app/Services/Billing/PaddleProductSync.php (new)
- database/migrations/...
- app/Http/Controllers/Billing/CheckoutController.php

## Constraints
- Idempotent sync on product/plan IDs.
- Same gateway-registry pattern as Stripe/PayPal/Razorpay.
EOT
)" --label=billing --label=eu</code></pre>

<p>
    Idempotent on title — re-running the same command does nothing.
    Status defaults to <code>backlog</code>; labels are repeatable
    flags. The body's <code>## Plan</code> is the contract: the user
    reads it before any code is written and redirects if needed.
    Cards without a plan body are not real cards — they're chat
    notes pretending to be tickets.
</p>

<h3>Move a card into Doing</h3>

<pre><code># By number (recommended):
php artisan board:start 14

# Or by exact title:
php artisan board:start "Add Paddle gateway"</code></pre>

<p>
    Run before code changes. The board's "In progress" column should
    accurately reflect what's actively being worked on right now.
    Idempotent — already-doing cards report a no-op.
</p>

<h3>Close a card with a UI test plan</h3>

<pre><code>php artisan board:done 14 \
  --test="$(cat &lt;&lt;'EOT'
1. Sign in as super_admin
2. Open /admin/plans
3. Click "Sync to Paddle" on the Pro row
4. Confirm the modal shows "Synced — Paddle plan up to date"
5. Reload; confirm the gateway badge stays green
EOT
)" \
  --release=v1.1.0</code></pre>

<p>
    <code>--test</code> is <strong>mandatory</strong> — the command
    refuses to close a card without it. The plan is appended to the
    card body under a <code>## How to test from UI</code> heading;
    the original context written when the card was added stays
    intact. Idempotent: re-running with a new plan replaces the prior
    section, never duplicates the heading.
</p>

<p>
    Test steps must be UI-driven, not "assert X in code". Start from
    "sign in as &lt;role&gt;" so a reviewer (or future-you) can
    follow them cold without context.
</p>

<h2>Release tags</h2>

<p>
    <code>--release=&lt;tag&gt;</code> stamps the application version
    a card shipped in onto the card row (renders as a small green
    pill next to the title on the board). Pass it whenever you close
    a card so the team can glance at Done and see "what landed in
    v1.1.0?" without running git log.
</p>

<p>
    If you forgot to pass <code>--release</code> at close time, the
    bulk-stamp command labels every Done card that doesn't already
    carry a version:
</p>

<pre><code># Stamp every Done card with v1.1.0 (idempotent — skips cards that
# already have a tag):
php artisan board:stamp-version v1.1.0 --status=done

# Stamp specific cards by number / title:
php artisan board:stamp-version v1.1.0 --ref=23 --ref=34

# Force-overwrite an existing tag (e.g. if a card was wrongly stamped):
php artisan board:stamp-version v1.1.0 --status=done --force</code></pre>

<p>
    Versions are free-form strings; we use semver-ish tags
    (<code>v1.1.0</code>, <code>v1.2.0-rc1</code>) but the schema
    doesn't enforce a format. The pill renders the literal value.
</p>

<h2>What it isn't</h2>

<ul>
    <li>Not a customer feature. Customers shouldn't see it.</li>
    <li>Not drag-and-drop in v1 — column moves go through the dropdown on the card-detail dialog. Drag-drop is a UI cost we'll add when there's a real need.</li>
    <li>Not multi-board. One platform board, period. If someone needs a per-workspace TODO list later, that goes in a separate table.</li>
</ul>
