<p>
    A CTA configured with <strong>forward context</strong> tacks a signed
    payload onto its outbound URL so the destination site can recognise
    the visitor without re-asking who they are. The payload is encoded as
    three query-string parameters and signed with an HMAC-SHA256 secret
    that lives on the workspace.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">Where to enable it</div>
        Open an agent → <code>Behavior rules &amp; CTAs</code> → pick or
        create a <code>link_with_context</code> CTA. Toggle <code>Forward
        context</code> and select which fields the destination site
        should receive.
    </div>
</div>

<h2>Outbound URL shape</h2>

<p>
    When forwarding is on, Pitchbar appends three parameters to the
    base URL you configured on the CTA:
</p>

<pre><code>{base-url}?pitchbar_ctx=&lt;base64url-json&gt;
         &amp;pitchbar_ts=&lt;unix-seconds&gt;
         &amp;pitchbar_sig=&lt;hex-hmac-sha256&gt;</code></pre>

<ul>
    <li><code>pitchbar_ctx</code> — base64url-encoded JSON of the
        whitelisted fields you chose to forward.</li>
    <li><code>pitchbar_ts</code> — unix timestamp at which the URL was
        minted; used for replay protection.</li>
    <li><code>pitchbar_sig</code> — hex HMAC-SHA256 of
        <code>"{pitchbar_ctx}.{pitchbar_ts}"</code> using the
        workspace's <code>cta_context_secret</code>.</li>
</ul>

<p>
    A CTA with no forward-fields selected emits the URL verbatim — the
    extra params only appear once the operator opts in.
</p>

<h2>Whitelisted fields</h2>

<p>
    Pitchbar only ships fields the operator explicitly enabled per CTA,
    chosen from this whitelist:
</p>

<table>
    <thead><tr><th>Field</th><th>Source</th></tr></thead>
    <tbody>
        <tr><td><code>conversation_id</code></td><td>Active conversation row</td></tr>
        <tr><td><code>agent_id</code></td><td>Owning agent</td></tr>
        <tr><td><code>page_url</code></td><td>Page the visitor was on when chat opened</td></tr>
        <tr><td><code>visitor_email</code></td><td>Latest captured <code>Lead.email</code></td></tr>
        <tr><td><code>visitor_name</code></td><td>Latest captured <code>Lead.name</code></td></tr>
        <tr><td><code>captured_fields</code></td><td><code>Lead.fields</code> JSON minus password/token/secret/api_key keys</td></tr>
    </tbody>
</table>

<p>
    Sensitive-looking custom field keys (anything containing
    <code>password</code>, <code>pwd</code>, <code>secret</code>,
    <code>token</code>, <code>api_key</code>, <code>apikey</code>, or
    <code>private</code>) are stripped from <code>captured_fields</code>
    before signing — defence-in-depth.
</p>

<h2>Find your workspace secret</h2>

<p>
    The signing secret lives on the workspace row as
    <code>cta_context_secret</code>. Pitchbar mints one automatically
    the first time a CTA emits a signed URL. You can copy it from
    <code>Settings → API tokens</code> (the
    <em>Signed CTA context</em> card) and rotate it when you need to
    revoke trust on receiving sites.
</p>

<div class="callout callout-warn">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Treat this secret like a webhook key</div>
        Never ship the secret to the browser. Verification happens on the
        receiving site's server — the value should sit in an environment
        variable on that server, not in client-side JavaScript.
    </div>
</div>

<h2>Replay window</h2>

<p>
    Pitchbar enforces a 5-minute replay window (<code>300s</code>) by
    rejecting verifications whose <code>pitchbar_ts</code> is more than
    that far from the current time. The verifier on your side
    <strong>must</strong> apply the same check — without it an attacker
    who scrapes the URL out of a referrer log can replay it forever.
</p>

<h2>Verifying the signature</h2>

<p>
    The pattern is the same in every language: rebuild the HMAC over the
    exact <code>ctx.ts</code> string with your workspace secret, compare
    against the inbound <code>pitchbar_sig</code> using a
    constant-time check, and only then decode <code>pitchbar_ctx</code>
    to read the payload.
