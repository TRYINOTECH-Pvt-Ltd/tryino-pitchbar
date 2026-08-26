<p>
    Widget chat and playground both fail with "Sorry — something went wrong". Tail of
    <code>storage/logs/laravel.log</code> shows:
</p>

<pre><code>Workers AI 401: {"success":false,"error":[{"code":2009,"message":"Unauthorized"}]}</code></pre>

<p>
    Cloudflare rejected the API token Pitchbar sent on the Workers AI call. This page is
    the recovery playbook.
</p>

<h2>Diagnose</h2>

<p>
    Run this <code>curl</code> command from your Pitchbar server, substituting the
    actual values from your <code>.env</code> or <code>/settings/system → AI</code>:
</p>

<pre><code>ACCOUNT_ID="paste-32-char-hex-here"
TOKEN="paste-fresh-token-here"
curl -i -X POST "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/ai/run/@cf/baai/bge-base-en-v1.5" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text":["hello"]}'</code></pre>

<p>
    Three possible outcomes:
</p>

<table>
    <thead><tr><th>Response</th><th>Cause</th><th>Fix</th></tr></thead>
    <tbody>
        <tr>
            <td><strong>200 + JSON with floats</strong></td>
            <td>Cloudflare is fine; Pitchbar is sending a stale cached token from <code>bootstrap/cache/config.php</code>.</td>
            <td><code>php artisan config:clear && php artisan cache:clear && php artisan view:clear</code></td>
        </tr>
        <tr>
            <td><strong>401</strong></td>
            <td>Account ID does not match the account this token was issued for, OR Workers AI is not enabled on this account, OR token was mistyped (whitespace, partial paste).</td>
            <td>See checklist below.</td>
        </tr>
        <tr>
            <td><strong>403</strong></td>
            <td>Token is valid for the account but lacks the <strong>Workers AI: Read</strong> scope.</td>
            <td>Re-issue the token with the correct scope. See "Required scopes" below.</td>
        </tr>
    </tbody>
</table>

<h2>401 checklist (in priority order)</h2>

<ol>
    <li>
        <strong>Confirm <code>CLOUDFLARE_ACCOUNT_ID</code> matches the account that issued the token.</strong>
        Log in to Cloudflare as the same email used to create the token → Workers &amp; Pages → right
        sidebar → <strong>Account ID</strong> (32-char hex). Paste that into Pitchbar →
        Settings → System → AI → Cloudflare. The most common cause of 401 with a fresh token is
        a mismatched account ID.
    </li>
    <li>
        <strong>Confirm Workers AI is enabled on this account.</strong> Dashboard → AI → Workers AI.
        If you see a "Get started" button, click it to enable. Free tier covers most CodeCanyon-scale
        installs.
    </li>
    <li>
        <strong>Regenerate the token cleanly.</strong> Dashboard → My Profile → API Tokens → Create
        Token → Custom token. Use the scopes listed below. Account Resources: "Include → Specific account → your account".
        Copy the token string with no leading or trailing whitespace, paste into Pitchbar, save.
    </li>
    <li>
        <strong>Verify with the verify endpoint.</strong>
        <pre><code>curl -i "https://api.cloudflare.com/client/v4/user/tokens/verify" \
  -H "Authorization: Bearer NEW_TOKEN"</code></pre>
        Expected: <code>"status":"active"</code>. Confirms the token string itself is valid;
        doesn't confirm scope.
    </li>
</ol>

<h2>Required Cloudflare API token scopes</h2>

<p>
    Pitchbar's default Cloudflare stack uses Workers AI for chat + embeddings, Vectorize
    for retrieval, and Cloudflare Browser Rendering for crawls. The token needs:
</p>

<table>
    <thead><tr><th>Scope</th><th>Why</th></tr></thead>
    <tbody>
        <tr><td><code>Workers AI: Read</code></td><td>Chat + embedding inference</td></tr>
        <tr><td><code>Vectorize: Write</code></td><td>Index CRUD + query (write implies read)</td></tr>
        <tr><td><code>Browser Rendering: Write</code></td><td>Page crawl for knowledge sources</td></tr>
        <tr><td><code>Workers Scripts: Write</code></td><td>One-click cron worker deploy from <code>/settings/system → Cron worker</code></td></tr>
    </tbody>
</table>

<p>
    <em>Not</em> required: AI Gateway, R2 Storage, Pages. Earlier versions of Pitchbar suggested AI
    Gateway scope — that hint was misleading and was removed in v2.0.0.
</p>

<h2>After token works</h2>

<p>
    Pitchbar config caches the token into <code>bootstrap/cache/config.php</code> on
    first request. After updating the token in <code>/settings/system → AI</code>, you must
    clear the cache:
</p>

<pre><code>php artisan config:clear
php artisan cache:clear
php artisan view:clear</code></pre>

<p>
    Then retry the playground at <code>/admin/agents/{id}/playground</code>. Should
    work immediately.
</p>
