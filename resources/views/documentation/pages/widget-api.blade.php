<p>
    The Widget API is the public HTTP surface the bundled JavaScript talks
    to. You normally don't call it yourself — the widget loader does — but
    the contract is documented here so you can build custom clients,
    audit traffic, or simulate the widget for testing.
</p>

<p>
    All endpoints are under <code>/api/v1/widget</code>. Authentication is
    a signed JWT issued by <code>/init</code>. CORS is permissive on
    <code>POST</code> for cross-origin embeds.
</p>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/init</h2>

<p>
    Boots the widget for a visitor. No auth — but the request's
    <code>Origin</code> header must match the agent's
    <code>allowed_origins</code> (see <a href="/documentation/allowed-origins">Allowed origins</a>).
</p>

<h4>Request</h4>

<pre><code>POST /api/v1/widget/init
Origin: https://your-site.com
Content-Type: application/json

{
    "agent_id": "01HXY...",
    "page_url": "https://your-site.com/pricing",
    "anon_id": "anon_abc123"     // optional; persists visitor across reloads
}</code></pre>

<h4>Response (200)</h4>

<pre><code>{
    "data": {
        "conversation_id": "01HXZ...",
        "visitor_id": "01HXY...",
        "anonymous_id": "anon_abc123",
        "jwt": "eyJhbGciOiJIUzI1NiI...",
        "expires_at": "2026-05-07T13:00:00Z",
        "agent": {
            "id": "01HXY...",
            "name": "Aria",
            "persona": { "name": "Aria", "tone": "friendly" },
            "theme": { "primary": "#111827", ... },
            "starter_prompts": [ "..." ],
            "language_default": "en"
        },
        "branding": { "show": true, "label": "...", "url": "...", "logo_url": "...", "display_mode": "logo_only" },
        "behavior_rules": [ ... ],
        "messages": [ ... ],          // last 30 messages of the resumed conversation
        "reverb": { "app_key": "...", "host": "...", "port": 8080, "scheme": "wss" }
    }
}</code></pre>

<h4>Error responses</h4>

<table>
    <thead><tr><th>Status</th><th>Code</th><th>Cause</th></tr></thead>
    <tbody>
        <tr><td>404</td><td><code>agent_not_found</code></td><td>Agent doesn't exist or isn't published.</td></tr>
        <tr><td>403</td><td><code>origin_forbidden</code></td><td>Origin not in <code>allowed_origins</code>.</td></tr>
        <tr><td>429</td><td><code>plan_limit_reached</code></td><td>Workspace exceeded its monthly conversation quota.</td></tr>
        <tr><td>429</td><td>(throttled)</td><td>Per-IP rate limit hit (60 rpm by default).</td></tr>
    </tbody>
</table>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/messages/stream</h2>

<p>
    The streaming endpoint. SSE response. Auth: <code>Authorization: Bearer
    &lt;jwt&gt;</code>. Use this for the visitor experience — every other
    method is sync and slower.
</p>

<h4>Request</h4>

<pre><code>POST /api/v1/widget/messages/stream
Authorization: Bearer eyJhbGciOiJIUzI1NiI...
Content-Type: application/json

{
    "message": "What's your refund policy?",
    "page_url": "https://your-site.com/pricing",
    "page_context": { ... }       // optional; structured data extracted from the current page
}</code></pre>

<h4>Response (Server-Sent Events)</h4>

<pre><code>HTTP/1.1 200 OK
content-type: text/event-stream

data: {"event":"token","token":"Our "}

data: {"event":"token","token":"refund "}

data: {"event":"token","token":"policy is 30 days "}

data: {"event":"citations","citations":[{"id":1,"url":"https://your-site.com/refunds"}]}

data: {"event":"done","conversation_id":"01HXZ..."}</code></pre>

<p>
    Token events come fastest in the first few hundred ms — that's the
    1-second-to-first-token target on the hot path. <code>citations</code>
    event arrives once after streaming completes; <code>done</code> closes
    the stream.
</p>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/messages</h2>

<p>
    Sync version of <code>/messages/stream</code>. Returns the full response
    in one JSON payload. Slower (visitor waits for the full response) but
    easier to integrate with non-browser clients.
</p>

