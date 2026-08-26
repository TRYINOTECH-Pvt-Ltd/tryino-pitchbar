@extends('marketing._layout')

@section('title', __('How it works — :brand', ['brand' => config('branding.site_title', 'Pitchbar')]))
@section('description', __('How the Pitchbar sales AI widget turns your website into a 24/7 sales rep — from setup to first conversation in under 5 minutes.'))

@php
    $brand = config('branding.site_title', 'Pitchbar');

    $steps = [
        [
            'number' => '01',
            'title' => __('Drop your URL'),
            'duration' => __('< 1 minute'),
            'description' => __('Sign up, paste your website URL, and we auto-discover your sitemap and key pages (about, pricing, FAQ, docs). Tick the ones you want indexed and we crawl them in the background — respecting robots.txt, blocking authenticated paths, never touching internal hosts.'),
            'sub_points' => [
                __('Cloudflare Browser Rendering for JS-heavy sites'),
                __('Plain HTTP fallback for simple sites'),
                __('Notion + Google Docs sources via OAuth'),
            ],
        ],
        [
            'number' => '02',
            'title' => __('We index your knowledge'),
            'duration' => __('~30 seconds for most sites'),
            'description' => __('Crawled pages are extracted (Readability), chunked along semantic boundaries (~500 tokens with overlap), embedded with Cloudflare bge-base-en-v1.5 or OpenAI text-embedding-3-small, and upserted into Vectorize or Qdrant — every chunk tagged with workspace + agent so retrieval is strictly tenant-scoped.'),
            'sub_points' => [
                __('Recursive splitter — paragraphs first, sentences as fallback'),
                __('Two-stage retrieval: ANN recall + cross-encoder rerank'),
                __('Strict workspace isolation enforced by global query scope'),
            ],
        ],
        [
            'number' => '03',
            'title' => __('Customize the agent'),
            'duration' => __('5 minutes of fine-tuning'),
            'description' => __("Set persona, tone, language, theme colors, starter prompts, and behavior rules. Add curated answers for pricing or refunds where you can't tolerate paraphrasing. A live preview shows visitors exactly what they'll see."),
            'sub_points' => [
                __('8 widget languages (en, es, fr, de, pt, ja, ar, zh)'),
                __('Behavior rules: scroll-depth, idle, exit-intent, intent-keyword'),
                __('A/B test rule variants and watch conversion deltas'),
            ],
        ],
        [
            'number' => '04',
            'title' => __('Publish a snapshot'),
            'duration' => __('instant'),
            'description' => __('Hit Publish — we snapshot the agent into an immutable version row. The widget runtime always reads from the published version, so editing draft settings never affects live visitors. Roll back to any prior version with one click.'),
            'sub_points' => [
                __('Versioned per-publish history'),
                __('Strict allowed-origin enforcement on /v1/widget/init'),
                __('One-click rollback to any prior snapshot'),
            ],
        ],
        [
            'number' => '05',
            'title' => __('Embed one script tag'),
            'duration' => __('30 seconds'),
            'description' => __('Paste a single &lt;script&gt; tag before &lt;/body&gt;. The widget bundle is &le; 50KB gzipped, async, and renders inside a Shadow DOM so it can\'t conflict with your site\'s CSS. Works on any framework — WordPress, Shopify, Next.js, plain HTML.'),
            'sub_points' => [
                __('Shadow DOM isolation — no CSS leaks'),
                __('Persistent visitor sessions across reloads'),
                __('Optional voice mic (browser SpeechRecognition)'),
            ],
        ],
        [
            'number' => '06',
            'title' => __('Visitor asks, AI answers'),
            'duration' => __('< 1 second to first token'),
            'description' => __('A visitor types a question. Hot path: curated short-circuit check → embed query → vector search → rerank → assemble prompt with sources tagged for prompt-injection defense → stream LLM response back over SSE. No DB writes, no synchronous webhooks — persistence is async after the stream completes.'),
            'sub_points' => [
                __('Hot-path 1s p95 TTFT contract enforced'),
                __('Citations [1] [2] linking back to your sources'),
                __('Confidence threshold per agent — agent says "I don\'t know" before guessing'),
            ],
        ],
        [
            'number' => '07',
            'title' => __('Capture leads, jump in live'),
            'duration' => __('when intent is high'),
            'description' => __('Behavior rules detect when a visitor shows real intent (asks about pricing, asks for a demo, hits the third turn) and offer the inline lead form. Captured leads land in your inbox immediately, fire a Slack alert, and POST to your webhook for HubSpot / Pipedrive / Mailchimp.'),
            'sub_points' => [
                __('Real-time inbox via Reverb WebSocket'),
                __('One-click human takeover — visitor sees "Human is here"'),
                __('Outgoing webhooks with HMAC-signed payloads'),
            ],
        ],
    ];

    $latency = [
        ['phase' => __('Receive + auth'), 'budget' => __('30 ms')],
        ['phase' => __('Curated short-circuit'), 'budget' => __('5 ms')],
        ['phase' => __('Embed query'), 'budget' => __('120 ms')],
        ['phase' => __('Vector search'), 'budget' => __('80 ms')],
        ['phase' => __('Rerank'), 'budget' => __('120 ms')],
        ['phase' => __('Prompt assembly'), 'budget' => __('10 ms')],
        ['phase' => __('LLM time-to-first-token'), 'budget' => __('500 ms')],
    ];
