<p>
    The widget bundle ships at <code>/widget/widget.js</code> and is
    re-built every release. To avoid stale browser / CDN caches on
    buyer sites, each build also publishes a
    content-hashed twin at
    <code>/widget/widget.&lt;hash&gt;.js</code> plus a manifest at
    <code>/widget/manifest.json</code>.
</p>

<h2>Default embed (auto-update)</h2>

<p>
    The snippet the admin sees on <code>/app/agents/{id}</code>
    points at the hashed file via the manifest. Each release ships a
    new hash, the manifest updates on deploy, and the snippet
    automatically resolves to the new file. Buyers don't need to
    touch their HTML.
</p>

<pre><code>&lt;script async src="https://app.example.com/widget/widget.abc123.js" data-agent="…"&gt;&lt;/script&gt;</code></pre>

<h2>Manifest endpoint</h2>

<p>
    Buyers who want to programmatically fetch the current bundle can
    hit <code>GET /widget/manifest.json</code>:
</p>

<pre><code>{
  "version": "2.0.0",
  "hash": "abc123abc123",
  "file": "widget.abc123abc123.js",
  "url": "/widget/widget.abc123abc123.js",
  "generated_at": "2026-05-16T07:11:36Z"
}</code></pre>

<p>
    The endpoint sets
    <code>Cache-Control: public, max-age=60, must-revalidate</code>
    so the manifest itself never holds long, but the bundle file can
    be served with long-lived <code>Cache-Control</code> headers
    because the URL changes per release.
</p>

<h2>Pinning to a specific version</h2>

<p>
    If you need to lock the bundle on the buyer's side (e.g. to
    coordinate a marketing campaign), copy the
    <code>file</code> field from the manifest and use it verbatim:
</p>

<pre><code>&lt;script async src="https://app.example.com/widget/widget.abc123abc123.js"&gt;&lt;/script&gt;</code></pre>

<p>
    The hashed artifact remains accessible after newer versions
    ship — old hashes are not deleted by the build script.
</p>

<h2>Fallback</h2>

<p>
    If the manifest is missing (for example a partial deploy), the
    snippet falls back to
    <code>/widget/widget.js?v=&lt;md5-prefix&gt;</code> so buyers
    never get an empty src. We keep the unhashed
    <code>widget.js</code> as the canonical artifact too.
</p>

<h2>Build pipeline</h2>

<p>
    <code>npm run build:widget</code> runs the Vite build, then
    invokes <code>bin/widget-postbuild.cjs</code> which:
</p>

<ol>
    <li>Reads <code>public/widget/widget.js</code>.</li>
    <li>Computes a 12-char SHA-256 prefix.</li>
    <li>Copies the bundle to
        <code>public/widget/widget.&lt;hash&gt;.js</code>.</li>
    <li>Writes <code>public/widget/manifest.json</code>.</li>
</ol>

<p>
    Both artifacts and the manifest are committed to the repo —
    the host doesn't run <code>npm run build</code>, the repo
    <em>is</em> the deployment artifact.
</p>
