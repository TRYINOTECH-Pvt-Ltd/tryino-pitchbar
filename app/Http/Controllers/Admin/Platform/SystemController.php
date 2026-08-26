<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\CronTickLog;
use App\Models\Lead;
use App\Notifications\NewLeadCaptured;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\RazorpayClient;
use App\Services\Llm\CloudflareModelFetcher;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\ModelCatalog;
use App\Services\Llm\ModelLatencyProbe;
use App\Support\AppBranding;
use App\Support\AuditLogger;
use App\Support\IntegrationCardsContent;
use App\Support\LlmErrorPresenter;
use App\Support\MarketingHomeContent;
use App\Support\MarketingTheme;
use App\Support\PrivacyPolicyContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;

/**
 * Platform-admin "system config + health" page. Two surfaces in one:
 *
 *   1. Editable forms per provider (Stripe, Cloudflare, OpenAI,
 *      OpenRouter, Mail, branding) → persists into the singleton
 *      `app_settings` row → AppSettingsOverrideServiceProvider applies
 *      the values to config() on the next request.
 *
 *   2. One-click smoke tests that exercise the currently-bound provider
 *      end-to-end so the admin can confirm a freshly-rotated key works.
 *
 * Sensitive fields (api keys, webhook secrets, mail password) are NEVER
 * sent back to the frontend in plaintext — only a `_set` boolean
 * indicating that a value is currently stored. Submitting the form with
 * an empty value for a sensitive field leaves the existing value
 * untouched; submitting a new value replaces it.
 *
 * All test endpoints catch every Throwable and return `{ok, message}`
 * with HTTP 200 so the UI can render the result consistently.
 */
class SystemController
{
    public function index(): Response
    {
        return $this->renderPage('system');
    }

    public function branding(): Response
    {
        return $this->renderPage('branding');
    }

    public function marketing(): Response
    {
        return $this->renderPage('marketing');
    }

    public function privacy(): Response
    {
        return $this->renderPage('privacy');
    }

    private function renderPage(string $page): Response
    {
        $settings = AppSetting::singleton();

        return Inertia::render('settings/system', [
            'page' => $page,
            'sections' => [
                'mail' => $this->mailSummary(),
                'stripe' => $this->stripeSummary(),
                'paypal' => $this->paypalSummary(),
                'razorpay' => $this->razorpaySummary(),
                'llm' => $this->llmSummary(),
                'cache' => $this->cacheSummary(),
                'vector' => $this->vectorSummary(),
                'reverb' => $this->reverbSummary(),
                'marketing' => [
                    'customized' => ! empty($settings->marketing_home_content),
                    // List of published agents available as the
                    // marketing-site widget. Super-admin scope: every
                    // workspace's published agent shown so the operator
                    // can pick from any tenant. Empty list = no agents
                    // published yet.
                    'agent_options' => Agent::query()
                        ->withoutGlobalScopes()
                        ->where('is_published', true)
                        ->orderBy('name')
                        ->limit(200)
                        ->get(['id', 'name', 'workspace_id'])
                        ->map(fn ($a) => [
                            'id' => $a->id,
                            'label' => $a->name,
                        ])
                        ->values(),
                    // Theme registry for the picker. `slugs` is the
                    // validation set so the frontend can't post an
                    // unknown value.
                    'theme_options' => MarketingTheme::available(),
                ],
                'privacy' => [
                    'customized' => ! empty($settings->privacy_policy_content),
                ],
                'cron_worker' => array_merge([
                    'deployed' => ! empty($settings->cron_worker_name) && $settings->cron_worker_deployed_at !== null,
                    'worker_name' => $settings->cron_worker_name,
                    'deployed_at' => $settings->cron_worker_deployed_at?->toIso8601String(),
                    'last_status' => $settings->cron_worker_last_status,
                    'last_status_at' => $settings->cron_worker_last_status_at?->toIso8601String(),
                    'cloudflare_configured' => ! empty(
                        $settings->cloudflare_account_id
                            ?: config('services.cloudflare.account_id', '')
                    ) && ! empty(
                        $settings->cloudflare_api_token
                            ?: config('services.cloudflare.api_token', '')
                    ),
                    'callback_url' => rtrim((string) config('app.url'), '/').'/api/v1/internal/queue-tick',
                ], $this->cronWorkerHealth()),
            ],
            'form' => $this->formValues($settings),
        ]);
    }

    /**
     * Health stats for the Cron Worker tab. Aggregates the
     * cron_tick_logs table so the admin can see "is the Cloudflare
     * Worker actually firing AND actually doing work?" without
     * leaving the dashboard.
     *
     * @return array{
     *   last_tick_at: ?string,
     *   seconds_since_last_tick: ?int,
     *   liveness: 'live'|'stale'|'dead'|'unknown',
     *   ticks_last_hour: int,
     *   ticks_last_24h: int,
     *   jobs_processed_last_hour: int,
     *   jobs_processed_last_24h: int,
     *   pending_jobs: int,
     *   failed_jobs: int,
     *   recent_ticks: array<int, array{at: string|null, processed: int, failed: int, remaining: int, ms: int}>,
     * }
     */
    private function cronWorkerHealth(): array
    {
        $latest = CronTickLog::query()->orderByDesc('id')->first();
        $now = now();
        $secondsSince = $latest ? (int) $latest->received_at->diffInSeconds($now) : null;
        $liveness = match (true) {
            $latest === null => 'unknown',
            $secondsSince !== null && $secondsSince <= 90 => 'live',
            $secondsSince !== null && $secondsSince <= 300 => 'stale',
            default => 'dead',
        };

        $hourAgo = $now->copy()->subHour();
        $dayAgo = $now->copy()->subDay();

        $recent = CronTickLog::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get(['received_at', 'processed', 'failed_in_tick', 'remaining_pending', 'elapsed_ms'])
            ->map(fn ($r) => [
                'at' => $r->received_at?->toIso8601String(),
                'processed' => (int) $r->processed,
                'failed' => (int) $r->failed_in_tick,
                'remaining' => (int) $r->remaining_pending,
                'ms' => (int) $r->elapsed_ms,
            ])
            ->values()
            ->all();

        return [
            'last_tick_at' => $latest?->received_at?->toIso8601String(),
            'seconds_since_last_tick' => $secondsSince,
            'liveness' => $liveness,
            'ticks_last_hour' => (int) CronTickLog::query()->where('received_at', '>=', $hourAgo)->count(),
            'ticks_last_24h' => (int) CronTickLog::query()->where('received_at', '>=', $dayAgo)->count(),
            'jobs_processed_last_hour' => (int) CronTickLog::query()->where('received_at', '>=', $hourAgo)->sum('processed'),
            'jobs_processed_last_24h' => (int) CronTickLog::query()->where('received_at', '>=', $dayAgo)->sum('processed'),
            'pending_jobs' => (int) \DB::table('jobs')->count(),
            'failed_jobs' => (int) \DB::table('failed_jobs')->count(),
            'recent_ticks' => $recent,
        ];
    }

