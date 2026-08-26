<p>
    Sources are how an agent learns about your business. This page
    covers every kind of source, the ingestion pipeline, and what to expect
    after you click "Add".
</p>

<h2>Source types</h2>

<table>
    <thead>
        <tr><th>Type</th><th>Use it for</th><th>What we ingest</th></tr>
    </thead>
    <tbody>
        <tr><td><code>url</code></td><td>One specific page</td><td>Crawl + extract main content + chunk + embed</td></tr>
        <tr><td><code>sitemap</code></td><td>A whole site at once</td><td>Read sitemap, fan out to one <code>CrawlPageJob</code> per URL</td></tr>
        <tr><td><code>feed</code></td><td>RSS / Atom blogs</td><td>Same as sitemap but reads <code>&lt;item&gt;</code> entries</td></tr>
        <tr><td><code>text</code></td><td>FAQs, snippets, anything you can paste</td><td>Skip the crawl, chunk + embed directly</td></tr>
        <tr><td><code>notion</code></td><td>Notion pages or databases</td><td>OAuth into Notion, fetch via API, treat each page as a document</td></tr>
        <tr><td><code>google_doc</code></td><td>Google Docs (Workspace)</td><td>OAuth, fetch via Drive API, ingest as a document</td></tr>
        <tr><td><code>google_sheet</code></td><td>Google Sheets tabs</td><td>OAuth, pull every non-empty row, one Document body with <code>Row N: …</code> markers</td></tr>
        <tr><td><code>sql</code></td><td>Remote MySQL / PostgreSQL</td><td>Direct PDO read-only SELECT; one Document body with <code>Row N: …</code> markers. Credentials AES-256-GCM at rest.</td></tr>
        <tr><td><code>file</code></td><td>PDF / DOCX / XLSX uploads</td><td>Parsed via Cloudflare <code>toMarkdown</code> (free tier), chunked + embedded</td></tr>
        <tr><td><code>woocommerce_products</code></td><td>WP / WooCommerce stores</td><td>Synced by the companion WordPress plugin</td></tr>
        <tr><td><code>auto</code></td><td>Pages visitors land on</td><td>Auto-queued by <code>AutoIndexPageVisit</code> from <code>/v1/widget/init</code></td></tr>
    </tbody>
</table>

<h2>Editing a pasted-text source</h2>

<p>
    Pasted-text (<code>text</code>) sources can be edited in place — no
    need to delete and recreate. The body is stored on the source's
    <code>config</code> when you add it, so the <strong>Edit</strong>
    (pencil) button on a text row reopens the form pre-filled with the
    current title, source URL, and content. Saving re-runs
    <code>IndexTextSourceJob</code>, which <strong>replaces</strong> the
    old indexed content: the prior document and its vector chunks are
    purged before the new text is embedded, so stale answers never
    linger alongside the edit.
</p>

<p>
    Older text sources created before this feature shipped have no stored
    body, so their Edit form opens with an empty content box — paste the
    updated content once and it persists from then on.
</p>

<p>
    <strong>Reindex</strong> on a pasted-text row re-embeds the
    <em>saved</em> body — it does not fetch anything external (there is no
    external origin), but because the body is stored it fully restores a
    text source that an earlier bug left <code>failed</code>. To
    <em>change</em> the content, use <strong>Edit</strong> instead; a text
    source with no saved body (a legacy one) tells you to edit or re-add it
    rather than doing nothing.
</p>

<h2>"What the AI sees" is a preview, not the full text</h2>

<p>
    The grey blurb under each source — and the chunk list in the
    <strong>What the AI sees</strong> panel — is a <strong>preview</strong>,
    capped for display only. The collapsed blurb shows the first ~600
    characters of the first chunk; the expanded panel shows up to 20
    chunks, each trimmed to 4,000 characters. This is purely a display
    limit: the <strong>entire</strong> source is stored, chunked, and
    embedded. If a pasted document looks like it "stops" partway (e.g.
    mid-list), that is the preview boundary, not lost content — the rest
    lives in the later chunks and is fully answerable.
</p>

