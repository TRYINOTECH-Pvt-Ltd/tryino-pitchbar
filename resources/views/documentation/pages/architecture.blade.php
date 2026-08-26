<p>
    Pitchbar is a single Laravel codebase with two frontends. Backend is
    Laravel 13 + Octane on PHP 8.3+; admin UI is Inertia v3 + React 19;
    visitor widget is Preact ≤50KB. AI stack defaults to Cloudflare (Workers
    AI + Vectorize + Browser Rendering) with OpenAI + Qdrant as fallback.
</p>

<h2>The stack</h2>

<table>
    <thead><tr><th>Layer</th><th>Tech</th></tr></thead>
    <tbody>
        <tr><td>App framework</td><td>Laravel 13 (PHP 8.3+)</td></tr>
        <tr><td>Server</td><td>Laravel Octane on FrankenPHP</td></tr>
        <tr><td>Realtime</td><td>Laravel Reverb (WebSocket)</td></tr>
        <tr><td>Queue</td><td>Laravel Horizon on Redis</td></tr>
        <tr><td>Auth</td><td>Laravel Fortify (sessions, 2FA, password reset) + Sanctum (API tokens)</td></tr>
        <tr><td>Billing</td><td>Laravel Cashier (Stripe)</td></tr>
        <tr><td>Database</td><td>Postgres 16</td></tr>
        <tr><td>Cache / sessions</td><td>Redis 7</td></tr>
        <tr><td>Admin frontend</td><td>Inertia v3 + React 19, Tailwind v4, shadcn/ui (Radix), Vite, strict TS</td></tr>
        <tr><td>Typed routes</td><td>Wayfinder (TS bindings to Laravel routes)</td></tr>
        <tr><td>Visitor widget</td><td>Preact 10 (aliased as React) + Vite + selective Tailwind v4</td></tr>
        <tr><td>LLM (preferred)</td><td>Cloudflare Workers AI — Llama 3.3 70B + bge-base-en-v1.5</td></tr>
        <tr><td>LLM (fallback)</td><td>OpenAI <code>gpt-4o-mini</code> + <code>text-embedding-3-small</code> (and OpenRouter as a router)</td></tr>
        <tr><td>Vector store (preferred)</td><td>Cloudflare Vectorize</td></tr>
        <tr><td>Vector store (fallback)</td><td>Qdrant (HTTP client)</td></tr>
        <tr><td>Crawler (preferred)</td><td>Cloudflare Browser Rendering</td></tr>
        <tr><td>Crawler (fallback)</td><td>Browserless → plain HTTP</td></tr>
        <tr><td>Object storage</td><td>Cloudflare R2 (S3-compatible)</td></tr>
        <tr><td>Hosting</td><td>Laravel Cloud</td></tr>
        <tr><td>Observability</td><td>Sentry + OpenTelemetry → Honeycomb / Grafana Cloud</td></tr>
    </tbody>
</table>

<h2>Repository layout</h2>

<p>
    One Laravel app at the repo root. The admin frontend ships as Inertia
    pages inside the same app; the visitor widget is a second isolated
    Vite build.
</p>

