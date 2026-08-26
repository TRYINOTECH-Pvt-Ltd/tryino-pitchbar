<p>
    Pitchbar is built for Laravel Cloud as the primary target — the stack
    is FrankenPHP + Postgres + Redis, all of which Laravel Cloud provisions
    natively. Self-hosting is supported but the operator owns more pieces.
</p>

<h2>Laravel Cloud</h2>

<p>
    <code>infra/cloud.yaml</code> in the repo describes the environments
    and processes. The high-level shape:
</p>

<ul>
    <li><strong>Region:</strong> <code>us-east</code> by default. Choose for proximity to your customers.</li>
    <li><strong>App process:</strong> FrankenPHP, Octane mode. Auto-scaled.</li>
    <li><strong>Worker process:</strong> Horizon, dedicated to <code>crawl</code>, <code>index</code>, and <code>default</code> queues.</li>
    <li><strong>Reverb process:</strong> persistent WebSocket server.</li>
    <li><strong>Postgres:</strong> 16, with daily backups.</li>
    <li><strong>Redis:</strong> 7, persistent.</li>
</ul>

<h2>Environments</h2>

<table>
    <thead><tr><th>Env</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td>preview</td><td>Per-PR ephemeral environments. Auto-spun on PR open, torn down on merge / close.</td></tr>
        <tr><td>staging</td><td>Long-lived. Mirrors production config. Used for QA and pre-release verification.</td></tr>
        <tr><td>production</td><td>The customer-facing environment. Releases gated on green CI + manual deploy.</td></tr>
    </tbody>
</table>

<h2>Compute sizing for v1 launch</h2>

<p>
    Starting point. Adjust based on traffic.
</p>

<table>
    <thead><tr><th>Component</th><th>Size</th><th>Why</th></tr></thead>
    <tbody>
        <tr><td>App (Octane)</td><td>2 instances × 2 vCPU / 2 GB</td><td>Hot path is mostly I/O-bound on LLM streaming. Two instances for HA.</td></tr>
        <tr><td>Worker (Horizon)</td><td>2 instances × 2 vCPU / 2 GB</td><td>Indexing throughput. Scale on queue depth.</td></tr>
        <tr><td>Reverb</td><td>1 instance × 1 vCPU / 1 GB</td><td>WebSocket, sticky.</td></tr>
        <tr><td>Postgres</td><td>2 vCPU / 4 GB / 50 GB SSD</td><td>Comfortable until ~10M messages.</td></tr>
        <tr><td>Redis</td><td>1 GB</td><td>Sessions, queue, hot caches.</td></tr>
    </tbody>
</table>

<h2>Domains</h2>

<p>
    You typically need:
</p>

<ul>
    <li><strong>Primary domain</strong> — <code>app.pitchbar.com</code> for the customer / admin app.</li>
    <li><strong>Widget domain</strong> — same or a separate <code>cdn.pitchbar.com</code> serving <code>/widget/widget.js</code>. The bundle has a content-hash query param, so aggressive caching is safe.</li>
    <li><strong>Reverb domain</strong> — <code>realtime.pitchbar.com</code> if you split the WebSocket process onto its own host.</li>
</ul>

<h2>CI/CD</h2>

<p>
    GitHub Actions workflows under <code>.github/workflows/</code>:
</p>

<ul>
    <li><code>tests.yml</code> — PHP setup + Composer + Pest suite (with fakes, no network).</li>
    <li><code>lint.yml</code> — Pint, ESLint, TypeScript <code>tsc --noEmit</code>.</li>
    <li><code>widget.yml</code> — widget bundle build + size budget check.</li>
</ul>

<p>
    Deploys are gated on green CI; the actual deploy step is configured
    on Laravel Cloud (or your hosting equivalent), not in the workflow
    files.
</p>

<h2>Migrations</h2>

<p>
    Laravel Cloud runs <code>php artisan migrate --force</code> on every
    deploy. Migrations should be backwards-compatible — a deploy that adds
    a NOT NULL column to a populated table needs a two-step:
</p>

<ol>
    <li>Deploy 1: add the column nullable, backfill, app code starts writing it.</li>
    <li>Deploy 2: change the column to NOT NULL.</li>
</ol>

<p>
    Same goes for renames and drops — never destructive in a single deploy.
</p>

<h2>Backups</h2>