<p>
    Hard limits that <em>do</em> apply: a pasted-text source is capped at
    <strong>200,000 characters</strong> at submission — paste more than
    that and the form rejects it with an error (it is never silently
    truncated). Chunking adds trailing overlap between adjacent chunks, so
    facts spanning a chunk boundary survive retrieval. Use the per-chunk
    character count in the panel to confirm a chunk's full length; a
    "+N more characters" note appears when a chunk is longer than the
    on-screen preview.
</p>

<h2>SQL database source (MySQL + PostgreSQL)</h2>

<p>
    Connect a read-only MySQL or PostgreSQL database directly as a knowledge
    source. Every non-empty row your SELECT returns becomes part of the agent's
    training data, with citations the LLM can use as <code>Row N</code>
    references.
</p>

<p><strong>Setup steps:</strong></p>

<ol>
    <li>Open <code>/app/agents/{id}/sources</code>, scroll to <strong>Add SQL database</strong>.</li>
    <li>Pick the driver (MySQL or PostgreSQL) — port autofills to <code>3306</code> or <code>5432</code>.</li>
    <li>Paste host, database name, username, password.</li>
    <li>Paste a <code>SELECT</code> query — every row that returns becomes part of the agent knowledge base.</li>
    <li>Optional column mapping: pick a <strong>title_column</strong> (used as the row label) and a <strong>body_column</strong> (used as the row content). When omitted, every column is concatenated as <code>colname: value</code> pairs.</li>
    <li>Click <strong>Connect &amp; sync</strong>. The first <code>SyncSqlSourceJob</code> runs on the <code>crawl</code> queue, indexes the rows, and flips the source to <code>indexed</code>.</li>
</ol>

<p><strong>Hard rules enforced on every query:</strong></p>

<ul>
    <li><strong>SSRF guard.</strong> Host runs through <code>UrlSafetyGuard::assertSafe()</code> — same allowlist the crawler uses. <code>127.0.0.1</code>, RFC1918 ranges, link-local <code>169.254.0.0/16</code> (AWS metadata IP), and any name that resolves to a private IP are all refused. The source flips to <code>failed</code> with <em>"Unsafe SQL host: Refusing to crawl an internal / loopback / link-local host."</em></li>
    <li><strong>SELECT-only.</strong> Query must start with <code>SELECT</code> (case-insensitive). Multi-statement (containing <code>;</code>) is rejected. The keywords <code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>, <code>DROP</code>, <code>ALTER</code>, <code>TRUNCATE</code>, <code>GRANT</code>, <code>REVOKE</code>, <code>CREATE</code>, <code>REPLACE</code>, <code>CALL</code>, <code>EXEC</code>, <code>EXECUTE</code>, <code>MERGE</code>, <code>LOAD</code> are blocked.</li>
    <li><strong>Read-only transaction.</strong> The connector wraps the query in <code>START TRANSACTION READ ONLY</code> (MySQL) / <code>BEGIN TRANSACTION READ ONLY</code> (PostgreSQL) so even a permissive query string can't mutate state.</li>
    <li><strong>5,000-row cap per sync.</strong> Protects worker memory and the Document table from runaway queries. Lower-bound your <code>SELECT</code> with a <code>LIMIT</code> if you have a big table you don't want fully indexed.</li>
    <li><strong>10-second connect timeout.</strong> Hosts that can't be reached fail fast — buyers see the error inline.</li>
</ul>

<p><strong>Credentials at rest:</strong></p>

<p>
    The host, port, database name, username, and password are stored in
    <code>sources.credentials_encrypted</code> (text column) and encrypted
    via Laravel's <code>encrypted:array</code> cast — AES-256-GCM with the
    install's <code>APP_KEY</code>. The non-sensitive bits (driver, query,
    title/body column mappings, optional label) live in the regular
    <code>config</code> JSON column.
</p>

<p>
    Reading the raw column produces ciphertext only; the plaintext is
    visible only inside worker memory during a sync run and never logged.
    Rotating <code>APP_KEY</code> will require buyers to re-enter the
    password (standard Laravel behaviour — see
    <a href="/documentation/security">Security</a>).
</p>

<p><strong>What gets indexed:</strong></p>

<p>
    Each row becomes a labeled line in a single Document body. When you
    set <code>title_column = "title"</code> and <code>body_column = "body"</code>,
    a row with <code>{ title: "Welcome", body: "Hello world" }</code>
    renders as:
