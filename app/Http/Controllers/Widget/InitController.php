<?php

namespace App\Http\Controllers\Widget;

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\Workspace;
use App\Services\Billing\MeteredBilling;
use App\Services\Crawl\AutoIndexPageVisit;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\TranslationLoader;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Widget\AcceptLanguage;
use App\Services\Widget\ShopperToken;
use App\Services\Widget\WidgetCopy;
use App\Services\Widget\WidgetJwt;
use App\Support\AppBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InitController
{
    public function __construct(
        private WidgetJwt $jwt,
        private MeteredBilling $billing,
        private AutoIndexPageVisit $autoIndex,
        private LocaleResolver $localeResolver,
        private WidgetCopy $widgetCopy,
        private TranslationLoader $translations,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string'],
            'page_url' => ['nullable', 'string', 'max:2000'],
            'anon_id' => ['nullable', 'string', 'max:64'],
            // Optional CMS-signed shopper token. WordPress plugin emits
            // it via `data-shopper-token` when a WC customer is logged
            // in; widget forwards it here. Bad signatures are silently
            // dropped — visitor still chats as anonymous.
            'shopper_token' => ['nullable', 'string', 'max:2000'],
            'page_title' => ['nullable', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            // Host page's language — the embed's `data-locale`, else the
            // page's `<html lang>`. Drives the widget UI locale so the
            // chat follows the site's selected language.
            'locale' => ['nullable', 'string', 'max:12'],
        ]);

        /** @var Agent|null $agent */
        $agent = Agent::query()->withoutWorkspaceScope()
            ->where('id', $data['agent_id'])
            ->where('is_published', true)
            ->first();

        if ($agent === null) {
            return response()->json([
                'error' => ['code' => 'agent_not_found', 'message' => 'Agent not found or not published.'],
            ], 404);
        }

        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if (! $this->originAllowed($origin, $agent)) {
            return response()->json([
                'error' => ['code' => 'origin_forbidden', 'message' => 'Origin is not allowed for this agent.'],
            ], 403);
        }

        // Quota gate — block new conversations once the workspace's monthly
        // plan limit is exceeded. Existing conversations and message replies
        // are unaffected; only init blocks.
        $workspace = Workspace::query()->find($agent->workspace_id);
        if ($workspace !== null && ! $this->billing->canStartConversation($workspace)) {
            return response()->json([
                'error' => [
                    'code' => 'plan_limit_reached',
                    'message' => 'This workspace has reached its monthly conversation limit. Upgrade to continue.',
                ],
            ], 429)->header('Access-Control-Allow-Origin', $origin ?? '*')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        $anonId = $data['anon_id'] ?? 'anon_'.Str::random(16);
        $visitor = Visitor::query()->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('anonymous_id', $anonId)
            ->first();

        if ($visitor === null) {
            $visitor = Visitor::create([
                'agent_id' => $agent->id,
                'anonymous_id' => $anonId,
                'ip_hash' => hash('sha256', (string) $request->ip()),
                'ua' => substr((string) $request->userAgent(), 0, 500),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'visit_count' => 1,
            ]);
        } else {
            $visitor->forceFill([
                'last_seen_at' => now(),
                'visit_count' => $visitor->visit_count + 1,
            ])->save();
        }

        $detectedLang = AcceptLanguage::detect(
            $request->headers->get('Accept-Language'),
            fallback: $agent->language_default,
        );
        // Pick the widget UI locale: the host page's language wins (so the
        // chat follows the site's selected language), then the agent's
        // configured default, then the visitor's browser preferences. Same
        // `lang/{locale}.json` translations the admin SPA uses — translators
        // only maintain one dictionary.
        $widgetLocale = $this->localeResolver->forWidget(
            $request,
            $agent->language_default,
            is_string($data['locale'] ?? null) ? $data['locale'] : null,
        );

        // Resume the visitor's most recent conversation if it's still
        // active (last activity in the past 24h, not claimed by a human
        // operator who's already moved on). Keeps chat history across
        // page reloads — without this, each init created a brand-new
        // conversation and the previous turns vanished.
        $resumeWindow = now()->subDay();
        $conversation = Conversation::query()->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $visitor->id)
            ->where('started_at', '>=', $resumeWindow)
            ->orderByDesc('started_at')
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::create([
                'agent_id' => $agent->id,
                'visitor_id' => $visitor->id,
                'page_url' => $data['page_url'] ?? null,
                'started_at' => now(),
                'lang' => $detectedLang,
            ]);
        } elseif (is_string($data['page_url'] ?? null) && $data['page_url'] !== $conversation->page_url) {
            // Visitor came back on a different page within the resume
            // window — pin the latest URL so the prompt's "current page"
            // hint stays accurate.
            $conversation->forceFill(['page_url' => $data['page_url']])->save();
        }

        // Auto-index the page the visitor just landed on, if we haven't
        // crawled it before. The service is fully guarded (origin/dedup/
        // rate-limit) so this is a safe no-op when conditions aren't met.
        // Pass the verified Origin header so agents using '*' allowed_origins
        // can still auto-index pages on the visitor's actual domain.
        if (is_string($data['page_url'] ?? null) && $data['page_url'] !== '') {
            try {
                $this->autoIndex->attempt($agent, $data['page_url'], $origin);
            } catch (\Throwable) {
                // Auto-indexing is best-effort — never break /init for it.
            }
        }

        // Trajectory capture. One row per /init call (== one row per
        // top-level page navigation, since the widget calls /init on
        // every page load). Feeds the LeadScoringEngine. Single indexed
        // insert; safe on this endpoint because /init is NOT the
        // streaming hot path. Deduped on same-page resume to avoid
        // double-counting an A/B test reload or a Vite HMR refresh.
        $pageUrlForView = is_string($data['page_url'] ?? null) ? trim($data['page_url']) : '';
        if ($pageUrlForView !== '') {
            $lastView = VisitorPageView::query()->withoutWorkspaceScope()
                ->where('visitor_id', $visitor->id)
                ->where('url', mb_substr($pageUrlForView, 0, 500))
                ->orderByDesc('viewed_at')
                ->first();
            $dedupeWindow = now()->subMinutes(2);
            $shouldRecord = $lastView === null || $lastView->viewed_at?->lt($dedupeWindow);

            if ($shouldRecord) {
                VisitorPageView::create([
                    'workspace_id' => $agent->workspace_id,
                    'agent_id' => $agent->id,
                    'visitor_id' => $visitor->id,
                    'conversation_id' => $conversation->id,
                    'url' => mb_substr($pageUrlForView, 0, 500),
                    'title' => is_string($data['page_title'] ?? null)
                        ? mb_substr(trim($data['page_title']), 0, 200) ?: null
                        : null,
                    'referrer' => is_string($data['referrer'] ?? null)
                        ? mb_substr(trim($data['referrer']), 0, 500) ?: null
                        : null,
                    'viewed_at' => now(),
                ]);

                // Score recompute is queued — keeps /init fast and lets
                // the scoring engine evolve without affecting the
                // visitor-facing path.
                RecomputeLeadScoreJob::dispatch($conversation->id);
            }
        }

        // Resolve a CMS-provided shopper identity, if any. Silent drop
        // on bad signature so legacy installs and forged tokens both
        // surface as anonymous visitors rather than auth failures.
        $shopperClaims = null;
        $shopperToken = $data['shopper_token'] ?? null;
        if (is_string($shopperToken) && $shopperToken !== '') {
            $shopperClaims = ShopperToken::verifyForWorkspace($shopperToken, $agent->workspace_id);
        }

        // Persist the shopper claims on the conversation so tools like
        // LookupOrderTool can read them on later turns without having
        // to re-decode the widget JWT inside the SSE stream.
        if ($shopperClaims !== null) {
            $attribution = (array) ($conversation->attribution ?? []);
            $attribution['shopper'] = $shopperClaims;
            $conversation->forceFill(['attribution' => $attribution])->save();
        }

        $issued = $this->jwt->issue($agent->id, $visitor->id, $conversation->id, $shopperClaims);

        // Recent turns so the widget can hydrate the chat log on page
        // reload. Last 30 messages, oldest-first — enough for context
        // without ballooning the init payload.
        // Hide turns from before the visitor last hit "Clear conversation".
        // The conversation row + messages stay in the DB for analytics +
        // lead linkage; we just stop hydrating them into the widget.
        $messagesQuery = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant', 'human-agent']);

        if ($conversation->cleared_at !== null) {
            $messagesQuery->where('created_at', '>', $conversation->cleared_at);
        }

        $messages = $messagesQuery
            ->orderBy('created_at')
            ->limit(30)
            ->get(['id', 'role', 'content', 'citations'])
            ->map(fn (Message $m) => [
                'id' => (string) $m->id,
                'role' => $m->role,
                'content' => (string) $m->content,
                'citations' => $m->citations ?? [],
            ])
            ->values();

        $behaviorRules = $agent->behaviorRules()
            ->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->limit(20)
            ->get(['id', 'kind', 'conditions', 'action'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'conditions' => $r->conditions ?? new \stdClass,
                'action' => $r->action ?? new \stdClass,
            ]);

        $branding = AppBranding::shared();

        // Pre-chat-gate companion: when require_lead_before_chat is on,
        // the widget needs to know on /init whether this conversation
        // has already captured a lead — otherwise a returning visitor
        // would see the gate again on every refresh. One indexed
        // existence check; cheaper than hydrating the row.
        $leadAlreadyCaptured = Lead::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('conversation_id', $conversation->id)
            ->exists();

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'visitor_id' => $visitor->id,
                'anonymous_id' => $anonId,
                'jwt' => $issued['token'],
                'expires_at' => $issued['expires_at'],
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'persona' => $agent->persona,
                    // Localize the launcher label + starter chips to the
                    // widget locale. The widget renders both verbatim, so
                    // resolving them here is what makes the SaaS preset's
                    // "Ask about the product" / "What does it cost?" follow
                    // the site language. Custom (non-preset) values with no
                    // override pass through unchanged.
                    'theme' => $this->localizeLauncherLabel($agent->theme, $widgetLocale),
                    'starter_prompts' => $this->localizeStarterPrompts($agent->starter_prompts, $widgetLocale),
                    'language_default' => $agent->language_default,
                    'site_type' => $agent->site_type,
                    'capabilities' => $this->resolveCapabilities($agent),
                    // URL-path glob list — the widget bails on boot
                    // when window.location.pathname matches any
                    // pattern. Lets buyers disable the bot on their
                    // own /admin or /checkout flow without code.
                    'restricted_paths' => array_values((array) ($agent->restricted_paths ?? [])),
                    // Per-agent toggle that gates the chat surface
                    // behind a Name + Email form. The widget renders
                    // a lead form first; only after capture does the
                    // chat panel unlock.
                    'require_lead_before_chat' => (bool) $agent->require_lead_before_chat,
                    // Custom lead-form schema (#34). Widget renders
                    // these fields in both the inline mid-chat form
                    // and the pre-chat gate. NULL = fall back to the
                    // default Name + Email shape.
                    'lead_form_fields' => $agent->lead_form_fields,
                    'locale' => $widgetLocale,
                    'copy' => $this->widgetCopy->for($widgetLocale),
                ],
                'branding' => [
                    // C6 wiring: route through Workspace::removesBranding()
                    // so a Lifetime Deal unlock takes effect even when
                    // the workspace's nominal subscription plan stays
                    // on Free.
                    'show' => ! ($workspace?->removesBranding() ?? false),
                    'label' => (string) config('branding.label'),
                    'url' => (string) config('branding.url'),
                    'logo_url' => $branding['footer_logo_url'] ?? $branding['header_logo_url'],
                    'display_mode' => $branding['footer_brand_display'],
                ],
                'behavior_rules' => $behaviorRules,
                'lead_captured' => $leadAlreadyCaptured,
                'messages' => $messages,
                'reverb' => [
                    'app_key' => config('reverb.apps.apps.0.key', env('REVERB_APP_KEY')),
                    'host' => env('REVERB_HOST', 'localhost'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                ],
            ],
        ])->header('Access-Control-Allow-Origin', $origin ?? '*')
            ->header('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Resolves the capability flag set the widget reads to know which
     * rich-UI affordances are allowed. Server is the source of truth —
     * the widget can never enable a capability it isn't told about.
     *
     * Hot-path safe: the registry is bound `scoped`, so this is one
     * column read (already loaded with the agent) plus one O(1) array
     * lookup. No DB query.
     *
     * @return array<int, string>
     */
    private function resolveCapabilities(Agent $agent): array
    {
        if ($agent->site_type === null) {
            return [];
        }

        $registry = app(VerticalPresetRegistry::class);
        $overrides = (array) ($agent->vertical_overrides ?? []);
        if (isset($overrides['capabilities']) && is_array($overrides['capabilities'])) {
            return array_values(array_unique(array_filter(
                $overrides['capabilities'],
                static fn ($v) => is_string($v) && $v !== '',
            )));
        }

        return $registry->for((string) $agent->site_type)->capabilities();
    }

    /**
     * Resolve each starter-prompt chip against the widget locale's
     * overrides. Preset defaults (registered via {@see VerticalChrome})
     * get translated; a custom prompt with no override falls back to its
     * own text. Null in → null out (the widget renders no chips).
     *
     * @return array<int, string>|null
     */
    private function localizeStarterPrompts(mixed $prompts, string $locale): ?array
    {
        if (! is_array($prompts)) {
            return null;
        }

        return array_values(array_map(
            fn ($prompt) => is_string($prompt)
                ? ($this->translations->get($locale, $prompt) ?? $prompt)
                : $prompt,
            $prompts,
        ));
    }

    /**
     * Resolve the theme's `launcher_label` (the chat input placeholder)
     * against the widget locale's overrides, leaving every other theme
     * key untouched. Non-array themes and absent / blank labels pass
     * through unchanged.
     */
    private function localizeLauncherLabel(mixed $theme, string $locale): mixed
    {
        if (! is_array($theme)) {
            return $theme;
        }

        $label = $theme['launcher_label'] ?? null;
        if (is_string($label) && $label !== '') {
            $theme['launcher_label'] = $this->translations->get($locale, $label) ?? $label;
        }

        return $theme;
    }

    /**
     * Strict, exact-match origin gate. The widget script is public; without
     * this gate any third party could embed it on their own site and burn
     * the customer's plan quota / poison their KB.
     *
     * Rules:
     *   - Empty allowed_origins → DENY ALL. Customer must list origins
     *     explicitly before the widget will load anywhere.
     *   - '*' → opt-in escape hatch. Allows any origin. Used for internal
     *     tools / demo agents; never the default.
     *   - Otherwise → exact scheme://host match. Subdomains are NOT
     *     inferred. `https://example.com` does NOT permit
     *     `https://app.example.com` — admins must list each origin.
     *
     * Both sides of the comparison are normalised first so customers can
     * paste "https://example.com/" (trailing slash) or " https://EXAMPLE.com "
     * (leading whitespace, mixed case) and still match the browser's
     * canonical "https://example.com" Origin header. The strict-subdomain
     * rule is preserved.
     */
    private function originAllowed(?string $origin, Agent $agent): bool
    {
        $allowed = array_values(array_filter(array_map(
            fn ($o) => self::normaliseOrigin((string) $o),
            (array) ($agent->allowed_origins ?? []),
        ), static fn (string $o) => $o !== ''));

        if ($allowed === []) {
            return false;
        }
        if (in_array('*', $allowed, true)) {
            return true;
        }
        if ($origin === null) {
            return false;
        }

        $normalised = self::normaliseOrigin($origin);

        return $normalised !== '' && in_array($normalised, $allowed, true);
    }

    /**
     * Reduces an origin string to a canonical "scheme://host" so user
     * input ("https://Example.com/", " https://example.com ") matches
     * the browser's "https://example.com" Origin header. Returns "*"
     * unchanged and an empty string for malformed input.
     */
    public static function normaliseOrigin(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            return $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_string($host) || $host === '' || ! is_string($scheme) || $scheme === '') {
            return '';
        }

        $port = parse_url($value, PHP_URL_PORT);
        $authority = strtolower($scheme).'://'.strtolower($host);
        if (is_int($port)) {
            $authority .= ':'.$port;
        }

        return $authority;
    }
}