@endphp

@section('content')

<section class="mx-auto max-w-5xl px-6 pt-20 pb-16 lg:pt-28">
    <div class="mx-auto max-w-3xl text-center">
        <span class="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white/85 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600">
            {{ __('How it works') }}
        </span>
        <h1 class="mt-6 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
            {{ __('From URL to live sales agent in') }} <span class="marketing-display italic">{{ __('under 5 minutes') }}</span>
        </h1>
        <p class="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
            {{ __(':brand reads your website, builds an AI agent grounded in your own content, and embeds it on any page with one line of HTML. Here\'s exactly what happens.', ['brand' => $brand]) }}
        </p>
    </div>
</section>

<section class="relative mx-auto max-w-4xl px-6 pb-16">
    <div class="absolute start-[27px] top-8 bottom-8 w-px bg-gradient-to-b from-transparent via-slate-900/15 to-transparent sm:start-[35px]"></div>

    <div class="space-y-6">
        @foreach ($steps as $step)
            <div class="relative rounded-3xl border border-slate-900/10 bg-white/85 p-6 ps-20 backdrop-blur shadow-[0_30px_70px_-60px_rgba(15,23,42,0.4)] sm:ps-24">
                <span class="absolute start-4 top-6 flex size-12 items-center justify-center rounded-2xl border border-slate-900/10 bg-slate-950 text-sm font-semibold text-white shadow-lg shadow-slate-900/20 sm:start-5">
                    {{ $step['number'] }}
                </span>
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h3 class="text-xl font-semibold tracking-tight text-slate-950 sm:text-2xl">{{ $step['title'] }}</h3>
                    <span class="rounded-full border border-emerald-600/30 bg-emerald-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700">
                        {{ $step['duration'] }}
                    </span>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">{!! $step['description'] !!}</p>
                @if (! empty($step['sub_points']))
                    <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach ($step['sub_points'] as $point)
                            <li class="flex items-start gap-2 text-sm text-slate-700">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="mt-[3px] size-4 flex-shrink-0 text-emerald-600">
                                    <path d="M5 10l4 4 7-8"/>
                                </svg>
                                <span>{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</section>

<section class="mx-auto max-w-5xl px-6 py-16">
    <div class="rounded-3xl border border-slate-900/10 bg-white/70 p-6 backdrop-blur sm:p-10">
        <div class="grid gap-8 lg:grid-cols-[1.2fr_1fr] lg:items-start">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-600">
                    {{ __('Hot path contract') }}
                </span>
                <h2 class="mt-4 text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">
                    {{ __('1 second to first token. Every time.') }}
                </h2>
                <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                    {{ __('The visitor-message → first-token path has a hard p95 budget. No DB writes, no synchronous webhooks, no retries. Persistence and analytics are dispatched async after the stream completes.') }}
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                    {{ __('OpenTelemetry spans wrap every phase so when the budget breaks the span heatmap points right at the offender.') }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-900/10 bg-white p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('p95 latency budget') }}</p>
                <ul class="mt-4 space-y-3">
                    @foreach ($latency as $phase)
                        <li class="flex items-center justify-between text-sm text-slate-700">
                            <span>{{ $phase['phase'] }}</span>
                            <span class="font-mono text-slate-950">{{ $phase['budget'] }}</span>
                        </li>
                    @endforeach
                    <li class="flex items-center justify-between border-t border-slate-900/10 pt-3 text-sm font-semibold text-slate-950">
                        <span>{{ __('Total to first token') }}</span>
                        <span class="font-mono text-emerald-700">{{ __('~ 865 ms') }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="mx-auto max-w-5xl px-6 pb-24">
    <div class="rounded-3xl bg-slate-950 px-8 py-12 text-center sm:px-12">
        <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ __('Try it on your own site.') }}</h2>
        <p class="mt-3 text-sm text-slate-300 sm:text-base">{{ __('Free to start. No card required. Live in 5 minutes.') }}</p>
        <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ url('/register') }}" class="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-100">
                {{ __('Start free') }}
            </a>
            <a href="{{ route('marketing.pricing') }}" class="inline-flex items-center justify-center rounded-full border border-white/20 px-6 py-3 text-sm font-medium text-white transition hover:bg-white/10">
                {{ __('See pricing') }}
            </a>
        </div>
    </div>
</section>

@endsection
