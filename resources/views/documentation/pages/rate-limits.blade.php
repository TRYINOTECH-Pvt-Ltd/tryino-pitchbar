<p>
    Pitchbar applies per-endpoint rate limits to protect against
    abuse and to keep cost-bearing endpoints (LLM, vector search)
    predictable. Every response from a rate-limited surface carries
    a quad of headers so integrators can pace themselves.
</p>

<h2>Headers on every response</h2>

<table>
    <thead><tr><th>Header</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><code>X-RateLimit-Limit</code></td><td>Maximum requests allowed in the current window.</td></tr>
        <tr><td><code>X-RateLimit-Remaining</code></td><td>Requests still available in the current window.</td></tr>
        <tr><td><code>X-RateLimit-Reset</code></td><td>Unix timestamp when the window resets and budget is restored.</td></tr>
        <tr><td><code>Retry-After</code></td><td>Only sent on 429 — seconds to wait before retrying.</td></tr>
    </tbody>
</table>

<p>
    Pitchbar's API-surface middleware ensures
    <code>X-RateLimit-Reset</code> ships on every response, not just
    on 429. That mirrors GitHub / Stripe behaviour.
</p>

<h2>Named limiters in use</h2>

<table>
    <thead><tr><th>Limiter</th><th>Applies to</th><th>Limit</th><th>Keyed by</th></tr></thead>
    <tbody>
        <tr>
            <td><code>widget-init</code></td>
            <td><code>POST /api/v1/widget/init</code></td>
            <td>1000/min/IP+agent · 30000/hr/IP</td>
            <td>Soft per-IP — absorbs NAT bursts.</td>
        </tr>
        <tr>
            <td><code>widget-session</code></td>
            <td><code>POST /api/v1/widget/messages*</code>, events, conversation operations</td>
            <td>300/min</td>
            <td>Per JWT (visitor session).</td>
        </tr>
        <tr>
            <td><code>widget-leads</code></td>
            <td><code>POST /api/v1/widget/leads</code></td>
            <td>30/min</td>
            <td>Per JWT.</td>
        </tr>
        <tr>
            <td><code>wp-plugin</code></td>
            <td>All <code>/v1/wp/*</code> bulk-sync endpoints</td>
            <td>60/min</td>
            <td>Per workspace API token id.</td>
        </tr>
        <tr>
            <td>Inline (<code>throttle:600,1</code>)</td>
            <td><code>POST /api/v1/widget/typing</code></td>
            <td>600/min/IP</td>
            <td>Per IP (visitor typing pings).</td>
        </tr>
        <tr>
            <td>Inline (<code>throttle:60,1</code>)</td>
            <td><code>POST /api/v1/widget/satisfaction</code></td>
            <td>60/min/IP</td>
            <td>Per IP.</td>
        </tr>
        <tr>
            <td>Inline (<code>throttle:120,1</code>)</td>
            <td><code>POST /api/v1/widget/coupon/apply</code></td>
            <td>120/min/IP</td>
            <td>Per IP.</td>
        </tr>
    </tbody>
</table>

<h2>What 429 looks like</h2>

<pre><code>HTTP/1.1 429 Too Many Requests
Content-Type: application/json
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1747438932
Retry-After: 28

{"error":{"code":"rate_limited","message":"Too many widget requests for this conversation. Please slow down."}}
</code></pre>

<h2>Best practices for consumers</h2>

<ul>
    <li>Watch <code>X-RateLimit-Remaining</code> on every response.
        When it hits a small threshold (e.g. &lt; 5), pause and
        wait for <code>X-RateLimit-Reset</code>.</li>
    <li>On 429, sleep for the value in <code>Retry-After</code>,
        then retry. Don't back off exponentially — the window is
        deterministic.</li>
    <li>If you operate a high-fanout integration, segment your
        callers so they don't all share an IP — the
        <code>widget-init</code> per-IP cap can squeeze hard from a
        single egress.</li>
</ul>
