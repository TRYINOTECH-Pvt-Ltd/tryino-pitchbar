<p>
    The widget script is public on purpose — anyone can fetch
    <code>/widget/widget.js</code>. That makes <strong>allowed origins</strong>
    the trust boundary that stops a third party from embedding your snippet
    on their own site and burning your quota.
</p>

<h2>The contract</h2>

<p>
    Every <code>POST /v1/widget/init</code> reads the request's
    <code>Origin</code> header (or <code>Referer</code> as a fallback) and
    checks it against the agent's <code>allowed_origins</code> list. The
    rules are:
</p>

<ol>
    <li><strong>Empty list → 403.</strong> Deny everywhere. New agents start empty until you add at least one origin.</li>
    <li><strong>Wildcard <code>"*"</code> → allow.</strong> Opt-in escape hatch for internal tools and demos. Never set as a default.</li>
    <li><strong>Otherwise → exact <code>scheme://host</code> match.</strong> No subdomain inference. <code>https://example.com</code> does <em>not</em> permit <code>https://app.example.com</code>.</li>
</ol>

<h2>Every privileged call re-checks Origin</h2>

<p>
    The init endpoint is where the JWT gets minted — but the JWT is
    a bearer token, and bearer tokens can be exfiltrated (leaked
    log, browser dev-tools, XSS on a victim site, MITM on
    cleartext). To stop a stolen JWT from being replayed from
    <code>attacker.example</code>, every privileged widget endpoint
    runs through the <code>VerifyWidgetOrigin</code> middleware,
    which re-validates the request Origin against the JWT-bound
    agent's <code>allowed_origins</code> on every call:
</p>

<ul>
    <li><code>POST /v1/widget/messages</code></li>
    <li><code>POST /v1/widget/messages/stream</code> (SSE)</li>
    <li><code>POST /v1/widget/leads</code></li>
    <li><code>POST /v1/widget/request-human</code></li>
    <li><code>POST /v1/widget/events</code></li>
    <li><code>POST /v1/widget/typing</code></li>
    <li><code>POST /v1/widget/satisfaction</code></li>
    <li><code>POST /v1/widget/coupon/apply</code></li>
    <li><code>POST /v1/widget/conversation/clear</code></li>
    <li><code>GET /v1/widget/conversation/messages</code></li>
    <li><code>DELETE /v1/widget/me</code></li>
</ul>

<p>
    Policy is identical to <code>/v1/widget/init</code> — empty
    list = deny, <code>"*"</code> = allow (including no-Origin),
    specific entries = exact normalised match — so a request that
    init would have approved can never be 403'd by the post-init
    middleware, and a request that init would have denied can never
    sneak past either.
</p>

<h2>Strict subdomain matching</h2>

<p>
    This is the rule that catches people off guard, so it deserves its own
    callout:
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        Listing <code>https://thecodestudio.com</code> in
        <code>allowed_origins</code> does <strong>not</strong> permit
        <code>https://pitchbar.thecodestudio.com</code>. Subdomains are
        independent — list each one explicitly. This prevents an attacker
        who controls a subdomain (via DNS or shared hosting) from inheriting
        trust from the parent.
    </div>
</div>

<p>
    If you actually want all subdomains, list them individually:
</p>

<pre><code>https://example.com
https://www.example.com
https://app.example.com
https://docs.example.com</code></pre>

<h2>Adding origins</h2>

<p>
    From the agent's Settings page (<code>/app/agents/{id}/settings</code>),
    the <strong>Allowed origins</strong> card has a textarea — one origin per
    line. Save updates the agent immediately; new init requests use the new
    list within seconds.
</p>

<p>
    Origins must include the scheme:
</p>

<table>
    <thead><tr><th>Valid</th><th>Invalid</th></tr></thead>
    <tbody>
        <tr><td><code>https://example.com</code></td><td><code>example.com</code></td></tr>
        <tr><td><code>http://localhost:3000</code></td><td><code>localhost</code></td></tr>
        <tr><td><code>https://shop.example.com</code></td><td><code>*.example.com</code> (wildcards aren't supported except as <code>"*"</code>)</td></tr>
    </tbody>
</table>

<h2>Testing locally</h2>

<p>
    During development, add <code>http://localhost:3000</code> (or whatever
    port you're using) to the agent's allowed origins. Don't use
    <code>"*"</code> for this — leaving it on by accident in production
    leaves the agent open.
</p>

<h2>What happens on rejection</h2>

<p>
    A request from a disallowed origin gets a JSON 403:
