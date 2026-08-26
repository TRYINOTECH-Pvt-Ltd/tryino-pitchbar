<p>
    Pitchbar reads its configuration from the standard Laravel
    <code>.env</code>, with overrides for some keys available via the
    platform admin's <strong>App Settings</strong> page so you can swap
    Stripe / mail / LLM credentials without redeploying.
</p>

<h2>Required</h2>

<table>
    <thead><tr><th>Variable</th><th>Why</th></tr></thead>
    <tbody>
        <tr><td><code>APP_KEY</code></td><td>Encryption master key. Generate with <code>php artisan key:generate</code>. Never rotate without a re-encrypt migration.</td></tr>
        <tr><td><code>APP_URL</code></td><td>Public URL, used to build widget snippets, OAuth callbacks, signed URLs.</td></tr>
        <tr><td><code>DB_*</code></td><td>Postgres connection.</td></tr>
        <tr><td><code>REDIS_HOST</code></td><td>Cache, sessions, queue, hot-path retrieval cache, conversation history cache.</td></tr>
        <tr><td><code>QUEUE_CONNECTION</code></td><td>Set to <code>redis</code> in production.</td></tr>
        <tr><td><code>SESSION_DRIVER</code></td><td><code>redis</code> in production.</td></tr>
        <tr><td><code>CACHE_DRIVER</code></td><td><code>redis</code>.</td></tr>
        <tr><td><code>WIDGET_JWT_SECRET</code></td><td>HS256 signing secret for visitor JWTs. ≥ 32 random bytes.</td></tr>
    </tbody>
</table>

<h2>LLM provider</h2>

<p>
    At least one of the following:
</p>

<table>
    <thead><tr><th>Variable</th><th>Provider</th></tr></thead>
    <tbody>
        <tr><td><code>CLOUDFLARE_ACCOUNT_ID</code> + <code>CLOUDFLARE_API_TOKEN</code></td><td>Cloudflare Workers AI (preferred). Auto-binds Llama 3.3 70B + bge-base-en-v1.5.</td></tr>
        <tr><td><code>OPENAI_API_KEY</code></td><td>OpenAI direct.</td></tr>
        <tr><td><code>OPENROUTER_API_KEY</code></td><td>OpenRouter (router across many providers).</td></tr>
    </tbody>
</table>

<p>
    Set <code>LLM_PROVIDER</code> to force a binding (<code>cloudflare</code>,
    <code>openai</code>, <code>openrouter</code>). When unset, the resolver
    picks based on which keys are available, in the priority above.
</p>

<h2>Vector store</h2>

<table>
    <thead><tr><th>Variable</th><th>Provider</th></tr></thead>
    <tbody>
        <tr><td><code>CLOUDFLARE_VECTORIZE_INDEX</code></td><td>Cloudflare Vectorize index name. Uses <code>CLOUDFLARE_ACCOUNT_ID</code> + token. Default <code>pitchbar-chunks</code>.</td></tr>
        <tr><td><code>QDRANT_URL</code> + <code>QDRANT_API_KEY</code></td><td>Qdrant.</td></tr>
    </tbody>
</table>

<p>
    Set <code>VECTOR_PROVIDER</code> to force. Auto-binds based on
    available keys (Vectorize preferred).
</p>

<h2>Crawler</h2>

<table>
    <thead><tr><th>Variable</th><th>Strategy</th></tr></thead>
    <tbody>
        <tr><td>(uses <code>CLOUDFLARE_*</code>)</td><td>Cloudflare Browser Rendering. Preferred.</td></tr>
        <tr><td><code>BROWSERLESS_TOKEN</code> + <code>BROWSERLESS_URL</code></td><td>Browserless fallback.</td></tr>
        <tr><td>(none)</td><td>Plain HTTP. Free; no JS rendering.</td></tr>
    </tbody>
