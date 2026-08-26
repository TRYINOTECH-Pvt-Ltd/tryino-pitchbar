<p>
    Pitchbar can run under a subfolder of your domain rather than a
    dedicated subdomain — useful when you're sharing a domain with
    other services (marketing site at the root, Pitchbar at
    <code>/app</code>, status page at <code>/status</code>). Laravel
    + Vite handle this with config only; no code changes.
</p>

<p>
    The walkthrough below assumes you want Pitchbar at
    <code>https://aichat.com/app</code>. Substitute your own host +
    path everywhere you see those values.
</p>

<h2>1. Set <code>APP_URL</code></h2>

<p>
    Open <code>.env</code> on the production server. Set:
</p>

<pre><code>APP_URL=https://aichat.com/app

# Session + Sanctum need the host (without the path).
SESSION_DOMAIN=aichat.com
SANCTUM_STATEFUL_DOMAINS=aichat.com

# Reverb websocket origin — same host:port as APP_URL.
REVERB_HOST=aichat.com
REVERB_PORT=443
REVERB_SCHEME=https</code></pre>

<p>
    Run <code>php artisan config:clear</code> + <code>php artisan
    route:clear</code> after editing.
</p>

<h2>2. Point the docroot at <code>public/</code></h2>

<p>
    The Pitchbar repo is a Laravel application; the public docroot
    must be <code>public/</code>, not the repo root. With a subfolder
    install your web server needs to map the subpath to that
    directory.
</p>

<h3>Apache (.htaccess)</h3>

<pre><code># /var/www/aichat.com/app -> Pitchbar repo
# /var/www/aichat.com/app/public is the docroot for /app.
Alias /app /var/www/aichat.com/pitchbar/public

&lt;Directory /var/www/aichat.com/pitchbar/public&gt;
    AllowOverride All
    Require all granted
&lt;/Directory&gt;</code></pre>

<h3>Nginx</h3>

<pre><code>server {
    listen 443 ssl http2;
    server_name aichat.com;
    root /var/www/aichat.com/marketing-site;  # your root site

    # Pitchbar subfolder.
    location ^~ /app/ {
        alias /var/www/aichat.com/pitchbar/public/;
        try_files $uri $uri/ @pitchbar;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $request_filename;
            fastcgi_param SCRIPT_NAME    /app/index.php;
            fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        }
    }

    location @pitchbar {
        rewrite ^/app(/.*)$ /app/index.php?$1 last;
    }
}</code></pre>

<h2>3. Vite assets</h2>

<p>
    Vite reads <code>APP_URL</code> at build time to write the
    correct manifest paths, so the admin SPA + widget bundle resolve
    under the subfolder automatically. After deploy run:
</p>

<pre><code>npm ci
npm run build
npm run build:widget</code></pre>

<p>
    Check <code>public/build/manifest.json</code> — the entries
    should resolve under <code>/app/build/...</code> when served.
</p>

<h2>4. Storage symlink</h2>

<pre><code>php artisan storage:link</code></pre>

<p>
    Creates <code>public/storage</code> → <code>storage/app/public</code>.
    Uploaded branding logos, public exports, and avatar files land
    there; without the symlink they 404.
</p>

<h2>5. Widget embed snippet</h2>

<p>
    Customers embed the widget via a <code>&lt;script&gt;</code> tag
    on their own site. The URL must point at your subfolder:
</p>

<pre><code>&lt;script
    src="https://aichat.com/app/widget/widget.js"
    data-agent-id="agent_..."
    defer
&gt;&lt;/script&gt;</code></pre>

<p>
    The admin's "Copy embed snippet" button on
    <code>/app/agents/{id}</code> derives the URL from
    <code>APP_URL</code>, so as long as step 1 is correct the snippet
    your customers copy will already include the subfolder.
</p>

<h2>6. Queue worker + scheduler</h2>

<p>
    Both still run from the repo root, unaffected by the subfolder:
</p>

<pre><code>php artisan queue:work
php artisan schedule:work</code></pre>

<p>
    The Cloudflare Worker cron driver (production default) hits
    <code>https://aichat.com/app/api/v1/internal/queue-tick</code> —
    update the worker's <code>QUEUE_TICK_URL</code> binding accordingly
    when you deploy it from <code>/admin/integrations/cron-worker</code>.
</p>

<h2>Troubleshooting</h2>

<h3>Admin SPA 404s on every route except <code>/app</code></h3>

<p>
    The web-server rewrite isn't sending Laravel's URLs back to
    <code>index.php</code>. Confirm the Apache <code>.htaccess</code>
    inside <code>public/</code> is being read (Apache:
    <code>AllowOverride All</code>) or the Nginx
    <code>@pitchbar</code> fallback fires (Nginx:
    <code>try_files</code> ordering).
</p>

<h3>Widget bundle 404</h3>

<p>
    The widget post-build emits a hashed filename
    (<code>widget.&lt;hash&gt;.js</code>). The
    <code>data-agent-id</code> snippet points at the unhashed
    <code>widget.js</code> which is also published. If that 404s,
    you skipped <code>npm run build:widget</code> after deploy.
</p>

<h3>Session cookie not setting on subdomain hosts</h3>

<p>
    If you serve the marketing site at <code>aichat.com</code> and
    Pitchbar at <code>aichat.com/app</code>, both share the same
    cookie host but different paths. Set <code>SESSION_PATH=/app</code>
    in <code>.env</code> so the Laravel session cookie is scoped to
    the subfolder — without this the cookie set by Pitchbar may be
    overwritten by the parent site's session library.
</p>

<h3>CSP / mixed content</h3>

<p>
    The default CSP in <code>AddSecurityHeaders</code> middleware
    allows <code>self</code> only for scripts. If you host static
    assets on a CDN, add the CDN host to <code>script-src</code>
    via the <code>app_security.csp_extra_script_src</code> config
    key.
</p>

<h3>Reverb websocket fails to upgrade</h3>

<p>
    Reverb listens on its own port (default 8080). Your reverse
    proxy must proxy the WebSocket upgrade — for Nginx that's:
</p>

<pre><code>location /reverb/ {
    proxy_pass http://127.0.0.1:8080/;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
}</code></pre>

<h2>What this does NOT change</h2>

<ul>
    <li>Webhook endpoints (Stripe, PayPal, Razorpay, WordPress plugin)
        derive their URL from <code>APP_URL</code>, so they
        automatically include the subfolder. Re-register them in the
        respective dashboards if you migrated from a different host.</li>
    <li>The widget JWT still binds to <code>allowed_origins</code> on
        the agent — that's the origin of the customer's site, NOT
        your Pitchbar host. Subfolder install doesn't affect which
        sites can embed.</li>
    <li>Multi-tenant scoping, billing gates, BYOK resolution, and
        SSE hot-path latency are all unaffected.</li>
</ul>
