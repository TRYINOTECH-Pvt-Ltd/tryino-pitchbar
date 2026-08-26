<!doctype html>
@php
    $_localeEntry = \App\Services\I18n\LocaleCatalog::entryFor(app()->getLocale());
    $_brand = \App\Support\AppBranding::siteTitle();
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $_localeEntry['rtl'] ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Disable browser auto-translate — we ship our own translations. --}}
    <meta name="google" content="notranslate">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', __(':brand — a Sales AI bar for any website.', ['brand' => $_brand]))">
    {{-- Favicon: mirror the Inertia root behavior so /p/{slug} pages
         (and /privacy, /terms) carry the operator's uploaded favicon,
         not the default Laravel icon. Buyer report 2026-05-21. --}}
    @php($_marketingFavicon = \App\Support\AppBranding::shared()['favicon_url'] ?? null)
    <link rel="icon" href="{{ $_marketingFavicon ?: asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen overflow-x-hidden bg-[#f3efe7] text-slate-900 antialiased notranslate" translate="no">
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="marketing-grid-background absolute inset-0 opacity-70"></div>
        <div class="absolute start-1/2 top-0 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-amber-200/45 blur-3xl"></div>
        <div class="absolute bottom-[-10rem] end-[-6rem] h-[24rem] w-[24rem] rounded-full bg-emerald-200/35 blur-3xl"></div>
    </div>

    {{--
        Geo-suggested locale banner. Only renders when the visitor's
        Cloudflare CF-IPCountry maps to a translation we ship and the
        dismiss cookie isn't set. Two forms — switch (PATCH) and
        dismiss (POST) — so it works without any JS.
    --}}
    @php($localeSuggestion = app(\App\Services\I18n\LocaleResolver::class)->suggestionFor(request(), app()->getLocale()))
    @if($localeSuggestion)
        @php($_loc = $localeSuggestion['suggested'])
        @php($_label = ['en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'tr' => 'Türkçe'][$_loc] ?? $_loc)
        @php($_flag = ['en' => '🇺🇸', 'es' => '🇪🇸', 'fr' => '🇫🇷', 'tr' => '🇹🇷'][$_loc] ?? '🌐')
        <div role="status" class="flex items-center gap-3 border-b border-emerald-300/40 bg-emerald-50 px-4 py-2 text-sm text-emerald-950">
            <span class="text-base" aria-hidden>{{ $_flag }}</span>
            <span class="flex-1">
                {{ __('Switch to :language?', ['language' => $_label]) }}
            </span>
            <form method="POST" action="{{ route('locale.switch') }}" class="inline-flex">
                @csrf
                @method('PATCH')
                <input type="hidden" name="locale" value="{{ $_loc }}">
                <button type="submit" class="inline-flex items-center rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-emerald-700">
                    {{ __('Switch to :language', ['language' => $_label]) }}
                </button>
            </form>
            <form method="POST" action="{{ route('locale.dismiss-suggestion') }}" class="inline-flex">
                @csrf
                <button type="submit" class="inline-flex size-7 items-center justify-center rounded-full text-emerald-900/70 transition hover:bg-emerald-100" aria-label="{{ __('Keep current language') }}">
                    ×
                </button>
            </form>
        </div>
    @endif

    <header class="sticky top-0 z-20 border-b border-slate-900/10 bg-[#f3efe7]/90 backdrop-blur-xl">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-4">
            <a href="{{ route('home') }}" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-slate-950 uppercase">
                <span class="flex size-9 items-center justify-center rounded-full border border-slate-900/15 bg-white/85 text-base tracking-normal">{{ mb_strtoupper(mb_substr($_brand, 0, 1)) }}</span>
                <span>{{ $_brand }}</span>
            </a>

            {{-- Demo-mode opens auth links in a new tab so reviewers
                 don't lose the marketing surface when they hop into
                 the live demo app. Driven by config('demo.enabled')
                 which is config-cached server-side. --}}
            @php($_demoTarget = config('demo.enabled') && ! auth()->check() ? 'target="_blank" rel="noopener noreferrer"' : '')

            <nav class="hidden items-center gap-6 text-sm text-slate-600 md:flex">
                <a href="{{ route('marketing.pricing') }}" class="transition hover:text-slate-950">{{ __('Pricing') }}</a>
                <a href="{{ route('marketing.how-it-works') }}" class="transition hover:text-slate-950">{{ __('How it works') }}</a>
                <a href="{{ url('/login') }}" {!! $_demoTarget !!} class="transition hover:text-slate-950">{{ __('Login') }}</a>
                <a href="{{ url('/register') }}" {!! $_demoTarget !!} class="rounded-full border border-slate-900/10 bg-slate-950 px-4 py-2 font-medium text-white transition hover:bg-slate-800">{{ __('Get started') }}</a>

                {{-- Public locale picker: opens a searchable modal listing
                     every locale we ship (`lang/*.json` discovery via
                     LocaleResolver::supported()). Switch via `?locale=`
                     so the SetLocale middleware persists the choice into
                     the `pb_locale` cookie on the redirect. --}}
                @php($_supportedCodes = app(\App\Services\I18n\LocaleResolver::class)->supported())
                @php($_catalog = \App\Services\I18n\LocaleCatalog::hydrate($_supportedCodes))
                @php($_currentEntry = $_catalog[app()->getLocale()] ?? \App\Services\I18n\LocaleCatalog::entryFor(app()->getLocale()))
                <button
                    type="button"
                    onclick="window.dispatchEvent(new CustomEvent('pb:open-locale-picker'))"
                    class="flex cursor-pointer items-center gap-1 text-sm text-slate-600 transition hover:text-slate-950"
                    aria-label="{{ __('Language') }}"
                >
                    <span aria-hidden="true">{{ $_currentEntry['flag'] ?? '🌐' }}</span>
                    <span class="uppercase">{{ app()->getLocale() }}</span>
                </button>
            </nav>

            <div class="flex items-center gap-2 md:hidden">
                <a href="{{ url('/login') }}" {!! $_demoTarget !!} class="rounded-full border border-slate-900/10 bg-white/80 px-3 py-2 text-sm font-medium text-slate-700">{{ __('Login') }}</a>
                <a href="{{ url('/register') }}" {!! $_demoTarget !!} class="rounded-full bg-slate-950 px-3 py-2 text-sm font-medium text-white">{{ __('Start') }}</a>
            </div>
        </div>
    </header>

    <main class="relative">@yield('content')</main>

    <footer class="border-t border-slate-900/10 bg-white/55">
        <div class="mx-auto grid max-w-7xl gap-10 px-6 py-10 md:grid-cols-[1.5fr_0.7fr_0.7fr]">
            <div class="max-w-md">
                <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">{{ $_brand }}</p>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    {{ __('A clean sales AI layer for websites that need faster answers, better qualification, and a clearer path from visitor intent to revenue.') }}
                </p>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-950">{{ __('Explore') }}</p>
                <div class="mt-4 grid gap-3 text-sm text-slate-600">
                    <a href="{{ route('home') }}" class="transition hover:text-slate-950">{{ __('Home') }}</a>
                    <a href="{{ route('marketing.pricing') }}" class="transition hover:text-slate-950">{{ __('Pricing') }}</a>
                    <a href="{{ route('marketing.how-it-works') }}" class="transition hover:text-slate-950">{{ __('How it works') }}</a>
                    @foreach (\App\Models\Page::query()->where('is_published', true)->orderBy('sort_order')->orderBy('title')->get(['slug', 'title']) as $customPage)
                        <a href="/p/{{ $customPage->slug }}" class="transition hover:text-slate-950">{{ $customPage->title }}</a>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-950">{{ __('Legal') }}</p>
                <div class="mt-4 grid gap-3 text-sm text-slate-600">
                    <a href="{{ route('marketing.privacy') }}" class="transition hover:text-slate-950">{{ __('Privacy') }}</a>
                    <a href="{{ route('marketing.terms') }}" class="transition hover:text-slate-950">{{ __('Terms') }}</a>
                    <a href="{{ url('/login') }}" {!! $_demoTarget !!} class="transition hover:text-slate-950">{{ __('Login') }}</a>
                </div>
            </div>
        </div>
        <div class="border-t border-slate-900/10 px-6 py-4 text-center text-sm text-slate-500">
            © {{ date('Y') }} {{ $_brand }}. {{ __('Sales AI for any website.') }}
        </div>
    </footer>

    @if($demoAgentId = \App\Support\MarketingDemoAgent::id())
        <script src="{{ rtrim(config('app.url'), '/') }}/widget/widget.js" data-agent-id="{{ $demoAgentId }}" data-demo="true" defer></script>
    @endif

    {{-- Locale picker modal (Blade variant). Listens for the
         `pb:open-locale-picker` custom event fired by the header pill.
         Native <dialog> + a tiny vanilla search filter — no Alpine, no
         React, no extra bundle. The list is rendered server-side with
         every locale the buyer has dropped into `lang/`, so adding a
         language is genuinely zero-code. --}}
    <dialog
        id="pb-locale-picker"
        class="m-auto w-[min(420px,92vw)] rounded-2xl border border-slate-900/10 bg-white p-0 shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm open:animate-[fadeIn_120ms_ease-out]"
        aria-label="{{ __('Language') }}"
    >
        <div class="flex items-center justify-between border-b border-slate-900/10 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">{{ __('Choose your language') }}</h2>
            <button
                type="button"
                onclick="document.getElementById('pb-locale-picker').close()"
                class="inline-flex size-7 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                aria-label="{{ __('Close') }}"
            >×</button>
        </div>
        <div class="px-4 py-3">
            <input
                type="search"
                id="pb-locale-search"
                placeholder="{{ __('Search languages…') }}"
                class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-slate-400 focus:outline-none"
                autocomplete="off"
                spellcheck="false"
            >
        </div>
        <ul id="pb-locale-list" class="max-h-[60vh] overflow-y-auto px-2 pb-3" role="listbox">
            @foreach ($_catalog as $code => $meta)
                <li>
                    <a
                        href="{{ url()->current() . '?locale=' . $code }}"
                        data-search="{{ strtolower($code . ' ' . $meta['native'] . ' ' . $meta['english']) }}"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition hover:bg-slate-100 {{ app()->getLocale() === $code ? 'bg-slate-50 font-semibold text-slate-950' : 'text-slate-700' }}"
                        role="option"
                        aria-selected="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                    >
                        <span class="text-base leading-none" aria-hidden>{{ $meta['flag'] }}</span>
                        <span class="flex-1 truncate">{{ $meta['native'] }}</span>
                        <span class="text-xs text-slate-400">{{ $meta['english'] }}</span>
                    </a>
                </li>
            @endforeach
            <li id="pb-locale-empty" hidden class="px-3 py-6 text-center text-sm text-slate-500">
                {{ __('No matching languages.') }}
            </li>
        </ul>
    </dialog>
    <script>
        (function () {
            var dialog = document.getElementById('pb-locale-picker');
            var input = document.getElementById('pb-locale-search');
            var list = document.getElementById('pb-locale-list');
            var empty = document.getElementById('pb-locale-empty');
            if (!dialog || !input || !list) return;

            window.addEventListener('pb:open-locale-picker', function () {
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                }
                setTimeout(function () { input.focus(); input.select(); }, 30);
            });

            input.addEventListener('input', function (e) {
                var q = (e.target.value || '').toLowerCase().trim();
                var anyVisible = false;
                Array.prototype.forEach.call(list.querySelectorAll('a[data-search]'), function (a) {
                    var match = q === '' || a.dataset.search.indexOf(q) !== -1;
                    a.parentElement.style.display = match ? '' : 'none';
                    if (match) anyVisible = true;
                });
                if (empty) empty.hidden = anyVisible;
            });

            // Close on ESC + on backdrop click (native dialog behaviour).
            dialog.addEventListener('click', function (e) {
                if (e.target === dialog) dialog.close();
            });
        })();
    </script>
</body>
</html>
