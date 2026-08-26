<p>
    Indexing is stuck across the board, or playground returns errors mentioning vector
    dimensions. Tail of <code>storage/logs/laravel.log</code> shows:
</p>

<pre><code>Vectorize POST indexes/X/upsert failed:
{"code":40012,"message":"invalid vector for id=\"…\",
 expected 768 dimensions, and got 1024 dimensions"}</code></pre>

<p>
    The Cloudflare Vectorize index was provisioned at one dimension (say 768) but the
    embedding model now returns vectors of a different size (1024). Most common cause:
    operator switched <code>CLOUDFLARE_EMBED_MODEL</code> from <code>@cf/baai/bge-base-en-v1.5</code>
    (768 dim) to <code>@cf/baai/bge-m3</code> or <code>@cf/baai/bge-large-en-v1.5</code> (both 1024 dim)
    without recreating the index.
</p>

<h2>Quick recovery</h2>

<p>
    Pitchbar ships a single-command recovery flow. SSH to your server and run:
</p>

<pre><code>cd /path/to/pitchbar
php artisan vector:rebuild-index</code></pre>

<p>
    Answer <code>yes</code> at the prompt. The command:
</p>

<ol>
    <li>Drops the existing Vectorize index.</li>
    <li>Re-creates it at the dimension that matches your currently-configured embedding model.</li>
    <li>Deletes every local <code>Chunk</code> row.</li>
    <li>Resets every <code>Source</code> to <code>status = pending</code>.</li>
    <li>Re-dispatches <code>IndexDocumentJob</code> for every Document with persisted text.</li>
</ol>

<p>
    Watch the queue worker — the Knowledge view will refill as each document
    re-embeds. URL-only Documents need a manual <strong>Reindex</strong> click after rebuild
    (they re-crawl via <code>CrawlPageJob</code> on demand).
</p>

<h2>Known model dimensions</h2>

<p>
    Pitchbar maps these embedding model slugs to dim automatically — leave
    <code>VECTOR_DIM</code> env unset and the
    <a href="/documentation/knowledge#switching-embedding-models">EmbedModelDimensions</a> resolver picks
    the right value:
</p>

<table>
    <thead><tr><th>Model</th><th>Dim</th></tr></thead>
    <tbody>
        <tr><td><code>@cf/baai/bge-small-en-v1.5</code></td><td>384</td></tr>
        <tr><td><code>@cf/baai/bge-base-en-v1.5</code> (default)</td><td>768</td></tr>
        <tr><td><code>@cf/baai/bge-large-en-v1.5</code></td><td>1024</td></tr>
        <tr><td><code>@cf/baai/bge-m3</code></td><td>1024</td></tr>
        <tr><td><code>text-embedding-3-small</code> (OpenAI)</td><td>1536</td></tr>
        <tr><td><code>text-embedding-3-large</code> (OpenAI)</td><td>3072</td></tr>
        <tr><td><code>text-embedding-ada-002</code> (OpenAI)</td><td>1536</td></tr>
    </tbody>
</table>

<h2>Flag overrides</h2>

<ul>
    <li><code>--force</code> — skip the confirmation prompt (useful for automation / CI rollouts)</li>
    <li><code>--dim=N</code> — override the auto-resolved dim (advanced; only if you point at an
        unlisted model)</li>
</ul>

<h2>Why this happens</h2>

<p>
    Cloudflare Vectorize indexes are immutable at the dimension level. Once provisioned
    with <code>config.dimensions = 768</code>, you cannot resize it in place; you must
    drop and recreate. Pitchbar adds two layers of defence so this doesn't bite the
    operator silently:
</p>

<ol>
    <li>
        <strong>Pre-flight guard</strong> in <code>VectorizeClient::upsertPoints</code> and
        <code>::search</code> (added 2026-05-15/16) rejects mismatched vectors locally
        with an actionable message naming the <code>vector:rebuild-index</code> command —
        before paying for a Cloudflare round-trip that would just return 40006 / 40012.
    </li>
    <li>
        <strong>ensureCollection dim check</strong> reads the existing index's dim on the
        first job per worker and throws an actionable error if it doesn't match the
        configured embed model's native dim.
    </li>
</ol>

<p>
    Both pathways funnel the operator to the same recovery: run <code>vector:rebuild-index</code>.
</p>

<h2>Cost note</h2>

<p>
    Re-embedding the entire knowledge base counts against Workers AI / OpenAI usage. For
    typical CodeCanyon-scale buyers (under 1000 chunks), Cloudflare Workers AI's free tier
    covers it. For OpenAI buyers, expect ~$0.02 per 1M tokens with <code>text-embedding-3-small</code>;
    a 100-chunk knowledge base is well under $0.01.
</p>
