<p>
    Billing is Stripe-backed via Laravel Cashier. Each workspace lives on
    one plan at a time. This page covers what plans exist, how usage is
    metered, and what happens when you hit a limit.
</p>

<h2>Plans</h2>

<p>
    Plans are managed by platform admins (see
    <a href="/documentation/admin-plans">Plans &amp; Stripe sync</a>) and
    visible to customers at <code>/app/billing</code>. A plan has:
</p>

<table>
    <thead><tr><th>Field</th><th>What it does</th></tr></thead>
    <tbody>
        <tr><td><code>name</code></td><td>Display name (Free, Pro, Enterprise).</td></tr>
        <tr><td><code>slug</code></td><td>Stable identifier — never changes after creation, even if the name does.</td></tr>
        <tr><td><code>monthly_conversations</code></td><td>Quota of new conversations per calendar month. <code>0</code> means unlimited.</td></tr>
        <tr><td><code>monthly_messages</code></td><td>Optional. Per-message quota counted across every visitor turn this calendar month. Leave blank for no extra cap; the conversation count alone gates the workspace.</td></tr>
        <tr><td><code>max_tokens_per_response</code></td><td>Optional. Caps the LLM's <code>max_tokens</code> for every reply on this plan. Leave blank to use the default of 800. Useful for keeping the free tier short and the paid tiers verbose.</td></tr>
        <tr><td><code>price_cents</code></td><td>Plan price in cents (charged once per <code>interval</code>). <code>0</code> means free / custom (skips gateway sync).</td></tr>
        <tr><td><code>interval</code></td><td>Billing cadence — <code>month</code> or <code>year</code>. Defaults to <code>month</code>. Stripe Prices, PayPal billing_cycles, and Razorpay periods all derive from this column.</td></tr>
        <tr><td><code>features.remove_branding</code></td><td>Hides the "Powered by" footer in the widget.</td></tr>
    </tbody>
</table>

<h2>Monthly + Yearly variants</h2>

<p>
    To offer an annual discount, create the same plan twice — one with
    <code>interval=month</code> and one with <code>interval=year</code> — and
    set the yearly price below 12× the monthly. The marketing pricing
    page detects both variants and renders a Monthly/Yearly toggle.
    Each gateway syncs to its own native cadence:
</p>

<ul>
    <li><strong>Stripe</strong> — <code>recurring.interval = month|year</code> on the Price.</li>
    <li><strong>PayPal</strong> — <code>billing_cycles[].frequency.interval_unit = MONTH|YEAR</code>.</li>
    <li><strong>Razorpay</strong> — <code>period = monthly|yearly</code> on the Plan.</li>
</ul>

<p>
    Workspaces still subscribe to one plan row at a time (one
    <code>workspaces.plan_id</code>), and switching from monthly to
    yearly is a normal plan change — the gateway either prorates
    (Stripe / PayPal) or starts the new cycle at the next billing
    boundary depending on workspace setting.
</p>

<h2>AI rate limits</h2>

<p>
    The two optional cap dials (<code>monthly_messages</code> and
    <code>max_tokens_per_response</code>) live under "AI rate limits"
    in the plan form. They're enforced at runtime:
</p>

<ul>
    <li>
        Every visitor message records a <code>message</code> row in
        <code>usage_events</code>. <code>MeteredBilling::canSendMessage()</code>
        sums them for the current calendar month and short-circuits the
        SSE stream with a <code>message_quota_exceeded</code> error event
        when the total hits <code>monthly_messages</code>.
    </li>
    <li>
        <code>MessageStreamController</code> reads <code>maxTokensFor()</code>
        once per turn (cheap — one row from the workspace's plan, on a
        request the controller is already loading) and threads it through
        both the tool-resolution loop and the final streaming call.
    </li>
</ul>

<h2>Stripe sync</h2>

<p>
    When an admin creates or updates a paid plan, the
    <code>StripeProductSync</code> service ensures a matching Stripe Product
    + Price exists. Customers never deal with Stripe directly until checkout
    — they pick a plan in the Pitchbar UI and get sent to Stripe Checkout
    via Cashier.
</p>

