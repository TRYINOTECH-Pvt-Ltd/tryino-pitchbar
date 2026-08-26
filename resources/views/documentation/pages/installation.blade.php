<p>
    This guide walks you from a fresh server to a running Pitchbar
    install with one admin account, one published agent, and the
    widget answering on a test page. Expect 20–40 minutes if your
    server is already provisioned with PHP, Node, a database, and
    Redis.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">Two paths to Pitchbar</div>
        Hosted Pitchbar customers don't need this page — sign up at the
        marketing site and follow the <a href="/documentation/quickstart">Quickstart</a>.
        This page is for buyers running Pitchbar on their own server
        (CodeCanyon Regular / Extended license) or self-host operators
        deploying from source.
    </div>
</div>

<h2>1. Server requirements</h2>

<table>
    <thead><tr><th>Component</th><th>Minimum</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td>PHP</td><td>8.3+</td><td>8.4 recommended. Extensions: <code>bcmath</code>, <code>curl</code>, <code>fileinfo</code>, <code>gd</code>, <code>intl</code>, <code>mbstring</code>, <code>openssl</code>, <code>pdo_pgsql</code> (or <code>pdo_mysql</code>), <code>tokenizer</code>, <code>xml</code>, <code>zip</code>.</td></tr>
        <tr><td>Composer</td><td>2.6+</td><td>Used to install PHP dependencies.</td></tr>
        <tr><td>Node.js</td><td>20+</td><td>Used to build the admin SPA and the visitor widget.</td></tr>
        <tr><td>Database</td><td>PostgreSQL 14+ <em>or</em> MySQL 8.0+</td><td>Postgres is the primary target.</td></tr>
        <tr><td>Redis</td><td>7+</td><td>Cache, sessions, queue, hot-path retrieval cache.</td></tr>
        <tr><td>RAM / CPU</td><td>2 vCPU / 2 GB</td><td>One app + one worker process. Scale up if you'll run them on the same box.</td></tr>
        <tr><td>Disk</td><td>10 GB+</td><td>Application + log volume. Vector store sits in Cloudflare / Qdrant, not on disk.</td></tr>
        <tr><td>TLS</td><td>HTTPS</td><td>The widget requires an HTTPS origin to load on customer sites. Use Caddy / Nginx / Cloudflare in front of FrankenPHP.</td></tr>
        <tr><td>SMTP</td><td>any provider</td><td>Postmark / Resend / SES / your own SMTP. Required for password reset, lead notifications, billing receipts.</td></tr>
    </tbody>
</table>

<h3>External accounts you'll need</h3>

<ul>
    <li>
        <strong>At least one LLM provider</strong> —
        <a href="https://dash.cloudflare.com">Cloudflare Workers AI</a> is
        the cheapest and what we recommend (chat + embeddings + vector DB
        + browser crawler all on one bill). <a href="https://platform.openai.com">OpenAI</a>
        works as a drop-in. <a href="https://openrouter.ai">OpenRouter</a>
        works too and exposes a free Llama 3.3 model.
    </li>
    <li>
        <strong>A vector store</strong> — Cloudflare Vectorize (preferred,
        shares the Cloudflare account) or a self-hosted Qdrant instance.
    </li>
    <li>
        <strong>Optional: Stripe</strong> for billing customers, and
        <strong>Sentry / Honeycomb</strong> for error / trace reporting.
    </li>
</ul>

<h2>2. Get the code onto the server</h2>

<p>
    Upload the source bundle you downloaded (CodeCanyon zip), or clone
    your private repo, into the document root. Everything in this guide
    assumes you're inside the project directory.
</p>

<pre><code>cd /var/www/pitchbar          # or wherever you unpacked the zip
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate</code></pre>

