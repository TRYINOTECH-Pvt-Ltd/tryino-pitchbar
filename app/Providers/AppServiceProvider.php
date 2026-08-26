<?php

namespace App\Providers;

use App\Events\Conversations\HumanRequestedEvent;
use App\Events\Leads\LeadCapturedEvent;
use App\Listeners\Leads\PushLeadToWordPress;
use App\Listeners\LiveChat\LiveChatNotifier;
use App\Listeners\Queue\RecordJobRun;
use App\Models\Workspace;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\RazorpayClient;
use App\Services\Changelog\ChangelogStore;
use App\Services\Cloudflare\ToMarkdownClient;
use App\Services\Crawl\BrowserlessClient;
use App\Services\Crawl\ChainedCrawler;
use App\Services\Crawl\CloudflareBrowserClient;
use App\Services\Crawl\CloudflareBrowserMarkdownCrawler;
use App\Services\Crawl\CloudflareBrowserScreenshotClient;
use App\Services\Crawl\CloudflareVisionClient;
use App\Services\Crawl\CloudflareVisionCrawler;
use App\Services\Crawl\Contracts\Crawler;
use App\Services\Crawl\PlainHttpCrawler;
use App\Services\Crawl\ReadabilityExtractor;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\OverrideTranslationLoader;
use App\Services\Integrations\Google\GoogleClient;
use App\Services\Integrations\Notion\NotionClient;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\FailoverOpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Llm\LlmProviderChain;
use App\Services\Llm\OpenAiHttpClient;
use App\Services\Llm\WorkersAiClient;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Fakes\FakeMcpClient;
use App\Services\Mcp\HttpMcpClient;
use App\Services\Mcp\McpCredentialResolver;
use App\Services\Mcp\McpServerRegistry;
use App\Services\Mcp\McpToolDiscovery;
use App\Services\Mcp\McpToolExecutor;
use App\Services\Mcp\Support\McpCircuitBreaker;
use App\Services\Parsers\ParserRegistry;
use App\Services\Rag\CloudflareReranker;
use App\Services\Rag\Contracts\Reranker;
use App\Services\Rag\Fakes\FakeReranker;
use App\Services\Tools\ToolExemplarStore;
use App\Services\Tools\ToolIntentRouter;
use App\Services\Tools\ToolRegistry;
use App\Services\Vector\CircuitBreakingVectorClient;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use App\Services\Vector\QdrantHttpClient;
use App\Services\Vector\VectorizeClient;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Widget\WidgetEventRecorder;
use App\Services\Widget\WidgetJwt;
use App\Support\AppBranding;
use App\Support\ByokResolver;
use App\Support\CurrentWorkspace;
use App\Support\DeprecationGuard;
use App\Support\UrlSafetyGuard;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Translation\FileLoader;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentWorkspace::class);

        // Make the bare __() / trans() helper override-aware by swapping
        // Laravel's translation file loader for one that merges the
        // admin's DB overrides onto the JSON string group. Rebuilt with
        // the SAME paths the framework configured (its own lang dir + the
        // app's), so nothing else changes. The React SPA, widget, and
        // marketing surfaces read through TranslationLoader and don't
        // depend on this; this is the belt-and-suspenders for server-side
        // __() (validation, mailables, @lang).
        $this->app->extend('translation.loader', function ($loader, $app) {
            $frameworkLang = dirname(
                (new \ReflectionClass(FileLoader::class))->getFileName()
            ).'/lang';

            $override = new OverrideTranslationLoader(
                $app['files'],
                [$frameworkLang, $app->langPath()],
            );

            // Carry over any JSON paths packages registered on the
            // original loader so package translations keep resolving.
            foreach ($loader->jsonPaths() as $path) {
                $override->addJsonPath($path);
            }

            return $override;
        });

        // File-backed changelog store. Reads/writes
        // storage/app/private/changelog-entries.json + auto-seeds
        // from database/changelog-entries/v*.md on first read. The
        // bootstrap dir is config-driven so tests can disable
        // seeding by setting `changelog.bootstrap_dir` to null in
        // their setup — it survives the HTTP boundary that a
        // container `instance()` rebinding can't.
        $this->app->scoped(ChangelogStore::class, function () {
            $configured = config('changelog.bootstrap_dir', '__default__');
            $bootstrapDir = $configured === '__default__'
                ? base_path('database/changelog-entries')
                : $configured;

            return new ChangelogStore(
                disk: 'local',
                bootstrapDir: $bootstrapDir,
            );
        });

        // Single Stripe SDK instance per request — Cashier uses its own
        // internal client too, but we lean on this one for product/price
        // provisioning where we want explicit control.
        // The SDK rejects an empty api_key at construction, so when Stripe
        // isn't configured yet we hand it a harmless placeholder; any real
        // network call would 401 long before we reach this client.
        $this->app->singleton(StripeClient::class, function () {
            $secret = (string) (config('cashier.secret') ?? env('STRIPE_SECRET', ''));

            return new StripeClient([
                'api_key' => $secret !== '' ? $secret : 'sk_unconfigured_placeholder',
                'stripe_version' => '2024-12-18.acacia',
            ]);
        });

        // PayPal + Razorpay clients are bound as transient (`bind`, not
        // `singleton`) so a credentials rotation in the admin UI takes
        // effect on the next resolve without needing an Octane reload.
        $this->app->bind(PayPalClient::class, fn () => PayPalClient::fromConfig());
        $this->app->bind(RazorpayClient::class, fn () => RazorpayClient::fromConfig());

        // C1: switched from singleton → scoped so each request can
        // re-resolve against the CurrentWorkspace's BYOK keys when
        // BYOK is unlocked for that user/workspace. scoped() is
        // Octane-safe (per-request instance, not per-worker). The
        // resolution closure stays cheap — just HTTP client setup.
        $this->app->scoped(OpenAiClient::class, function () {
            if (app()->runningUnitTests()) {
                return new FakeOpenAi;
            }

            // C1: workspace-level BYOK takes precedence over platform
            // keys when unlocked. Resolver matrix lives in
            // App\Support\ByokResolver; see /documentation/byok.
            $byok = $this->resolveByokKeys('llm');
            if ($byok !== null) {
                return $this->byokOpenAiClient($byok);
            }

            // All provider settings come from config(), which
            // AppSettingsOverrideServiceProvider hydrates from the
            // app_settings table at boot. The .env values registered in
            // config/services.php are first-install defaults only —
            // super-admin saves at /settings/system override them.
            $provider = (string) config('services.llm.provider', '');

            // Collect provider credentials, then let LlmProviderChain decide
            // which providers join the failover chain and in what order (that
            // pure rule is unit-tested; this binding short-circuits to a fake
            // under tests, so the rules can't be tested through the container).
            // The chain is the reliability backbone: a single provider outage
            // (slow, 5xx, 429, out of credits) no longer kills every visitor
            // turn — FailoverOpenAiClient retries the next one.
            $cfAccount = (string) config('services.cloudflare.account_id', '');
            $cfToken = (string) config('services.cloudflare.api_token', '');
            $cfChatModel = (string) config('services.cloudflare.chat_model', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
            $cfFallbackModel = (string) config('services.cloudflare.chat_model_fallback', '');
            $cfEmbedModel = (string) config('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5');
            $cfGateway = config('services.cloudflare.ai_gateway_url') ?: null;
            $openAiKey = (string) config('services.openai.key', env('OPENAI_API_KEY', ''));
            $openRouterKey = (string) (config('services.openrouter.key') ?: env('OPENROUTER_API_KEY', ''));

            // One Workers AI client per Cloudflare CHAT model. The chain
            // below registers the primary AND (when configured) a fallback
            // model as separate failover entries — same account/token/embed,
            // different chat model — so a single-Cloudflare install can
            // self-heal model→model with no second provider.
            $makeCloudflare = fn (string $chatModel): OpenAiClient => new WorkersAiClient(
                accountId: $cfAccount,
                apiToken: $cfToken,
                chatModel: $chatModel,
                embedModel: $cfEmbedModel,
                aiGatewayUrl: $cfGateway,
            );

            $order = LlmProviderChain::order(
                $provider,
                $cfAccount !== '' && $cfToken !== '',
                $openAiKey !== '',
                $openRouterKey !== '',
            );

            // Nothing configured (or only a stale OpenRouter key) → fake, so
            // dev never hard-fails AND a leftover OPENROUTER_API_KEY can't bind
            // as the sole provider and 401 every stream.
            if ($order === []) {
                return new FakeOpenAi;
            }

            // Lazy factories — only the providers actually in $order get built.
            /** @var array<string, callable(): OpenAiClient> $factories */
            $factories = [
                'cloudflare' => fn (): OpenAiClient => $makeCloudflare($cfChatModel),
                'cloudflare-fallback' => fn (): OpenAiClient => $makeCloudflare($cfFallbackModel),
                'openai' => fn (): OpenAiClient => new OpenAiHttpClient(
                    apiKey: $openAiKey,
                    chatModel: (string) config('services.openai.chat_model', env('OPENAI_CHAT_MODEL', 'gpt-4o-mini')),
                    embedModel: (string) config('services.openai.embed_model', env('OPENAI_EMBED_MODEL', 'text-embedding-3-small')),
                ),
                // OpenAI-compatible REST surface, so OpenAiHttpClient is reused
                // with OpenRouter's base URI + ranking headers. Embeddings
                // aren't on the free tier — IndexDocumentJob falls back to
                // OPENAI_API_KEY.
                'openrouter' => fn (): OpenAiClient => new OpenAiHttpClient(
                    apiKey: $openRouterKey,
                    chatModel: (string) (config('services.openrouter.chat_model') ?: env('OPENROUTER_CHAT_MODEL', 'meta-llama/llama-3.3-70b-instruct:free')),
                    embedModel: (string) env('OPENROUTER_EMBED_MODEL', 'text-embedding-3-small'),
                    baseUri: 'https://openrouter.ai/api/v1',
                    extraHeaders: [
                        'HTTP-Referer' => (string) env('APP_URL', 'https://pitchbar.dev'),
                        'X-Title' => AppBranding::siteTitle(),
                    ],
                ),
            ];

            // Expand the provider order into concrete failover entries,
            // splitting Cloudflare into primary + fallback CHAT model when
            // a distinct fallback is configured (the pure rule is
            // unit-tested in LlmProviderChainTest). This is what gives a
            // single-Cloudflare install model→model self-heal.
            $entries = LlmProviderChain::entries($order, $cfChatModel, $cfFallbackModel);

            // Exactly one entry → return it bare: no decorator, zero
            // overhead on the success path (the hot-path latency contract).
            if (count($entries) === 1) {
                return $factories[$entries[0]['name']]();
            }

            // Two or more → wrap in the runtime failover decorator.
            return new FailoverOpenAiClient(
                array_map(fn (array $entry): array => [
                    'name' => $entry['name'],
                    'client' => $factories[$entry['name']](),
                ], $entries),
                new WidgetEventRecorder,
            );
        });

        // Embedding fallback — resolved by IndexDocumentJob when the
        // primary embed call (usually Workers AI) fails. We default to
        // OpenAI when OPENAI_API_KEY is set; otherwise no fallback and
        // the primary error propagates as before.
        $this->app->bind('embedding.fallback', function () {
            if (app()->runningUnitTests()) {
                return null;
            }
            $key = (string) (config('services.openai.key') ?? env('OPENAI_API_KEY', ''));
            if ($key === '') {
                return null;
            }

            return new OpenAiHttpClient(
                apiKey: $key,
                chatModel: (string) (config('services.openai.chat_model') ?? env('OPENAI_CHAT_MODEL', 'gpt-4o-mini')),
                embedModel: (string) (config('services.openai.embed_model') ?? env('OPENAI_EMBED_MODEL', 'text-embedding-3-small')),
            );
        });

        $this->app->scoped(QdrantClient::class, function () {
            if (app()->runningUnitTests()) {
                return new FakeQdrant;
            }

            // C1: BYOK vector keys take precedence. The same resolver
            // path that drove the LLM client picks up the workspace's
            // Cloudflare / Qdrant keys when BYOK is unlocked.
            $byok = $this->resolveByokKeys('vector');
            if ($byok !== null) {
                return $this->byokQdrantClient($byok);
            }

            $provider = (string) config('services.vector.provider', '');

            // Cloudflare Vectorize (preferred). All credentials come
            // from config(), which AppSettingsOverrideServiceProvider
            // hydrates from app_settings at boot. .env reads happen
            // once at config build time in config/services.php; runtime
            // never re-reads env().
            $cfAccount = (string) config('services.cloudflare.account_id', '');
            $cfToken = (string) config('services.cloudflare.api_token', '');
            if ($provider === 'cloudflare' || ($provider === '' && $cfAccount !== '' && $cfToken !== '')) {
                return $this->wrapInCircuitBreaker(
                    VectorizeClient::default($cfAccount, $cfToken),
                    'vectorize',
                );
            }

            // Qdrant (fallback)
            $url = (string) config('services.qdrant.url', '');
            if ($url !== '') {
                return $this->wrapInCircuitBreaker(
                    new QdrantHttpClient(
                        baseUrl: $url,
                        apiKey: config('services.qdrant.api_key') ?: null,
                    ),
                    'qdrant',
                );
            }

            return new FakeQdrant;
        });

        $this->app->singleton(Crawler::class, function () {
            // Tiered fallback (ordered fastest/cheapest → slowest/costliest):
            //   1. Plain HTTP — works for SSR'd / static pages, free, fast.
            //   2. CF Browser Rendering /markdown — JS render + clean
            //      markdown extraction server-side. Strictly better than
            //      fetching HTML and stripping with our regex pipeline.
            //   3. CF Browser Rendering /content — JS render + our own
            //      Readability extractor. Catches cases where the markdown
            //      extractor's heuristics drop content (atypical layouts,
            //      tables that don't map to GFM cleanly).
            //   4. CF Vision (screenshot + Workers AI OCR) — last resort
            //      for canvas-rendered pages, all-image landings, embedded
            //      PDF viewers, slide decks. Only fires when every
            //      HTML-based tier produces text below the threshold.
            //
            // Browserless was removed from the default chain in 2026-06 —
            // CF /markdown + /content + Vision covers the same ground
            // free (CF charges Browser Rendering invocations + Workers AI
            // Neurons, both already on the customer's Cloudflare bill).
            // BrowserlessClient stays in the codebase for callers binding
            // it manually but no longer auto-registers.
            $tiers = [];

            $tiers[] = [
                'name' => 'plain_http',
                'client' => new PlainHttpCrawler($this->app->make(UrlSafetyGuard::class)),
            ];

            $cfAccount = (string) config('services.cloudflare.account_id', '');
            $cfToken = (string) config('services.cloudflare.api_token', '');
            $browserRendering = config('services.cloudflare.browser_rendering', true);
            if ($cfAccount !== '' && $cfToken !== '' && $browserRendering) {
                $tiers[] = [
                    'name' => 'cloudflare_browser_markdown',
                    'client' => CloudflareBrowserMarkdownCrawler::default($cfAccount, $cfToken),
                ];
                $tiers[] = [
                    'name' => 'cloudflare_browser',
                    'client' => CloudflareBrowserClient::default($cfAccount, $cfToken),
                ];

                $visionModel = (string) config('services.cloudflare.vision_model', '@cf/meta/llama-3.2-11b-vision-instruct');
                $aiGateway = (string) config('services.cloudflare.ai_gateway_url', '');
                $tiers[] = [
                    'name' => 'cloudflare_vision',
                    'client' => new CloudflareVisionCrawler(
                        CloudflareBrowserScreenshotClient::default($cfAccount, $cfToken),
                        CloudflareVisionClient::default(
                            $cfAccount,
                            $cfToken,
                            $visionModel,
                            $aiGateway !== '' ? $aiGateway : null,
                        ),
                    ),
                ];
            }

            return new ChainedCrawler(
                $tiers,
                $this->app->make(ReadabilityExtractor::class),
            );
        });

        $this->app->singleton(Reranker::class, function () {
            if (app()->runningUnitTests()) {
                return new FakeReranker;
            }

            // Reranker is optional (FakeReranker is a safe passthrough)
            // but admins who entered CF credentials in the UI expect
            // reranking to work too. Credentials resolve via config()
            // only — AppSettingsOverrideServiceProvider hydrates from
            // the DB at boot.
            $cfAccount = (string) config('services.cloudflare.account_id', '');
            $cfToken = (string) config('services.cloudflare.api_token', '');
            if ($cfAccount !== '' && $cfToken !== '' && config('services.rag.rerank_enabled', true)) {
                return CloudflareReranker::default($cfAccount, $cfToken);
            }

            // No CF keys → passthrough, retrieval still works.
            return new FakeReranker;
        });

        $this->app->singleton(NotionClient::class, fn () => NotionClient::default(
            (string) config('services.notion.client_id', ''),
            (string) config('services.notion.client_secret', ''),
        ));

        $this->app->singleton(GoogleClient::class, fn () => GoogleClient::default(
            (string) config('services.google.client_id', ''),
            (string) config('services.google.client_secret', ''),
        ));

        $this->app->singleton(WidgetJwt::class, function () {
            // firebase/php-jwt v6.10+ enforces a 256-bit (32-byte)
            // minimum key length for HS256 and throws DomainException
            // "Provided key is too short" otherwise. A fresh install
            // with the default placeholder or a hand-picked short
            // string (<32 bytes) would 500 every /widget/init call.
            // SHA-256 the configured secret unconditionally — same
            // signing key across requests (deterministic), always
            // 32 bytes, no change to existing tokens because the
            // signing key derivation is stable.
            $rawSecret = (string) config('services.widget.jwt_secret');
            if ($rawSecret === '' || $rawSecret === 'change-me-in-production') {
                // Fall through to APP_KEY so a fresh install with
                // only `php artisan key:generate` already has a real
                // signing key. APP_KEY is 32 bytes base64 by default.
                $rawSecret = (string) config('app.key');
            }
            $derived = hash('sha256', $rawSecret, true);

            return new WidgetJwt(
                secret: $derived,
                ttlMinutes: (int) config('services.widget.jwt_ttl_minutes', 60),
            );
        });

        // Cloudflare Workers AI `toMarkdown` — converts PDF / DOCX /
        // XLSX / ODT to structured markdown free of cost. Only bind
        // when CF credentials are configured AND we're not running
        // tests — feature tests opt into the CF parser by binding
        // their own mock, and unit tests should never hit real HTTP.
        // `scoped` so AppSettingsOverrideServiceProvider can rebind
        // CF config mid-process under Octane without freezing stale
        // creds into a long-lived singleton.
        if (! app()->runningUnitTests()) {
            $cfAccount = (string) config('services.cloudflare.account_id', '');
            $cfToken = (string) config('services.cloudflare.api_token', '');
            if ($cfAccount !== '' && $cfToken !== '') {
                $this->app->scoped(ToMarkdownClient::class, fn () => ToMarkdownClient::default(
                    (string) config('services.cloudflare.account_id', ''),
                    (string) config('services.cloudflare.api_token', ''),
                ));
            }
        }

        // ParserRegistry is `scoped` (not singleton) so each request gets a
        // fresh registry that re-resolves the optional Cloudflare parser.
        // Under Octane this matters when admin keys are rotated at runtime
        // (AppSettingsOverrideServiceProvider rebinds CF creds mid-process);
        // a singleton would freeze the parser list with the boot-time state.
        $this->app->scoped(ParserRegistry::class);

        // Vertical preset registry — pure in-memory lookup table of preset
        // classes. `scoped` keeps it cheap under Octane: built once per
        // worker, reused per request, no transitive request-scoped state.
        $this->app->scoped(VerticalPresetRegistry::class, fn () => new VerticalPresetRegistry);

        // Tool registry depends on the preset registry to resolve which
        // tools an agent's capabilities allow. `scoped` for the same
        // Octane reasons.
        $this->app->scoped(ToolRegistry::class, fn ($app) => new ToolRegistry(
            $app->make(VerticalPresetRegistry::class),
        ));

        // Fast router pieces. Both `scoped` — no request state on the
        // instances (config read inside methods, never in constructors),
        // rebuilt per request under Octane. ToolExemplarStore takes the
        // resolved OpenAiClient contract so tests transparently get
        // FakeOpenAi.
        $this->app->scoped(ToolExemplarStore::class, fn ($app) => new ToolExemplarStore(
            $app->make(OpenAiClient::class),
        ));
        $this->app->scoped(ToolIntentRouter::class, fn ($app) => new ToolIntentRouter(
            $app->make(ToolExemplarStore::class),
        ));

        // LocaleResolver caches its `lang/*.json` directory scan on the
        // instance. `scoped` reuses that memo per request (zero-cost
        // dispatch from middleware → Inertia share → suggestion banner)
        // while still recalculating on the next request — so dropping a
        // new `lang/<code>.json` is picked up on the next page load
        // without restarting Octane.
        $this->app->scoped(LocaleResolver::class, fn () => new LocaleResolver);

        // MCP client — bound `scoped` so a Guzzle/proxy config change
        // takes effect on the next request, and tests can replace with
        // FakeMcpClient. The HttpMcpClient is stateless wrt requests;
        // credentials are passed through the McpConnection DTO on
        // every call, never stored on the client instance.
        $this->app->scoped(McpClient::class, function () {
            if (app()->runningUnitTests()) {
                return new FakeMcpClient;
            }

            return new HttpMcpClient(
                http: new Client,
                urlGuard: app(UrlSafetyGuard::class),
                logger: app(LoggerInterface::class),
            );
        });

        // MCP credential resolver + server registry — both `scoped`
        // (per-request, Octane-safe). Registry depends on the resolver
        // for connectionFor(); resolver has no DB-touching constructor
        // deps so can be reused freely.
        $this->app->scoped(McpCredentialResolver::class);
        $this->app->scoped(McpServerRegistry::class);

        // Discovery is stateless and uses the bound client + registry.
        // Bound `scoped` so background jobs resolve a fresh instance
        // each run.
        $this->app->scoped(McpToolDiscovery::class);

        // Circuit breaker + executor — both `scoped`. Breaker holds no
        // mutable state on the instance (everything in cache); executor
        // is stateless wrt requests. The runtime tool path resolves the
        // executor per turn.
        $this->app->scoped(McpCircuitBreaker::class);
        $this->app->scoped(McpToolExecutor::class);
    }

    /**
     * C1: returns the workspace's BYOK key map when BYOK is unlocked
     * for the current visitor / authenticated user, else null. The
     * scope hint (`llm` or `vector`) lets the caller decide which
     * subset of keys to require.
     */
    private function resolveByokKeys(string $scope): ?array
    {
        try {
            $workspace = app(CurrentWorkspace::class)->get();
        } catch (\Throwable) {
            return null;
        }
        if ($workspace === null) {
            return null;
        }

        $resolver = app(ByokResolver::class);
        // Resolver tolerates a null user — for visitor (widget) flows
        // there's no authenticated user and we fall through to the
        // global toggle.
        $user = auth()->user();
        if (! $resolver->isUnlockedFor($user, $workspace)) {
            return null;
        }

        $keys = $resolver->keysFor($workspace);

        if ($scope === 'llm') {
            $hasAny = ! empty($keys['cloudflare_api_token'])
                || ! empty($keys['openai_api_key'])
                || ! empty($keys['openrouter_api_key']);
            if (! $hasAny) {
                return null;
            }
        }

        if ($scope === 'vector') {
            $hasAny = ! empty($keys['cloudflare_api_token'])
                || ! empty($keys['qdrant_url']);
            if (! $hasAny) {
                return null;
            }
        }

        return $keys;
    }

    /**
     * Build an OpenAiClient from a workspace's BYOK key map. Selects
     * the same provider order as the platform fallback (Cloudflare →
     * OpenRouter → OpenAI), but using the workspace's own credentials.
     */
    private function byokOpenAiClient(array $keys): OpenAiClient
    {
        if (! empty($keys['cloudflare_account_id']) && ! empty($keys['cloudflare_api_token'])) {
            return new WorkersAiClient(
                accountId: (string) $keys['cloudflare_account_id'],
                apiToken: (string) $keys['cloudflare_api_token'],
                chatModel: (string) ($keys['cloudflare_chat_model'] ?? '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
                embedModel: (string) ($keys['cloudflare_embed_model'] ?? '@cf/baai/bge-base-en-v1.5'),
                aiGatewayUrl: $keys['cloudflare_ai_gateway_url'] ?? null,
            );
        }

        if (! empty($keys['openai_api_key'])) {
            return new OpenAiHttpClient(
                apiKey: (string) $keys['openai_api_key'],
                chatModel: (string) ($keys['openai_chat_model'] ?? 'gpt-4o-mini'),
                embedModel: (string) ($keys['openai_embed_model'] ?? 'text-embedding-3-small'),
            );
        }

        if (! empty($keys['openrouter_api_key'])) {
            return new OpenAiHttpClient(
                apiKey: (string) $keys['openrouter_api_key'],
                chatModel: (string) ($keys['openrouter_chat_model'] ?? 'meta-llama/llama-3.3-70b-instruct:free'),
                embedModel: (string) ($keys['openrouter_embed_model'] ?? 'text-embedding-3-small'),
                baseUri: 'https://openrouter.ai/api/v1',
                extraHeaders: [
                    'HTTP-Referer' => (string) env('APP_URL', 'https://pitchbar.dev'),
                    'X-Title' => AppBranding::siteTitle(),
                ],
            );
        }

        // Should not reach here — resolveByokKeys() returns null when
        // no usable keys are present. Defensive fallback to fake.
        return new FakeOpenAi;
    }

    /**
     * Build a QdrantClient from a workspace's BYOK key map. Cloudflare
     * Vectorize comes first (shares the workspace Cloudflare account),
     * Qdrant second.
     */
    private function byokQdrantClient(array $keys): QdrantClient
    {
        if (! empty($keys['cloudflare_account_id']) && ! empty($keys['cloudflare_api_token'])) {
            return VectorizeClient::default(
                (string) $keys['cloudflare_account_id'],
                (string) $keys['cloudflare_api_token'],
            );
        }

        if (! empty($keys['qdrant_url'])) {
            return new QdrantHttpClient(
                baseUrl: (string) $keys['qdrant_url'],
                apiKey: $keys['qdrant_api_key'] ?? null,
            );
        }

        return new FakeQdrant;
    }

    /**
     * Wrap a vector client with a circuit breaker. Configurable via
     * VECTOR_CIRCUIT_THRESHOLD / VECTOR_CIRCUIT_COOLDOWN /
     * VECTOR_CIRCUIT_WINDOW. Fakes bypass the wrap.
     */
    private function wrapInCircuitBreaker(QdrantClient $delegate, string $label): QdrantClient
    {
        if ($delegate instanceof FakeQdrant) {
            return $delegate;
        }

        return new CircuitBreakingVectorClient(
            delegate: $delegate,
            clientLabel: $label,
            failureThreshold: (int) config('services.vector.circuit_threshold', 5),
            windowSeconds: (int) config('services.vector.circuit_window', 60),
            cooldownSeconds: (int) config('services.vector.circuit_cooldown', 60),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Wrap Laravel's error handler so a deprecation raised while the
        // container is flushed cannot fatal the request. Installed here
        // rather than in bootstrap/app.php because HandleExceptions registers
        // its handler during bootstrapWith(), i.e. before providers boot —
        // we must land on top of it. Idempotent, so Octane's per-request
        // provider boot cannot nest handlers. See #492.
        DeprecationGuard::install();

        // Belt-and-braces registration of the `mail::` view namespace.
        // MailServiceProvider already does this, but a buyer report
        // surfaced "InvalidArgumentException: No hint path defined for
        // [mail]" when the WorkspaceInvitation job ran on a shared host
        // — likely from a stale config cache or a custom provider list
        // that excluded MailServiceProvider. Pointing the namespace at
        // our published views (resources/views/vendor/mail/) means the
        // hint resolves even when the vendor provider doesn't fire.
        //
        // OCTANE-SAFETY: View\FileViewFinder::addNamespace array-merges
        // the path into a singleton hints[] array on every call. Calling
        // loadViewsFrom() unconditionally in boot() would append the
        // same path on every request, growing the array unbounded under
        // Octane. Guard the registration so the path is added at most
        // once per worker.
        $mailViews = resource_path('views/vendor/mail');
        if (is_dir($mailViews)) {
            $finder = $this->app['view']->getFinder();
            $existing = (array) ($finder->getHints()['mail'] ?? []);
            if (! in_array($mailViews, $existing, true)) {
                $this->loadViewsFrom($mailViews, 'mail');
            }
        }

        // Workspace is the billing entity, not User. One Stripe customer per
        // workspace, so the same person can own/admin multiple workspaces
        // each with its own subscription and plan.
        Cashier::useCustomerModel(Workspace::class);

        // Stripe Tax — opt-in. Enables automatic_tax (+ tax-ID
        // collection) on every NEW Cashier subscription, one-off
        // invoice, and Checkout session. Default OFF because flipping
        // this while Stripe Tax is not activated in the operator's
        // Stripe dashboard (origin address + tax registrations) makes
        // every checkout fail. Existing subscriptions are NOT updated
        // retroactively. See /documentation/billing.
        if ((bool) config('services.stripe_tax.enabled', false)) {
            Cashier::calculateTaxes();
        }

        // We register our own webhook route at /billing/webhook (extending
        // Cashier's controller). Suppress Cashier's default route under
        // /stripe/* to avoid two endpoints handling the same payload.
        Cashier::ignoreRoutes();

        // Live-chat handoff: ping configured Slack / Teams webhooks
        // when a visitor asks for a human. Listener runs on the queue
        // so the visitor's request-human HTTP latency stays predictable.
        Event::listen(HumanRequestedEvent::class, LiveChatNotifier::class);

        // Mirror captured leads back into the WordPress site behind
        // the agent (when one is attached). Queued — never blocks the
        // visitor's lead-capture HTTP turn.
        Event::listen(LeadCapturedEvent::class, PushLeadToWordPress::class);

        // Per-job lifecycle log → the platform-admin "Queue health"
        // widget tails this table to show RUNNING / DONE / FAILED rows
        // live. Listener is synchronous (NOT queued) — it has to
        // record events from inside the queue worker itself.
        Event::subscribe(RecordJobRun::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Public widget routes need their own throttles. These endpoints are hit
     * from third-party sites, so we segment by IP for init and by widget token
     * for follow-up session calls to avoid one noisy visitor suppressing every
     * other active conversation.
     */
    protected function configureRateLimiting(): void
    {
        // Widget endpoints used to ALSO segment by IP. That fails on the
        // very-common case of many visitors sitting behind one NAT or
        // proxy (corporate VPN, mobile carriers, shared WiFi, CDN edge
        // gateway) — the FIRST visitor's request count poisons every
        // other visitor on the same IP and they all start getting 429
        // even though they're separate people on different conversations.
        //
        // Fix: segment by the WIDGET TOKEN (issued per-conversation,
        // per-visitor at /widget/init). Real abuse from one conversation
        // still trips; benign sharing across NAT does not. The /init
        // endpoint runs BEFORE a token exists, so it keeps a soft IP
        // bucket — but at a level high enough that a small office
        // bursting through the marketing site never sees 429.
        RateLimiter::for('widget-init', function (Request $request) {
            $ip = $request->ip() ?? 'unknown';
            $agentId = Str::lower((string) $request->input('agent_id', 'missing-agent'));

            // Soft per-IP limit on init only. 1000/min/IP+agent +
            // 30000/hr/IP comfortably absorbs a packed coworking
            // office or a college campus on one outbound IP.
            return [
                Limit::perMinute(1000)
                    ->by("widget-init:minute:{$ip}:{$agentId}")
                    ->response($this->widgetThrottleResponse('Too many widget initialization requests. Please try again shortly.')),
                Limit::perHour(30000)
                    ->by("widget-init:hour:{$ip}")
                    ->response($this->widgetThrottleResponse('Too many widget initialization requests. Please try again later.')),
            ];
        });

        RateLimiter::for('widget-session', function (Request $request) {
            // PER-TOKEN ONLY. 300/min/token is generous for a real
            // visitor (streaming chat fires a handful of requests per
            // turn, not hundreds). The token is unique to one
            // (visitor, conversation, agent) tuple — abuse from one
            // bucket is the same visitor flooding their own session.
            $tokenKey = $this->widgetTokenThrottleKey($request);

            return [
                Limit::perMinute(300)
                    ->by("widget-session:token:{$tokenKey}")
                    ->response($this->widgetThrottleResponse('Too many widget requests for this conversation. Please slow down.')),
            ];
        });

        RateLimiter::for('wp-plugin', function (Request $request) {
            // Bulk-sync endpoints — keyed by the resolved workspace api
            // token id; a single buyer's plugin shouldn't be able to
            // spam the sync pipeline. 60/min covers a 5000-post initial
            // sync split into legitimate batches.
            $token = $request->attributes->get('api_token');
            $key = is_object($token) && isset($token->id)
                ? 'wp-plugin:'.$token->id
                : 'wp-plugin:ip:'.$request->ip();

            return [Limit::perMinute(60)->by($key)];
        });

        RateLimiter::for('widget-leads', function (Request $request) {
            // Per-token only. A real visitor submits one lead per
            // conversation; 30/min is the abuse floor without making
            // NAT-shared lead-form bursts look like 429-able traffic.
            $tokenKey = $this->widgetTokenThrottleKey($request);

            return [
                Limit::perMinute(30)
                    ->by("widget-leads:token:{$tokenKey}")
                    ->response($this->widgetThrottleResponse('Too many lead submissions. Please wait before trying again.')),
            ];
        });

        RateLimiter::for('try-now-start', function (Request $request) {
            // Anonymous URL ingest from the marketing hero. Each
            // ingest fetches an external page synchronously, so we
            // cap aggressively per IP. Real demo traffic is one
            // ingest per visitor.
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(10)
                    ->by("try-now-start:{$ip}")
                    ->response($this->widgetThrottleResponse('Too many try-now requests from this connection. Please wait a minute.')),
                Limit::perHour(60)
                    ->by("try-now-start-hour:{$ip}")
                    ->response($this->widgetThrottleResponse('Too many try-now requests from this connection. Please try again later.')),
            ];
        });

        RateLimiter::for('try-now-stream', function (Request $request) {
            // Per-token streaming throttle. One visitor with one
            // ingested page; 60 messages/min is the abuse floor.
            $token = (string) $request->input('token', 'missing');
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(60)
                    ->by("try-now-stream:{$token}:{$ip}")
                    ->response($this->widgetThrottleResponse('Too many messages for this demo session. Please slow down.')),
            ];
        });
    }

    /**
     * @return \Closure(Request, array<string, string>): JsonResponse
     */
    protected function widgetThrottleResponse(string $message): \Closure
    {
        return fn (Request $request, array $headers) => response()->json([
            'error' => [
                'code' => 'rate_limited',
                'message' => $message,
            ],
        ], 429, $headers);
    }

    protected function widgetTokenThrottleKey(Request $request): string
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');

        if (! is_string($token) || $token === '') {
            return 'missing-token';
        }

        return substr(hash('sha256', $token), 0, 20);
    }
}