<p>
    On price changes, the old Stripe Price is archived and a new one is
    created (Stripe Prices are immutable). Existing subscriptions stay
    grandfathered on the old price; new subscriptions use the new one. This
    is the same behavior every Stripe-native SaaS uses.
</p>

<h2>Subscribing</h2>

<p>
    From <code>/billing</code>, a workspace member with the
    <code>billing.manage</code> permission can:
</p>

<ol>
    <li>Pick a plan from the comparison table.</li>
    <li>Get redirected to Stripe Checkout.</li>
    <li>Pay; Stripe redirects back to <code>/billing</code> with a success flash.</li>
    <li>The Stripe webhook updates the workspace's <code>plan_id</code> + creates a <code>plan_subscription</code> row.</li>
</ol>

<p>
    Card on file is managed via Stripe's Customer Portal. The
    <strong>Manage card</strong> button on <code>/billing</code> opens it.
</p>

<h2>Public /pricing matrix parity</h2>

<p>
    The public <code>/pricing</code> comparison table and the in-app
    <code>/billing</code> plan cards must surface the same ten feature
    rows so prospects don't see a thinner pitch than what customers
    get in-product: Published agents, Monthly conversations, AI
    messages per month, Workspace members, Workspaces per owner,
    Knowledge sources, Workflows, Integrations, API access, Branding
    removed. The
    <code>tests/Feature/Marketing/PricingMatrixParityTest</code>
    regression hard-fails CI if a row drifts off the public matrix.
</p>

<h2>Quotas</h2>

<p>
    The free plan caps monthly new conversations. Enforcement is on the hot
    path — every <code>/v1/widget/init</code> call asks
    <code>MeteredBilling::canStartConversation()</code> whether the
    workspace is under its plan limit. If not:
</p>

<pre><code>{
    "error": {
        "code": "plan_limit_reached",
        "message": "This workspace has reached its monthly conversation limit. Upgrade to continue."
    }
}</code></pre>

<p>
    Returned as 429. The widget's loader gracefully hides the launcher when
    it sees this — visitors don't see a broken state.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        Existing conversations and human takeovers are <strong>not</strong>
        gated. Only new init calls. So a visitor mid-conversation when you
        hit the limit can finish their thread.
    </div>
</div>

<h2>What counts as a conversation</h2>

<p>
    Every distinct conversation row counts as 1, fired by
    <code>IncrementUsageJob</code> when the conversation's first turn
    completes. Playground conversations (<code>is_playground=true</code>)
    don't count, so the agent's owners can test freely.
</p>

<p>
    Resumed conversations don't count again — only the original init bumps
    the meter.
</p>

<h2>Branding removal</h2>

<p>
    Plans with <code>features.remove_branding = true</code> hide the
    "Powered by Pitchbar" footer in the widget. The Free plan ships with
    branding on; paid plans typically off. The Plan model exposes this as
    <code>$plan-&gt;removesBranding()</code>, called at init time.
</p>

<h2>Invoices</h2>

<p>
    Stripe sends invoices to the billing email on file. The full history is
    available in the Stripe Customer Portal (Manage card → Invoices). Cashier
    also exposes <code>$workspace-&gt;invoices()</code> server-side if you
    want to render them in-app.
</p>

<h2>Automatic tax (Stripe Tax)</h2>

<p>
    Pitchbar can let <a href="https://stripe.com/tax" target="_blank" rel="noopener">Stripe Tax</a>
    calculate VAT / sales tax automatically from each customer's billing
    address. Opt-in, in this order — flipping the flag first breaks
    checkout:
</p>

<ol>
    <li><strong>Stripe dashboard first:</strong> activate Stripe Tax
        (Settings → Tax), set your origin address, and add a tax
        registration for every country where you're registered (e.g.
        your local VAT number). Stripe Tax is a paid Stripe feature
        (billed per transaction where tax is calculated).</li>
    <li><strong>Then enable in Pitchbar:</strong></li>
</ol>

<pre><code>CASHIER_AUTO_TAX=true     # .env (default off)
php artisan config:clear</code></pre>

<p>
    From that point every <em>new</em> subscription, one-off invoice,
    and Stripe Checkout session carries <code>automatic_tax</code>;
    Checkout also collects the customer's billing address and offers a
    business tax-ID field automatically.
</p>