</p>

<h3>PHP</h3>

<pre><code>$ctx = (string) ($_GET['pitchbar_ctx'] ?? '');
$ts  = (string) ($_GET['pitchbar_ts'] ?? '');
$sig = (string) ($_GET['pitchbar_sig'] ?? '');
$secret = getenv('PITCHBAR_CTA_SECRET');

if ($ctx === '' || $ts === '' || $sig === '' || $secret === '') {
    http_response_code(401);
    exit;
}

// Replay window — 5 minutes either direction.
if (abs(time() - (int) $ts) &gt; 300) {
    http_response_code(401);
    exit;
}

$expected = hash_hmac('sha256', $ctx . '.' . $ts, $secret);
if (! hash_equals($expected, $sig)) {
    http_response_code(401);
    exit;
}

// Signature valid — decode the payload. Note base64url, not standard base64.
$padded = $ctx . str_repeat('=', (4 - strlen($ctx) % 4) % 4);
$json   = base64_decode(strtr($padded, '-_', '+/'), true);
$payload = $json === false ? null : json_decode($json, true);
// $payload now contains the whitelisted fields the operator forwarded.</code></pre>

<h3>Node.js</h3>

<pre><code>const crypto = require('crypto');

function verifyCtaContext(query, secret) {
    const ctx = String(query.pitchbar_ctx || '');
    const ts  = String(query.pitchbar_ts  || '');
    const sig = String(query.pitchbar_sig || '');

    if (!ctx || !ts || !sig) {
        return null;
    }

    // Replay window.
    if (Math.abs(Date.now() / 1000 - Number(ts)) &gt; 300) {
        return null;
    }

    const expected = crypto
        .createHmac('sha256', secret)
        .update(`${ctx}.${ts}`)
        .digest('hex');

    if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig))) {
        return null;
    }

    const padded = ctx + '='.repeat((4 - (ctx.length % 4)) % 4);
    const json = Buffer
        .from(padded.replace(/-/g, '+').replace(/_/g, '/'), 'base64')
        .toString('utf8');

    try {
        return JSON.parse(json);
    } catch {
        return null;
    }
}</code></pre>

<h3>Python</h3>

<pre><code>import base64, hmac, hashlib, json, time

def verify_cta_context(query, secret: str):
    ctx = query.get('pitchbar_ctx', '')
    ts  = query.get('pitchbar_ts',  '')
    sig = query.get('pitchbar_sig', '')

    if not ctx or not ts or not sig:
        return None

    try:
        if abs(time.time() - int(ts)) &gt; 300:
            return None
    except ValueError:
        return None

    expected = hmac.new(
        secret.encode(),
        f"{ctx}.{ts}".encode(),
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, sig):
        return None

    padded = ctx + '=' * ((4 - len(ctx) % 4) % 4)
    raw = base64.urlsafe_b64decode(padded.encode())
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return None</code></pre>

<h2>Example payload</h2>

<p>
    A CTA configured to forward
    <code>conversation_id</code>, <code>visitor_email</code>, and
    <code>page_url</code> ships:
</p>

<pre><code>{
    "conversation_id": "01JRX4D8YQ8KEXP3F5VZ8MEXAM",
    "visitor_email": "jane@example.com",
    "page_url": "https://customer-site.com/pricing"
}</code></pre>

<p>
    base64url-encoded into <code>pitchbar_ctx</code>, paired with the
    current <code>pitchbar_ts</code>, and signed with your secret.
</p>

<h2>Why not the outbound webhook?</h2>

<p>
    The webhook subscription delivers the full conversation transcript
    asynchronously — perfect for analytics or CRM push, but useless when
    a visitor clicks a CTA and you want to land them on a personalised
    page <em>before</em> any background job finishes. The signed CTA
    context exists for that fast path: it travels with the click and
    arrives with the request.
</p>

<p>
    Keep using webhooks for anything that needs the full body, or for
    leads that you want to fan out to multiple downstream systems.
    They're complementary, not redundant.
</p>