</p>

<pre><code>Welcome: Hello world</code></pre>

<p>
    Without a body column it falls back to a key/value join of every
    non-null column:
</p>

<pre><code>Row 1 — id: 42, name: ACME Corp, plan: Pro, last_seen: 2026-05-13</code></pre>

<p>
    The Document body is then chunked and embedded through the same
    <code>IndexDocumentJob</code> pipeline every other source uses, so
    SQL rows surface in retrieval just like crawled pages or pasted text.
</p>

<p><strong>Re-sync today:</strong> manual via the source's Refresh action.
Periodic auto-sync isn't scheduled yet — same as Notion / Google Doc /
Google Sheet. File a card if you want a cron.</p>

<p><strong>Drivers not shipped yet:</strong> MSSQL and Oracle. Both
require PHP extensions (<code>pdo_sqlsrv</code> / <code>oci8</code>) that
aren't bundled by default and aren't universal across CodeCanyon hosts.
Open a feature request if you need them.</p>

<h2>Add a source</h2>

<p>
    Open <code>/app/agents/{id}/sources</code>. The <strong>Add source</strong>
    modal handles all types in one form. Behind the scenes:
</p>

<ol>
    <li><strong>Validate</strong>. URLs must be http/https; private hosts (<code>10.x</code>, <code>192.168.x</code>, <code>127.x</code>, <code>::1</code>) are blocked to prevent SSRF.</li>
    <li><strong>Create the source row</strong> with <code>status = pending</code>.</li>
    <li><strong>Dispatch a job</strong> — <code>CrawlSourceJob</code> for url/sitemap/feed; <code>IngestNotionPageJob</code>/<code>IngestGoogleDocJob</code> for connected sources; <code>IndexTextSourceJob</code> for pasted text.</li>
    <li><strong>The job runs on the <code>crawl</code> queue</strong>, fetches content, creates Document rows, then dispatches <code>IndexDocumentJob</code> on the <code>index</code> queue.</li>
    <li><strong>The status flips</strong> from <code>pending → crawling → done</code> (or <code>failed</code> with an error message you can read in the UI).</li>
</ol>

<h2>"Indexing didn't finish" — diagnosing failed pages</h2>

<p>
    A page can land in the Knowledge view with <strong>0 chunks</strong>
    and an amber "Indexing didn't finish" badge. The expanded row now
    shows the actual error from <code>sources.error</code> when present,
    plus the crawler used and last-fetched timestamp. Most failures map
    to one of these:
</p>

<ul>
    <li><strong>JavaScript-only / SPA pages</strong> with no SSR fallback — Cloudflare Browser Rendering executes JS, but if the app fully hydrates client-side and exposes no scrapeable text, the extractor returns under the 200-character floor and the source is marked failed.</li>
    <li><strong>Bot challenge / Cloudflare protection</strong> — the fetched HTML is the challenge page, not the real content. Detected via <code>detectBlocker()</code> heuristics.</li>
    <li><strong>Login wall</strong> — site requires auth; we don't run authenticated crawls.</li>
    <li><strong>Soft 404</strong> — many sites return a 200-OK "not found" page when a URL is mistyped. <code>looksLike404()</code> rejects these.</li>
    <li><strong>Queue worker behind</strong> — the document row was created but <code>IndexDocumentJob</code> hasn't run yet. Wait a minute and refresh; if it persists, check Queue Health.</li>
</ul>

<p>
    Click <strong>Reindex</strong> on a failed row to retry the same
    pipeline (URL re-crawl + re-index, or file re-parse for uploads).
    Persistent failures usually mean the URL itself is unscrapable —
    try a different page on the same site, or upload the content as
    a file.
</p>

<h2>Switching embedding models</h2>

<p>
    The Cloudflare Vectorize index is provisioned at the exact
    dimension of the embedding model that was active when it was
    first created. Changing <code>CLOUDFLARE_EMBED_MODEL</code> from
    a 768-dim model (bge-base-en-v1.5) to a 1024-dim model (bge-m3,
    bge-large-en-v1.5) or back will cause every <code>IndexDocumentJob</code>
    to crash with:
