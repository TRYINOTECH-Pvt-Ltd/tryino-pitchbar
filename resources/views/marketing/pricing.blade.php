@extends('marketing._layout')

@section('title', __('Pricing — :brand', ['brand' => config('branding.site_title', 'Pitchbar')]))
@section('description', __('Simple, conversation-based pricing for the Pitchbar sales AI widget. Start free; scale as you grow.'))

@php
    $brand = config('branding.site_title', 'Pitchbar');

    $plans = [
        [
            'name' => __('Free'),
            'price' => 0,
            'tagline' => __('Try it on your live site.'),
            'volume' => __('100 conversations / month'),
            'cta_label' => __('Start free'),
            'cta_href' => url('/register'),
            'highlight' => false,
            'features' => [
                __('1 published agent'),
                __('URL + sitemap + text knowledge sources'),
                __('Lead capture inbox'),
                __('Slack & outgoing webhook integrations'),
                __(':brand branding shown', ['brand' => $brand]),
            ],
        ],
        [
            'name' => __('Standard'),
            'price' => 49,
            'tagline' => __('For real traffic.'),
            'volume' => __('500 conversations / month'),
            'cta_label' => __('Start 14-day trial'),
            'cta_href' => url('/register'),
            'highlight' => true,
            'features' => [
                __('5 published agents'),
                __('Everything in Free, plus:'),
                __('A/B testing on behavior rules'),
                __('Content gap analytics'),
                __('Notion + Google Doc knowledge sources'),
                __('Branding removed from widget'),
                __('Email support'),
            ],
        ],
        [
            'name' => __('Pro'),
            'price' => 249,
            'tagline' => __('For teams that need scale.'),
            'volume' => __('3,000 conversations / month'),
            'cta_label' => __('Start 14-day trial'),
            'cta_href' => url('/register'),
            'highlight' => false,
            'features' => [
                __('Unlimited agents'),
                __('Everything in Standard, plus:'),
                __('HubSpot + Pipedrive native sync'),
                __('Higher LLM rate limits'),
                __('Custom domain for the widget'),
                __('Priority support, 8h SLA'),
            ],
        ],
    ];

    $matrixRows = [
        ['label' => __('Published agents'), 'free' => '1', 'standard' => '5', 'pro' => __('Unlimited')],
        ['label' => __('Monthly conversations'), 'free' => '100', 'standard' => '500', 'pro' => '3,000'],
        ['label' => __('Voice mic on widget'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('Lead capture + inbox'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('Persistent visitor sessions'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('URL / sitemap / text sources'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('Notion + Google Doc sources'), 'free' => false, 'standard' => true, 'pro' => true],
        ['label' => __('Auto-index visited pages'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('8 widget languages'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('A/B testing + content gaps'), 'free' => false, 'standard' => true, 'pro' => true],
        ['label' => __('Behavior rules + CTAs'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('Curated answers'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('Branding removed'), 'free' => false, 'standard' => true, 'pro' => true],
        ['label' => __('Slack + outgoing webhooks'), 'free' => true, 'standard' => true, 'pro' => true],
        ['label' => __('HubSpot + Pipedrive native'), 'free' => false, 'standard' => false, 'pro' => true],
        ['label' => __('Custom widget domain'), 'free' => false, 'standard' => false, 'pro' => true],
        ['label' => __('Workspace members'), 'free' => '2', 'standard' => '10', 'pro' => __('Unlimited')],
        ['label' => __('Support'), 'free' => __('Community'), 'standard' => __('Email'), 'pro' => __('Priority, 8h SLA')],
    ];

    $faqs = [
        ['q' => __('What counts as a conversation?'), 'a' => __('A conversation is metered the moment a visitor sends their first message. Resumed conversations within 24 hours don\'t count again. Playground / staging traffic is exempt.')],
        ['q' => __('What happens if I exceed the quota?'), 'a' => __('New conversations are paused with a friendly upgrade prompt. Conversations already in progress finish normally — including human takeovers. Your widget never breaks visibly.')],
        ['q' => __('Can I cancel anytime?'), 'a' => __('Yes. Cancellations take effect at the end of the billing period; you keep access until then and your data stays safe.')],
        ['q' => __('Do you offer refunds?'), 'a' => __('A 30-day money-back guarantee on all paid plans, no questions asked. Email :email.', ['email' => config('mail.from.address', 'support@example.com')])],
        ['q' => __('Is there an Enterprise plan?'), 'a' => __('Yes — custom limits, SSO, dedicated infrastructure, and a named CSM. Contact us for a quote.')],
        ['q' => __('Can I self-host?'), 'a' => __(':brand is also available as a self-hosted application via a one-time license. Same features, your infrastructure, your data.', ['brand' => $brand])],
    ];
@endphp

@section('content')

<section class="relative mx-auto max-w-6xl px-6 pt-20 pb-12 lg:pt-28">
    <div class="mx-auto max-w-3xl text-center">
        <span class="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white/85 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600">
            {{ __('Pricing') }}
        </span>
        <h1 class="mt-6 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
            {{ __('Simple,') }} <span class="marketing-display italic">{{ __('conversation-based') }}</span> {{ __('pricing') }}
        </h1>
        <p class="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
            {{ __('Start free. Upgrade when you outgrow it. No per-seat fees, no setup fees, no surprise overages — just a clear monthly conversation limit.') }}
        </p>
    </div>
</section>

<section class="mx-auto max-w-6xl px-6 pb-12">
    <div class="grid gap-6 md:grid-cols-3">
        @foreach ($plans as $plan)
            <div @class([
                'relative flex flex-col rounded-3xl border bg-white/85 p-7 backdrop-blur shadow-[0_30px_70px_-50px_rgba(15,23,42,0.4)]',
                'border-slate-900/10' => ! $plan['highlight'],
                'border-emerald-500/40 ring-2 ring-emerald-500/30' => $plan['highlight'],
            ])>
                @if ($plan['highlight'])
                    <span class="absolute -top-3 start-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white shadow-lg shadow-emerald-600/30">
                        {{ __('Most popular') }}
                    </span>
                @endif

                <div>
                    <h3 class="text-xl font-semibold text-slate-950">{{ $plan['name'] }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ $plan['tagline'] }}</p>
                </div>

                <div class="mt-6 flex items-baseline gap-1">
                    <span class="text-5xl font-bold tracking-tight text-slate-950">${{ $plan['price'] }}</span>
                    @if ($plan['price'] > 0)
                        <span class="text-sm text-slate-500">{{ __('/month') }}</span>
                    @endif
                </div>
                <p class="mt-2 text-sm font-medium text-slate-700">{{ $plan['volume'] }}</p>

                <ul class="mt-6 flex-1 space-y-3 text-sm text-slate-700">
                    @foreach ($plan['features'] as $feature)
                        <li class="flex items-start gap-2">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="mt-[3px] size-4 flex-shrink-0 text-emerald-600">
                                <path d="M5 10l4 4 7-8"/>
                            </svg>
                            <span>{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>

                <a
                    href="{{ $plan['cta_href'] }}"
                    @class([
                        'mt-8 inline-flex items-center justify-center rounded-full px-5 py-3 text-sm font-semibold transition',
                        'bg-slate-950 text-white hover:bg-slate-800' => $plan['highlight'],
                        'border border-slate-900/15 bg-white text-slate-950 hover:bg-slate-50' => ! $plan['highlight'],
                    ])
                >
                    {{ $plan['cta_label'] }}
                </a>
            </div>
        @endforeach
    </div>

    <p class="mt-8 text-center text-sm text-slate-500">
        {{ __('Need higher limits or a self-hosted license?') }}
        <a href="mailto:{{ config('mail.from.address', 'support@example.com') }}" class="font-medium text-slate-950 underline underline-offset-4">
            {{ __('Talk to us about Enterprise') }}
        </a>.
    </p>
</section>

<section class="mx-auto max-w-6xl px-6 py-16">
    <div class="rounded-3xl border border-slate-900/10 bg-white/70 p-6 shadow-[0_30px_70px_-60px_rgba(15,23,42,0.4)] backdrop-blur sm:p-10">
        <div class="mb-6">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">{{ __('Compare every feature') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('Everything you can do on each plan, side by side.') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
                <thead>
                    <tr class="text-start text-slate-500">
                        <th class="border-b border-slate-900/10 py-3 pe-4 font-medium uppercase tracking-wider text-xs">{{ __('Feature') }}</th>
                        <th class="border-b border-slate-900/10 px-4 py-3 text-center font-semibold text-slate-950">{{ __('Free') }}</th>
                        <th class="border-b border-slate-900/10 px-4 py-3 text-center font-semibold text-emerald-700">{{ __('Standard') }}</th>
                        <th class="border-b border-slate-900/10 px-4 py-3 text-center font-semibold text-slate-950">{{ __('Pro') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrixRows as $row)
                        <tr class="text-slate-700">
                            <td class="border-b border-slate-900/5 py-3 pe-4 font-medium text-slate-950">{{ $row['label'] }}</td>
                            @foreach (['free', 'standard', 'pro'] as $col)
                                <td class="border-b border-slate-900/5 px-4 py-3 text-center">
                                    @if ($row[$col] === true)
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="mx-auto size-4 text-emerald-600"><path d="M5 10l4 4 7-8"/></svg>
                                    @elseif ($row[$col] === false)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <span class="text-slate-700">{{ $row[$col] }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="mx-auto max-w-4xl px-6 py-16">
    <h2 class="text-center text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">{{ __('Frequently asked') }}</h2>
    <div class="mt-10 grid gap-4">
        @foreach ($faqs as $faq)
            <details class="group rounded-2xl border border-slate-900/10 bg-white/80 p-5 backdrop-blur transition hover:bg-white">
                <summary class="flex cursor-pointer items-center justify-between text-base font-medium text-slate-950 marker:hidden [&::-webkit-details-marker]:hidden">
                    <span>{{ $faq['q'] }}</span>
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 text-slate-500 transition group-open:rotate-180">
                        <path d="M5 8l5 5 5-5"/>
                    </svg>
                </summary>
                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </div>
</section>

<section class="mx-auto max-w-5xl px-6 pb-24">
    <div class="rounded-3xl bg-slate-950 px-8 py-12 text-center sm:px-12">
        <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ __('Ready to ship a real sales AI?') }}</h2>
        <p class="mt-3 text-sm text-slate-300 sm:text-base">{{ __('Free to start. Deploys in 5 minutes. No card required.') }}</p>
        <a href="{{ url('/register') }}" class="mt-6 inline-flex items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-100">
            {{ __('Get started free') }}
        </a>
    </div>
</section>
@endsection
