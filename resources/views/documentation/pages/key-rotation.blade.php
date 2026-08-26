<p>
    Pitchbar encrypts every customer-controlled secret at rest under
    the Laravel <code>APP_KEY</code>. If that key leaks, you need to
    rotate to a new one without losing access to existing data. The
    <code>security:rotate-app-key</code> Artisan command makes the
    rotation safe and idempotent.
</p>

<h2>Columns covered</h2>

<ul>
    <li><code>workspaces.cta_context_secret</code></li>
    <li><code>workspaces.byok_keys</code></li>
    <li><code>workspaces.slack_webhook_url</code></li>
    <li><code>workspaces.teams_webhook_url</code></li>
    <li><code>webhook_subscriptions.secret</code></li>
    <li><code>workspace_api_tokens.shopper_signing_secret</code></li>
    <li><code>dsr_requests.result_payload</code></li>
    <li><code>integration_connections.credentials_encrypted</code></li>
    <li><code>sources.credentials_encrypted</code></li>
    <li><code>app_settings.*</code> (Stripe, PayPal, Razorpay,
        Cloudflare, OpenAI, OpenRouter, mail, internal queue
        secrets)</li>
</ul>

<h2>Rotation flow</h2>

<ol>
    <li>Generate a new key:
        <pre><code>php artisan key:generate --show
# copy the output</code></pre>
    </li>
    <li>Add the new key as the active <code>APP_KEY</code> and move
        the old key into <code>APP_PREVIOUS_KEYS</code> (comma
        separated if multiple). Example:
        <pre><code>APP_KEY=base64:NEW_KEY
APP_PREVIOUS_KEYS=base64:OLD_KEY</code></pre>
    </li>
    <li>Deploy. Laravel will decrypt rows with either key (new takes
        priority on writes); reads using the old ciphertext fall
        back to the previous key. The app keeps serving uninterrupted.</li>
    <li>Run the sweep:
        <pre><code>php artisan security:rotate-app-key --confirm-production</code></pre>
    </li>
    <li>The command iterates every encrypted column in chunks of 200
        rows, decrypts with the current key (or the previous-key
        fallback), and re-encrypts with the new key. Skipped rows
        (rows whose ciphertext cannot be decrypted) are reported but
        do not abort the sweep.</li>
    <li>Once the command completes, remove
        <code>APP_PREVIOUS_KEYS</code> from your env. The old key
        is no longer needed for any row.</li>
</ol>

<h2>Safety flags</h2>

<ul>
    <li><code>--confirm-production</code> — required in
        <code>production</code> environment. Omit in
        <code>staging</code> / <code>local</code>.</li>
    <li><code>--dry-run</code> — counts rows + columns that
        <em>would</em> be re-encrypted without writing. Useful to
        preview the surface before committing.</li>
</ul>

<h2>Idempotency</h2>

<p>
    Running the command twice is harmless. The second pass
    decrypts and re-encrypts the same values; the ciphertext
    bytes change (fresh IV) but the plaintext remains intact.
</p>

<h2>What if a row cannot decrypt?</h2>

<p>
    The command logs the column and row id to the console and
    moves on. That row stays on whatever key produced its current
    ciphertext. If you removed
    <code>APP_PREVIOUS_KEYS</code> before the sweep completed,
    those rows are unrecoverable — restore from a backup and
    re-run with the previous key in env.
</p>

<h2>Audit footprint</h2>

<p>
    The command does not write to <code>audit_logs</code> on its
    own — but the operator who runs it should record the rotation
    in their change-management system. If you want every rotation
    auto-audited, wrap the artisan call in a deploy script that
    emits an audit row.
</p>