<ul>
    <li><strong>Postgres</strong> — daily snapshots, retained 30 days. Point-in-time recovery enabled.</li>
    <li><strong>Vector store</strong> — Cloudflare Vectorize / Qdrant don't have a built-in backup; rebuild from the <code>chunks</code> table by re-dispatching <code>IndexDocumentJob</code> for every document. <code>php artisan pitchbar:audit-vectors</code> reports drift between the chunks table and the live vector store; if you need to repair, dispatch the job per row.</li>
    <li><strong>R2 / object storage</strong> — versioning enabled.</li>
    <li><strong>App secrets</strong> — Laravel Cloud's secret store is encrypted; back up <code>APP_KEY</code> separately (it's the master for app_settings encryption).</li>
</ul>

<h2>Rollback</h2>

<p>
    Laravel Cloud keeps the previous release for instant rollback. For
    schema-incompatible rollbacks (rare), restore from the latest snapshot.
</p>

<h2>Self-hosting</h2>

<p>
    The same Docker setup that powers <code>docker-compose.yml</code> works
    for production with a few additions:
</p>

<ul>
    <li>Reverse proxy (Caddy or Nginx) terminating TLS in front of FrankenPHP.</li>
    <li>Managed Postgres + Redis (or self-managed with HA replicas).</li>
    <li>Horizon as a long-running service, monitored by systemd / a process manager.</li>
    <li>Reverb as its own process.</li>
    <li>Sentry / OTEL collector running locally or pointing at a SaaS.</li>
</ul>

<p>
    The <code>composer run dev</code> shortcut starts everything locally
    (Octane, queue worker, Reverb, vite) for development.
</p>

<h3>php.ini is mandatory — FrankenPHP ships without one</h3>

<p>
    <strong>This section only applies when you serve with
    FrankenPHP/Octane.</strong> Apache, nginx&nbsp;+&nbsp;PHP-FPM,
    LiteSpeed, and <code>php artisan serve</code> all ignore the
    repository's <code>php.ini</code> and use your system's own PHP
    configuration — there, just make sure <code>memory_limit</code> has
    headroom and <code>log_errors</code> is on, and skip the rest of this
    section.
</p>

<p>
    The FrankenPHP static binary contains its own PHP (independent of any
    system PHP; responses carry that version in <code>X-Powered-By</code>)
    and it bundles <strong>no php.ini</strong>. With none present,
    <code>php_ini_loaded_file()</code> is empty and PHP runs on its
    compile-time defaults — two of which are actively dangerous under
    Octane:
</p>

<table>
    <thead><tr><th>Directive</th><th>Bare default</th><th>Why it bites</th></tr></thead>
    <tbody>
        <tr>
            <td><code>memory_limit</code></td>
            <td><code>128M</code></td>
            <td>Octane boots the app once and a worker accumulates memory across requests, settling near 105-125&nbsp;MB. Whichever worker crosses the ceiling dies mid-request.</td>
        </tr>
        <tr>
            <td><code>log_errors</code></td>
            <td><code>0</code></td>
            <td>Every fatal is silently discarded — nothing in <code>laravel.log</code>, nothing in the process manager's stdout/stderr logs, nothing anywhere.</td>
        </tr>
    </tbody>
</table>

<p>
    Together those two produce a failure that is almost impossible to
    trace: <code>HTTP 500</code> with a <strong>zero-byte body</strong>,
    answered in milliseconds, recovering with no intervention, and leaving
    no log line at all. The supervising process never restarts (only the
    worker thread dies), so the process table looks healthy too.
</p>

<p>
    The repository therefore ships a <code>php.ini</code> at the project
    root, which is where FrankenPHP looks. Verify a change
    <em>before</em> restarting anything:
</p>

<pre><code>./frankenphp php-cli -r 'echo php_ini_loaded_file(), PHP_EOL, ini_get("memory_limit"), PHP_EOL;'</code></pre>

<p>
    Fatals land in <code>storage/logs/php-fatal.log</code>. The PHP CLI
    does not search the working directory for a php.ini, so this file
    changes nothing for <code>php artisan</code>, Herd, or the test suite.
    <code>tests/Feature/Ops/PhpIniGuardTest.php</code> fails the build if
    the file is deleted or its directives are weakened — the regression is
    otherwise invisible, since removing it breaks no test and no healthy
    box.
</p>

<h3>Why a deprecation could take down a response</h3>

<p>
    Turning error logging on immediately exposed what the 500s actually
    were, and it was not memory. An exception escaping an Octane request
    makes Laravel render its 500 page; building that response constructs
    an <code>Illuminate\Http\Response</code>, whose constructor assigns
    <code>$headers</code> directly — which
    <code>symfony/http-foundation</code> 8.1 turned into a property hook
    that fires a deprecation on every assignment. Laravel then tries to
    log that deprecation against a container Octane has already flushed:
</p>

<pre><code>#11 HandleExceptions.php(105)  LogManager-&gt;channel('deprecations')
#5  Application-&gt;make('config')
#0  ReflectionException: Class "config" does not exist   -&gt; FATAL</code></pre>

<p>
    The result is a zero-byte 500 <em>and</em> the loss of the original
    exception, which is never rendered and never logged. Laravel's own
    three guards all miss it: <code>Application::flush()</code> does not
    reset <code>hasBeenBootstrapped</code>, and
    <code>make(LogManager::class)</code> succeeds by reflection because
    LogManager is a concrete class, so the surrounding try/catch never
    fires.
</p>

<p>
    <code>App\Support\DeprecationGuard</code> wraps the error handler and
    drops deprecations raised while <code>config</code> is unresolvable,
    delegating everything else untouched. It keys off container state
    rather than any package version, so a future Laravel or Symfony bump
    cannot reopen the hole. It is installed from
    <code>AppServiceProvider::boot()</code> — after
    <code>HandleExceptions</code> has registered its own handler during
    <code>bootstrapWith()</code> — and is idempotent, because PHP chains
    error handlers and Octane boots providers on every request.
</p>

<div class="callout callout-warning">
    <strong>Forward the delegated return value verbatim.</strong> PHP runs
    its own handler on top only when a handler returns exactly
    <code>false</code>, and Laravel's <code>handleError()</code> returns
    void. Casting the delegated result to <code>bool</code> turns every
    deprecation into a second write to <code>error_log</code>.
</div>

<div class="callout callout-warning">
    <strong><code>octane:reload</code> does not apply a php.ini change.</strong>
    The ini is read once, when the process starts. A reload swaps the
    application code inside the running FrankenPHP process and leaves the
    old ini in force, so the fix looks applied while nothing changed.
    Restart the Octane process itself (systemd / PM2 / supervisor).
</div>

<h2>Queue worker tick from a Cloudflare Worker cron</h2>

<p>
    When in-cluster scheduling isn't available (cPanel shared hosting,
    DIY VPS without systemd, Laravel Cloud's preview environments), an
    external Cloudflare Worker can drive the queue every 60 seconds by
    POSTing <code>/api/v1/internal/queue-tick</code> with the
    <code>INTERNAL_QUEUE_TOKEN</code> bearer secret. The endpoint
    invokes <code>php artisan queue:tick</code> which spawns one
    <code>queue:work --once --stop-when-empty</code> pass with these
    defaults:
</p>

<ul>
    <li><code>--max-time=55</code> — the loop exits before the 60-second tick boundary so consecutive ticks don't pile up.</li>
    <li><code>--job-timeout=120</code> — individual jobs (mostly CrawlPageJob / IndexDocumentJob) get a 2-minute ceiling.</li>
    <li>Queues processed: <code>analytics,default,index,crawl</code> — strict priority, light queues first. <code>analytics</code> is mandatory: it carries the after-stream telemetry and persistence jobs (widget events, turn persistence, usage counters). Drop it and the Widget Monitor stays empty while conversation history silently never saves.</li>
</ul>

<p>
    Build + deploy the Worker via
    <code>php artisan pitchbar:deploy-cron-worker</code>. The Worker
    body is templated from <code>WorkerDeployer</code> and ships with
    the tick parameters baked in. Rotate
    <code>INTERNAL_QUEUE_TOKEN</code> after deploy.
</p>

<h2>Crawler reliability</h2>

<p>
    <code>CrawlPageJob</code> retries up to <strong>3</strong> times
    with backoff <code>[30, 90, 180]</code> seconds. The retry path
    branches on failure class:
</p>

<ul>
    <li><strong>Rate-limit (429)</strong> — <code>release(60)</code> without burning a retry slot. Every fan-out page tends to hit the same 429 wave; the shared wait is productive.</li>
    <li><strong>Permanent failures</strong> — curl DNS errors (6, 7), connection refused, malformed URL, HTTP 400 / 401 / 403 / 404 / 410 / 451 — call <code>$this->fail()</code> immediately. Without this, every dead URL burned the full 3-retry budget and produced a generic <code>MaxAttemptsExceededException</code> in the logs.</li>
    <li><strong>Transient</strong> (5xx, network blip) — normal retry with backoff.</li>
</ul>

<p>
    Per-job timeout is 90 seconds; <code>failOnTimeout=true</code> so
    a SIGTERM on timeout still runs the <code>failed()</code> callback
    and flips the Source row to <code>failed</code> with a
    customer-readable error.
</p>