    public function update(Request $request, string $section): RedirectResponse
    {
        $rules = $this->validationRulesFor($section);

        if ($rules === null) {
            abort(404);
        }

        $data = $request->validate($rules);

        $settings = null;

        if ($section === 'branding') {
            $settings = AppSetting::singleton();
            $data = $this->prepareBrandingData($data, $settings);
        }

        // Strip blanks — those mean "leave the existing value alone",
        // not "clear it". (To clear a value, the admin can type the
        // string "null" via the dedicated UI later; for now blank ==
        // no change.)
        //
        // Exception: a small allowlist of nullable keys where blank
        // legitimately means "clear me" (e.g. unset the marketing-site
        // widget agent picker so toggling back ON re-prompts the
        // admin). For those keys we keep nulls through the strip.
        $arraySections = ['pricing', 'integrations'];
        $nullableClearKeys = ['marketing_widget_agent_id'];
        $data = array_filter(
            $data,
            static fn ($v, $k) => ($v !== '' && $v !== null) || in_array($k, $nullableClearKeys, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($section === 'marketing' && array_key_exists('marketing_home_content', $data)) {
            $data['marketing_home_content'] = MarketingHomeContent::resolve($data['marketing_home_content']);
        }

        if ($section === 'privacy' && array_key_exists('privacy_policy_content', $data)) {
            $data['privacy_policy_content'] = PrivacyPolicyContent::resolve($data['privacy_policy_content']);
        }

        // Allow the pricing / integrations sections to send empty
        // arrays — those legitimately mean "reset to default fallback".
        if (in_array($section, $arraySections, true)) {
            foreach (['pricing_faqs', 'pricing_matrix', 'integrations_enabled', 'integration_cards'] as $key) {
                if ($request->has($key) && ! isset($data[$key])) {
                    $data[$key] = [];
                }
            }
        }

        // Normalise integration_cards through the support class so the
        // stored shape is canonical (`{key,name,category,tagline,description}`)
        // and discards anonymous extras the admin didn't ship.
        if ($section === 'integrations' && array_key_exists('integration_cards', $data)) {
            $data['integration_cards'] = IntegrationCardsContent::normalise(
                is_array($data['integration_cards']) ? $data['integration_cards'] : []
            );
        }

        if ($data === []) {
            return back()->with('error', 'No '.str_replace('_', ' ', $section).' settings changes were received.');
        }

        $settings ??= AppSetting::singleton();
        $settings->fill($data);
        $settings->save();
        AppSetting::flushSingleton();

        // Marketing widget settings changes — drop the demo-agent
        // caches so the toggle (or agent-id swap) takes effect on
        // the next request. Otherwise admins would see the change
        // up to 5 minutes later because MarketingDemoAgent caches
        // the explicit-id validity (2 min) and the auto-discovered
        // demo agent id (5 min). Client report 2026-05-23: "if I
        // enable, disable the checkbox, it is not displayed right
        // away, I have to wait some time".
        if (array_intersect(array_keys($data), ['marketing_widget_enabled', 'marketing_widget_agent_id']) !== []) {
            Cache::forget('marketing.demo_agent_id');
            $explicit = config('services.marketing.demo_agent_id');
            if (is_string($explicit) && $explicit !== '') {
                Cache::forget('marketing.demo_agent_id.explicit_valid.'.$explicit);
            }
        }

        // Platform-level setting changes are super-admin only and impact
        // every workspace. We can't tag them to a single workspace, so
        // they go on the acting user's default_workspace_id (always set
        // for super_admins via the impersonation/onboarding flow). Secret
        // values are stripped before write so the audit row never leaks
        // a Stripe key or SMTP password — only the changed key names.
        $auditWorkspace = $request->user()?->default_workspace_id;
        if ($auditWorkspace !== null) {
            $auditSafeKeys = collect(array_keys($data))
                ->reject(fn ($k) => preg_match('/_(secret|password|api_key|token)$/i', (string) $k))
                ->values()
                ->all();
            AuditLogger::log(
                workspaceId: $auditWorkspace,
                action: 'platform_settings.updated',
                entityType: 'app_setting',
                entityId: (string) AppSetting::SINGLETON_ID,
                after: ['section' => $section, 'keys' => $auditSafeKeys],
                request: $request,
            );
        }

        // Marketing widget side-effect: if the operator just enabled
        // the widget on a published agent, make sure the agent's
        // allowed_origins contains APP_URL. Without that the widget
        // loads but every /api/v1/widget/* call returns 403 — and the
        // operator has no way to spot the mismatch from this page.
        // We auto-add APP_URL (normalised to scheme://host) rather
        // than reject the save, because rejecting would surprise the
        // operator who's already on /settings/marketing.
        $announcement = null;
        if (
            $section === 'marketing'
            && ($settings->marketing_widget_enabled ?? false)
            && ! empty($settings->marketing_widget_agent_id)
        ) {
            $announcement = $this->ensureMarketingAgentAllowsAppUrl($settings->marketing_widget_agent_id);
        }

        return back()->with('success', ucfirst($section).' settings saved.'.($announcement ? ' '.$announcement : ''));
    }

    /**
     * Add APP_URL's scheme://host to the picked marketing-widget
     * agent's allowed_origins when it isn't already present. Returns
     * a human-readable message describing what happened, or null when
     * no change was needed.
     */
    private function ensureMarketingAgentAllowsAppUrl(string $agentId): ?string
    {
        $appUrl = (string) config('app.url');
        if ($appUrl === '') {
            return null;
        }

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        $port = parse_url($appUrl, PHP_URL_PORT);
        $needed = strtolower($scheme).'://'.strtolower($host).(is_int($port) ? ':'.$port : '');

        $agent = Agent::query()->withoutGlobalScopes()->find($agentId);
        if ($agent === null) {
            return null;
        }

        $current = array_values(array_filter(array_map(
            static fn ($o) => is_string($o) ? trim($o) : '',
            (array) ($agent->allowed_origins ?? []),
        ), static fn ($o) => $o !== ''));

        foreach ($current as $existing) {
            $existingHost = parse_url($existing, PHP_URL_HOST);
            $existingScheme = parse_url($existing, PHP_URL_SCHEME);
            $existingPort = parse_url($existing, PHP_URL_PORT);
            if (! is_string($existingHost) || ! is_string($existingScheme)) {
                continue;
            }
            $normalised = strtolower($existingScheme).'://'.strtolower($existingHost).(is_int($existingPort) ? ':'.$existingPort : '');
            if ($normalised === $needed || $existing === '*') {
                return null; // Already allowed.
            }
        }

        $agent->forceFill([
            'allowed_origins' => [...$current, $needed],
        ])->save();

        return sprintf('Added "%s" to the agent\'s allowed_origins so the widget can load on the marketing site.', $needed);
    }

    public function testMail(Request $request): JsonResponse
    {
        $to = (string) $request->user()->email;

        try {
            Mail::raw(
                "This is a Pitchbar SMTP test from the admin System page.\n\n".
                'If you received this, your mail configuration is wired up correctly.',
                function ($message) use ($to) {
                    $message->to($to)
                        ->subject('Pitchbar — SMTP test');
                },
            );

            return $this->ok("Test email queued for {$to} via ".config('mail.default'));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * End-to-end probe of the captured-lead notification path:
     * dispatches a queued NewLeadCaptured notification with a
     * synthetic Lead so the admin can confirm the queue worker is
     * picking jobs up AND the actual email template renders. Buyer
     * reports of "leads not arriving" almost always trace back to
     * MAIL_MAILER=log or no queue worker; the raw SMTP test above
     * catches the first but not the second. This one catches both.
     *
     * Synthetic lead is built in-memory and never persisted —
     * sending the notification directly to the admin's email avoids
     * a migration on production data and side-stepping multi-tenancy.
     */
    public function testLeadEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        $to = (string) $user->email;

        try {
            $lead = new Lead([
                'email' => 'demo-lead@example.com',
                'name' => 'Demo Lead',
                'phone' => null,
            ]);
            // forceFill stamps an id + timestamps that the email
            // template references without requiring a DB row.
            $lead->forceFill([
                'id' => (string) Str::uuid7(),
                'agent_id' => 'demo-agent',
                'workspace_id' => 'demo-workspace',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Notification::route('mail', $to)
                ->notify(new NewLeadCaptured($lead));

            $queueDriver = (string) config('queue.default');
            $mailDriver = (string) config('mail.default');

            return $this->ok(
                "Test lead-captured email dispatched for {$to} "
                ."(queue={$queueDriver}, mail={$mailDriver}). "
                .'If the queue worker is running and mail is wired up, '
                .'the email arrives within seconds.'
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function testStripe(): JsonResponse
    {
        $secret = (string) (config('cashier.secret') ?? '');

        if ($secret === '') {
            return $this->fail('STRIPE_SECRET is not configured.');
        }

        try {
            $stripe = new StripeClient($secret);
            $stripe->balance->retrieve();

            return $this->ok('Stripe API reachable with the configured key.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function testPayPal(): JsonResponse
    {
        $client = PayPalClient::fromConfig();

        if (! $client->isConfigured()) {
            return $this->fail('PayPal client_id / client_secret is not configured.');
        }

        try {
            // Fresh REST client cycle: this triggers the OAuth token fetch,
            // which is the same endpoint we'd hit for any real PayPal call.
            // A 200 here proves credentials + mode are valid end-to-end.
            Http::baseUrl($client->baseUrl())
                ->withBasicAuth(
                    (string) config('services.paypal.client_id'),
                    (string) config('services.paypal.client_secret'),
                )
                ->asForm()
                ->acceptJson()
                ->throw()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            return $this->ok('PayPal API reachable in '.config('services.paypal.mode').' mode.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function testRazorpay(): JsonResponse
    {
        $client = RazorpayClient::fromConfig();

        if (! $client->isConfigured()) {
            return $this->fail('Razorpay key_id / key_secret is not configured.');
        }

        try {
            // /v1/payments?count=1 is a cheap, idempotent "list nothing"
            // call that confirms the key pair authenticates and the API
            // surface is reachable from this install.
            Http::baseUrl('https://api.razorpay.com')
                ->withBasicAuth(
                    (string) config('services.razorpay.key_id'),
                    (string) config('services.razorpay.key_secret'),
                )
                ->acceptJson()
                ->throw()
                ->get('/v1/payments', ['count' => 1]);

            return $this->ok('Razorpay API reachable with the configured key pair.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function testLlm(): JsonResponse
    {
        try {
            $client = app(OpenAiClient::class);
            $tokens = [];

            foreach ($client->streamChat(
                [['role' => 'user', 'content' => 'Reply with the single word "ok".']],
                ['max_tokens' => 8],
            ) as $tok) {
                $tokens[] = $tok;

                if (count($tokens) >= 16) {
                    break;
                }
            }

            $text = trim(implode('', $tokens));

            if ($text !== '') {
                return $this->ok('LLM responded: '.mb_substr($text, 0, 80));
            }

            // Streaming returned zero tokens but no exception was
            // thrown. That used to surface as "LLM stream returned no
            // tokens." which was a dead end for the buyer. Fall back
            // to a non-streaming call (`chatWithTools`) to figure out
            // whether the provider is healthy AT ALL or whether
            // streaming-specifically is broken. Buyer report
            // 2026-05-29: non-Llama Cloudflare models hit this path.
            $nonStream = $client->chatWithTools(
                messages: [['role' => 'user', 'content' => 'Reply with the single word "ok".']],
                tools: [],
                opts: ['max_tokens' => 8],
            );
            $nonStreamText = trim((string) ($nonStream['content'] ?? ''));

            if ($nonStreamText === '') {
                return $this->fail(
                    'No content via streaming OR non-streaming — the configured model has no OpenAI-compatible chat endpoint. Switch to a streaming-capable slug like @cf/meta/llama-3.3-70b-instruct-fp8-fast.'
                );
            }

            return $this->fail(sprintf(
                'Non-streaming worked (%s) but streaming returned zero tokens. The model lacks OpenAI-style streaming. Pick a streaming-capable @cf/meta/llama-* slug instead.',
                mb_substr($nonStreamText, 0, 40),
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Live latency probe per provider+model. Runs a one-token streamChat,
     * records first-token + total wall time, caches 24h. Buyer hits this
     * via the "Test connection" button on the model dropdown — answers
     * "is gpt-4o-mini actually fast from my server?" without making them
     * trust the published estimate.
     *
     * Rate-limited 10/min per admin so a chatty UI does not hammer the
     * provider's API.
     */
    public function probeLatency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:cloudflare,openai,openrouter'],
            'model' => ['required', 'string', 'max:255'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $key = 'llm-latency-probe:'.($request->user()?->id ?? $request->ip());
        if (Cache::get($key, 0) >= 10) {
            return response()->json([
                'ok' => false,
                'error' => 'Too many probes. Wait a minute and try again.',
            ], 429);
        }
        Cache::put($key, Cache::get($key, 0) + 1, now()->addMinute());

        $result = app(ModelLatencyProbe::class)->measure(
            $data['provider'],
            $data['model'],
            (bool) ($data['force'] ?? true),
        );

        return response()->json($result);
    }

    /**
     * Force-refresh the live Cloudflare model list. Returns the merged
     * static + live catalogue so the UI can re-render immediately.
     */
    public function refreshCloudflareModels(Request $request): JsonResponse
    {
        $key = 'llm-cf-models-refresh:'.($request->user()?->id ?? $request->ip());
        if (Cache::get($key, 0) >= 5) {
            return response()->json([
                'ok' => false,
                'error' => 'Too many refreshes. Wait a minute and try again.',
            ], 429);
        }
        Cache::put($key, Cache::get($key, 0) + 1, now()->addMinute());

        $fetched = app(CloudflareModelFetcher::class)->fetch(force: true);

        return response()->json($fetched);
    }

    public function testEmbed(): JsonResponse
    {
        try {
            $client = app(OpenAiClient::class);
            $vectors = $client->embed(['pitchbar health check']);

            if (empty($vectors) || ! is_array($vectors[0])) {
                return $this->fail('Embed call returned no vectors.');
            }

            return $this->ok('Embed produced a '.count($vectors[0]).'-dim vector.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function testCache(): JsonResponse
    {
        try {
            $key = 'pb:system:health:'.uniqid();
            $value = 'ok-'.now()->getTimestamp();

            Cache::put($key, $value, now()->addSeconds(10));
            $read = Cache::get($key);
            Cache::forget($key);

            if ($read !== $value) {
                return $this->fail('Cache write/read mismatch (got: '.var_export($read, true).').');
            }

            return $this->ok('Cache write/read/forget cycle succeeded on driver: '.config('cache.default'));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Validation rules per section. Returns null for unknown sections
     * so the controller 404s consistently.
     *
     * @return array<string, array<int, string>>|null
     */
    private function validationRulesFor(string $section): ?array
    {
        return match ($section) {
            'stripe' => [
                'stripe_key' => ['nullable', 'string', 'max:255'],
                'stripe_secret' => ['nullable', 'string', 'max:255'],
                'stripe_webhook_secret' => ['nullable', 'string', 'max:255'],
                'cashier_currency' => ['nullable', 'string', 'max:8'],
            ],
            'paypal' => [
                'paypal_mode' => ['nullable', 'string', 'in:live,sandbox'],
                'paypal_client_id' => ['nullable', 'string', 'max:500'],
                'paypal_client_secret' => ['nullable', 'string', 'max:500'],
                'paypal_webhook_id' => ['nullable', 'string', 'max:255'],
            ],
            'razorpay' => [
                'razorpay_key_id' => ['nullable', 'string', 'max:255'],
                'razorpay_key_secret' => ['nullable', 'string', 'max:500'],
                'razorpay_webhook_secret' => ['nullable', 'string', 'max:500'],
            ],
            'gateways' => [
                'stripe_enabled' => ['sometimes', 'boolean'],
                'paypal_enabled' => ['sometimes', 'boolean'],
                'razorpay_enabled' => ['sometimes', 'boolean'],
            ],
            'cloudflare' => [
                'cloudflare_account_id' => ['nullable', 'string', 'max:255'],
                'cloudflare_api_token' => ['nullable', 'string', 'max:500'],
                'cloudflare_chat_model' => ['nullable', 'string', 'max:255'],
                'cloudflare_embed_model' => ['nullable', 'string', 'max:255'],
                'cloudflare_vectorize_index' => ['nullable', 'string', 'max:255'],
                'cloudflare_ai_gateway_url' => ['nullable', 'string', 'url', 'max:500'],
                'cloudflare_browser_rendering' => ['sometimes', 'boolean'],
            ],
            'openai' => [
                'openai_api_key' => ['nullable', 'string', 'max:255'],
                'openai_chat_model' => ['nullable', 'string', 'max:255'],
                'openai_embed_model' => ['nullable', 'string', 'max:255'],
            ],
            'openrouter' => [
                'openrouter_api_key' => ['nullable', 'string', 'max:255'],
                'openrouter_chat_model' => ['nullable', 'string', 'max:255'],
            ],
            'routing' => [
                'llm_provider' => ['nullable', 'string', 'in:cloudflare,openai,openrouter'],
                'vector_provider' => ['nullable', 'string', 'in:cloudflare,qdrant'],
            ],
            // C1: global BYOK toggle. When `1`, every workspace must
            // supply its own AI keys; platform keys never resolve.
            'byok' => [
                'byok_enabled_globally' => ['required', 'boolean'],
            ],
            'mail' => [
                'mail_driver' => ['nullable', 'string', 'max:32'],
                'mail_host' => ['nullable', 'string', 'max:255'],
                'mail_port' => ['nullable', 'integer', 'between:1,65535'],
                'mail_encryption' => ['nullable', 'string', 'in:tls,ssl'],
                'mail_username' => ['nullable', 'string', 'max:255'],
                'mail_password' => ['nullable', 'string', 'max:500'],
                'mail_from_address' => ['nullable', 'email', 'max:255'],
                'mail_from_name' => ['nullable', 'string', 'max:255'],
            ],
            'branding' => [
                'site_title' => ['nullable', 'string', 'max:120'],
                'header_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'footer_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'dashboard_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'header_logo_dark' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'footer_logo_dark' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'dashboard_logo_dark' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
                'favicon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:2048'],
                'header_brand_display' => ['nullable', 'string', 'in:logo_text,logo_only,text_only'],
                'footer_brand_display' => ['nullable', 'string', 'in:logo_text,logo_only,text_only'],
                'dashboard_brand_display' => ['nullable', 'string', 'in:logo_text,logo_only,text_only'],
                'pitchbar_brand_url' => ['nullable', 'url', 'max:500'],
                'pitchbar_brand_label' => ['nullable', 'string', 'max:120'],
                'marketing_site_enabled' => ['sometimes', 'boolean'],
                // Admin-editable side-panel copy for the auth pages
                // (Aurora / Prism). Empty/null on any field = theme
                // falls back to its bundled default.
                'auth_aside_eyebrow' => ['nullable', 'string', 'max:120'],
                'auth_aside_heading' => ['nullable', 'string', 'max:200'],
                'auth_aside_lede' => ['nullable', 'string', 'max:1000'],
                'auth_aside_bullets' => ['nullable', 'array', 'max:6'],
                'auth_aside_bullets.*' => ['nullable', 'string', 'max:200'],
            ],
            'marketing' => [
                // Was `required` until the widget sub-section was carved out
                // into its own form (2026-05-21). The widget form only sends
                // marketing_widget_enabled + marketing_widget_agent_id, so
                // requiring the home content here 422'd every widget save.
                // Controller already guards re-resolution with array_key_exists.
                'marketing_home_content' => ['sometimes', 'array'],
                'marketing_widget_enabled' => ['sometimes', 'boolean'],
                'marketing_widget_agent_id' => ['sometimes', 'nullable', 'uuid'],
                // Theme slug must be one of the registered themes —
                // unknown values would silently fall back to `harvest`
                // at render time, but rejecting at save time gives the
                // operator clearer feedback.
                'marketing_theme' => ['sometimes', 'string', 'in:'.implode(',', MarketingTheme::availableSlugs())],
            ],
            'privacy' => [
                'privacy_policy_content' => ['required', 'array'],
            ],
            // Buyer-reported (Lucian, 2026-05-15): "would be nice to
            // have a checkbox on settings to send email to admin at
            // least with new subscriptions". Daily digest is opt-in;
            // command short-circuits when this flag is false.
            'notifications' => [
                'admin_daily_digest_enabled' => ['required', 'boolean'],
            ],
            // Signup flow controls — email-verify enforcement +
            // default-signup-plan selection both live here.
            // Buyer-reported (2026-05-19).
            'signup' => [
                'require_email_verification' => ['required', 'boolean'],
            ],
            // WordPress / WooCommerce plugin distribution. Buyer-reported
            // (2026-05-20) the integrations page hard-coded a "Download
            // from your CodeCanyon receipt" instruction that doesn't fit
            // installs hosting their own plugin mirror.
            'wordpress_plugin' => [
                'wordpress_plugin_download_url' => ['nullable', 'string', 'url', 'max:500'],
                'wordpress_plugin_help_text' => ['nullable', 'string', 'max:2000'],
            ],
            // "Integrations available to workspaces" toggles on
            // /settings/system?section=integrations. Buyer reported
            // (Jamiu, 2026-05-20) that saving the form 404'd because
            // the section wasn't enumerated here (or in the route
            // regex). The form posts a flat map of kind=>boolean
            // which we project into the `integrations_enabled` JSON
            // column on AppSetting.
            'integrations' => [
                'integrations_enabled' => ['sometimes', 'array'],
                'integrations_enabled.slack' => ['sometimes', 'boolean'],
                'integrations_enabled.notion' => ['sometimes', 'boolean'],
                'integrations_enabled.google' => ['sometimes', 'boolean'],
                'integrations_enabled.webhooks' => ['sometimes', 'boolean'],
                'integrations_enabled.wordpress' => ['sometimes', 'boolean'],
                // Per-card copy overrides for the public /integrations
                // landing page. Only the editable fields are validated;
                // icon + accent stay controlled by the codebase so the
                // admin can't accidentally break the layout. Empty rows
                // legitimately mean "reset this card's copy to default".
                'integration_cards' => ['sometimes', 'array'],
                'integration_cards.*.key' => ['sometimes', 'nullable', 'string', 'max:120'],
                'integration_cards.*.name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'integration_cards.*.category' => ['sometimes', 'nullable', 'string', 'max:120'],
                'integration_cards.*.tagline' => ['sometimes', 'nullable', 'string', 'max:300'],
                'integration_cards.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            // Pricing-matrix / FAQs editor on the marketing tab.
            // Same missing-section bug — referenced by `$arraySections`
            // higher in update() but never validated here, so saves
            // 404'd. Schema mirrors the public /pricing page payload.
            // Validator keys must match BOTH the admin form
            // (resources/js/pages/settings/system.tsx → {q,a}) AND the
            // public-side reader (MarketingController::pricingFaqs() →
            // {q,a}). Client report 2026-05-22: prior validator used
            // {question,answer} so Laravel's validate() stripped every
            // q/a key from the payload, persisting empty rows. The
            // /pricing surface then rendered the hard-coded defaults.
            'pricing' => [
                'pricing_faqs' => ['sometimes', 'array'],
                'pricing_faqs.*.q' => ['required_with:pricing_faqs.*', 'string', 'max:255'],
                'pricing_faqs.*.a' => ['required_with:pricing_faqs.*', 'string', 'max:5000'],
                'pricing_matrix' => ['sometimes', 'array'],
            ],
            default => null,
        };
    }

    /**
     * Project the AppSetting row to the frontend. Plaintext values for
     * non-sensitive fields, just `_set` booleans for sensitive ones —
     * the actual ciphertext / decrypted secret never leaves the server.
     *
     * @return array<string, mixed>
     */
    private function formValues(AppSetting $s): array
    {
        $branding = AppBranding::shared();

        return [
            // Stripe — public key is publishable, secret + webhook are sensitive.
            'stripe_key' => $s->stripe_key,
            'stripe_secret_set' => ! empty($s->stripe_secret),
            'stripe_webhook_secret_set' => ! empty($s->stripe_webhook_secret),
            'cashier_currency' => $s->cashier_currency,

            // Payment gateway enable flags — admin-toggleable. Default
            // shape: Stripe on, others off (matches schema defaults).
            'stripe_enabled' => (bool) $s->stripe_enabled,
            'paypal_enabled' => (bool) $s->paypal_enabled,
            'razorpay_enabled' => (bool) $s->razorpay_enabled,

            // PayPal — client_id is "publishable" (used by SDK on the
            // browser), client_secret + webhook_id are sensitive.
            'paypal_mode' => $s->paypal_mode ?: 'sandbox',
            'paypal_client_id' => $s->paypal_client_id,
            'paypal_client_secret_set' => ! empty($s->paypal_client_secret),
            'paypal_webhook_id' => $s->paypal_webhook_id,

            // Razorpay — key_id is publishable (Checkout.js needs it on the
            // browser), key_secret + webhook_secret are sensitive.
            'razorpay_key_id' => $s->razorpay_key_id,
            'razorpay_key_secret_set' => ! empty($s->razorpay_key_secret),
            'razorpay_webhook_secret_set' => ! empty($s->razorpay_webhook_secret),

            // Cloudflare — account id is public-ish, token is sensitive.
            'cloudflare_account_id' => $s->cloudflare_account_id,
            'cloudflare_api_token_set' => ! empty($s->cloudflare_api_token),
            'cloudflare_chat_model' => $s->cloudflare_chat_model,
            'cloudflare_embed_model' => $s->cloudflare_embed_model,
            'cloudflare_vectorize_index' => $s->cloudflare_vectorize_index,
            'cloudflare_ai_gateway_url' => $s->cloudflare_ai_gateway_url,
            'cloudflare_browser_rendering' => (bool) ($s->cloudflare_browser_rendering ?? true),

            // OpenAI / OpenRouter — keys sensitive, models public.
            'openai_api_key_set' => ! empty($s->openai_api_key),
            'openai_chat_model' => $s->openai_chat_model,
            'openai_embed_model' => $s->openai_embed_model,
            'openrouter_api_key_set' => ! empty($s->openrouter_api_key),
            'openrouter_chat_model' => $s->openrouter_chat_model,

            // Provider routing.
            'llm_provider' => $s->llm_provider,
            'vector_provider' => $s->vector_provider,

            // Mail — password sensitive, everything else public.
            'mail_driver' => $s->mail_driver,
            'mail_host' => $s->mail_host,
            'mail_port' => $s->mail_port,
            'mail_encryption' => $s->mail_encryption,
            'mail_username' => $s->mail_username,
            'mail_password_set' => ! empty($s->mail_password),
            'mail_from_address' => $s->mail_from_address,
            'mail_from_name' => $s->mail_from_name,

            // Branding — fully public.
            'site_title' => $s->site_title ?: AppBranding::siteTitle(),
            'header_logo_url' => AppBranding::assetUrl($s->header_logo_path),
            'footer_logo_url' => AppBranding::assetUrl($s->footer_logo_path),
            'dashboard_logo_url' => AppBranding::assetUrl($s->dashboard_logo_path),
            'header_logo_dark_url' => AppBranding::assetUrl($s->header_logo_dark_path),
            'footer_logo_dark_url' => AppBranding::assetUrl($s->footer_logo_dark_path),
            'dashboard_logo_dark_url' => AppBranding::assetUrl($s->dashboard_logo_dark_path),
            'favicon_url' => AppBranding::assetUrl($s->favicon_path),
            'header_brand_display' => $branding['header_brand_display'],
            'footer_brand_display' => $branding['footer_brand_display'],
            'dashboard_brand_display' => $branding['dashboard_brand_display'],
            'pitchbar_brand_url' => $s->pitchbar_brand_url,
            'pitchbar_brand_label' => $s->pitchbar_brand_label,
            'auth_aside_eyebrow' => (string) ($s->auth_aside_eyebrow ?? ''),
            'auth_aside_heading' => (string) ($s->auth_aside_heading ?? ''),
            'auth_aside_lede' => (string) ($s->auth_aside_lede ?? ''),
            'auth_aside_bullets' => is_array($s->auth_aside_bullets) ? $s->auth_aside_bullets : [],
            'marketing_site_enabled' => (bool) ($s->marketing_site_enabled ?? true),

            // Pick-your-own-agent for the public marketing site widget.
            // Default: OFF (no widget); when ON the chosen agent speaks
            // to anonymous visitors on /welcome + /marketing/*.
            'marketing_widget_enabled' => (bool) ($s->marketing_widget_enabled ?? false),
            'marketing_widget_agent_id' => $s->marketing_widget_agent_id,
            'marketing_theme' => $s->marketing_theme ?: MarketingTheme::DEFAULT_SLUG,

            // Marketing homepage content — edited via a structured form.
            'marketing_home_content' => MarketingHomeContent::resolve($s->marketing_home_content),
            'privacy_policy_content' => PrivacyPolicyContent::resolve($s->privacy_policy_content),

            // Pricing surfaces — admin-editable. Empty = use defaults.
            'pricing_faqs' => is_array($s->pricing_faqs) ? $s->pricing_faqs : [],
            'pricing_matrix' => is_array($s->pricing_matrix) ? $s->pricing_matrix : [],

            // Integrations enable/disable per kind. NULL/empty = all on.
            'integrations_enabled' => is_array($s->integrations_enabled) ? $s->integrations_enabled : [],
            // Per-card copy overrides for the public /integrations
            // landing page — resolved (defaults overlaid with admin
            // overrides) so the React form can prefill with whatever
            // visitors currently see.
            'integration_cards' => IntegrationCardsContent::editorRows(),

            // C1: app-wide BYOK gate. When true, platform AI keys never
            // resolve — workspaces must provision their own.
            'byok_enabled_globally' => (bool) $s->byok_enabled_globally,
            'admin_daily_digest_enabled' => (bool) ($s->admin_daily_digest_enabled ?? false),
            'require_email_verification' => (bool) ($s->require_email_verification ?? false),
            'wordpress_plugin_download_url' => (string) ($s->wordpress_plugin_download_url ?? ''),
            'wordpress_plugin_help_text' => (string) ($s->wordpress_plugin_help_text ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareBrandingData(array $data, AppSetting $settings): array
    {
        if (array_key_exists('site_title', $data) && is_string($data['site_title'])) {
            $data['site_title'] = trim($data['site_title']);
        }

        foreach ([
            'header_logo' => 'header_logo_path',
            'footer_logo' => 'footer_logo_path',
            'dashboard_logo' => 'dashboard_logo_path',
            'header_logo_dark' => 'header_logo_dark_path',
            'footer_logo_dark' => 'footer_logo_dark_path',
            'dashboard_logo_dark' => 'dashboard_logo_dark_path',
            'favicon' => 'favicon_path',
        ] as $input => $column) {
            $data = $this->storeBrandingUpload($data, $settings, $input, $column);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storeBrandingUpload(
        array $data,
        AppSetting $settings,
        string $input,
        string $column,
    ): array {
        $file = $data[$input] ?? null;

        if (! $file instanceof UploadedFile) {
            unset($data[$input]);

            return $data;
        }

        $disk = Storage::disk(AppBranding::disk());
        $currentPath = $settings->{$column};

        if (is_string($currentPath) && $currentPath !== '') {
            $disk->delete($currentPath);
        }

        $directory = $input === 'favicon' ? 'branding/favicon' : 'branding/'.str_replace('_logo', '', $input);

        $data[$column] = $file->storePublicly($directory, AppBranding::disk());
        unset($data[$input]);

        return $data;
    }

    /**
     * Summaries are intentionally lightweight — just enough to confirm
     * which provider is bound and which fields are populated. Secrets
     * are masked to last-4 only so the page is safe to screenshot.
     *
     * @return array<string, mixed>
     */
    private function mailSummary(): array
    {
        $driver = (string) config('mail.default');
        $mailer = (array) config("mail.mailers.{$driver}", []);

        return [
            'driver' => $driver,
            'host' => $mailer['host'] ?? null,
            'port' => $mailer['port'] ?? null,
            'encryption' => $mailer['encryption'] ?? null,
            'username' => isset($mailer['username']) ? $this->maskTail((string) $mailer['username']) : null,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'configured' => ! empty($driver),
        ];
    }

    private function stripeSummary(): array
    {
        $key = (string) (config('cashier.key') ?: env('STRIPE_KEY', ''));
        $secret = (string) (config('cashier.secret') ?: env('STRIPE_SECRET', ''));
        $webhook = (string) (config('cashier.webhook.secret') ?: env('STRIPE_WEBHOOK_SECRET', ''));

        return [
            'public_key' => $key !== '' ? $this->maskTail($key) : null,
            'secret' => $secret !== '' ? $this->maskTail($secret) : null,
            'webhook_secret' => $webhook !== '' ? $this->maskTail($webhook) : null,
            'currency' => config('cashier.currency'),
            'enabled' => (bool) config('cashier.enabled', true),
            'configured' => $secret !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paypalSummary(): array
    {
        $clientId = (string) (config('services.paypal.client_id') ?: env('PAYPAL_CLIENT_ID', ''));
        $secret = (string) (config('services.paypal.client_secret') ?: env('PAYPAL_CLIENT_SECRET', ''));
        $webhookId = (string) (config('services.paypal.webhook_id') ?: env('PAYPAL_WEBHOOK_ID', ''));

        return [
            'mode' => (string) (config('services.paypal.mode') ?: 'sandbox'),
            'client_id' => $clientId !== '' ? $this->maskTail($clientId) : null,
            'client_secret' => $secret !== '' ? $this->maskTail($secret) : null,
            'webhook_id' => $webhookId !== '' ? $this->maskTail($webhookId) : null,
            'enabled' => (bool) config('services.paypal.enabled', false),
            'configured' => $clientId !== '' && $secret !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function razorpaySummary(): array
    {
        $keyId = (string) (config('services.razorpay.key_id') ?: env('RAZORPAY_API_KEY', ''));
        $secret = (string) (config('services.razorpay.key_secret') ?: env('RAZORPAY_SECRET_KEY', ''));
        $webhookSecret = (string) (config('services.razorpay.webhook_secret') ?: env('RAZORPAY_WEBHOOK_SECRET', ''));

        return [
            'key_id' => $keyId !== '' ? $this->maskTail($keyId) : null,
            'key_secret' => $secret !== '' ? $this->maskTail($secret) : null,
            'webhook_secret' => $webhookSecret !== '' ? $this->maskTail($webhookSecret) : null,
            'enabled' => (bool) config('services.razorpay.enabled', false),
            'configured' => $keyId !== '' && $secret !== '',
        ];
    }

    private function llmSummary(): array
    {
        $provider = (string) config('services.llm.provider', '');
        $cfAccount = (string) config('services.cloudflare.account_id', '');
        $cfToken = (string) config('services.cloudflare.api_token', '');
        $openAiKey = (string) config('services.openai.key', '');
        $openRouterKey = (string) config('services.openrouter.key', '');

        $resolved = match (true) {
            $provider === 'cloudflare' || ($provider === '' && $cfAccount !== '' && $cfToken !== '') => 'cloudflare',
            $provider === 'openrouter' => 'openrouter',
            $openAiKey !== '' => 'openai',
            default => 'fake',
        };

        $catalog = app(ModelCatalog::class);
        $probe = app(ModelLatencyProbe::class);
        $cfFetcher = app(CloudflareModelFetcher::class);

        $cfModel = (string) config('services.cloudflare.chat_model', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
        $openaiModel = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $openrouterModel = (string) config('services.openrouter.chat_model', 'meta-llama/llama-3.3-70b-instruct:free');

        $cfStatic = $catalog->forProvider(ModelCatalog::PROVIDER_CLOUDFLARE);
        $cfMerged = $this->mergeWithLiveCloudflare($cfStatic, $cfFetcher->cached());

        return [
            'provider_env' => $provider !== '' ? $provider : '(auto)',
            'resolved' => $resolved,
            'cloudflare_account' => $cfAccount !== '' ? $this->maskTail($cfAccount) : null,
            'cloudflare_chat_model' => $cfModel,
            'openai_key' => $openAiKey !== '' ? $this->maskTail($openAiKey) : null,
            'openai_chat_model' => $openaiModel,
            'openrouter_key' => $openRouterKey !== '' ? $this->maskTail($openRouterKey) : null,
            'openrouter_chat_model' => $openrouterModel,
            'configured' => $resolved !== 'fake',
            'model_catalog' => [
                ModelCatalog::PROVIDER_CLOUDFLARE => $cfMerged,
                ModelCatalog::PROVIDER_OPENAI => $catalog->forProvider(ModelCatalog::PROVIDER_OPENAI),
                ModelCatalog::PROVIDER_OPENROUTER => $catalog->forProvider(ModelCatalog::PROVIDER_OPENROUTER),
            ],
            'embed_catalog' => [
                ModelCatalog::PROVIDER_CLOUDFLARE => $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_CLOUDFLARE),
                ModelCatalog::PROVIDER_OPENAI => $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_OPENAI),
                ModelCatalog::PROVIDER_OPENROUTER => $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_OPENROUTER),
            ],
            'current_vector_dim' => (int) config('services.vector_dim', 768),
            'model_latencies' => [
                ModelCatalog::PROVIDER_CLOUDFLARE => $probe->cached(ModelCatalog::PROVIDER_CLOUDFLARE, $cfModel),
                ModelCatalog::PROVIDER_OPENAI => $probe->cached(ModelCatalog::PROVIDER_OPENAI, $openaiModel),
                ModelCatalog::PROVIDER_OPENROUTER => $probe->cached(ModelCatalog::PROVIDER_OPENROUTER, $openrouterModel),
            ],
            'cloudflare_live_models_fetched_at' => $cfFetcher->cached()['fetched_at'] ?? null,
        ];
    }

    /**
     * Merge the live Cloudflare model IDs into the static catalogue.
     * Static entries win on metadata; live-only IDs append at the end
     * with default tier/cost so the buyer can still pick them.
     *
     * @param  array<int, array<string, mixed>>  $static
     * @param  array{ok: bool, ids: array<int, string>, error: string|null, fetched_at: string|null}|null  $cachedLive
     * @return array<int, array<string, mixed>>
     */
    private function mergeWithLiveCloudflare(array $static, ?array $cachedLive): array
    {
        if ($cachedLive === null || ! ($cachedLive['ok'] ?? false)) {
            return $static;
        }

        $staticIds = array_flip(array_map(fn ($e) => $e['id'], $static));
        $merged = $static;

        foreach ($cachedLive['ids'] as $id) {
            if (isset($staticIds[$id])) {
                continue;
            }
            $merged[] = [
                'id' => $id,
                'label' => $this->prettyLabelForCloudflareId($id),
                'provider' => ModelCatalog::PROVIDER_CLOUDFLARE,
                'ttft_ms' => 300,
                'tier' => ModelCatalog::TIER_MEDIUM,
                'cost' => '$$',
                'context_tokens' => 8000,
                'supports_tools' => false,
                'recommended' => false,
                'notes' => 'Discovered from Cloudflare API. Click "Test connection" to confirm.',
            ];
        }

        return $merged;
    }

    private function prettyLabelForCloudflareId(string $id): string
    {
        $name = preg_replace('#^@cf/[^/]+/#', '', $id) ?? $id;

        return ucfirst(str_replace(['-', '_'], ' ', $name));
    }

    private function cacheSummary(): array
    {
        return [
            'driver' => config('cache.default'),
            'redis_host' => env('REDIS_HOST'),
            'redis_port' => env('REDIS_PORT'),
            'configured' => true,
        ];
    }

    private function vectorSummary(): array
    {
        $provider = (string) config('services.vector.provider', '');
        $cfAccount = (string) config('services.cloudflare.account_id', '');
        $qdrantUrl = (string) config('services.qdrant.url', '');

        $resolved = match (true) {
            $provider === 'cloudflare' || ($provider === '' && $cfAccount !== '') => 'cloudflare-vectorize',
            $provider === 'qdrant' || ($provider === '' && $qdrantUrl !== '') => 'qdrant',
            default => 'fake',
        };

        return [
            'provider_env' => $provider !== '' ? $provider : '(auto)',
            'resolved' => $resolved,
            'vectorize_index' => config('services.cloudflare.vectorize_index', 'pitchbar-chunks'),
            'qdrant_url' => $qdrantUrl !== '' ? $qdrantUrl : null,
            'configured' => $resolved !== 'fake',
        ];
    }

    private function reverbSummary(): array
    {
        return [
            'app_key' => env('REVERB_APP_KEY') ? $this->maskTail((string) env('REVERB_APP_KEY')) : null,
            'host' => env('REVERB_HOST', 'localhost'),
            'port' => (int) env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'configured' => env('REVERB_APP_KEY') !== null && env('REVERB_APP_KEY') !== '',
        ];
    }

    private function maskTail(string $value): string
    {
        $len = mb_strlen($value);

        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', max(4, $len - 4)).mb_substr($value, -4);
    }

    private function ok(string $message): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message]);
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => LlmErrorPresenter::present($message) ?? $message,
        ], 200);
    }
}