<pre><code>pitchbar/                     — Laravel app
├── app/
│   ├── Actions/Fortify/      — Fortify hooks (CreateNewUser, etc.)
│   ├── Concerns/             — BelongsToWorkspace, BelongsToAgent traits
│   ├── Http/
│   │   ├── Controllers/Admin/    — customer + admin Inertia controllers
│   │   ├── Controllers/Widget/   — /api/v1/widget/* (visitor-side, JWT)
│   │   └── Middleware/
│   ├── Models/               — Workspace, Agent, Conversation, Plan, …
│   ├── Services/
│   │   ├── Rag/              — Retriever, Chunker, PromptBuilder, CuratedAnswerMatcher
│   │   ├── Llm/              — OpenAiHttpClient, WorkersAiClient, Fakes
│   │   ├── Vector/           — VectorizeClient, QdrantHttpClient
│   │   ├── Crawl/            — CloudflareBrowserClient, AutoIndexPageVisit, PlainHttpCrawler
│   │   ├── Triggers/         — CtaSelector, LeadIntentDetector
│   │   ├── Analytics/        — EventStore (analytics rollups + gap detection ride in app/Jobs/Analytics)
│   │   ├── Billing/          — StripeProductSync, MeteredBilling, PayPalClient, RazorpayClient
│   │   ├── Tools/            — ToolRegistry + EscalateToHumanTool (Phase 2)
│   │   ├── Vertical/         — VerticalPresetRegistry + 7 preset classes
│   │   ├── I18n/             — LocaleResolver, LocaleCatalog (132 languages)
│   │   └── Widget/           — WidgetJwt, WidgetCopy, InlineBlockParser
│   ├── Jobs/Crawl/           — CrawlSourceJob, CrawlPageJob, IndexDocumentJob
│   ├── Jobs/Analytics/       — DetectGapJob (post-stream gap detection)
│   └── Events/               — TokenStreamed, TurnCompleted, TurnFailed
├── resources/
│   ├── js/                   — admin Inertia (default Vite build)
│   │   ├── pages/
│   │   ├── components/
│   │   └── …
│   ├── widget/               — visitor widget (separate Vite build)
│   ├── views/                — Blade (Inertia root + marketing + docs)
│   └── css/app.css           — Tailwind v4 entry
├── routes/
│   ├── web.php
│   ├── api.php
│   └── channels.php
├── database/{migrations,factories,seeders}
├── tests/{Feature,Unit,Browser}
├── docs/PLAN.md              — full engineering plan
└── public/widget/            — built widget bundle</code></pre>

<h2>Two frontends, one backend</h2>

<p>
    The admin and customer surfaces are the same Inertia app — same Vite
    build, same component library. The roles are separated by route group
    and middleware, not by codebase. This keeps a single source of truth
    for design tokens, routing helpers, and authentication state.
</p>

<p>
    The visitor widget is the opposite — it intentionally shares
    <em>nothing</em> with the admin code. It can't import from
    <code>resources/js/</code>; it has its own Vite config; it has its own
    router (just a Preact component tree) and its own state. The size
    budget is a hard 50KB gzipped — admin features cannot bleed in.
</p>

<h2>Reverb &amp; WebSocket</h2>

<p>
    Reverb runs as a separate process and powers:
</p>

<ul>
    <li><strong>Inbox live updates</strong> — operators see new messages as they arrive.</li>
    <li><strong>Human takeover events</strong> — the visitor's widget gets a "human is here" event when an operator claims the conversation.</li>
    <li><strong>Operator presence</strong> — Available / Away states sync across team members.</li>
</ul>

<p>
    Channels are private by default — the widget joins
    <code>conversation.{id}</code> using its JWT, and the operator app
    joins <code>workspace.{id}</code> using its session.
</p>

<h2>Cloudflare one-bill mode</h2>

<p>
    Set <code>CLOUDFLARE_ACCOUNT_ID</code> + <code>CLOUDFLARE_API_TOKEN</code>
    and the LLM, vector store, and crawler all auto-bind to Cloudflare.
    Total external infra cost: $5/month Workers Paid + per-request usage.
    Replace any one piece (e.g. swap Vectorize for Qdrant by setting
    <code>QDRANT_URL</code>) and the binding shifts.
</p>

<h2>Multi-tenant isolation</h2>

<p>
    Every tenant-scoped query is filtered by the
    <code>BelongsToWorkspace</code> trait's global scope. Crossing the
    boundary requires an explicit <code>withoutWorkspaceScope()</code> with
    a justifying comment. There's a regression test that fails the build
    if a model with a <code>workspace_id</code> column doesn't use the
    trait. See <a href="/documentation/multi-tenancy">Multi-tenancy</a>.
</p>
