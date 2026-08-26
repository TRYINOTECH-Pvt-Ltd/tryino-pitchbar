<p>
    Every public route emits a per-page SEO block — title,
    description, canonical URL, Open Graph card, Twitter card, and
    JSON-LD structured data — without any per-page work in the
    React layer. Buyers running a white-label install inherit the
    same machinery; replace the brand name in
    <code>/settings/branding</code> and the meta block follows.
</p>

<h2>What ships out of the box</h2>

<ul>
    <li>
        <strong>Per-route titles + descriptions.</strong> Defined in
        <code>App\Support\SeoMeta::ROUTE_DEFAULTS</code>. Tokens like
        <code>{brand}</code> are interpolated at render time so a
        renamed install never leaks the source product name into the
        meta block.
    </li>
    <li>
        <strong>Canonical URLs.</strong> Every page declares its
        canonical via <code>&lt;link rel="canonical"&gt;</code>,
        anchored on <code>config('app.url')</code> + the route path.
        Search engines never have to guess which variant is the
        master.
    </li>
    <li>
        <strong>Open Graph + Twitter cards.</strong> Shared social
        previews cover <code>og:type</code>, <code>og:site_name</code>,
        <code>og:title</code>, <code>og:description</code>,
        <code>og:url</code>, <code>og:image</code>, plus the
        <code>twitter:card="summary_large_image"</code> companion.
        The OG image defaults to <code>public/og-image.png</code>;
        drop your own in there to override.
    </li>
    <li>
        <strong>JSON-LD structured data.</strong> Three blocks layer
        on top of the standard meta:
        <ul>
            <li>
                <strong>Organization</strong> — every page. Brand
                name, canonical URL, and (when present) the logo.
            </li>
            <li>
                <strong>SoftwareApplication</strong> — home only.
                Lets Google's rich-result picker render us as an app
                with an offer + free-tier price.
            </li>
            <li>
                <strong>FAQPage</strong> — home only, sourced from the
                marketing FAQ admins edit at Settings → Marketing.
                Edits flow through to the structured data
                automatically — Google's rich-result picker renders
                Q+A directly under the search snippet for matching
                queries.
            </li>
        </ul>
    </li>
    <li>
        <strong>/sitemap.xml</strong> — every marketing page, every
        documentation slug, and every published changelog version.
        Cached for 1 hour at the controller level so a fresh
        changelog entry shows up within the cache window.
    </li>
    <li>
        <strong>/robots.txt</strong> — allows public surfaces;
        disallows <code>/admin</code>, <code>/app</code>,
        <code>/api/</code>, <code>/settings</code>, and the auth
        flows; references the sitemap so crawlers find it on first
        sniff.
    </li>
</ul>

<h2>Adding SEO to a new route</h2>

<p>
    Two places to touch when adding a new public page:
</p>

<ol>
    <li>
        Register a default in <code>SeoMeta::ROUTE_DEFAULTS</code>
        with <code>title</code> / <code>description</code> /
        <code>path</code>. Use <code>{brand}</code> for any place
        the install's name should appear.
    </li>
    <li>
        In the controller, pass the SEO payload to Inertia:
        <pre><code>return Inertia::render('your/page', [
    // ... your props
    'seo' => SeoMeta::for('your-route-key'),
]);</code></pre>
    </li>
</ol>

<p>
    The Inertia root layout
    (<code>resources/views/app.blade.php</code>) reads
    <code>props.seo</code> and emits the meta block automatically.
    No per-page Blade work needed.
</p>

<p>
    Need to vary the description per row (e.g. a per-version
    changelog page)? Pass overrides:
</p>

<pre><code>'seo' => SeoMeta::for('changelog.show', [
    'title' => "v1.1.0 — what's new in {brand}",
    'description' => "Released " . $entry->released_at_human . " — " . $entry->summary,
])</code></pre>

<h2>Customising the Open Graph image</h2>

<p>
    Drop a 1200×630 PNG (or JPG) at <code>public/og-image.png</code>.
    Buyers running their own install can swap the file directly via
    SFTP or the deploy host's file manager. The
    <code>SeoMeta</code> resolver checks the file exists at render
    time; if it doesn't, the meta block silently omits
    <code>og:image</code> and <code>twitter:image</code> rather than
    pointing at a 404.
</p>

<h2>Sitemap cache</h2>

<p>
    The sitemap is cached for 1 hour under
    <code>seo:sitemap.xml</code>. To force a refresh after publishing
    a long-awaited changelog entry:
</p>

<pre><code>php artisan tinker --execute 'cache()->forget("seo:sitemap.xml");'</code></pre>

<p>
    Crawler traffic on a busy install would otherwise be O(crawl
    rate) database hits; the cache flattens it to one rebuild per
    hour per node.
</p>

<h2>Excluded routes</h2>

<table>
    <thead><tr><th>Route prefix</th><th>Why excluded</th></tr></thead>
    <tbody>
        <tr>
            <td><code>/admin/*</code></td>
            <td>Platform-admin surface. Tenant-aware data.</td>
        </tr>
        <tr>
            <td><code>/app/*</code></td>
            <td>Workspace dashboard. Auth-required, workspace-scoped.</td>
        </tr>
        <tr>
            <td><code>/api/*</code></td>
            <td>API surface — JSON, not for crawlers.</td>
        </tr>
        <tr>
            <td><code>/login</code>, <code>/register</code>, password reset</td>
            <td>Auth flows — no SEO value, nothing for a crawler to index.</td>
        </tr>
        <tr>
            <td><code>/settings/*</code></td>
            <td>Auth-required user / platform settings.</td>
        </tr>
    </tbody>
</table>
