<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Plan;
use App\Services\Billing\CurrencyCatalog;
use App\Services\Billing\CurrencyResolver;
use App\Services\Billing\PlanFeatureMatrix;
use App\Support\IntegrationCardsContent;
use App\Support\MarketingDemoAgent;
use App\Support\MarketingHomeContent;
use App\Support\MarketingShellContent;
use App\Support\MarketingTheme;
use App\Support\MarketingTranslator;
use App\Support\PrivacyPolicyContent;
use App\Support\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController
{
    public function home(): Response
    {
        $settings = AppSetting::singleton();
        $content = MarketingTranslator::translate(
            MarketingHomeContent::applyExternalDocsUrl(
                MarketingHomeContent::resolve($settings->marketing_home_content),
            ),
        );

        return Inertia::render(MarketingTheme::component('home'), [
            'canRegister' => Route::has('register'),
            'demoAgentId' => MarketingDemoAgent::id(),
            'content' => $content,
            'seo' => SeoMeta::for('home', [
                'faq_items' => $content['faq']['items'] ?? [],
            ]),
        ]);
    }

    public function pricing(Request $request, CurrencyResolver $currencies): Response
    {
        $supported = $this->supportedCurrencies();
        $currency = strtoupper(
            $currencies->resolve($request, $supported),
        );

        // Snap to USD if the resolver hands us something we can't price in.
        // The marketing page is a fallback for installs with no DB plans, so
        // only currencies we actually have prices for should be listed.
        if (! in_array($currency, $supported, true)) {
            $currency = 'USD';
        }

        return Inertia::render(MarketingTheme::component('pricing'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingTranslator::translate(MarketingShellContent::resolve()),
            'brand' => $this->brand(),
            'plans' => $this->pricingPlans($currency),
            'lifetime_plans' => $this->lifetimePlans($currency),
            'currency' => $currency,
            'currencies' => $this->currencyChoices($supported),
            'matrix' => MarketingTranslator::translate($this->pricingMatrix()),
            'faqs' => MarketingTranslator::translate($this->pricingFaqs()),
            'contact_email' => $this->contactEmail(),
            'seo' => SeoMeta::for('pricing'),
        ]);
    }

    /**
     * Which ISO 4217 codes can be rendered on the marketing page. We
     * always include USD (the hardcoded fallback) and union in every
     * currency at least one active DB Plan has prices for.
     *
     * @return list<string>
     */
    private function supportedCurrencies(): array
    {
        $codes = ['USD'];

        Plan::query()->where('is_active', true)->get(['prices'])
            ->each(function (Plan $plan) use (&$codes) {
                foreach ($plan->availableCurrencies() as $code) {
                    $codes[] = strtoupper($code);
                }
            });

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, name: string, symbol: string}>
     */
    private function currencyChoices(array $codes): array
    {
        $catalog = CurrencyCatalog::indexedByCode();

        return array_map(function (string $code) use ($catalog) {
            $row = $catalog[strtolower($code)] ?? null;

            return [
                'code' => $code,
                'name' => (string) ($row['name'] ?? $code),
                'symbol' => (string) ($row['symbol'] ?? $code),
            ];
        }, $codes);
    }

    public function howItWorks(): Response
    {
        return Inertia::render(MarketingTheme::component('how-it-works'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingTranslator::translate(MarketingShellContent::resolve()),
            'brand' => $this->brand(),
            'steps' => MarketingTranslator::translate($this->howItWorksSteps()),
            'latency' => $this->latencyBudget(),
            'seo' => SeoMeta::for('how-it-works'),
        ]);
    }

    public function integrations(): Response
    {
        return Inertia::render(MarketingTheme::component('integrations'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingTranslator::translate(MarketingShellContent::resolve()),
            'brand' => $this->brand(),
            'native' => MarketingTranslator::translate($this->nativeIntegrations()),
            'data_sources' => MarketingTranslator::translate($this->dataSourceIntegrations()),
            'roadmap' => MarketingTranslator::translate($this->roadmapIntegrations()),
            'seo' => SeoMeta::for('integrations'),
        ]);
    }

    public function privacy(): Response
    {
        $settings = AppSetting::singleton();

        return Inertia::render(MarketingTheme::component('privacy'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingTranslator::translate(MarketingShellContent::resolve()),
            'brand' => $this->brand(),
            'content' => PrivacyPolicyContent::resolve($settings->privacy_policy_content),
            'seo' => SeoMeta::for('privacy'),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render(MarketingTheme::component('terms'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingTranslator::translate(MarketingShellContent::resolve()),
            'brand' => $this->brand(),
            'effective_date' => (string) config('branding.terms_effective_date', now()->format('F j, Y')),
            'contact_email' => $this->contactEmail(),
            'intro' => $this->termsIntro(),
            'sections' => $this->termsSections(),
            'seo' => SeoMeta::for('terms'),
        ]);
    }

    /**
     * Capture the domain and stash it in a short-lived token so the signup flow can
     * pre-fill and trigger an automatic crawl after the user finishes registration.
     */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'url', 'max:500']]);

        $token = Str::random(32);
        Cache::put("marketing:domain:{$token}", $data['domain'], now()->addHours(2));

        // Persist on session so CreateNewUser can pick it up after registration.
        $request->session()->put('marketing.start_domain', $data['domain']);

        return redirect()->to('/register?domain='.urlencode($data['domain']).'&start_token='.$token);
    }

    private function brand(): string
    {
        return (string) config('branding.site_title', 'Pitchbar');
    }

    private function contactEmail(): string
    {
        return (string) config('mail.from.address', 'support@example.com');
    }

    /**
     * Pricing plans rendered on /pricing. 100% DB-driven — every plan
     * the admin marks `is_active=true` with `billing_model=subscription`
     * (the default) appears. Empty list when the admin hasn't created
     * any subscription plans yet.
     *
     * Yearly price is ONLY shown when the admin has explicitly set a
     * yearly price for the plan — either:
     *   - plan.interval = 'year' (whole plan is yearly), OR
     *   - plan.prices contains a yearly-suffixed currency key.
     * We never invent a `monthly * 10` yearly price the admin didn't set.
     *
     * Per-plan feature bullets come from `plan.features.bullets` JSON
     * (array of strings). Empty list when admin hasn't added any.
     *
     * Tagline + volume + CTA label come from `plan.features.tagline`,
     * `plan.features.volume`, `plan.features.cta_label`. CTA target is
     * always /register because the buyer needs an account first.
     *
     * @return list<array<string, mixed>>
     */
    private function pricingPlans(string $currency): array
    {
        $symbol = CurrencyCatalog::symbolFor($currency);
        $decimals = CurrencyCatalog::decimalPlacesFor($currency);

        $plans = Plan::query()
            ->where('is_active', true)
            // Per-plan visibility on the public /pricing page. Default
            // true on existing rows via migration. Operators hide
            // "Custom" / "Enterprise" cards when an Enterprise text
            // CTA below the grid already serves that purpose. Buyer
            // request (Lucian, 2026-05-18).
            ->where('show_on_pricing_page', true)
            ->where(function ($q) {
                $q->whereNull('billing_model')
                    ->orWhere('billing_model', '')
                    ->orWhere('billing_model', Plan::BILLING_SUBSCRIPTION);
            })
            ->orderBy('price_cents')
            ->get();

        return $plans->map(function (Plan $plan) use ($currency, $symbol, $decimals) {
            $features = is_array($plan->features) ? $plan->features : [];
            $bullets = is_array($features['bullets'] ?? null)
                ? array_values(array_filter($features['bullets'], fn ($v) => is_string($v) && $v !== ''))
                : [];

            $minor = $plan->priceFor($currency);
            $major = $minor !== null ? $minor / (10 ** $decimals) : 0;

            $monthly = 0.0;
            $yearly = 0.0;
            if ($plan->interval === 'year') {
                $yearly = $major;
                $monthly = $major > 0 ? $major / 12 : 0.0;
            } else {
                $monthly = $major;
                // Look for an admin-set yearly companion price. Only
                // show yearly when EXPLICITLY set — no synthetic
                // monthly*10 numbers.
                $prices = is_array($plan->prices) ? $plan->prices : [];
                $yearlyKey = strtolower($currency).'_yearly';
                if (isset($prices[$yearlyKey]) && (int) $prices[$yearlyKey] > 0) {
                    $yearly = ((int) $prices[$yearlyKey]) / (10 ** $decimals);
                }
            }

            return [
                'name' => (string) $plan->name,
                'monthly_price' => round($monthly, $decimals),
                'yearly_price' => round($yearly, $decimals),
                'currency' => $currency,
                'currency_symbol' => $symbol,
                'decimal_places' => $decimals,
                'tagline' => (string) ($features['tagline'] ?? ''),
                'volume' => (string) ($features['volume']
                    ?? ($plan->monthly_conversations > 0
                        ? number_format($plan->monthly_conversations).' conversations / month'
                        : '')),
                'cta_label' => (string) ($features['cta_label'] ?? ($monthly > 0 ? 'Start trial' : 'Start free')),
                'cta_href' => '/register',
                'highlight' => (bool) ($features['highlight'] ?? false),
                'features' => $bullets,
            ];
        })->values()->all();
    }

    /**
     * C6 marketing surface: every active DB plan whose billing_model is
     * `lifetime` or `one_time`. Empty list (the default for installs that
     * haven't created LTD plans yet) hides the section.
     *
     * @return list<array<string, mixed>>
     */
    private function lifetimePlans(string $currency): array
    {
        $decimals = CurrencyCatalog::decimalPlacesFor($currency);
        $symbol = CurrencyCatalog::symbolFor($currency);

        return Plan::query()
            ->where('is_active', true)
            ->whereIn('billing_model', [Plan::BILLING_LIFETIME, Plan::BILLING_ONE_TIME])
            ->orderBy('price_cents')
            ->get()
            ->map(function (Plan $plan) use ($currency, $symbol, $decimals) {
                $minor = $plan->priceFor($currency);
                $major = $minor !== null
                    ? $minor / (10 ** $decimals)
                    : null;

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $major !== null ? round($major, $decimals) : null,
                    'currency' => $currency,
                    'currency_symbol' => $symbol,
                    'decimal_places' => $decimals,
                    'features' => is_array($plan->features) ? array_values(
                        array_filter($plan->features, fn ($v) => is_string($v) && $v !== '')
                    ) : [],
                    'tagline' => $plan->isLifetime()
                        ? 'Pay once, use forever.'
                        : 'One-time purchase.',
                    'cta_label' => 'Buy '.$plan->name,
                    'cta_href' => '/register?lifetime='.$plan->slug,
                    'monthly_conversations' => (int) $plan->monthly_conversations,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Side-by-side comparison matrix. Admin-editable via AppSetting
     * `pricing_matrix` JSON. When empty, falls back to auto-derived
     * rows pulled directly from each plan's limit columns
     * (agents_limit, monthly_conversations, members_limit, plus
     * features.remove_branding, features.bullets) — no fictional
     * feature claims that the app doesn't actually implement.
     *
     * @return list<array<string, mixed>>
     */
    private function pricingMatrix(): array
    {
        $settings = AppSetting::query()->find(AppSetting::SINGLETON_ID);
        $stored = is_array($settings?->pricing_matrix) ? $settings->pricing_matrix : null;
        if ($stored !== null && $stored !== []) {
            return array_values(array_filter($stored, 'is_array'));
        }

        $plans = Plan::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('billing_model')
                    ->orWhere('billing_model', '')
                    ->orWhere('billing_model', Plan::BILLING_SUBSCRIPTION);
            })
            ->orderBy('price_cents')
            ->get();

        // Canonical row set lives in PlanFeatureMatrix so admin
        // /billing and public /pricing can never drift apart again.
        return PlanFeatureMatrix::matrixFor($plans);
    }

    /**
     * Admin-editable pricing FAQs via AppSetting `pricing_faqs` JSON
     * (array of {q,a} pairs). Falls back to default copy when the admin
     * hasn't customised it. The homepage and /pricing both call this so
     * the FAQ stays in one place; future admin work can split if needed.
     *
     * @return list<array{q: string, a: string}>
     */
    public function pricingFaqs(): array
    {
        $settings = AppSetting::query()->find(AppSetting::SINGLETON_ID);
        $stored = is_array($settings?->pricing_faqs) ? $settings->pricing_faqs : null;
        if ($stored !== null && $stored !== []) {
            // Accept both `{q,a}` (current admin form shape) AND the
            // legacy `{question,answer}` shape from rows that may have
            // been saved before the validator was aligned. Once
            // detected, normalise to {q,a} so the public payload stays
            // consistent.
            $normalised = array_map(static function ($row) {
                if (! is_array($row)) {
                    return null;
                }
                $q = is_string($row['q'] ?? null)
                    ? $row['q']
                    : (is_string($row['question'] ?? null) ? $row['question'] : null);
                $a = is_string($row['a'] ?? null)
                    ? $row['a']
                    : (is_string($row['answer'] ?? null) ? $row['answer'] : null);

                return ($q !== null && $a !== null && $q !== '' && $a !== '')
                    ? ['q' => $q, 'a' => $a]
                    : null;
            }, $stored);
            $filtered = array_values(array_filter($normalised, static fn ($row) => $row !== null));

            if ($filtered !== []) {
                return $filtered;
            }
            // Stored array existed but contained zero usable rows
            // (e.g. validator stripped to empties). Fall through to the
            // hard-coded defaults so /pricing still renders content
            // instead of an empty FAQ section.
        }

        $contact = $this->contactEmail();
        $brand = $this->brand();

        return [
            ['q' => 'What counts as a conversation?', 'a' => 'A conversation is metered the moment a visitor sends their first message. Resumed conversations within 24 hours don\'t count again. Playground / staging traffic is exempt.'],
            ['q' => 'What happens if I exceed the quota?', 'a' => 'New conversations are paused with a friendly upgrade prompt. Conversations already in progress finish normally — including human takeovers. Your widget never breaks visibly.'],
            ['q' => 'Can I cancel anytime?', 'a' => 'Yes. Cancellations take effect at the end of the billing period; you keep access until then and your data stays safe.'],
            ['q' => 'Do you offer refunds?', 'a' => "A 30-day money-back guarantee on all paid plans, no questions asked. Email {$contact}."],
            ['q' => 'Is there an Enterprise plan?', 'a' => 'Yes — custom limits, SSO, dedicated infrastructure, and a named CSM. Contact us for a quote.'],
            ['q' => 'Can I self-host?', 'a' => "{$brand} is also available as a self-hosted application via a one-time license. Same features, your infrastructure, your data."],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function howItWorksSteps(): array
    {
        return [
            [
                'number' => '01',
                'title' => 'Drop your URL',
                'duration' => '< 1 minute',
                'description' => 'Sign up, paste your website URL, and we auto-discover your sitemap and key pages (about, pricing, FAQ, docs). Tick the ones you want indexed and we crawl them in the background — respecting robots.txt, blocking authenticated paths, never touching internal hosts.',
                'sub_points' => [
                    'Cloudflare Browser Rendering for JS-heavy sites',
                    'Plain HTTP fallback for simple sites',
                    'Notion + Google Docs sources via OAuth',
                ],
            ],
            [
                'number' => '02',
                'title' => 'We index your knowledge',
                'duration' => '~30 seconds for most sites',
                'description' => 'Crawled pages are extracted (Readability), chunked along semantic boundaries (~500 tokens with overlap), embedded with Cloudflare bge-base-en-v1.5 or OpenAI text-embedding-3-small, and upserted into Vectorize or Qdrant — every chunk tagged with workspace + agent so retrieval is strictly tenant-scoped.',
                'sub_points' => [
                    'Recursive splitter — paragraphs first, sentences as fallback',
                    'Two-stage retrieval: ANN recall + cross-encoder rerank',
                    'Strict workspace isolation enforced by global query scope',
                ],
            ],
            [
                'number' => '03',
                'title' => 'Customize the agent',
                'duration' => '5 minutes of fine-tuning',
                'description' => "Set persona, tone, language, theme colors, starter prompts, and behavior rules. Add curated answers for pricing or refunds where you can't tolerate paraphrasing. A live preview shows visitors exactly what they'll see.",
                'sub_points' => [
                    '8 widget languages (en, es, fr, de, pt, ja, ar, zh)',
                    'Behavior rules: scroll-depth, idle, exit-intent, intent-keyword',
                    'A/B test rule variants and watch conversion deltas',
                ],
            ],
            [
                'number' => '04',
                'title' => 'Publish a snapshot',
                'duration' => 'instant',
                'description' => 'Hit Publish — we snapshot the agent into an immutable version row. The widget runtime always reads from the published version, so editing draft settings never affects live visitors. Roll back to any prior version with one click.',
                'sub_points' => [
                    'Versioned per-publish history',
                    'Strict allowed-origin enforcement on /v1/widget/init',
                    'One-click rollback to any prior snapshot',
                ],
            ],
            [
                'number' => '05',
                'title' => 'Embed one script tag',
                'duration' => '30 seconds',
                'description' => 'Paste a single &lt;script&gt; tag before &lt;/body&gt;. The widget bundle is &le; 50KB gzipped, async, and renders inside a Shadow DOM so it can\'t conflict with your site\'s CSS. Works on any framework — WordPress, Shopify, Next.js, plain HTML.',
                'sub_points' => [
                    'Shadow DOM isolation — no CSS leaks',
                    'Persistent visitor sessions across reloads',
                    'Optional voice mic (browser SpeechRecognition)',
                ],
            ],
            [
                'number' => '06',
                'title' => 'Visitor asks, AI answers',
                'duration' => '< 1 second to first token',
                'description' => 'A visitor types a question. Hot path: curated short-circuit check → embed query → vector search → rerank → assemble prompt with sources tagged for prompt-injection defense → stream LLM response back over SSE. No DB writes, no synchronous webhooks — persistence is async after the stream completes.',
                'sub_points' => [
                    'Hot-path 1s p95 TTFT contract enforced',
                    'Citations [1] [2] linking back to your sources',
                    'Confidence threshold per agent — agent says "I don\'t know" before guessing',
                ],
            ],
            [
                'number' => '07',
                'title' => 'Capture leads, jump in live',
                'duration' => 'when intent is high',
                'description' => 'Behavior rules detect when a visitor shows real intent (asks about pricing, asks for a demo, hits the third turn) and offer the inline lead form. Captured leads land in your inbox immediately, fire a Slack alert, and POST to your webhook for HubSpot / Pipedrive / Mailchimp.',
                'sub_points' => [
                    'Real-time inbox via Reverb WebSocket',
                    'One-click human takeover — visitor sees "Human is here"',
                    'Outgoing webhooks with HMAC-signed payloads',
                ],
            ],
        ];
    }

    /**
     * @return list<array{phase: string, budget: string}>
     */
    private function latencyBudget(): array
    {
        return [
            ['phase' => 'Receive + auth', 'budget' => '30 ms'],
            ['phase' => 'Curated short-circuit', 'budget' => '5 ms'],
            ['phase' => 'Embed query', 'budget' => '120 ms'],
            ['phase' => 'Vector search', 'budget' => '80 ms'],
            ['phase' => 'Rerank', 'budget' => '120 ms'],
            ['phase' => 'Prompt assembly', 'budget' => '10 ms'],
            ['phase' => 'LLM time-to-first-token', 'budget' => '500 ms'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nativeIntegrations(): array
    {
        return IntegrationCardsContent::resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dataSourceIntegrations(): array
    {
        return [
            ['name' => 'URL crawl', 'tagline' => 'Drop a URL, we pull the content respecting robots.txt.', 'icon' => 'Globe2'],
            ['name' => 'Sitemap', 'tagline' => 'Point at /sitemap.xml — we fan out to one job per page.', 'icon' => 'Map'],
            ['name' => 'RSS / Atom feeds', 'tagline' => 'Keep blog content fresh with feed-driven re-ingestion.', 'icon' => 'Rss'],
            ['name' => 'Pasted text', 'tagline' => 'Paste FAQs, scripts, or anything text-shaped — instant indexing.', 'icon' => 'Type'],
            ['name' => 'Auto-index visited pages', 'tagline' => 'Every page a visitor lands on gets indexed automatically (with guardrails).', 'icon' => 'Sparkles'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roadmapIntegrations(): array
    {
        return [
            ['name' => 'HubSpot', 'tagline' => 'Push leads as contacts and create deals on the configured pipeline.'],
            ['name' => 'Salesforce', 'tagline' => 'Native sync for Lead + Opportunity objects.'],
            ['name' => 'Pipedrive', 'tagline' => 'New deals + activities on lead capture.'],
            ['name' => 'Mailchimp', 'tagline' => 'Push leads as audience subscribers with conversation transcripts as custom fields.'],
            ['name' => 'Zapier', 'tagline' => 'Built-in trigger + action for the Zapier directory.'],
            ['name' => 'Calendly / Cal.com', 'tagline' => 'CTA buttons that open an inline booking widget mid-conversation.'],
        ];
    }

    private function termsIntro(): string
    {
        $brand = $this->brand();

        return "These terms govern your use of {$brand} (the “Service”). By creating an account, embedding the widget, or otherwise using the Service, you agree to these terms. If you do not agree, do not use the Service.";
    }

    /**
     * @return list<array{title: string, body?: array<int, string>, bullets?: array<int, string>}>
     */
    private function termsSections(): array
    {
        $brand = $this->brand();

        return [
            [
                'title' => '1. The Service',
                'body' => [
                    "{$brand} is an AI-powered website chat widget and operator console. We provide hosting for your agents, knowledge ingestion, retrieval, large-language-model responses, lead capture, and an inbox where your team can take over conversations from the AI. Specific capabilities, quotas, and limits are described on the pricing page and in your active subscription.",
                ],
            ],
            [
                'title' => '2. Your account',
                'bullets' => [
                    'You must provide accurate registration information and keep it current.',
                    'You are responsible for safeguarding your password and any access tokens.',
                    'You are responsible for all activity under your account, including activity by workspace members you invite.',
                    'You must promptly notify us of any unauthorized access or suspected breach.',
                    'You must be at least 16 years of age (or the age of digital consent in your jurisdiction).',
                ],
            ],
            [
                'title' => '3. Acceptable use',
                'body' => ['You agree not to use the Service to:'],
                'bullets' => [
                    'Violate any applicable law, regulation, or third-party right.',
                    'Send spam, harass, defraud, or impersonate any person or entity.',
                    'Distribute malware, conduct phishing, or attempt to compromise security.',
                    'Embed the widget on sites operated by third parties without their permission.',
                    'Reverse engineer, scrape, or attempt to derive source code beyond what is expressly permitted.',
                    'Resell, sublicense, or otherwise commercialize the Service except as expressly authorized.',
                    'Submit knowledge sources or visitor inputs that infringe copyright, contain unlawful content, or expose the personal data of individuals without a lawful basis.',
                ],
            ],
            [
                'title' => '4. Your content & data',
                'body' => [
                    'You retain ownership of the data you submit to the Service: knowledge sources, agent configuration, conversation transcripts, leads, and associated metadata (“Customer Data”). You grant us a worldwide, non-exclusive, royalty-free license to process Customer Data solely as needed to operate, secure, support, and improve the Service for you.',
                    'You represent that you have all rights necessary to submit Customer Data and that doing so does not violate any law or third-party right.',
                ],
            ],
            [
                'title' => '5. Visitor data & privacy',
                'body' => ['When you embed the widget on your site, visitor messages, IP-derived signals, and any contact details they submit pass through the Service. You are the controller of that data; we are the processor. You must:'],
                'bullets' => [
                    "Maintain a privacy notice that discloses the use of {$brand} and AI processing on your site.",
                    'Obtain any consents required by applicable law (GDPR, CCPA, equivalent).',
                    'Honor data subject rights for visitors who request access, correction, or deletion.',
                ],
            ],
            [
                'title' => '6. AI output disclaimer',
                'body' => ['AI responses are generated based on the knowledge sources you supply and the underlying language model. They may contain inaccuracies, omissions, or unintended outputs. You are responsible for:'],
                'bullets' => [
                    'Reviewing your knowledge base, system prompt, and behavior rules.',
                    'Configuring an appropriate confidence threshold for your use case.',
                    'Reviewing transcripts and capturing leads for any business-critical interaction.',
                ],
            ],
            [
                'title' => '7. Subscriptions, billing, & refunds',
                'bullets' => [
                    'Paid plans renew automatically until cancelled. You may cancel from your billing settings; cancellation takes effect at the end of the current billing period.',
                    'Fees are billed in advance and are non-refundable except where required by law or where we explicitly grant a refund.',
                    'You authorize us and our payment processor (Stripe) to charge the payment method on file.',
                    'If your usage exceeds your plan limits, we may rate-limit, block new conversations, or invite you to upgrade.',
                    'We may change pricing for new billing periods with reasonable notice.',
                ],
            ],
            [
                'title' => '8. Third-party services',
                'body' => [
                    'The Service integrates with third-party providers (large-language-model APIs, vector stores, payment processors, OAuth-based knowledge sources such as Notion or Google Drive, and your configured outgoing webhooks). Their availability, latency, and pricing are outside our control, and your use of those services is subject to their own terms.',
                ],
            ],
            [
                'title' => '9. Service availability',
                'body' => [
                    'We aim for high availability but do not guarantee uninterrupted access. We may perform maintenance, updates, or emergency response that briefly affects the Service. We are not liable for downtime caused by third-party providers or by force majeure.',
                ],
            ],
            [
                'title' => '10. Termination',
                'bullets' => [
                    'You may terminate by cancelling your subscription and deleting your workspace.',
                    'We may suspend or terminate the Service for violation of these terms, non-payment, or to comply with applicable law.',
                    'Upon termination we will retain Customer Data for a reasonable transition period (typically 30 days) before deletion.',
                ],
            ],
            [
                'title' => '11. Intellectual property',
                'body' => [
                    "The Service, including software, design, and trademarks, is owned by {$brand} and its licensors. Nothing in these terms grants you ownership of the Service. Feedback you provide may be used by us without obligation.",
                ],
            ],
            [
                'title' => '12. Disclaimers',
                'body' => [
                    'THE SERVICE IS PROVIDED “AS IS” WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE ERROR-FREE OR UNINTERRUPTED, OR THAT AI OUTPUTS WILL BE ACCURATE.',
                ],
            ],
            [
                'title' => '13. Limitation of liability',
                'body' => [
                    'TO THE MAXIMUM EXTENT PERMITTED BY LAW, NEITHER PARTY WILL BE LIABLE FOR INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, REVENUE, GOODWILL, OR DATA, ARISING OUT OF OR RELATED TO THE SERVICE. OUR AGGREGATE LIABILITY FOR ANY CLAIM WILL NOT EXCEED THE FEES YOU PAID FOR THE SERVICE IN THE 12 MONTHS PRECEDING THE CLAIM.',
                ],
            ],
            [
                'title' => '14. Indemnification',
                'body' => [
                    "You agree to defend and indemnify {$brand} against claims arising from (a) your Customer Data, (b) your use of the Service in violation of these terms, or (c) your violation of any law or third-party right.",
                ],
            ],
            [
                'title' => '15. Changes to these terms',
                'body' => [
                    'We may update these terms from time to time. Material changes will be announced via the Service or by email at least 14 days before they take effect. Continued use after the effective date constitutes acceptance.',
                ],
            ],
            [
                'title' => '16. Governing law',
                'body' => [
                    "These terms are governed by the laws of the jurisdiction in which the operator of {$brand} is established, without regard to conflict-of-law principles. Disputes will be resolved in the courts of that jurisdiction unless required otherwise by law.",
                ],
            ],
        ];
    }
}