<p>
    <code>php artisan key:generate</code> writes a fresh
    <code>APP_KEY</code> to <code>.env</code>. <strong>Back this value
    up the moment you generate it</strong> — every encrypted column
    in <code>app_settings</code> (Stripe / Cloudflare / OpenAI keys you'll
    paste in step 7) is sealed with this key. Losing it means losing
    those secrets.
</p>

<h2>3. Configure the database connection</h2>

<p>
    Edit <code>.env</code> and fill the database block. The defaults
    point at a local Docker Postgres; swap to your actual host.
</p>

<pre><code>DB_CONNECTION=pgsql            # or mysql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pitchbar
DB_USERNAME=pitchbar
DB_PASSWORD=…strong-password…</code></pre>

<p>
    Create the database first if it doesn't exist:
</p>

<pre><code>createdb -U postgres pitchbar
# or, MySQL:
mysql -uroot -p -e "CREATE DATABASE pitchbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"</code></pre>

<h2>4. Fill the rest of <code>.env</code></h2>

<p>
    Every key you can flip via the admin UI later — Stripe, PayPal,
    Razorpay, Cloudflare, OpenAI, OpenRouter, mail, branding —
    <em>can</em> be left blank in <code>.env</code> and pasted in the
    web admin instead. The keys below are the ones the app needs at
    boot, before you can open the admin.
</p>

<table>
    <thead><tr><th>Variable</th><th>Value</th></tr></thead>
    <tbody>
        <tr><td><code>APP_URL</code></td><td>The public HTTPS URL you'll serve the app from, e.g. <code>https://app.example.com</code>. Used to build widget snippets, OAuth callbacks, and signed URLs.</td></tr>
        <tr><td><code>APP_NAME</code></td><td>Display name shown in the title bar and emails.</td></tr>
        <tr><td><code>APP_ENV</code></td><td><code>production</code>.</td></tr>
        <tr><td><code>APP_DEBUG</code></td><td><code>false</code>.</td></tr>
        <tr><td><code>REDIS_HOST</code> / <code>REDIS_PORT</code> / <code>REDIS_PASSWORD</code></td><td>Redis connection.</td></tr>
        <tr><td><code>SESSION_DRIVER</code> / <code>CACHE_STORE</code> / <code>QUEUE_CONNECTION</code></td><td>All <code>redis</code> in production.</td></tr>
        <tr><td><code>WIDGET_JWT_SECRET</code></td><td>The HS256 signing secret for visitor session JWTs. Generate <code>openssl rand -hex 32</code> and paste the result. <strong>Do not leave at the default.</strong></td></tr>
        <tr><td><code>BROADCAST_CONNECTION</code></td><td><code>reverb</code> if you want the realtime inbox + live-chat handoff. Set to <code>null</code> to disable.</td></tr>
        <tr><td><code>REVERB_APP_ID</code> / <code>REVERB_APP_KEY</code> / <code>REVERB_APP_SECRET</code></td><td>Random tokens identifying the Reverb app. Generate fresh strings.</td></tr>
        <tr><td><code>REVERB_HOST</code></td><td>Public hostname for the WebSocket process — same domain as <code>APP_URL</code> if you reverse-proxy WS on the same host.</td></tr>
        <tr><td><code>REVERB_SCHEME</code></td><td><code>wss</code> in production.</td></tr>
        <tr><td><code>MAIL_FROM_ADDRESS</code> / <code>MAIL_FROM_NAME</code></td><td>Sender identity for outgoing email. Required.</td></tr>
    </tbody>
</table>

<p>
    Full reference for every variable lives at <a href="/documentation/env">Environment variables</a>.
</p>

<h3>LLM provider keys (you can also paste these in the admin later)</h3>

<p>
    Set at least one of these so a freshly created agent can answer.
    The auto-binder picks Cloudflare → OpenRouter → OpenAI, in that
    order, based on which keys are present.
</p>

<pre><code># Cloudflare Workers AI (preferred)
CLOUDFLARE_ACCOUNT_ID=
CLOUDFLARE_API_TOKEN=
CLOUDFLARE_VECTORIZE_INDEX=pitchbar-chunks

