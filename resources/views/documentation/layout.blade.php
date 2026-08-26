<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>{{ $pageTitle }} · {{ config('branding.site_title', 'Pitchbar') }} docs</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=geist:400,500,600,700|geist-mono:400,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ url('/documentation-assets/style.css?v=2') }}">
</head>
<body>
    <header class="docs-topbar">
        <div class="docs-topbar-inner">
            <a href="/documentation" class="docs-brand">
                <span class="docs-brand-mark">{{ substr(config('branding.site_title', 'Pitchbar'), 0, 1) }}</span>
                <span class="docs-brand-text">{{ config('branding.site_title', 'Pitchbar') }} <span class="docs-brand-sub">docs</span></span>
            </a>

            <div class="docs-search">
                <input type="search" id="docs-search-input" placeholder="Search docs…" autocomplete="off" aria-label="Search documentation">
                <kbd>/</kbd>
            </div>

            <nav class="docs-topbar-links" aria-label="Top navigation">
                <a href="/" class="docs-topbar-link">Home</a>
                <a href="/dashboard" class="docs-topbar-link">Dashboard</a>
                <button type="button" id="docs-theme-toggle" class="docs-theme-toggle" aria-label="Toggle theme">
                    <svg class="docs-theme-icon docs-theme-icon-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                    <svg class="docs-theme-icon docs-theme-icon-dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
                <button type="button" id="docs-mobile-toggle" class="docs-mobile-toggle" aria-label="Toggle menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                </button>
            </nav>
        </div>
    </header>

    <div class="docs-shell">
        <aside class="docs-sidebar" id="docs-sidebar" aria-label="Sidebar navigation">
            <nav class="docs-nav">
                @foreach ($tree as $group)
                    <div class="docs-nav-group">
                        <h4 class="docs-nav-group-title">{{ $group['title'] }}</h4>
                        <ul class="docs-nav-list">
                            @foreach ($group['items'] as $item)
                                <li>
                                    <a
                                        href="/documentation/{{ $item['slug'] }}"
                                        data-search-title="{{ $item['title'] }}"
                                        data-search-group="{{ $group['title'] }}"
                                        class="docs-nav-link {{ $slug === $item['slug'] ? 'is-active' : '' }}"
                                    >
                                        {{ $item['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        <main class="docs-main">
            <article class="docs-article">
                <p class="docs-eyebrow">{{ $pageGroup }}</p>
                <h1 class="docs-page-title">{{ $pageTitle }}</h1>
                <div class="docs-content">
                    @include($partial)
                </div>

                <nav class="docs-page-nav" aria-label="Page navigation">
                    @if ($neighbors['prev'])
                        <a href="/documentation/{{ $neighbors['prev']['slug'] }}" class="docs-page-prev">
                            <span class="docs-page-nav-label">Previous</span>
                            <span class="docs-page-nav-title">← {{ $neighbors['prev']['title'] }}</span>
                        </a>
                    @else
                        <span></span>
                    @endif

                    @if ($neighbors['next'])
                        <a href="/documentation/{{ $neighbors['next']['slug'] }}" class="docs-page-next">
                            <span class="docs-page-nav-label">Next</span>
                            <span class="docs-page-nav-title">{{ $neighbors['next']['title'] }} →</span>
                        </a>
                    @endif
                </nav>
            </article>

            <aside class="docs-toc" aria-label="On this page">
                <p class="docs-toc-title">On this page</p>
                <ul id="docs-toc-list" class="docs-toc-list"></ul>
            </aside>
        </main>
    </div>

    <script src="{{ url('/documentation-assets/script.js?v=2') }}" defer></script>
</body>
</html>