</table>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>CRAWL_MAX_PAGES_PER_SOURCE</code></td><td>Max pages fan-out per Source. Default <strong>500</strong> (raised from 25 — buyers adding 100-URL sitemaps were silently losing 75 pages). Cap applies after sitemap-index recursion + dedupe.</td></tr>
        <tr><td><code>CRAWL_PAGE_DISPATCH_DELAY</code></td><td>Per-page delay (seconds) when fanning out sitemap pages so Cloudflare Browser Rendering's small per-account concurrency doesn't 429. Default <code>2</code>.</td></tr>
        <tr><td><code>CLOUDFLARE_BROWSER_RENDERING</code></td><td>Set to <code>false</code> to force the Browserless / plain-HTTP path even when Cloudflare keys are present. Default <code>true</code>.</td></tr>
    </tbody>
</table>

<h2>RAG / retrieval</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>RAG_CONFIDENCE_THRESHOLD</code></td><td>Default similarity threshold for newly created agents. <code>0.5</code> for Cloudflare bge-base-en-v1.5 (its ANN cosine peaks lower than OpenAI's); <code>0.78</code> for OpenAI text-embedding-3-small. Auto-resolves based on <code>LLM_PROVIDER</code>.</td></tr>
        <tr><td><code>RAG_QUERY_REWRITE</code></td><td>Hands the retrieval query to a small LLM instead of the built-in stitch. <strong>Off by default, and the safe default</strong> — the stitch is deterministic and costs nothing, while the rewrite adds a round-trip to every context-dependent turn. If you enable it, set <code>RAG_QUERY_REWRITE_MODEL</code> to a small fast model; leaving it empty runs the rewrite on your main chat model. Whatever it returns, any product name it dropped is added back before the search runs. <code>php artisan pitchbar:diagnose-widget</code> reports the current state.</td></tr>
        <tr><td><code>RAG_RERANK_ENABLED</code></td><td>Set to <code>false</code> to disable the bge-reranker-base cross-encoder pass. Default <code>true</code> when Cloudflare keys are present.</td></tr>
        <tr><td><code>RAG_RERANK_TIMEOUT_SECONDS</code></td><td>HTTP timeout for the reranker call, default <code>3</code>. Deliberately short: the reranker only re-orders candidates the vector search already found, so failing fast to that ordering costs a little precision, while waiting costs the whole turn. At the previous 8-second timeout a slow reranker could push a turn past the widget's own connection timeout and the visitor saw a generic error instead of a slightly-less-ranked answer.</td></tr>
        <tr><td><code>VECTOR_DIM</code></td><td>Embedding dimension override. Leave unset to auto-resolve from the configured embed model via the known-model map (bge-base=768, bge-m3=1024, text-embedding-3-small=1536, etc.). Set explicitly only when pointing at an unlisted model. <strong>Changing this on an existing install needs <code>php artisan vector:rebuild-index</code> — the Vectorize index is provisioned at the dim active when it was first created and cannot be resized in place.</strong></td></tr>
    </tbody>
</table>

<h2>Cloudflare AI Gateway (optional)</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>CLOUDFLARE_AI_GATEWAY_URL</code></td><td>If set, Workers AI calls route through Cloudflare's AI Gateway — observability, caching, rate limits.</td></tr>
        <tr><td><code>CLOUDFLARE_CHAT_MODEL</code></td><td>Default <code>@cf/meta/llama-3.3-70b-instruct-fp8-fast</code>.</td></tr>
        <tr><td><code>CLOUDFLARE_EMBED_MODEL</code></td><td>Default <code>@cf/baai/bge-base-en-v1.5</code>.</td></tr>
        <tr><td><code>CLOUDFLARE_VECTORIZE_INDEX</code></td><td>Vectorize index name. Default <code>pitchbar-chunks</code>.</td></tr>
        <tr><td><code>OPENROUTER_CHAT_MODEL</code></td><td>OpenRouter chat model slug. Default <code>meta-llama/llama-3.3-70b-instruct:free</code>.</td></tr>
        <tr><td><code>OPENROUTER_EMBED_MODEL</code></td><td>OpenRouter embedding model. Default <code>text-embedding-3-small</code>.</td></tr>
        <tr><td><code>QDRANT_COLLECTION</code></td><td>Qdrant collection name when using the Qdrant vector store fallback. Default <code>pitchbar_chunks</code>.</td></tr>
    </tbody>
</table>

<h2>S3 / R2 (object storage for file uploads)</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>AWS_ACCESS_KEY_ID</code> + <code>AWS_SECRET_ACCESS_KEY</code></td><td>Bucket credentials. For Cloudflare R2, use the R2 token's key/secret pair.</td></tr>
        <tr><td><code>AWS_DEFAULT_REGION</code></td><td>Region of the bucket. Use <code>auto</code> for R2.</td></tr>
        <tr><td><code>AWS_BUCKET</code></td><td>Bucket name.</td></tr>
        <tr><td><code>AWS_ENDPOINT</code></td><td>Custom S3-compatible endpoint. Required for R2 (e.g. <code>https://&lt;accountid&gt;.r2.cloudflarestorage.com</code>). Leave blank for native AWS S3.</td></tr>
        <tr><td><code>AWS_USE_PATH_STYLE_ENDPOINT</code></td><td><code>true</code> for R2 and most non-AWS S3 implementations; <code>false</code> for native AWS.</td></tr>
    </tbody>
</table>

<h2>Frontend (Vite)</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>VITE_WIDGET_CDN_URL</code></td><td>Base URL the visitor widget loader uses. Defaults to <code>http://localhost:5173</code> in dev. In production, point at the public origin serving <code>/widget/widget.js</code>.</td></tr>
    </tbody>
</table>

<h2>Stripe</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>STRIPE_KEY</code></td><td>Publishable key.</td></tr>
        <tr><td><code>STRIPE_SECRET</code></td><td>Secret key. Used for plan sync and Cashier.</td></tr>
        <tr><td><code>STRIPE_WEBHOOK_SECRET</code></td><td>Signing secret for incoming webhooks (<code>whsec_…</code>).</td></tr>
        <tr><td><code>CASHIER_CURRENCY</code></td><td>Defaults to <code>usd</code>.</td></tr>
    </tbody>
</table>

<h2>Reverb (realtime)</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>REVERB_APP_KEY</code></td><td>Public app key. Embedded in widget init payload.</td></tr>
        <tr><td><code>REVERB_APP_SECRET</code></td><td>Secret. Server-side only.</td></tr>
        <tr><td><code>REVERB_APP_ID</code></td><td>App identifier.</td></tr>
        <tr><td><code>REVERB_HOST</code></td><td>Public hostname for the Reverb server.</td></tr>
        <tr><td><code>REVERB_PORT</code></td><td>Default 8080.</td></tr>
        <tr><td><code>REVERB_SCHEME</code></td><td><code>wss</code> in production, <code>ws</code> locally.</td></tr>
    </tbody>
</table>

<h2>Mail</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>MAIL_MAILER</code></td><td><code>smtp</code> / <code>postmark</code> / <code>resend</code> / etc.</td></tr>
        <tr><td><code>MAIL_FROM_ADDRESS</code></td><td>Sender address. Required.</td></tr>
        <tr><td><code>MAIL_FROM_NAME</code></td><td>Display name.</td></tr>
        <tr><td><code>MAIL_HOST</code> / <code>MAIL_PORT</code> / <code>MAIL_USERNAME</code> / <code>MAIL_PASSWORD</code></td><td>SMTP credentials.</td></tr>
    </tbody>
</table>

<h2>Branding</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>BRANDING_LABEL</code></td><td>Default "Powered by Pitchbar" label. Override in app_settings for white-label.</td></tr>
        <tr><td><code>BRANDING_URL</code></td><td>Where the label links to.</td></tr>
        <tr><td><code>BRANDING_FOOTER_LOGO_PATH</code></td><td>Storage path to the footer logo image.</td></tr>
    </tbody>
</table>

<h2>Observability</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>SENTRY_LARAVEL_DSN</code></td><td>Error reporting. (Some hosts still ship the legacy <code>SENTRY_DSN</code> name; the Laravel SDK reads <code>SENTRY_LARAVEL_DSN</code> as of v4.x — keep both in <code>.env</code> for back-compat if you migrated.)</td></tr>
        <tr><td><code>OTEL_EXPORTER_OTLP_ENDPOINT</code></td><td>OpenTelemetry collector. Honeycomb / Grafana Cloud.</td></tr>
        <tr><td><code>OTEL_SERVICE_NAME</code></td><td>Service name in traces. Default <code>pitchbar</code>.</td></tr>
    </tbody>
</table>

<h2>Payment gateways</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>PAYPAL_ENABLED</code></td><td>Set to <code>true</code> to expose PayPal at checkout.</td></tr>
        <tr><td><code>PAYPAL_MODE</code></td><td><code>sandbox</code> or <code>live</code>.</td></tr>
        <tr><td><code>PAYPAL_CLIENT_ID</code> + <code>PAYPAL_CLIENT_SECRET</code></td><td>REST API credentials.</td></tr>
        <tr><td><code>PAYPAL_WEBHOOK_ID</code></td><td>For verify-signature webhook checks.</td></tr>
        <tr><td><code>RAZORPAY_ENABLED</code></td><td>Set to <code>true</code> to expose Razorpay at checkout.</td></tr>
        <tr><td><code>RAZORPAY_API_KEY</code> + <code>RAZORPAY_SECRET_KEY</code></td><td>HTTP Basic auth keys.</td></tr>
        <tr><td><code>RAZORPAY_WEBHOOK_SECRET</code></td><td>HMAC-SHA256 signing secret for webhook bodies.</td></tr>
    </tbody>
</table>

<h2>OAuth integrations</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>NOTION_CLIENT_ID</code> + <code>NOTION_CLIENT_SECRET</code> + <code>NOTION_REDIRECT_URI</code></td><td>Notion public-integration credentials for connecting Notion pages as knowledge sources.</td></tr>
        <tr><td><code>GOOGLE_CLIENT_ID</code> + <code>GOOGLE_CLIENT_SECRET</code> + <code>GOOGLE_REDIRECT_URI</code></td><td>Google OAuth (Drive + Docs + Sheets) — register an OAuth client in the GCP console with <code>drive.readonly</code>, <code>documents.readonly</code>, and <code>spreadsheets.readonly</code> scopes. Omitting <code>spreadsheets.readonly</code> blocks the Google Sheets source type.</td></tr>
    </tbody>
</table>

<h2>Marketing demo widget</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>MARKETING_DEMO_AGENT_ID</code></td><td>UUID of a published agent that powers the live demo widget on the marketing pages. Add the marketing site URL to that agent's <code>allowed_origins</code>.</td></tr>
        <tr><td><code>DEMO</code></td><td>Set to <code>true</code> to open Login / Get started links in a new tab on the marketing site — useful for reviewers exploring a CodeCanyon live demo without losing the marketing page when they hop into the app.</td></tr>
    </tbody>
</table>

<h2>Queue tick (Cloudflare Worker cron)</h2>

<table>
    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>INTERNAL_QUEUE_TOKEN</code></td><td>Shared bearer secret protecting <code>POST /api/v1/internal/queue-tick</code>. The Cloudflare Worker cron pings this every 60s to drive the queue when in-cluster scheduling isn't available. <strong>Only set it when this host has no queue daemon.</strong> If PM2 or supervisor already runs <code>queue:work</code>, the tick is redundant — leave the token empty. The tick runs the worker in its own subprocess, so it can no longer take the web worker down with it, but two things draining the same queues buys you nothing.</td></tr>
    </tbody>
</table>

<h2>App Settings overrides</h2>

<p>
    The <code>app_settings</code> singleton row stores plaintext-encrypted
    overrides for:
</p>

<ul>
    <li>Stripe secret + webhook secret + publishable key.</li>
    <li>Mail driver settings.</li>
    <li>Cloudflare / OpenAI / OpenRouter keys.</li>
    <li>Branding (label, URL, logo).</li>
</ul>

<p>
    The <code>AppSettingsOverrideServiceProvider</code> reads this on boot
    and merges into <code>config()</code>. Setting things via the admin
    panel is preferred for production — you don't have to re-deploy when a
    key rotates.
</p>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <code>APP_KEY</code> rotation requires a manual migration. The
        encrypted columns in <code>app_settings</code> were sealed with the
        old key; rotating without re-encrypting renders them unreadable.
    </div>
</div>