<h4>Response</h4>

<pre><code>{
    "data": {
        "message_id": "01HXZ...",
        "conversation_id": "01HXZ...",
        "content": "Our refund policy is 30 days...",
        "citations": [{"id": 1, "url": "..."}],
        "low_confidence": false
    }
}</code></pre>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/leads</h2>

<p>
    Submit captured contact info. Auth: same JWT as messages.
</p>

<pre><code>POST /api/v1/widget/leads
Authorization: Bearer eyJhbGciOiJIUzI1NiI...

{
    "name": "Alex",
    "email": "alex@example.com",
    "phone": "+1...",
    "fields": { "company": "Acme" }   // any agent-defined custom fields
}</code></pre>

<p>
    Dedupes on (agent_id, email): repeat submissions update the existing
    lead instead of creating a new one. Rate-limited at 5 requests per
    JWT per window — abuse-resistant.
</p>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/events</h2>

<p>
    Lightweight client-side analytics. The widget calls this with telemetry
    events (launcher opened, CTA clicked, dismissed, scroll trigger fired).
    Auth: JWT. Rate-limited.
</p>

<pre><code>{
    "event": "cta.click",
    "rule_id": "01HXY...",
    "metadata": { ... }
}</code></pre>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/request-human</h2>

<p>
    Visitor escalates to a live operator. The bot's reply pauses; the
    conversation flips to <code>human_requested_at</code> and the
    in-app Inbox alerts every workspace operator. Auth: JWT.
</p>

<pre><code>{ "reason": "I'd like to talk to sales" }   // reason optional, max 500 chars</code></pre>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/typing</h2>

<p>
    Visitor typing indicator. The widget pings while the visitor is
    composing so operators see "is typing…" in real time. Throttled
    to 600 rpm per IP (high to absorb keystroke bursts; per-IP
    instead of per-JWT so multiple tabs share the budget). No body
    required — a bare POST is enough.
</p>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/satisfaction</h2>

<p>
    Capture the visitor's CSAT rating at end of conversation. Auth:
    JWT. Throttled to 60 rpm per IP.
</p>

<pre><code>{
    "rating": "good",                  // good | bad
    "comment": "Loved the help"        // optional, max 500 chars
}</code></pre>

<h2>POST <span class="docs-pill docs-pill-post">POST</span> /v1/widget/coupon/apply</h2>

<p>
    E-commerce vertical only. Visitor accepts an offered coupon CTA;
    the controller stamps the conversation with the coupon code so
    downstream attribution can credit the bot. Auth: JWT. Throttled
    to 120 rpm per IP.
</p>

<pre><code>{ "code": "SAVE20" }                   // 3-32 chars, alphanumeric + dashes</code></pre>

<h2>JWT format</h2>

<p>
    HS256, signed with <code>WIDGET_JWT_SECRET</code>. Claims:
</p>

<pre><code>{
    "iss": "pitchbar",
    "iat": 1714900000,
    "exp": 1714903600,           // 60 minutes
    "agent_id": "01HXY...",
    "visitor_id": "01HXY...",
    "conversation_id": "01HXZ..."
}</code></pre>

<p>
    Tokens are scoped to a single conversation. Re-init to get a fresh
    token for a new conversation. Verifying happens in
    <code>WidgetJwt::verify()</code> — invalid signatures, expired tokens,
    or tampered claims all return 401.
</p>

<h2>Rate limits</h2>

<table>
    <thead><tr><th>Endpoint</th><th>Limit</th><th>Key</th></tr></thead>
    <tbody>
        <tr><td><code>/init</code></td><td>60 rpm</td><td>per IP + agent_id (<code>throttle:widget-init</code>)</td></tr>
        <tr><td><code>/messages</code>, <code>/messages/stream</code>, <code>/events</code>, <code>/conversation/*</code>, <code>DELETE /me</code></td><td>30 rpm</td><td>per JWT (<code>throttle:widget-session</code>)</td></tr>
        <tr><td><code>/leads</code></td><td>5 rpm</td><td>per JWT (<code>throttle:widget-leads</code>)</td></tr>
    </tbody>
</table>

<p>
    All return 429 with a <code>Retry-After</code> header on limit.
</p>