# or OpenAI
OPENAI_API_KEY=

# or OpenRouter (free Llama 3.3 model available)
OPENROUTER_API_KEY=
LLM_PROVIDER=openrouter        # required to opt into OpenRouter</code></pre>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">Cloudflare Vectorize provisioning lag</div>
        Newly created Vectorize indexes take ~2 minutes before queries
        return results. Upserts succeed immediately; reads return 0
        until the index is fully provisioned. <code>VectorizeClient::ensureCollection</code>
        is idempotent, so re-running install steps is safe.
    </div>
</div>

<h2>5. Run the migrations and seed the plans</h2>

<pre><code>php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force</code></pre>

<p>
    <code>PlanSeeder</code> creates four plan rows the billing system
    reads — <code>free</code>, <code>standard</code>, <code>pro</code>,
    and <code>custom</code> (enterprise / contact-sales placeholder).
    It is idempotent — re-running it won't duplicate plans. Edit
    pricing, conversation caps, and Stripe price IDs in the admin at
    <code>/admin/plans</code> after seeding.
</p>

<p>
    <strong>Skip <code>UserSeeder</code> in production.</strong> It
    creates the demo accounts <code>admin@mail.com</code> /
    <code>customer@mail.com</code> with the public password
    <code>password</code> — fine for local dev, an open door on a
    public deployment.
</p>

<h2>6. Build the frontend bundles</h2>

<pre><code>npm ci
npm run build              # admin Inertia SPA → public/build/
npm run build:widget       # visitor widget → public/widget/widget.js
php artisan storage:link   # symlinks public/storage → storage/app/public
php artisan optimize       # caches routes, config, views</code></pre>

<p>
    Both build outputs are committed alongside source in our deploy
    artifact (the CodeCanyon zip includes them pre-built), but
    re-running on the server guarantees the bundle matches the PHP
    version of the code you uploaded.
</p>

<h2>7. Serve the app and run the workers</h2>

<p>
    Pitchbar runs on <strong>Laravel Octane + FrankenPHP</strong> for
    the HTTP server, <strong>Horizon</strong> for the queue, and
    <strong>Reverb</strong> for the WebSocket realtime channel. All
    three need to be supervised processes; here's the minimum shape
    for a single-host install using systemd.
</p>

<h3>App server</h3>

<pre><code># /etc/systemd/system/pitchbar-app.service
[Unit]
Description=Pitchbar Octane (FrankenPHP)
After=network.target redis.service postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/pitchbar
ExecStart=/usr/bin/php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=4
Restart=always

[Install]
WantedBy=multi-user.target</code></pre>

<p>
    Put your TLS terminator (Caddy, Nginx, Cloudflare proxy) in front,
    pointed at <code>127.0.0.1:8000</code>. The reverse proxy is what
    serves <code>https://app.example.com</code> to the public; the
    Octane process only binds to localhost.
</p>

<h3>Queue worker</h3>

<pre><code># /etc/systemd/system/pitchbar-horizon.service
[Unit]
Description=Pitchbar Horizon queue worker
After=network.target redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/pitchbar
ExecStart=/usr/bin/php artisan horizon
Restart=always

[Install]
WantedBy=multi-user.target</code></pre>

<p>
    Horizon supervises crawl / index / default queues by default.
    Check the queue health from the platform admin at
    <code>/admin/queue-health</code>.
</p>

<h3>WebSocket process</h3>

<pre><code># /etc/systemd/system/pitchbar-reverb.service
[Unit]
Description=Pitchbar Reverb WebSocket server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/pitchbar
ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always

[Install]
WantedBy=multi-user.target</code></pre>

<p>
    Reverse-proxy <code>wss://realtime.example.com</code> (or the same
    domain on a different path) to <code>127.0.0.1:8080</code>. Skip
    this process if you set <code>BROADCAST_CONNECTION=null</code> —
    you'll lose the live inbox and human takeover features.
