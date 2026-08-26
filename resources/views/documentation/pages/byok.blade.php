<p>
    <strong>BYOK</strong> — Bring Your Own Keys — lets each workspace
    pay their own Cloudflare / OpenAI / OpenRouter / Qdrant bill instead
    of running on the platform operator's shared credentials. Strategic
    for operators reselling Pitchbar as a SaaS to many end customers:
    every customer's chat, embeddings, and vector storage land on their
    upstream account, not yours.
</p>

<h2>How the policy matrix works</h2>

<p>
    BYOK has two switches that compose:
</p>

<table>
    <thead><tr><th>Global flag</th><th>Per-user override</th><th>BYOK unlocked?</th></tr></thead>
    <tbody>
        <tr><td>OFF</td><td>not set</td><td>No — platform keys are used.</td></tr>
        <tr><td>OFF</td><td>force-on</td><td>Yes — this user gets BYOK access even though the platform default is off.</td></tr>
        <tr><td>OFF</td><td>force-deny</td><td>No.</td></tr>
        <tr><td>ON</td><td>not set</td><td>Yes — every workspace must supply own keys.</td></tr>
        <tr><td>ON</td><td>force-on</td><td>Yes (same as not-set when global is on).</td></tr>
        <tr><td>ON</td><td>force-deny</td><td>No — explicit deny always wins.</td></tr>
    </tbody>
</table>

<p>
    The matrix is enforced by
    <code>App\Support\ByokResolver::isUnlockedFor($user, $workspace)</code>.
    The LLM and Vector container bindings call into the resolver on
    every resolution, so flipping a switch takes effect on the next
    request without an Octane reload.
</p>

<h2>Enabling BYOK</h2>

<h3>Global (every workspace)</h3>

<ol>
    <li>Sign in as <strong>super_admin</strong>.</li>
    <li>Open <a href="/settings/system"><code>/settings/system</code></a>.</li>
    <li>Toggle <strong>"Enable BYOK globally"</strong> ON. Save.</li>
</ol>

<p>
    From this point every workspace must paste their own keys before
    their widget can serve visitors. Workspaces with no keys hit a
    friendly <em>"this workspace is not configured"</em> bubble in chat
    instead of a stack trace.
</p>

<h3>Per-user override</h3>

<ol>
    <li>Sign in as <strong>super_admin</strong>.</li>
    <li>Open <a href="/admin/users"><code>/admin/users</code></a>.</li>
    <li>Find the user. The <strong>BYOK</strong> column has a tri-state dropdown:
        <ul>
            <li><code>Inherit global</code> (default) — follow the global flag.</li>
            <li><code>Force enable</code> — grant BYOK access to this user only.</li>
            <li><code>Force disable</code> — block BYOK for this user. Always wins.</li>
        </ul>
    </li>
</ol>

<h2>Where customers paste their keys</h2>

<p>
    When BYOK is unlocked for a workspace, an
    <strong>AI keys</strong> entry appears in the customer's Settings
    sidebar (between <em>API tokens</em> and the platform pages).
    Click it to open <a href="/settings/byok-keys"><code>/settings/byok-keys</code></a>:
</p>

<ul>
    <li><strong>Cloudflare</strong> — account_id, API token, Vectorize index, optional chat / embed model overrides.</li>
    <li><strong>OpenAI</strong> — API key, optional chat / embed model overrides.</li>
    <li><strong>OpenRouter</strong> — API key, optional chat model override.</li>
    <li><strong>Qdrant</strong> — base URL, API key, collection name.</li>
</ul>

<p>
    Each section has a separate <strong>Save</strong> button so a
    customer can configure providers one at a time, and a per-provider
    <strong>Clear</strong> button to wipe just that provider's credentials.
    The form never echoes secrets back into the UI — customers re-paste
    on every rotation. Public fields (account_id, model names, index
    names, Qdrant URL) are pre-filled.
</p>

<p>
    Super-admins do <strong>not</strong> see the "AI keys" entry. They
    manage platform-wide credentials at
    <a href="/settings/system"><code>/settings/system</code></a> →
    <em>AI providers</em>. Workspace-level BYOK is customer-only.
</p>

<h2>What's stored, where, and how it's protected</h2>