<p>
    Caveats: <strong>never retroactive</strong> — existing active
    subscriptions keep invoicing without tax until they're individually
    updated in Stripe. Prices are treated as tax-<em>exclusive</em> by
    default (tax added on top at checkout); if your customers expect
    VAT-inclusive prices, set your Stripe prices' tax behavior to
    "inclusive" in the dashboard before enabling. PayPal / Razorpay
    flows are unaffected — Stripe Tax is Stripe-only.
</p>

<h2>Lifecycle: cancel, resume, swap</h2>

<p>
    The customer-facing controls live on <code>/app/billing</code>:
</p>

<ul>
    <li>
        <strong>Cancel subscription</strong>. Stripe schedules a cancel at
        the end of the current period (you keep access until then). PayPal
        cancels immediately (PayPal makes CANCELLED a terminal state).
        Razorpay schedules a cancel at the cycle end.
    </li>
    <li>
        <strong>Resume subscription</strong>. Only Stripe, and only if the
        cancel hasn't yet taken effect (still inside Cashier's
        <code>onGracePeriod</code>). PayPal CANCELLED can't be resumed; you
        subscribe again. Razorpay similarly does not support resume on a
        cancelled subscription.
    </li>
    <li>
        <strong>Plan swap (upgrade / downgrade)</strong>. Stripe does an
        in-place swap with proration on the next invoice. PayPal and
        Razorpay don't have a clean in-place swap, so clicking another
        plan cancels the current subscription and re-enters checkout. The
        orphan-cleanup branch of <code>CheckoutController</code> makes
        sure you're never paying both subscriptions at once.
    </li>
</ul>

<h3>Plan-change email</h3>

<p>
    When a workspace's plan actually changes, the owner is emailed a
    <strong>"Your plan was changed to {new plan}"</strong> confirmation
    (<code>PlanChangedMail</code>). This is necessary because <strong>Stripe
    sends nothing on a plain swap</strong>: a downgrade is a proration
    <em>credit</em> (no payment, so no receipt email) and no invoice is
    finalized at swap time (so no invoice email) — and Stripe has no
    "plan changed" email at all. So without this, a customer who
    downgrades hears nothing.
</p>

<p>
    The email fires from the confirmed source of truth — the
    <code>customer.subscription.updated</code> webhook
    (<code>WebhookController::syncWorkspacePlan</code>), the moment the
    workspace's <code>plan_id</code> flips to a different plan — so it
    covers in-app swaps, upgrades, downgrades, and changes made directly
    in the Stripe Dashboard. It is <em>not</em> sent on the initial
    subscribe (<code>customer.subscription.created</code>) or on the many
    no-op <code>updated</code> events Stripe fires for renewals, card
    updates, and status changes (the plan hasn't changed, so no email).
    Delivery is best-effort and queued: a mail failure never fails the
    webhook.
</p>

<h2>Post-checkout reconciliation</h2>

<p>
    The <code>SubscriptionReconciler</code> service is the safety net for
    webhook delivery. After a successful checkout the customer redirects
    to <code>/app/billing?checkout=success</code> (Stripe also appends
    <code>session_id={CHECKOUT_SESSION_ID}</code>) and the controller
    pulls live subscription state directly from the gateway, flipping
    <code>workspace.plan_id</code> in-band. The page renders the correct
    plan even when:
</p>

<ul>
    <li>
        The Stripe webhook endpoint isn't registered yet in the customer's
        Stripe dashboard (very common on a fresh install).
    </li>
    <li>
        The webhook fires but our endpoint is briefly down / the signature
        mismatched / it's rejected by an upstream WAF.
    </li>
    <li>
        The webhook eventually arrives but takes 30+ seconds, during
        which the customer reloads the billing page and panics.
    </li>
</ul>

<p>
    The reconciler is idempotent — safe to call on every page load. The
    webhook still does the same job whenever it lands; the two paths
    converge on the same row in <code>plan_subscriptions</code>.
</p>

<h2>Custom plans</h2>

<p>
    Plans with <code>price_cents = 0</code> aren't free in the customer
    sense — they're <em>local-only</em>, never synced to Stripe, and used
    for hand-rolled enterprise deals or for replacing the Free plan. Admins
    create them the same way; the Stripe sync simply skips.
</p>