</p>

<h3>Enable and start everything</h3>

<pre><code>sudo systemctl daemon-reload
sudo systemctl enable --now pitchbar-app pitchbar-horizon pitchbar-reverb</code></pre>

<h2>8. Wire up the cron scheduler</h2>

<p>
    Several jobs run on a schedule — refresh stale crawls, sync OAuth
    sources, release stale "needs human" conversations, suggest curated
    answers from gaps. Pick one of the two options below.
</p>

<h3>Option A — host cron (simplest)</h3>

<p>
    Add a single line to <code>root</code>'s crontab (or the user that
    owns the project files):
</p>

<pre><code>* * * * * cd /var/www/pitchbar && php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></pre>

<h3>Option B — Cloudflare Cron Worker</h3>

<p>
    Pitchbar can deploy a Cloudflare Worker that hits your install's
    <code>/api/v1/internal/queue-tick</code> endpoint every minute, so you
    don't need a host cron at all. Useful for serverless deploys where
    no process can run periodically.
</p>

<p>
    After you've pasted your Cloudflare account ID and API token into
    <strong>Settings → System</strong> (next step), open
    <strong>Settings → System → Cron worker</strong> and click
    <em>Deploy</em>. Status is reported at
    <code>/settings/system/cron-worker/status</code>.
</p>

<h2>9. Create your first admin</h2>

<p>
    Sign up the normal way at <code>{APP_URL}/register</code>. Pitchbar
    auto-creates the first workspace for you. Then promote your account
    to <strong>super_admin</strong> from the command line so you can
    reach the platform admin and paste system keys.
</p>

<pre><code>php artisan pitchbar:make-admin you@example.com</code></pre>

<p>
    Log out and back in; <code>/admin</code> and <strong>Settings →
    System</strong> are now visible in the sidebar.
</p>

<h2>10. Drop in system keys via the admin (recommended)</h2>

<p>
    Open <strong>Settings → System</strong> as the super_admin. Paste:
</p>

<ul>
    <li><strong>Cloudflare</strong> account ID + API token + Vectorize index name + AI Gateway URL (optional).</li>
    <li><strong>OpenAI</strong> key (optional fallback).</li>
    <li><strong>OpenRouter</strong> key (optional, free Llama 3.3 model).</li>
    <li><strong>Stripe</strong> publishable + secret + webhook signing secret (optional, for billing).</li>
    <li><strong>PayPal</strong> / <strong>Razorpay</strong> if you want extra payment gateways.</li>
    <li><strong>Mail</strong> SMTP / API credentials.</li>
    <li><strong>Branding</strong> — replace "Powered by Pitchbar" with your own label, footer logo, and link target.</li>
</ul>

<p>
    Each section has a <strong>Test</strong> button that talks to the
    upstream API with the key you just pasted —
    <code>Test mail</code> sends a real email,
    <code>Test LLM</code> calls a small chat completion,
    <code>Test Stripe</code> hits the Stripe API root, etc. Use these
    before saving so you catch a wrong key immediately instead of at
    the first customer signup.
</p>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <code>APP_KEY</code> rotation requires a manual migration. The
        encrypted columns in <code>app_settings</code> are sealed with
        the value of <code>APP_KEY</code> at the time you pasted them;
        rotating without re-encrypting renders them unreadable and
        you'll have to paste every key again.
    </div>
</div>

<h2>11. Smoke test the install</h2>

<ol>
    <li>Visit <code>{APP_URL}/admin</code> and confirm the platform dashboard renders without red banners.</li>
    <li>From <strong>Settings → System</strong>, click each <strong>Test</strong> button (mail, LLM, Stripe). Each should report success.</li>
    <li>From your workspace, run the <a href="/documentation/quickstart">Quickstart</a>: create an agent, add one knowledge source from your own site, watch it flip to <em>indexed</em>, publish the agent, paste the embed snippet on a test page.</li>
    <li>Open your test page in an incognito window. Ask the agent a question that should be answered from the page you indexed. You should see streaming tokens and a citation appear within ~1 second.</li>
    <li>Check <code>/admin/queue-health</code> — crawl + index queues should be draining, no failed jobs.</li>
