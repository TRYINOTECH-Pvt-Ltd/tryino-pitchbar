// Pitchbar documentation runtime. Self-contained vanilla JS.
//   - Theme toggle (auto / light / dark) persisted in localStorage
//   - Mobile sidebar
//   - Sidebar search filter (titles + groups)
//   - "/" focuses search
//   - Per-pre "Copy" button
//   - Right-side TOC built from h2/h3 in the article + scroll-spy

(function () {
    'use strict';

    // ── theme ────────────────────────────────────────────────────
    const THEME_KEY = 'pitchbar-docs-theme';
    const html = document.documentElement;
    const stored = localStorage.getItem(THEME_KEY);
    if (stored === 'light' || stored === 'dark') html.dataset.theme = stored;

    const themeBtn = document.getElementById('docs-theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            const order = ['auto', 'light', 'dark'];
            const next = order[(order.indexOf(html.dataset.theme || 'auto') + 1) % 3];
            html.dataset.theme = next;
            if (next === 'auto') localStorage.removeItem(THEME_KEY);
            else localStorage.setItem(THEME_KEY, next);
        });
    }

    // ── mobile sidebar ──────────────────────────────────────────
    const sidebar = document.getElementById('docs-sidebar');
    const mobileBtn = document.getElementById('docs-mobile-toggle');
    if (mobileBtn && sidebar) {
        mobileBtn.addEventListener('click', function () {
            sidebar.classList.toggle('is-open');
        });
        sidebar.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () {
                sidebar.classList.remove('is-open');
            });
        });
    }

    // ── sidebar search ─────────────────────────────────────────
    const searchInput = document.getElementById('docs-search-input');
    const navLinks = document.querySelectorAll('.docs-nav-link');

    function filter(query) {
        const q = query.trim().toLowerCase();
        const groups = document.querySelectorAll('.docs-nav-group');

        groups.forEach(function (group) {
            let any = false;
            group.querySelectorAll('.docs-nav-link').forEach(function (a) {
                const title = (a.dataset.searchTitle || '').toLowerCase();
                const grp = (a.dataset.searchGroup || '').toLowerCase();
                const match = q === '' || title.indexOf(q) !== -1 || grp.indexOf(q) !== -1;
                a.parentElement.classList.toggle('is-search-hidden', !match);
                if (match) any = true;
            });
            group.style.display = any ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function (e) {
            filter(e.target.value);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.blur();
                searchInput.value = '';
                filter('');
            }
        });
    }

    // ── copy buttons on <pre> ──────────────────────────────────
    document.querySelectorAll('.docs-content pre').forEach(function (pre) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'docs-copy';
        btn.textContent = 'Copy';
        btn.addEventListener('click', function () {
            const code = pre.querySelector('code') || pre;
            const text = code.textContent || '';
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    btn.textContent = 'Copy';
                    btn.classList.remove('is-copied');
                }, 1500);
            });
        });
        pre.appendChild(btn);
    });

    // ── on-page TOC ────────────────────────────────────────────
    const tocList = document.getElementById('docs-toc-list');
    const headings = document.querySelectorAll('.docs-content h2, .docs-content h3');
    const tocAnchors = [];

    if (tocList && headings.length) {
        headings.forEach(function (h, i) {
            if (!h.id) {
                h.id = (h.textContent || '')
                    .trim()
                    .toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-') || 'section-' + i;
            }
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = '#' + h.id;
            a.textContent = h.textContent || '';
            if (h.tagName === 'H3') a.classList.add('is-h3');
            li.appendChild(a);
            tocList.appendChild(li);
            tocAnchors.push({ a: a, target: h });
        });

        // Scroll-spy: highlight whichever heading is closest to the top
        // (within ~120px). Throttled with rAF to stay cheap.
        let raf = null;
        function spy() {
            raf = null;
            const offset = 120;
            let activeIdx = 0;
            for (let i = 0; i < tocAnchors.length; i++) {
                const top = tocAnchors[i].target.getBoundingClientRect().top;
                if (top - offset <= 0) activeIdx = i;
                else break;
            }
            tocAnchors.forEach(function (entry, i) {
                entry.a.classList.toggle('is-active', i === activeIdx);
            });
        }
        window.addEventListener('scroll', function () {
            if (!raf) raf = requestAnimationFrame(spy);
        }, { passive: true });
        spy();
    }
})();
