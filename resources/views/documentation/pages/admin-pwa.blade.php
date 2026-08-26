<p>
    Pitchbar ships as a Progressive Web App so admins can install the
    dashboard to their home screen and run it like a native app. The
    install prompt appears automatically in supported browsers
    (Chrome, Edge, Safari iOS 16.4+, Brave) once the page meets the
    install criteria — usually after a few seconds on the dashboard.
</p>

<h2>Manifest endpoint</h2>

<p>
    The Web App Manifest is served dynamically at
    <code>/manifest.webmanifest</code> by
    <code>App\Http\Controllers\PwaManifestController</code> so the
    white-label cascade (site title, favicon) flows into the install
    prompt — operators rebranding the site never ship "Pitchbar" to
    home-screen labels.
</p>

<h2>start_url is <code>/dashboard</code></h2>

<p>
    The installed app opens to <code>/dashboard</code>:
</p>

<ul>
    <li><strong>Unauthenticated installers</strong> — Fortify redirects to <code>/login</code>.</li>
    <li><strong>Authed admin with no workspace yet</strong> — the dashboard renders an empty state.</li>
    <li><strong>Authed admin with a workspace</strong> — Inertia renders the live dashboard.</li>
</ul>

<p>
    Client report 2026-05-25: <code>start_url</code> was previously
    <code>/app</code>, which has no bare route. Installed PWAs opened
    to a 404 and the service worker cached the 404 shell until the
    next SW version bump. Fixed by repointing to
    <code>/dashboard</code> and rolling the SW <code>VERSION</code>
    constant in <code>public/sw.js</code>.
</p>

<h2>App icon comes from Settings → Branding → Favicon</h2>

<p>
    When an operator uploads a favicon under
    <strong>/settings/branding</strong>, the manifest prepends it as
    a generic <code>any</code>-sized icon so the install prompt uses
    that brand mark. The bundled <code>icon-192.png</code> and
    <code>icon-512.png</code> placeholders stay behind it so installers
    that demand exact PNG sizes still match.
</p>

<p>
    User-uploaded favicons are NOT tagged <code>purpose=maskable</code>
    — they aren't safe-zone designed, so Android's adaptive-icon mask
    would crop the corners badly. The bundled placeholders are
    purpose-built for maskable use and stay tagged that way.
</p>

<h2>Bumping the service worker version</h2>

<p>
    <code>public/sw.js</code> has a top-level <code>VERSION</code>
    constant (e.g. <code>pitchbar-shell-v2</code>). When you change
    anything about the install behaviour or shell caching, bump the
    suffix — the <code>activate</code> handler deletes every cache
    that doesn't start with the new VERSION, so existing installs
    purge stale assets on next visit.
</p>

<h2>Cache strategy</h2>

<ul>
    <li><strong>Shell HTML</strong> (Inertia routes) — network-first with a 4-second timeout, falling back to last-known cache so a flaky-mobile-data operator still sees something.</li>
    <li><strong>Vite bundles</strong> under <code>/build/*</code> — cache-first (URLs are content-hashed; hash rolls every deploy).</li>
    <li><strong>Icons / manifest</strong> — cache-first.</li>
    <li><strong>Mutation endpoints</strong> (POST/PATCH/DELETE) and <code>/api/v1/widget/*</code> — network-only, never cached.</li>
</ul>
