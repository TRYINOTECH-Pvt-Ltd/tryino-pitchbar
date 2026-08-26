<p>
    Outgoing webhooks let Pitchbar push events to your endpoint when
    something interesting happens. Configure them per workspace under
    <code>/app/integrations/webhooks</code>.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">v1 surface — small but stable</div>
        Today only one event ships: <code>lead.captured</code>. The
        delivery is single-attempt (no retries) with HMAC-SHA256
        signing. Conversation-level events
        (<code>conversation.started</code>, <code>conversation.message</code>,
        <code>conversation.routed</code>) are deferred and not yet emitted.
    </div>
</div>

<h2>Configuration</h2>

<p>
    Each webhook subscription has:
</p>

<ul>
    <li><strong>URL</strong> — your endpoint. HTTPS strongly recommended.</li>
    <li><strong>Events</strong> — currently only <code>lead.captured</code>.</li>
    <li><strong>Signing secret</strong> — auto-generated. Used to HMAC the body.</li>
    <li><strong>Active</strong> — toggle.</li>
</ul>

<h2>Headers</h2>

<p>
    The dispatcher sends these headers:
</p>

<table>
    <thead><tr><th>Header</th><th>Value</th></tr></thead>
    <tbody>
        <tr><td><code>Content-Type</code></td><td><code>application/json</code></td></tr>
        <tr><td><code>X-Pitchbar-Signature</code></td><td><code>t={timestamp},v1={hmac}</code> — Stripe-style timestamped signature</td></tr>
        <tr><td><code>X-Pitchbar-Webhook-Id</code></td><td>A UUID unique to this delivery. Use it to dedupe at-most-once on your side.</td></tr>
        <tr><td><code>X-Pitchbar-Event</code></td><td>Event name (e.g. <code>lead.captured</code>) so your router doesn't have to parse the body before dispatching.</td></tr>
    </tbody>
</table>

<h2>Rotating the secret</h2>

<p>
    If you suspect the secret has leaked, rotate it from
    <code>/app/integrations</code> &rarr; the webhook card &rarr;
    <strong>Rotate secret</strong>. The new value is shown <em>once</em>
    in a modal — copy it immediately into your verifier. The old
    secret stops working the moment the rotation completes; in-flight
    deliveries still use the old secret until they finish. A
    <code>integration.webhook_secret_rotated</code> entry lands in the
    audit log.
</p>

<h2>Signature verification</h2>

<p>
    The signature is an HMAC-SHA256 of <code>"{timestamp}.{body}"</code>
    using the subscription's signing secret. To verify:
</p>

<pre><code>// Node
const crypto = require('crypto');

function verify(rawBody, signatureHeader, secret) {
    const parts = Object.fromEntries(
        signatureHeader.split(',').map(p =&gt; p.split('='))
    );
    const ts = parts.t;
    const sig = parts.v1;

    const expected = crypto
        .createHmac('sha256', secret)
        .update(`${ts}.${rawBody}`)
        .digest('hex');

    return crypto.timingSafeEqual(
        Buffer.from(expected),
        Buffer.from(sig)
    );
}</code></pre>

<p>
    Always use a constant-time comparison
    (<code>timingSafeEqual</code> in Node, <code>hash_equals</code> in PHP)
    to avoid timing attacks. Reject the delivery if <code>t</code> is
    older than ~5 minutes — replay protection lives on your side.
</p>

<h2>Delivery semantics</h2>

<p>
    Each delivery is a single HTTP POST with a 5-second timeout. There is
    <strong>no built-in retry</strong>: a non-2xx response or timeout
    drops the event. If your endpoint is briefly down you'll lose that
    event. Recommended pattern:
</p>

<ul>
    <li><strong>Reply 2xx fast.</strong> Buffer to your own queue and process asynchronously.</li>
    <li><strong>Idempotency.</strong> Use the event's <code>occurred_at</code> + <code>data</code> contents to dedupe — there's no per-delivery ID yet.</li>
    <li><strong>Reconciliation.</strong> For business-critical data, periodically pull from the admin lead list rather than relying solely on webhooks.</li>
</ul>

<h2>Event payloads</h2>

<h3>lead.captured</h3>

<pre><code>{
    "event": "lead.captured",
    "occurred_at": "2026-05-07T12:00:00Z",
    "data": {
        "lead_id": "01HXY...",
        "agent_id": "01HXY...",
        "conversation_id": "01HXZ...",
        "name": "Alex",
        "email": "alex@example.com",
        "phone": "+1...",
        "fields": { "company": "Acme" }
    }
}</code></pre>

<p>
    The exact field set depends on what the visitor filled into the
    inline form and any custom fields you've defined on the agent. Keys
    are stable; missing values are <code>null</code> rather than absent.
</p>

<h2>Stripe webhooks (incoming)</h2>

<p>
    These are separate — Stripe sends to <code>/billing/webhook</code>
    and Cashier verifies the standard <code>Stripe-Signature</code> header
    using <code>STRIPE_WEBHOOK_SECRET</code>. They drive subscription
    state. You don't configure these from the integrations page; they're
    a platform-admin concern.
</p>

<h2>Testing locally</h2>

<p>
    Point a webhook at <code>https://webhook.site</code> or a tunneled
    local URL (ngrok). Submit a lead via the playground or the live
    widget; the webhook fires within a second.
</p>

<h2>Roadmap</h2>

<p>
    The webhook surface will expand to include conversation-level events,
    a per-delivery ID, and at-least-once retry semantics. Until then,
    poll the admin endpoints for state you care about beyond
    <code>lead.captured</code>.
</p>
