<p>
    Pitchbar's admin and marketing pages render through Inertia v3 + React 19.
    By default Inertia ships the page state as a JSON payload inside a
    <code>&lt;script data-page="app"&gt;</code> tag; React then hydrates that into
    DOM on first paint. Crawlers (Pitchbar's own <code>CrawlPageJob</code>,
    Google, Bing, social-card scrapers) don't execute JS — they see the empty
    shell and bail at the
    <a href="https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script">script</a>
    tag.
</p>

<p>
    Server-side rendering (SSR) fixes this by pre-rendering each Inertia page
    in a Node process before Laravel returns the HTML. Crawlers, SEO bots,
    and the first-paint of slow connections all see fully-rendered markup.
</p>

<h2>How it's wired</h2>

<ul>
    <li><code>resources/js/ssr.tsx</code> — Vite SSR entry. Mirrors
        <code>resources/js/app.tsx</code> but renders via
        <code>react-dom/server</code> instead of <code>react-dom/client</code>.</li>
    <li><code>bootstrap/ssr/ssr.js</code> — built artifact, <strong>committed
        to the repo</strong> alongside <code>public/build/*</code> so the deploy
        host never has to run <code>npm run build:ssr</code>.</li>
    <li><code>config/inertia.php</code> — <code>ssr.enabled = true</code>,
        <code>ssr.url = http://127.0.0.1:13714</code>. Octane / FrankenPHP
        proxies each Inertia response through this URL.</li>
    <li><code>ecosystem.config.cjs</code> — declares the
        <code>pitchbar-ssr</code> PM2 process alongside
        <code>pitchbar-queue</code>.</li>
</ul>

<h2>One-time PM2 setup (per server, ever)</h2>

<p>
    Pitchbar already uses PM2 for the queue worker. Add the SSR process to the
    same daemon:
</p>

<pre><code>cd /var/www/html
pm2 reload ecosystem.config.cjs
pm2 save</code></pre>

<p>
    <code>pm2 reload</code> picks up the new <code>pitchbar-ssr</code> entry
    and starts it. <code>pm2 save</code> snapshots the process list so PM2
    restores it on server reboot (assuming <code>pm2 startup</code> was run
    when the queue worker was first installed — if not, run that once too).
</p>

<p>
    Verify the process is running:
</p>

<pre><code>pm2 status pitchbar-ssr
# expect: status=online, watching=enabled
curl -s http://127.0.0.1:13714/health
# expect: HTTP 200</code></pre>

<h2>Every future deploy</h2>

<p>
    <strong>Just <code>git pull</code>. Nothing else.</strong>
</p>

<p>
    The PM2 entry watches <code>bootstrap/ssr/ssr.js</code> for mtime changes.
    When <code>git pull</code> replaces the committed bundle, PM2 restarts the
    Node process automatically. No <code>pm2 reload</code>, no manual restart,
    no SSH-and-touch-something step.
</p>

<h2>How to verify SSR is actually rendering pages</h2>

<pre><code>curl -sk -A "Mozilla/5.0" https://YOUR-DOMAIN/integrations \
    | grep -oE 'data-server-rendered'</code></pre>

<p>
    If you see <code>data-server-rendered</code> printed, SSR is working —
    Inertia stamps that attribute on the root <code>&lt;div&gt;</code> only
    when the Node renderer answered successfully. If you see nothing, the
    Node process either isn't running or is crashing per request — check
    <code>storage/logs/pm2-ssr-error.log</code>.
</p>

<h2>Graceful fallback when SSR is down</h2>

<p>
    If <code>pitchbar-ssr</code> stops responding (Node process died, port
    13714 closed), Inertia falls back to client-side rendering automatically.
    Marketing pages will still load for browsers — but crawlers and social
    bots will go back to seeing the JS shell. PM2's <code>autorestart</code>
    + <code>max_restarts: 50</code> recovers from process-level crashes
    without intervention. If the process refuses to start at all, the
    error log is the first place to look.
</p>

<h2>When NOT to commit a stale bundle</h2>

<p>
    Whenever you edit a file under <code>resources/js/</code>,
    <code>resources/css/</code>, or any Inertia page, rebuild and re-stage
    the SSR bundle in the same commit:
</p>

<pre><code>npm run build
npm run build:ssr
git add public/build bootstrap/ssr resources/</code></pre>

<p>
    Forgetting this means the client-side bundle has new code but the SSR
    bundle still serves the old version — first-paint markup diverges from
    the eventual hydrated DOM and React 19 will throw a hydration mismatch
    warning. The
    <a href="../architecture">Architecture</a> page has more on the
    repo-as-deploy-artifact rule.
</p>

<h2>Local development</h2>

<p>
    During <code>npm run dev</code>, the Inertia Vite plugin handles SSR
    inline without a separate Node process. PM2 is for production only.
    To smoke-test SSR locally against the production-style bundle:
</p>

<pre><code>npm run build:ssr
node bootstrap/ssr/ssr.js &
curl -sk https://pitchbar.test/integrations | grep data-server-rendered</code></pre>