</p>

<pre><code>Cloudflare 40012: invalid vector for id="...", expected 768 dimensions, and got 1024 dimensions</code></pre>

<p>
    Pitchbar now detects this BEFORE sending the upsert, surfaces an
    actionable error, and ships a recovery command. Known model→dim
    map (auto-applied when <code>VECTOR_DIM</code> env is unset):
</p>

<table>
    <thead><tr><th>Model</th><th>Dim</th></tr></thead>
    <tbody>
        <tr><td><code>@cf/baai/bge-small-en-v1.5</code></td><td>384</td></tr>
        <tr><td><code>@cf/baai/bge-base-en-v1.5</code> (default)</td><td>768</td></tr>
        <tr><td><code>@cf/baai/bge-large-en-v1.5</code></td><td>1024</td></tr>
        <tr><td><code>@cf/baai/bge-m3</code></td><td>1024</td></tr>
        <tr><td><code>text-embedding-3-small</code></td><td>1536</td></tr>
        <tr><td><code>text-embedding-3-large</code></td><td>3072</td></tr>
        <tr><td><code>text-embedding-ada-002</code></td><td>1536</td></tr>
    </tbody>
</table>

<p>
    Recovery: drop the existing index, recreate at the new dim, and
    re-dispatch <code>IndexDocumentJob</code> for every document:
</p>

<pre><code>php artisan vector:rebuild-index           # interactive — asks before proceeding
php artisan vector:rebuild-index --force   # for automation / CI
php artisan vector:rebuild-index --dim=1024  # override the resolved dim</code></pre>

<p>
    The command resets every Source to <code>pending</code>, deletes
    every Chunk row, drops the Vectorize index, recreates it at the
    target dim, and queues a re-index job per document onto the
    <code>index</code> queue. File-backed Documents re-index from the
    persisted text on disk; URL-only Documents need a manual
    <strong>Reindex</strong> click (which triggers <code>CrawlPageJob</code>
    to re-fetch).
</p>

<h2>Auto-discovery</h2>

<p>
    On the sources page, the <strong>Discover</strong> button takes a domain
    and probes it for crawlable pages without you having to list them. We:
</p>

<ul>
    <li>Read <code>robots.txt</code> for sitemap declarations.</li>
    <li>Probe a sitemap directly when present.</li>
    <li>Try a small set of common paths: <code>/about</code>, <code>/pricing</code>, <code>/features</code>, <code>/products</code>, <code>/faq</code>, <code>/docs</code>, <code>/help</code>, <code>/support</code>, <code>/contact</code>.</li>
    <li>Return a checkable list. Tick which to ingest, hit <strong>Add selected</strong>.</li>
</ul>

<h2>Sitemap fan-out</h2>

<p>
    Adding a Source of type <code>sitemap</code> dispatches one
    <code>CrawlPageJob</code> per URL in the sitemap, staggered by a
    small per-page delay so Cloudflare Browser Rendering doesn't
    rate-limit on burst. The discoverer (<code>SitemapDiscoverer</code>)
    handles three input shapes:
</p>