</p>

<pre><code>{
    "error": {
        "code": "origin_forbidden",
        "message": "Origin is not allowed for this agent."
    }
}</code></pre>

<p>
    The widget's loader handles this gracefully — the launcher disappears
    silently rather than throwing a console error, so visitors never see a
    broken UI. The 403 is logged on the platform side so you can watch for
    abuse patterns.
</p>

<h2>What about same-host origins</h2>

<p>
    The check uses scheme + host, so <code>http</code> vs. <code>https</code>
    is distinct (as it should be). And different ports are different
    origins (<code>http://localhost:3000</code> ≠
    <code>http://localhost:3001</code>).
</p>

<h2>Wildcards: when to use, when not</h2>

<p>
    <code>"*"</code> exists for cases where you genuinely don't know the
    origin in advance:
</p>

<ul>
    <li>Internal demo agents that get embedded on every prospect's preview site.</li>
    <li>Sandbox / preview environments where origin churns daily.</li>
</ul>

<p>
    For production agents, never. The cost of forgetting <code>"*"</code> is
    that anyone who finds your <code>data-agent-id</code> can drain your
    quota. The cost of an explicit list is one minute per new origin.
</p>

<h2>Restricted paths — the path-level companion</h2>

<p>
    <strong>Allowed origins</strong> draws the trust boundary at the
    domain level (only <code>https://shop.example.com</code> can load
    the widget). <strong>Restricted paths</strong> is its sibling: a
    list of URL <em>paths within an already-allowed origin</em> where
    the widget should NOT mount. Use it to keep the bot off your own
    <code>/admin</code>, <code>/checkout</code>, or <code>/account</code>
    flows without touching code.
</p>

<p>
    Each entry is a glob — <code>*</code> is the only wildcard, and
    matches across slashes greedily. Comparison is case-insensitive
    against <code>window.location.pathname</code>:
</p>

<table>
    <thead><tr><th>Pattern</th><th>Matches</th><th>Doesn't match</th></tr></thead>
    <tbody>
        <tr>
            <td><code>/admin</code></td>
            <td><code>/admin</code>, <code>/Admin</code></td>
            <td><code>/admin/users</code> (use <code>/admin/*</code> for that)</td>
        </tr>
        <tr>
            <td><code>/admin/*</code></td>
            <td><code>/admin/users</code>, <code>/admin/billing/invoices</code></td>
            <td><code>/admin</code> exactly (the bare prefix); list both if you want both</td>
        </tr>
        <tr>
            <td><code>/checkout</code></td>
            <td><code>/checkout</code></td>
            <td><code>/checkout/confirm</code></td>
        </tr>
        <tr>
            <td><code>/account/*</code></td>
            <td><code>/account/profile</code>, <code>/account/security</code></td>
            <td><code>/Help/account</code></td>
        </tr>
    </tbody>
</table>

<h3>How it works at runtime</h3>

<p>
    The agent's <code>restricted_paths</code> list rides the same
    <code>POST /v1/widget/init</code> response as the rest of the
    config. After init succeeds, the widget checks
    <code>window.location.pathname</code> against the list — if any
    pattern matches, the bar never mounts, the trigger engine never
    starts, and no further HTTP rides on that page. The init call
    itself does happen (the server is the source of truth), so if the
    overhead matters, also gate at the script-tag level using
    <code>allowed_origins</code> for the host.
</p>

<h3>Authoring</h3>

<p>
    From the agent's Settings page, the <strong>Restricted paths</strong>
    card has a textarea — one path per line. Same UX as Allowed origins.
    Empty list = no restrictions (widget mounts everywhere within an
    allowed origin). Up to 32 entries, each up to 200 characters.
</p>

<h3>Why this is a separate knob from auth</h3>

<p>
    The platform also auto-suppresses the marketing demo widget on
    authenticated admin/customer routes via a server-side check in the
    Inertia root layout — that's a hard guarantee that doesn't depend
    on agent config. <code>restricted_paths</code> is the buyer-side
    extension: even on a fully unauthenticated marketing site,
    <code>/checkout</code> shouldn't be cluttered with a sales chat bot.
</p>

<h2>How auto-index uses origins</h2>

<p>
    <a href="/documentation/auto-index">Auto-index</a> uses the same
    allow-list, but with a twist when <code>"*"</code> is set: the page URL
    being auto-indexed must match the visitor's actual <code>Origin</code>
    header. That stops a malicious page from auto-indexing arbitrary
    third-party domains via the wildcard.
</p>
