@php
    $sharedBranding = data_get($page ?? [], 'props.branding', []);
    $siteTitle = data_get($sharedBranding, 'site_title', config('branding.site_title', config('app.name', 'Pitchbar')));
    $customFavicon = data_get($sharedBranding, 'favicon_url');
    $initialFavicon = $customFavicon ?: asset('favicon.ico');
    $initialTouchIcon = $customFavicon ?: asset('apple-touch-icon.png');

    // SEO payload — per-page seo[] from the controller, or a sane
    // fallback so every render gets <meta description> + canonical
    // + Open Graph + Twitter Card + JSON-LD even when a page didn't
    // declare its own. Pure-PHP, hot-path safe.
    $seo = data_get($page ?? [], 'props.seo')
        ?: \App\Support\SeoMeta::defaults();
    $seoTitle = (string) ($seo['title'] ?? $siteTitle);
    $seoDescription = (string) ($seo['description'] ?? '');
    $seoCanonical = (string) ($seo['canonical'] ?? '');
    $seoSiteName = (string) ($seo['site_name'] ?? $siteTitle);
    $seoImage = $seo['image_url'] ?? null;
    $seoTwitterCard = (string) ($seo['twitter_card'] ?? 'summary_large_image');
    $seoTwitterHandle = $seo['twitter_handle'] ?? null;
    $seoNoindex = (bool) ($seo['noindex'] ?? false);
    $seoJsonLd = (array) ($seo['json_ld'] ?? []);

    // Resolve locale metadata once per render so <html dir>, lang
    // attributes, and the locale picker can all read from the same
    // source without re-instantiating the catalog lookup three times.
    $_localeEntry = \App\Services\I18n\LocaleCatalog::entryFor(app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $_localeEntry['rtl'] ? 'rtl' : 'ltr' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ $siteTitle }}">
        {{--
            We ship our own translations via /settings/locale + the
            geo-suggested banner. Browser auto-translate (Chrome's
            Google Translate prompt, Edge Translator) competes with
            our React render — once it rewrites the DOM, our useT()
            updates stop applying because the text nodes have been
            replaced. `notranslate` tells the browser to leave the
            page alone.
        --}}
        <meta name="google" content="notranslate">
        <meta name="google" content="notranslate" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

        {{-- SEO --}}
        @if($seoDescription !== '')
            <meta name="description" content="{{ $seoDescription }}">
        @endif
        @if($seoCanonical !== '')
            <link rel="canonical" href="{{ $seoCanonical }}">
        @endif
        @if($seoNoindex)
            <meta name="robots" content="noindex,nofollow">
        @endif

        {{-- Open Graph --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $seoSiteName }}">
        <meta property="og:title" content="{{ $seoTitle }}">
        @if($seoDescription !== '')
            <meta property="og:description" content="{{ $seoDescription }}">
        @endif
        @if($seoCanonical !== '')
            <meta property="og:url" content="{{ $seoCanonical }}">
        @endif
        @if($seoImage)
            <meta property="og:image" content="{{ $seoImage }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $seoSiteName }}">
        @endif

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="{{ $seoTwitterCard }}">
        <meta name="twitter:title" content="{{ $seoTitle }}">
        @if($seoDescription !== '')
            <meta name="twitter:description" content="{{ $seoDescription }}">
        @endif
        @if($seoImage)
            <meta name="twitter:image" content="{{ $seoImage }}">
        @endif
        @if($seoTwitterHandle)
            <meta name="twitter:site" content="{{ $seoTwitterHandle }}">
        @endif

        {{-- JSON-LD structured data — Organization on every page,
             plus per-route blocks (SoftwareApplication on home,
             FAQPage from buyer-edited content, BreadcrumbList on
             docs). --}}
        @foreach($seoJsonLd as $jsonLdBlock)
            <script type="application/ld+json">{!! json_encode($jsonLdBlock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <script>
            window.__PITCHBAR_SITE_TITLE__ = @json($siteTitle);
            window.__PITCHBAR_DEFAULT_FAVICON_URL__ = @json($initialFavicon);
            window.__PITCHBAR_DEFAULT_TOUCH_ICON_URL__ = @json($initialTouchIcon);
        </script>

        <link id="app-favicon" rel="icon" href="{{ $initialFavicon }}" sizes="any">
        <link id="app-apple-touch-icon" rel="apple-touch-icon" href="{{ $initialTouchIcon }}">

        {{-- D1: PWA manifest + theme-color so installed admins look native
             on mobile + desktop. The manifest is generated dynamically so
             the white-label site title flows through (operators rebranding
             the site don't ship Pitchbar's name to install prompts). --}}
        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <meta name="theme-color" content="#0f172a">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="{{ $siteTitle }}">

        {{-- Geist (Vercel's typeface) served via Bunny Fonts — applies to
             admin + customer + auth + marketing because this is the only
             root blade template. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=geist:100,200,300,400,500,600,700,800,900|geist-mono:400,500,600&display=swap"
            rel="stylesheet"
        >

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $seoTitle }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased notranslate" translate="no">
        <x-inertia::app />

        @php
            $component = $page['component'] ?? null;
            $isMarketingComponent = $component === 'welcome'
                || str_starts_with((string) $component, 'marketing/')
                || str_starts_with((string) $component, 'marketing-themes/');
            // Auth check is the safety net — even if a future page is
            // mis-categorised as "marketing", the demo widget never
            // mounts for authed admin / customer surfaces. A signed-in
            // workspace owner sitting on /dashboard should never see
            // their own marketing site's demo bot watching them.
            $isAuthenticated = auth()->check();
            // First-paint emission uses the same resolver the Inertia
            // shared prop calls, so Blade and React agree on which
            // agent should mount. After first paint, the React-side
            // mount component (resources/js/components/marketing-widget-mount.tsx)
            // owns the script tag — it watches the shared prop and
            // tears down / remounts on changes so the marketing tab
            // doesn't go stale when the operator toggles the setting.
            $marketingWidgetPayload = ($isMarketingComponent && ! $isAuthenticated)
                ? \App\Support\MarketingWidget::payload($isAuthenticated)
                : ['enabled' => false, 'agent_id' => null, 'is_demo' => false, 'version' => 'dev', 'src' => ''];
        @endphp
        @if($marketingWidgetPayload['enabled'])
            {{--
                data-marketing-widget marks the tag as React-managed so
                the mount component can find + replace it on prop change.
                data-demo="true" renders a "Demo" pill so reviewers /
                visitors can tell this is a sandbox. Auth-gated above so
                a signed-in admin never sees their own marketing site's
                widget watching them.
            --}}
            <script
                src="{{ $marketingWidgetPayload['src'] }}?v={{ $marketingWidgetPayload['version'] }}"
                data-agent-id="{{ $marketingWidgetPayload['agent_id'] }}"
                data-marketing-widget="true"
                @if($marketingWidgetPayload['is_demo']) data-demo="true" @endif
                defer
            ></script>
        @endif
    </body>
</html>