<ul>
    <li>
        <strong>Domain root</strong> (<code>https://example.com</code>) —
        probes <code>/sitemap.xml</code> + <code>/sitemap_index.xml</code>.
    </li>
    <li>
        <strong>Direct sitemap URL</strong>
        (<code>https://example.com/sitemap.xml</code> or
        <code>https://example.com/products/sitemap.xml</code>) — fetched
        verbatim. Pre-fix the discoverer used to append a second
        <code>/sitemap.xml</code> here and 404 the request.
    </li>
    <li>
        <strong>Sitemap-index</strong> (the <code>&lt;sitemapindex&gt;</code>
        XML many CMSes — WordPress, Shopify, Webflow — emit by default)
        — recurses one level into each child sitemap and aggregates
        page URLs.
    </li>
</ul>

<p>
    Output is deduped (so a URL listed in two child sitemaps gets
    indexed once) and capped at
    <code>services.crawl.max_pages_per_source</code> (default 500,
    override via <code>CRAWL_MAX_PAGES_PER_SOURCE</code>). The cap
    used to be 25 — a buyer adding a 100-URL sitemap silently lost 75
    pages — the new default is generous enough for most marketing /
    docs sites. Very large catalogues should split the sitemap by
    section anyway.
</p>

<h2>Crawler strategies</h2>

<p>
    The crawler is provider-driven. In order of preference:
</p>

<ol>
    <li><strong>Cloudflare Browser Rendering</strong> — preferred. Full JS rendering, fast, no SSRF risk because egress is on Cloudflare. Used when <code>CLOUDFLARE_ACCOUNT_ID</code> + <code>CLOUDFLARE_API_TOKEN</code> are set.</li>
    <li><strong>Browserless</strong> — fallback when <code>BROWSERLESS_TOKEN</code> is set. Same headless-Chrome behavior on a different vendor.</li>
    <li><strong>Plain HTTP</strong> — last resort for server-rendered sites. No JS execution. Free.</li>
</ol>

<p>
    Once HTML is in hand, <code>ReadabilityExtractor</code> strips nav,
    footer, ads, etc., leaving the article body. Pages under 200 chars or
    detected as 404s are dropped.
</p>

<h2>File upload parsing</h2>

<p>
    Direct file uploads (Sources &rarr; <strong>Upload files</strong>)
    are parsed locally first, then handed to the same chunk + embed
    pipeline crawled pages use. The parser is picked by file extension:
</p>

<table>
    <thead>
        <tr><th>Extension</th><th>Parser</th><th>Network call?</th></tr>
    </thead>
    <tbody>
        <tr><td><code>.pdf</code>, <code>.docx</code>, <code>.doc</code>, <code>.xlsx</code>, <code>.xls</code>, <code>.odt</code>, <code>.ods</code></td><td>Cloudflare Workers AI <code>toMarkdown</code> when CF creds are configured; <code>Smalot\PdfParser</code> / <code>PhpOffice\PhpWord</code> otherwise</td><td>Yes &mdash; one multipart POST per file to <code>/ai/tomarkdown</code> (free of cost, 0 Neurons)</td></tr>
        <tr><td><code>.csv</code></td><td><code>League\Csv</code> &mdash; emits one segment per row formatted as <code>col: value | col: value</code></td><td>No</td></tr>
        <tr><td><code>.md</code>, <code>.markdown</code>, <code>.txt</code></td><td>Plain text, split on H1/H2 headings</td><td>No</td></tr>
    </tbody>
</table>

<p>
    The Cloudflare path is preferred for binary office formats because
    Smalot and PhpWord are unreliable on real-world documents: Word-
    exported PDFs that put body text in one big content stream, scanned
    PDFs with a thin text layer, and DOCX files with nested tables or
    text frames all tend to extract poorly. Workers AI's
    <code>toMarkdown</code> returns structured markdown (headings, lists,
    tables preserved) which feeds the chunker much better.
</p>

<p>
    Pricing: <code>toMarkdown</code> is free for every format above.
    Only image-to-markdown conversion consumes Workers AI Neurons (we
    do not send images). When Cloudflare credentials are absent (BYOK
    OpenAI customers, fresh installs), or when the Cloudflare call
    fails, the in-process PHP parsers take over so PDF / DOCX / CSV /
    TXT / MD uploads never silently break.
</p>

<p class="callout">
    <strong>Spreadsheet uploads (.xlsx, .xls, .ods, .odt) require
    Cloudflare Workers AI.</strong> There is no local fallback. When
    those formats are uploaded to a workspace without
    <code>CLOUDFLARE_ACCOUNT_ID</code> + <code>CLOUDFLARE_API_TOKEN</code>
    configured, the source row is created with <code>status=failed</code>
    and the error stamp includes an actionable hint:
    <em>"Spreadsheet / OpenDocument formats need Cloudflare Workers AI."</em>
    Admins who need Excel ingestion on a BYOK-OpenAI install should
    export to CSV (the local <code>League\Csv</code> parser handles
    that format with no external dependency).
</p>

<p>
    Whichever parser ran, the resulting text is persisted under
    <code>storage/app/private/uploads/{source_id}/segment-N.txt</code>.
    That's the file the Reindex button reads &mdash; you don't need to
    re-upload the original to re-index.
</p>

<h2>Chunking and embedding</h2>

<p>
    The extractor's text goes into <code>Chunker</code>, a recursive
    splitter that prefers semantic boundaries:
</p>

<ol>
    <li>Split on markdown headings, then blank lines (paragraphs).</li>
    <li>Pack paragraphs greedily up to a target size (~2000 chars / ~500 tokens).</li>
    <li>If a paragraph is too big, fall back to sentence boundaries.</li>
    <li>Char-window as the absolute last resort.</li>
    <li>Add a small overlap between chunks so cross-chunk facts stay linkable.</li>
</ol>

<p>
    Each chunk is embedded in a batch (default 100 chunks per call) and
    upserted into the vector store with metadata: <code>agent_id</code>,
    <code>document_id</code>, <code>chunk_id</code>, <code>url</code>,
    <code>workspace_id</code>, <code>source_id</code>, <code>lang</code>.
</p>

<h2>Crawl retry policy</h2>

<p>
    Each <code>CrawlPageJob</code> attempts up to 3 times with
    backoff <code>[30s, 90s, 180s]</code>. The retry path is split
    by failure class:
</p>

<ul>
    <li><strong>Rate-limited (HTTP 429, "too many requests" upstream)</strong> — releases back to the queue with a fresh 60-second delay <em>without burning a retry slot</em>. Every fan-out page on the same workspace tends to hit the same 429 wave; the shared wait is productive.</li>
    <li><strong>Permanent failure</strong> (curl DNS resolve / connection refused, HTTP 400 / 401 / 403 / 404 / 410 / 451, malformed URL) — short-circuits via <code>fail()</code> so the Source row gets the real reason immediately instead of being stranded behind two more retries that will deterministically fail.</li>
    <li><strong>Transient</strong> (5xx, network blip) — normal retry with backoff.</li>
    <li><strong>Per-job timeout</strong> — 90s. <code>failOnTimeout=true</code> so a worker SIGTERM still flips the source to <code>failed</code> with a customer-readable error.</li>
</ul>

<p>
    Buyer-facing error messages on the Sources list are sanitized
    via <code>SourceErrorPresenter</code> — raw upstream JSON
    envelopes (Cloudflare 401 bodies, Browserless stack traces) get
    rewritten to friendly lines like <em>"We couldn't reach this page"</em>
    or <em>"The crawl service is busy right now — we will retry
    automatically."</em> Operators still see the full raw message
    under <strong>Show details</strong>.
</p>

<h2>Reindex and preview</h2>

<p>
    From the sources list, each row has:
</p>

<ul>
    <li><strong>Reindex</strong> — re-runs the type-appropriate pipeline: URL / sitemap sources re-crawl, OAuth sources (Google Docs/Sheets, Notion) re-fetch via their API, the <em>Auto-indexed from visitors</em> bucket re-crawls each page it has collected, and uploaded files re-chunk + re-embed from the persisted segment text under <code>storage/app/private/uploads/{source_id}/segment-N.txt</code> — no need to re-upload the original. If the persisted file is missing (pre-persistence uploads or a disk wipe) the already-indexed content keeps working and the UI asks for a re-upload only to <em>refresh</em> it; reindexing never fails a source whose content is intact. Pasted-text sources have no external origin to re-fetch, so Reindex re-embeds the text you saved on the source (which also restores one scarred <em>failed</em> by an older bug) — to change the wording itself, use Edit.</li>
    <li><strong>Answer on every page</strong> (globe icon) — see <a href="#answer-on-every-page">below</a>.</li>
    <li><strong>Preview</strong> — shows the extracted documents and a sample of chunks so you can spot bad extraction (e.g. nav bar polluting the text).</li>
    <li><strong>Delete</strong> — removes the source, its documents, its chunks, and the corresponding vector points.</li>
</ul>

<h2 id="answer-on-every-page">Answer on every page (global sources)</h2>

<p>
    Retrieval prefers knowledge from the page the visitor is on. That
    precision is usually what you want — but some facts are <em>global</em>:
    your address, opening hours, phone number, email. A visitor might ask
    for them from any page, yet if that content was only crawled onto (say)
    the Contact page, it can score just below the confidence threshold
    everywhere else and the assistant answers &ldquo;I can&rsquo;t confirm
    that.&rdquo;
</p>

<p>
    Toggle the <strong>globe</strong> button on a source to mark it
    <strong>&ldquo;Answer on every page.&rdquo;</strong> Its chunks then
    get the same lift a current-page match gets — from <em>any</em> page —
    so the fact is reachable everywhere. An <em>Every page</em> badge
    appears on the source. The lift is relevance-gated and capped: it only
    applies when the reranker already judged the fact relevant to the
    question (so &ldquo;what are your prices?&rdquo; never drags the address
    in), and at most two global chunks are force-included per answer, so a
    large global source can&rsquo;t crowd out the real answer.
</p>

<p>
    Best for a small, universally-relevant source (a contact card, an
    opening-hours note). It takes effect instantly — no re-index.
</p>

<h2>Automatic retries</h2>

<p>
    You should never have to babysit the Reindex button. Failures heal
    themselves on two layers:
</p>

<ul>
    <li><strong>In-job retries</strong> — every indexing job retries up
        to 3 times with spaced backoff, so a momentary rate limit or
        network blip resolves within minutes without surfacing at all.</li>
    <li><strong>Scheduled sweep</strong> — every 15 minutes,
        <code>pitchbar:retry-sources</code> re-queues sources that failed
        for <em>transient</em> reasons (rate limits, timeouts, upstream
        5xx, an unreachable queue) and rescues sources stranded in
        <em>crawling</em>/<em>pending</em> by a dead worker. Capped at 3
        automatic retries per failure episode (the counter resets after
        24 quiet hours).</li>
</ul>

<p>
    Permanent problems — a page blocked by robots.txt, a 404, a login
    wall, an expired Google connection, a file that needs re-uploading —
    are <em>never</em> auto-retried: they stay visible as
    <em>failed</em> with an actionable message until you fix the cause,
    and the sweep never burns crawl quota on them.
</p>

<h2>Notion and Google Docs</h2>

<p>
    Both use OAuth. Connect once from <code>/app/integrations</code>; the
    token is encrypted at rest. After connecting, the source modal lets you
    pick pages or documents directly.
</p>

<p>
    Pasting a <code>docs.google.com</code> document link into the plain
    <em>URL</em> form also works: the form recognises it and creates a
    proper Google Doc source (the web crawler can't read Google Docs, so
    crawling one as a website would always fail). Sheets links get
    pointed at the Google Sheet tab, which needs the tab name.
</p>

<p>
    Re-syncs are manual (per-source <strong>Reindex</strong> button) — we
    don't poll your Notion / Drive on a schedule. If you change a Notion
    page, click Reindex on that source.
</p>

<h2>"My agent doesn't know about the file I just uploaded"</h2>

<p>
    Cloudflare Vectorize has eventual consistency on metadata-filtered
    queries — even after an upsert returns 200 OK, an
    <code>agent_id</code>-filtered query against that vector typically
    returns 0 hits for the first <strong>30 to 60 seconds</strong> while
    the metadata index propagates across edge regions.
</p>

<p>
    Practical consequence: a freshly uploaded file shows up as
    <code>status=indexed</code> in the Sources page immediately, but the
    agent won't be able to answer questions about it until the propagation
    window closes. The upload-success banner reminds the admin of this.
    If the agent still doesn't return relevant chunks after a minute,
    open the source's <strong>Preview</strong> to confirm the extracted
    text isn't empty — that's a parser-side issue, not a vector-side one.
</p>

<p>
    Same gotcha applies to the very first upload after creating a
    Cloudflare Vectorize index for the first time — the index itself
    has a ~2 minute provisioning lag before any queries return results,
    even unfiltered ones.
</p>

<h2>Storage and retention</h2>

<ul>
    <li><strong>Postgres</strong> — sources, documents, chunks (text + metadata).</li>
    <li><strong>Vector store</strong> — embeddings. Cloudflare Vectorize when configured, Qdrant otherwise.</li>
    <li><strong>R2 / object storage</strong> — original artifacts (PDFs, images) when uploaded.</li>
</ul>

<p>
    Deleting a source cascades: documents, chunks, and vector points all go
    in one transaction. There's no soft-delete on sources.
</p>