<table>
    <thead><tr><th>Field</th><th>Database column</th><th>Encryption</th></tr></thead>
    <tbody>
        <tr><td>All BYOK credentials</td><td><code>workspaces.byok_keys</code> (JSON map)</td><td><code>encrypted:array</code> via <code>APP_KEY</code></td></tr>
        <tr><td>Global flag</td><td><code>app_settings.byok_enabled_globally</code> (boolean)</td><td>Plain (it's a public flag)</td></tr>
        <tr><td>Per-user override</td><td><code>users.byok_enabled</code> (nullable boolean)</td><td>Plain</td></tr>
    </tbody>
</table>

<p>
    The encrypted column stores a Laravel Crypt envelope. The raw DB
    column never contains plaintext credentials — anyone reading the
    column directly only gets a base64 blob. Decryption requires
    <code>APP_KEY</code>; rotating <code>APP_KEY</code> renders the
    column unreadable until customers re-paste.
</p>

<h2>Tenant isolation guarantees</h2>

<p>
    BYOK isolates each workspace from every other. The boundary is
    enforced in five layers:
</p>

<ol>
    <li>
        <strong>DB column encryption.</strong> Reading
        <code>workspaces.byok_keys</code> outside the application
        returns a Crypt envelope, not the plaintext token.
    </li>
    <li>
        <strong>Tenant resolution.</strong> Every call to
        <code>ByokResolver::keysFor(...)</code> takes a Workspace
        model that comes from <code>CurrentWorkspace::get()</code>.
        That helper resolves the workspace from the authenticated
        admin's <code>default_workspace_id</code> OR the widget JWT's
        <code>agent.workspace_id</code> — <em>never</em> from request
        body input. There's no global lookup that could leak the wrong
        tenant.
    </li>
    <li>
        <strong>Container binding lifetime.</strong>
        <code>OpenAiClient</code> and <code>QdrantClient</code> are
        bound <code>scoped()</code> in
        <code>AppServiceProvider</code>, not <code>singleton()</code>.
        Each HTTP request rebuilds the client. Workspace A's keys
        never persist into worker memory for workspace B's next
        request, even under Octane.
    </li>
    <li>
        <strong>Mutation surface.</strong>
        <code>ByokKeysController::clear</code> and <code>::update</code>
        resolve the workspace from <code>CurrentWorkspace</code>, never
        from a <code>?workspace_id=</code> param. Workspace A can't
        wipe or read workspace B's keys via any documented API call.
    </li>
    <li>
        <strong>Negative regression tests.</strong>
        <code>tests/Feature/Byok/ByokTenantIsolationTest.php</code>
        pins each guarantee — encryption at rest, A-doesn't-see-B,
        mutating A doesn't touch B, clearing A doesn't touch B.
        Removing any of the protections fails the test suite.
    </li>
</ol>

<h2>Resolution flow at runtime</h2>

<p>
    When a visitor sends a message, the LLM container binding runs:
</p>

<ol>
    <li>Resolve <code>CurrentWorkspace</code> from the widget JWT.</li>
    <li>Call <code>ByokResolver::isUnlockedFor($user, $workspace)</code> (visitor flow has no user, so the resolver checks only the global flag plus the workspace's keys).</li>
    <li>If unlocked AND <code>workspaces.byok_keys</code> has the matching provider's keys, build the client with those credentials.</li>
    <li>If unlocked but keys are missing AND the global flag is ON, throw <code>MissingByokKeyException</code> — the visitor sees a friendly "workspace not configured" line, not a stack trace.</li>
    <li>Otherwise (global OFF + no per-user override + no workspace keys) fall through to platform-wide credentials.</li>
</ol>

<p>
    The same chain drives Vectorize / Qdrant binding selection, the
    crawler's Cloudflare Browser Rendering credentials, and the reranker.
</p>

<h2>FAQ</h2>

<h3>Do BYOK customers pay Pitchbar nothing?</h3>

<p>
    They still pay you for the application itself (subscription, lifetime
    deal). BYOK only shifts the variable AI / vector / browser-rendering
    cost. Your Pitchbar billing is unrelated to the upstream LLM bill.
</p>

<h3>What happens to existing platform-keyed workspaces when I flip global ON?</h3>

<p>
    Their next request misses keys and they see the "workspace not
    configured" bubble. The fix is one of:
</p>
<ul>
    <li>Paste keys via <code>/settings/byok-keys</code> as the workspace owner.</li>
    <li>Force-deny that specific user (super_admin → <code>/admin/users</code>) so they fall back to platform credentials.</li>
    <li>Flip global OFF and only force-enable for the customers you want on BYOK.</li>
</ul>

<h3>Can I rotate <code>APP_KEY</code>?</h3>

<p>
    Yes, but the existing encrypted <code>byok_keys</code> column entries
    are sealed with the old key — rotating renders them unreadable until
    customers re-paste. Run a one-shot artisan command (or migrate
    in-place) to decrypt with the old key and re-encrypt with the new.
    Same caveat applies to every other <code>encrypted</code> cast in the
    app.
</p>

<h3>Does BYOK affect the SSRF guards on the crawler?</h3>

<p>
    No. The
    <a href="/documentation/security">SSRF guard</a> (private-IP
    blocklist + DNS rebind protection) runs regardless of which
    Cloudflare account is fetching the URL. BYOK only swaps the
    credentials; the safety net stays in place.
</p>

<h3>Where do BYOK customers see their usage?</h3>

<p>
    They check it on their own provider's dashboard (Cloudflare,
    OpenAI, Qdrant Cloud) — Pitchbar doesn't proxy usage metering
    back. Workspace-level analytics in your Pitchbar dashboard still
    show conversation + message counts, which is enough for them to
    correlate.
</p>
