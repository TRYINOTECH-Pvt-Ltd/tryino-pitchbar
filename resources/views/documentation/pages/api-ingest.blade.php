<p>
    The <strong>Sources REST API</strong> lets you push knowledge into a
    Pitchbar workspace from any system that can make an HTTP request.
    Useful for piping content out of an internal wiki, a CMS, a data
    pipeline, or a curated CSV — without going through the admin UI.
</p>

<h2>Authentication</h2>

<p>
    Every request needs a <strong>workspace API token</strong> issued from
    <strong>Settings → API tokens</strong>. The token must carry the
    <code>sources:write</code> ability. The plaintext is shown once at
    creation; only the <code>SHA-256</code> hash is persisted.
</p>

<pre><code>Authorization: Bearer pbar_…48-character-token…</code></pre>

<p>
    Tokens are workspace-scoped — anything you push lands in the token's
    workspace, never another. Revoke from the same page; revoked tokens
    fail the next call without affecting historical audit rows.
</p>

<h2>Endpoints</h2>

<h3>List sources</h3>

<pre><code>GET https://{your-pitchbar-host}/api/v1/workspace/sources</code></pre>

<p>Returns up to 100 most-recent sources for the token's workspace.</p>

<pre><code>{
    "data": [
        {
            "id": "019e2000-…",
            "agent_id": "019e1fec-…",
            "kind": "url",
            "status": "indexed",
            "config": { "url": "https://example.com/pricing" },
            "last_synced_at": "2026-05-13T08:00:00+00:00",
            "created_at": "2026-05-13T07:50:00+00:00"
        }
    ]
}</code></pre>

<h3>Create a source</h3>

<pre><code>POST https://{your-pitchbar-host}/api/v1/workspace/sources
Content-Type: application/json
Authorization: Bearer pbar_…</code></pre>

<p>
    Three kinds are supported. <code>agent_id</code> must reference an
    agent in the token's workspace; the call 404s otherwise.
</p>

<h4>1. Crawl a single URL</h4>

<pre><code>{
    "agent_id": "019e1fec-…",
    "kind": "url",
    "url": "https://example.com/pricing"
}</code></pre>

<h4>2. Crawl a sitemap (fans out to every URL inside)</h4>

<pre><code>{
    "agent_id": "019e1fec-…",
    "kind": "sitemap",
    "url": "https://example.com/sitemap.xml"
}</code></pre>

<h4>3. Push raw text (skips the crawler — bulletproof for pages behind auth)</h4>

<pre><code>{
    "agent_id": "019e1fec-…",
    "kind": "text",
    "title": "Q3 pricing breakdown",
    "content": "Our Starter plan is …",
    "source_url": "https://example.com/internal/pricing"
}</code></pre>

<p>
    The response (<code>201 Created</code>) returns the new source with
    <code>status: "pending"</code>. The crawler / indexer runs on the
    Pitchbar queue and flips the status to <code>indexed</code> when
    chunks land in the vector store. Existing sources with the same
    content are deduped by SHA-256 hash, so re-running the same call is
    safe.
</p>

<h3>Fetch one source</h3>

<pre><code>GET https://{your-pitchbar-host}/api/v1/workspace/sources/{id}</code></pre>

<p>Same shape as the list endpoint, single row.</p>

<h2>Curl example</h2>

<pre><code>curl -X POST https://app.example.com/api/v1/workspace/sources \
  -H "Authorization: Bearer pbar_xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "019e1fec-…",
    "kind": "text",
    "title": "Refund policy",
    "content": "Customers have 30 days to request a refund …"
  }'</code></pre>

<h2>Error shape</h2>

<table>
    <thead><tr><th>Status</th><th>Body</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><code>401</code></td><td><code>{"error":{"code":"missing_token"}}</code></td><td>No token in <code>Authorization</code> header.</td></tr>
        <tr><td><code>401</code></td><td><code>{"error":{"code":"invalid_token"}}</code></td><td>Token revoked or doesn't match a workspace.</td></tr>
        <tr><td><code>403</code></td><td><code>{"error":{"code":"missing_ability"}}</code></td><td>Token doesn't carry <code>sources:write</code>.</td></tr>
        <tr><td><code>404</code></td><td><code>{"error":{"code":"agent_not_found"}}</code></td><td><code>agent_id</code> isn't in the token's workspace.</td></tr>
        <tr><td><code>422</code></td><td>Laravel validation envelope</td><td>Missing or malformed field.</td></tr>
    </tbody>
</table>

<h2>Rate limits</h2>

<p>
    Per-token throttling kicks in at <strong>120 requests / minute</strong>.
    Over-quota requests return <code>429</code> with a
    <code>Retry-After</code> header.
</p>

<h2>What happens after a successful push</h2>

<ol>
    <li>A <code>Source</code> row is created in the agent's workspace.</li>
    <li>For <code>kind=url</code> / <code>kind=sitemap</code> a <code>CrawlSourceJob</code> is queued. Pitchbar's crawler hits the URL (Cloudflare Browser Rendering → Browserless → plain HTTP).</li>
    <li>For <code>kind=text</code> an <code>IndexTextSourceJob</code> bypasses the crawler and sends the content straight into chunk extraction.</li>
    <li>Chunks are embedded with the workspace's configured embedding model and upserted to Cloudflare Vectorize / Qdrant.</li>
    <li>The next visitor question that matches the content gets it as a citation.</li>
</ol>

<p>
    Status reaches <code>indexed</code> typically within 10-60 seconds of
    a push (longer for very large sitemaps that fan out to many pages).
    Watch the source row in the dashboard or poll the
    <code>GET</code> endpoint until <code>status</code> transitions.
</p>