</ol>

<h2>Troubleshooting</h2>

<table>
    <thead><tr><th>Symptom</th><th>Fix</th></tr></thead>
    <tbody>
        <tr>
            <td><code>Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest</code></td>
            <td>You skipped <code>npm run build</code> or pulled changes to <code>resources/js/</code> without rebuilding. Run <code>npm run build</code> + <code>npm run build:widget</code>.</td>
        </tr>
        <tr>
            <td>Agent answers "I don't have enough information" even with sources indexed</td>
            <td>Likely a confidence threshold mismatch. Cloudflare's bge-base-en-v1.5 peaks at 0.55–0.65, OpenAI peaks higher. Open the agent's <strong>Advanced</strong> tab and lower <code>confidence_threshold</code> to <code>0.5</code> for Cloudflare-backed installs. New agents get this default automatically.</td>
        </tr>
        <tr>
            <td>Widget script loads but never opens on customer sites</td>
            <td>Check <strong>Agent → Settings → Allowed origins</strong>. Each entry is strict-matched against the page's <code>Origin</code> header; an empty list means deny-everywhere. See <a href="/documentation/allowed-origins">Allowed origins</a>.</td>
        </tr>
        <tr>
            <td>Queue not draining; <code>/admin/queue-health</code> shows growing depth</td>
            <td>Horizon process isn't running or isn't subscribed to the right connection. <code>sudo systemctl status pitchbar-horizon</code> → check it's <em>active (running)</em>; <code>QUEUE_CONNECTION</code> in <code>.env</code> should be <code>redis</code>.</td>
        </tr>
        <tr>
            <td>Live inbox doesn't update in real time</td>
            <td>Reverb process isn't running or the WS reverse-proxy isn't wired. Check <code>REVERB_HOST</code> + <code>REVERB_PORT</code> match what your reverse proxy forwards; in the browser console, you should see a successful <code>wss://…</code> upgrade.</td>
        </tr>
        <tr>
            <td><code>"Crawl failed: …"</code> on every source</td>
            <td>Probably no LLM provider configured. <strong>Settings → System → Cloudflare</strong> + click <strong>Test LLM</strong>. The error column on the source is sanitized for customers; super_admins see the raw upstream message on the source detail page.</td>
        </tr>
        <tr>
            <td><code>Failed to load PostCSS config</code> during <code>npm run build</code></td>
            <td>You probably ran <code>npm install --production</code>. The build needs the dev dependencies — re-run <code>npm ci</code> with the default flags.</td>
        </tr>
    </tbody>
</table>

<h2>What's next?</h2>

<div class="docs-cards">
    <a class="docs-card" href="/documentation/quickstart">
        <div class="docs-card-title">Create your first agent</div>
        <div class="docs-card-body">5-minute walk-through, hosted-side workflow that works the same on a self-host.</div>
    </a>
    <a class="docs-card" href="/documentation/env">
        <div class="docs-card-title">Environment variables</div>
        <div class="docs-card-body">Full reference for every <code>.env</code> key — required, optional, and platform-overridable.</div>
    </a>
    <a class="docs-card" href="/documentation/deployment">
        <div class="docs-card-title">Deployment</div>
        <div class="docs-card-body">Sizing, CI/CD, backups, rollback — the operator playbook for the install you just stood up.</div>
    </a>
    <a class="docs-card" href="/documentation/observability">
        <div class="docs-card-title">Observability</div>
        <div class="docs-card-body">Wire up Sentry + OpenTelemetry so you find problems before customers do.</div>
    </a>
</div>
