# Pitchbar

A Sales AI bar for any website — answers visitor questions in real time,
captures leads, and routes them to your team. Built on Laravel 13 + Inertia +
React 19 for teams running their own customer-facing AI assistant.

> **Version 2.1.0.** Full installation, configuration, and operator
> documentation ships inside the application itself at `/documentation`
> once it is running — start with the *Install Pitchbar (self-host)* page.
>
> **VPS / Docker:** production stack is `docker-compose.yml` (Caddy +
> FrankenPHP + Horizon). Follow [infra/docker/README.md](infra/docker/README.md).
> Local Postgres/Redis/Qdrant/Mailpit only: `docker-compose.local.yml`.

```
┌──────────────┐    ┌──────────────────┐    ┌──────────────┐
│  visitor's   │ ── │ widget.js (27KB) │ ── │  /v1/widget  │
│   browser    │    │ Preact + SSE     │    │  endpoints   │
└──────────────┘    └──────────────────┘    └──────┬───────┘
                                                   │
   admin/customer Inertia SPA  ──── Laravel ───────┤
   (React 19 + shadcn/ui)              │           │
                                       │           ▼
                       ┌───────────────┴────────────────────┐
                       │  Cloudflare:                       │
                       │  • Workers AI (chat + embeddings)  │
                       │  • Vectorize (chunks DB)           │
                       │  • Browser Rendering (crawler)     │
                       └────────────────────────────────────┘
```

## Quick start

The release package ships with `vendor/` and the compiled front-end
assets already in place, so a production install is configuration only:

```sh
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Point your web server at `public/` and you're serving. For development —
or after changing anything under `resources/` — you also need the build
toolchain:

```sh
composer install           # restores the dev dependencies (tests, linters)
npm install
npm run build              # admin bundle
npm run build:ssr          # server-side render bundle
npm run build:widget       # hashed widget bundle + public/widget/manifest.json
composer run dev           # serve + queue:listen + pail + npm run dev
```

Logins seeded by `php artisan migrate:fresh --seed`:

| email             | password   | role        |
|-------------------|------------|-------------|
| admin@mail.com    | password   | super_admin |
| customer@mail.com | password   | customer    |

**Change both passwords before exposing the install** — they are seed
data, identical on every copy of the product.

To see the live demo widget on the marketing pages:

```sh
php artisan pitchbar:seed-demo-agent
# → copy the printed UUID into MARKETING_DEMO_AGENT_ID in .env
```

## What's wired up

**RAG pipeline** — Cloudflare bge-base-en-v1.5 embeddings → Vectorize ANN →
bge-reranker-base cross-encoder rerank → Llama 3.3 70B chat (streamed via
SSE). Soft-404 / login-wall / paywall detectors keep junk pages out. See
[app/Services/Rag/](app/Services/Rag/) and the
[`HotPathTimer`](app/Support/HotPathTimer.php) for per-stage latency logs.

**Knowledge sources** — paste URLs, sitemaps, or use the OAuth integrations
to ingest [Notion](app/Services/Integrations/Notion/) pages and
[Google Docs](app/Services/Integrations/Google/). Auto-discovery scans
robots.txt + /sitemap.xml + common paths during onboarding. Stale content
re-syncs hourly (OAuth) or daily (web crawls).

**Multi-tenant** — every model uses the
[`BelongsToWorkspace`](app/Concerns/BelongsToWorkspace.php) global scope.
Cross-tenant access at the HTTP layer is verified by
[CrossTenantEndpointAuditTest](tests/Feature/Tenancy/CrossTenantEndpointAuditTest.php).

**Live human takeover** — workspace members can claim an in-flight
conversation; the bot stops auto-replying and the operator's messages
flow back to the visitor's widget over a 3-second poll.

**Self-improvement loop** — recurring unanswered questions become draft
curated answers a workspace owner can approve in one click.

**Operator surface** — Slack alerts on lead capture, branded email
notifications to owners/admins, failed-jobs admin page with full stack
traces, vector-store consistency audit cron, GDPR delete endpoint.

## Test suite

```sh
composer install          # dev dependencies, if you installed without them
php artisan test --compact
# → 2641 passing, ~3 minutes
```

`tests/Pest.php` ships shared `workspaceMember()` /
`workspaceMemberWithAgent()` helpers — prefer those over per-file
duplicates so new tests don't collide with old ones.

## Code style

```sh
vendor/bin/pint --dirty --format agent     # PHP — PSR-12 + custom presets
npm run lint                               # JS/TS — ESLint + Prettier
npm run build                              # admin + widget production build
```

Both PHP and TypeScript are strict-typed. Keep the linters and the test
suite green when you customize — the suite covers multi-tenancy isolation,
the hot path, and billing, so a red test usually means a real regression.

## Repository layout

```
app/
├── Actions/Fortify/             — auth side-effects (e.g. auto-crawl on register)
├── Concerns/                    — BelongsToWorkspace / BelongsToAgent traits
├── Console/Commands/            — pitchbar:* artisan commands
├── Events/Conversations/        — Reverb broadcasts (live takeover)
├── Http/Controllers/
│   ├── Admin/                   — customer-facing Inertia controllers
│   ├── Admin/Platform/          — super_admin Inertia controllers (/admin/*)
│   └── Widget/                  — public /v1/widget/* surface
├── Jobs/                        — Crawl, Index, Leads, Analytics
├── Models/                      — Eloquent + factories
├── Notifications/               — NewLeadCaptured (branded email)
├── Services/
│   ├── Crawl/                   — sitemap discovery, HTML extraction, soft-404
│   ├── Integrations/{Notion,Google} — OAuth clients
│   ├── Llm/                     — OpenAiClient interface + Cloudflare/OpenAI/Fake
│   ├── Rag/                     — Retriever, Reranker, Chunker, PromptBuilder
│   ├── Triggers/                — CTA selector, behavior rules
│   ├── Vector/                  — QdrantClient interface + Vectorize/Qdrant/Fake
│   └── Widget/                  — JWT issuer, Accept-Language detector
└── Support/                     — HotPathTimer, OAuthState, CurrentWorkspace
resources/
├── js/                          — admin Inertia (default Vite build)
├── widget/                      — visitor widget (separate Vite build, ≤50KB gzip)
└── views/marketing/             — public Blade pages
routes/
├── api.php                      — /v1/widget/*
├── channels.php                 — Reverb private channels
├── console.php                  — scheduler entries
└── web.php                      — Inertia + Fortify + admin
tests/
├── Feature/                     — most tests live here
├── Unit/                        — service-level micro-tests
└── Pest.php                     — shared fixtures
```

## License

Proprietary. © 2026 Pitchbar.
