<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $site_title) — {{ $site_title }}</title>
    <meta name="description" content="@yield('description', 'Knowledge base for ' . $site_title)">
    @if (! ($workspace?->removesBranding() ?? false))
        <meta name="generator" content="{{ $site_title }}">
    @endif
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #ffffff;
            --fg: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #0ea5e9;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f172a; --fg: #f1f5f9; --muted: #94a3b8; --border: #1e293b; --primary: #38bdf8; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--fg); background: var(--bg); }
        .wrap { max-width: 760px; margin: 0 auto; padding: 32px 24px 64px; }
        header { border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 32px; }
        header a.brand { font-weight: 600; font-size: 18px; color: var(--fg); text-decoration: none; }
        header p { color: var(--muted); margin: 4px 0 0; font-size: 14px; }
        h1 { font-size: 28px; line-height: 1.25; margin: 0 0 12px; }
        h2 { font-size: 20px; margin: 32px 0 12px; }
        h3 { font-size: 17px; margin: 24px 0 8px; }
        p, ul, ol { margin: 0 0 14px; }
        ul li, ol li { margin: 4px 0; }
        a { color: var(--primary); }
        a:hover { text-decoration: underline; }
        code { background: rgba(0,0,0,0.04); padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
        @media (prefers-color-scheme: dark) { code { background: rgba(255,255,255,0.08); } }
        pre { background: rgba(0,0,0,0.04); padding: 12px 14px; border-radius: 6px; overflow-x: auto; }
        @media (prefers-color-scheme: dark) { pre { background: rgba(255,255,255,0.04); } }
        .article-list { list-style: none; padding: 0; }
        .article-list li { margin: 0; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .article-list a { font-weight: 500; }
        footer { margin-top: 64px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--muted); font-size: 13px; }
        .crumb { color: var(--muted); font-size: 14px; margin-bottom: 16px; }
        .crumb a { color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <a class="brand" href="/kb/{{ $workspace->slug }}">{{ $site_title }} — @yield('crumb', 'Knowledge base')</a>
            <p>@yield('subtitle', 'Help articles + how-tos curated by our team.')</p>
        </header>
        @yield('content')
        <footer>
            @if (! ($workspace?->removesBranding() ?? false))
                Powered by <a href="{{ config('branding.url', 'https://pitchbar.dev') }}">{{ config('branding.label', 'Pitchbar') }}</a>
            @else
                © {{ date('Y') }} {{ $site_title }}
            @endif
        </footer>
    </div>
</body>
</html>
