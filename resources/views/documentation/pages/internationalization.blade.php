<p>
    Pitchbar ships translations for 130+ languages out of the box,
    with English, Spanish, French, and Turkish covered end-to-end and
    the long tail (German, Hindi, Bengali, Arabic, Hebrew, Chinese,
    Japanese, Korean, Vietnamese, every popular European/Asian/African
    language, plus RTL scripts) covered for UI chrome — buttons,
    navigation, forms, status pills. Every key not yet translated for
    a given locale falls back to the English source automatically, so
    the UI never breaks while individual translators contribute the
    long tail.
</p>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Off by default — enable with one env var</div>
        The whole language layer is gated behind
        <code>MULTILINGUAL_ENABLED</code> (default <code>false</code>). While
        it's off the site is <strong>English-only</strong>: the locale picker
        is hidden, <code>?locale</code> / saved locale / <code>Accept-Language</code>
        are ignored (every request resolves to the app locale), the
        geo-suggestion banner never shows, and the
        <a href="/documentation/admin-translations">Translation manager</a> is
        hidden (404). Set <code>MULTILINGUAL_ENABLED=true</code> to turn
        everything below on. The toggle is environment-only by design — flip it
        per deployment, no UI switch.
    </div>
</div>

<h2>Adding a new language is just dropping a file</h2>

<p>
    The <code>LocaleResolver::supported()</code> service auto-discovers
    locales by scanning <code>lang/*.json</code> at request time. To
    add a new language, drop a <code>lang/&lt;code&gt;.json</code>
    file (e.g. <code>lang/sv.json</code> for Swedish) — no code change,
    no migration, no service restart. The next page load picks it up,
    the picker modal lists it, the SetLocale middleware accepts
    <code>?locale=sv</code>, and it shows up in every API response that
    enumerates supported locales.
</p>

<p>
    The picker pulls metadata (native name, English name, flag emoji,
    RTL flag) from <code>App\Services\I18n\LocaleCatalog::ENTRIES</code>
    — a curated list of 130+ popular languages. If you ship a JSON
    file for a code that isn't in the catalog, it still works — the
    picker falls back to the code itself, a 🌐 globe emoji, and LTR
    direction. Contributing the catalog metadata just upgrades the
    visual.
</p>

<h2>Right-to-left languages</h2>

<p>
    The catalog flags Arabic, Hebrew, Persian, Urdu, Pashto, Sindhi,
    Dhivehi, Yiddish, and Uyghur as RTL. The rendering pipeline mirrors
    accordingly:
</p>

<ul>
    <li>The Blade root templates (<code>resources/views/app.blade.php</code>
        for the admin SPA, <code>resources/views/marketing/_layout.blade.php</code>
        for the marketing site, <code>resources/views/emails/leads/captured.blade.php</code>
        for the lead-captured email) emit <code>dir="rtl"</code> on
        <code>&lt;html&gt;</code> when the active locale's catalog entry
        has <code>rtl =&gt; true</code>.</li>
    <li>Tailwind v4 logical properties carry the layout: every
        <code>ms-/me-/ps-/pe-/start-/end-/text-start/text-end</code>
        utility class flips automatically based on the document
        direction. The codebase uses logical properties exclusively;
        physical <code>ml-/mr-/pl-/pr-/left-/right-</code> classes were
        swept out by an earlier codemod.</li>
    <li>A Radix <code>&lt;DirectionProvider&gt;</code> wraps the React
        app at <code>resources/js/app.tsx</code>, so every Radix
        primitive (DropdownMenu, Popover, Tooltip, Select, Sheet,
        ContextMenu) gets correct alignment + animation direction
        without per-component code.</li>
    <li>The <code>useIsRtl()</code> / <code>useDirection()</code> hooks
        in <code>resources/js/lib/direction.ts</code> read the current
        locale's RTL flag from the shared i18n catalog. Use them when a
        component needs explicit JS direction logic.</li>
    <li>Directional icons (back, forward, chevrons) wrap with
        <code>&lt;DirArrow direction="forward|back" /&gt;</code> from
        <code>resources/js/components/dir-icon.tsx</code> so the icon
        itself flips. Icons whose direction is decorative (paper-plane
        send, undo) use the <code>.flip-rtl</code> CSS utility instead
        of swapping glyphs.</li>
    <li>The visitor widget reads <code>init.agent.locale</code> on boot
        and sets <code>dir="rtl"</code> on its shadow root if the locale
        is RTL — every Tailwind logical-property class inside the
        widget then flips like the admin SPA.</li>
    <li>The <code>&lt;Sidebar&gt;</code> component defaults its
        <code>side</code> prop to the visual start edge based on
        direction (<code>side="left"</code> in LTR,
        <code>side="right"</code> in RTL), so a vanilla
        <code>&lt;Sidebar /&gt;</code> always pins to the visual start.</li>
</ul>

<p>
    The <code>tests/Feature/I18n/RtlDirTest.php</code> regression
    asserts that every RTL locale produces <code>dir="rtl"</code> on
    the admin Inertia root, the marketing layout, and the lead-captured
    email; LTR locales conversely produce <code>dir="ltr"</code>.
</p>

<h2>Locale picker modal</h2>

<p>
    Both the admin shell and the marketing site open a searchable
    Dialog when you click the language pill. The list shows native
    name + English name + flag for every locale, filterable by code or
    name. Same component on both surfaces. With 130+ entries, the old
    dropdown was unscrollable — the modal scales to thousands of
    languages.
</p>

<h2>Resolution order</h2>

<p>
    The <code>SetLocale</code> middleware runs after the session middleware
    on every web request and walks this priority list:
</p>

<ol>
    <li>Explicit <code>?locale=&lt;slug&gt;</code> query (allow-listed).</li>
    <li>The authenticated user's <code>users.locale</code> column.</li>
    <li><code>pb_locale</code> cookie (set by the geo-banner switch
        path, persists across sessions for unauth visitors).</li>
    <li>The browser's <code>Accept-Language</code> header (highest q-value
        wins).</li>
    <li>The application default from <code>config/app.php</code>.</li>
</ol>

<h2>Geo-suggested locale banner</h2>

<p>
    When Cloudflare's <code>CF-IPCountry</code> header maps to a
    locale we ship and the visitor's current locale is something
    else, a slim banner appears asking <strong>"Switch to Español?"</strong>
    The banner never auto-switches — surprise = bad UX. Two actions:
</p>

<ul>
    <li><strong>Switch to &lt;language&gt;</strong> — PATCHes
        <code>/locale/switch</code>. Sets <code>users.locale</code>
        when signed in and the <code>pb_locale</code> cookie always
        (1-year lifetime), then reloads in the new language.</li>
    <li><strong>✕</strong> — POSTs
        <code>/locale/dismiss-suggestion</code>, sets
        <code>pb_locale_dismiss=1</code> cookie (180-day lifetime).
        The banner never appears again on that device.</li>
</ul>

<p>
    Suppression rules (server-side, in
    <code>LocaleResolver::suggestionFor</code>):
</p>

<ol>
    <li>Visitor already dismissed → null.</li>
    <li>Current locale already matches the suggestion → null.</li>
    <li>No <code>CF-IPCountry</code> header (local dev, non-CF
        deploys) or value is <code>XX</code>/<code>T1</code> → null.</li>
    <li>Country isn't in <code>COUNTRY_TO_LOCALE</code> → null.</li>
</ol>

<p>
    Country → locale map covers Spanish-speaking Latin America +
    Spain (es), French Europe + Quebec (fr), Turkey (tr). Other
    countries get no suggestion.
</p>

<p>
    Banner mounts on the admin SPA (<code>app-sidebar-layout</code>),
    on every Inertia marketing page (<code>marketing-shell</code>),
    and via Blade <code>@verbatim{{ __('Switch to :language?') }}@endverbatim</code>
    in <code>resources/views/marketing/_layout.blade.php</code> for
    any future Blade-rendered marketing pages.
</p>

<p>
    The same <code>LocaleResolver</code> service powers the widget. The
    widget pass adds one extra step at the top: the agent's
    <code>language_default</code> — admins can pin a vertical-specific
    language even if the visitor's browser disagrees. The picker on the
    agent form currently offers English, Dutch, Spanish, French,
    German, Portuguese, Japanese, Arabic, and Chinese; the LLM answers
    in the pinned language and the widget chrome loads the matching
    <code>lang/{locale}.json</code> copy.
</p>

<h2>Marketing site copy</h2>

<p>
    Marketing pages are operator-editable <em>content</em> (the JSON in
    Settings → System → Marketing content), not i18n keys — so they're
    localised at render time by <code>MarketingTranslator</code>:
    every string in the resolved payload is looked up in
    <code>lang/{locale}.json</code> and rendered translated when an
    entry exists. The shipped English defaults are all present in the
    Dutch dictionary; any copy you customise in the editor simply has
    no dictionary entry and renders verbatim in every locale. To
    localise custom copy, add it as a key/translation pair to the
    locale's JSON file. The admin content editor always shows the
    canonical English source.
</p>

<h2>Where the strings live</h2>

<ul>
    <li><code>lang/{locale}.json</code> — the JSON dictionary used by the
        admin React SPA, the widget, the marketing Blade pages, and the
        mail templates. English source strings act as the keys.</li>
    <li><code>lang/{locale}/auth.php</code>, <code>validation.php</code>,
        <code>passwords.php</code>, <code>pagination.php</code> —
        Laravel's namespaced PHP files for built-in framework messages.</li>
    <li><code>lang/_glossary.md</code> — terminology lock so future strings
        translate consistently with prior runs.</li>
</ul>

<h2>Where to add translations</h2>

<p>
    Whenever you add a user-facing string in code:
</p>

<ul>
    <li><strong>Backend Blade:</strong> wrap with
        <code>{{ __('English source') }}</code>.</li>
    <li><strong>Admin React:</strong> import the hook and call
        <code>const &#123; t &#125; = useT();</code>, then
        <code>t('English source')</code>.</li>
    <li><strong>Widget:</strong> import <code>t</code> from
        <code>core/i18n.ts</code> and call <code>t('English source')</code>.
        Add the key to <code>WidgetCopy::KEYS</code> so the server
        materialises it into the <code>/init</code> payload.</li>
</ul>

<p>
    Translated keys are added to <code>lang/&lt;locale&gt;.json</code>.
    Missing keys silently fall back to the English source — the UI
    never breaks. The <code>tests/Feature/I18nTest::every supported
    locale ships a parseable JSON dictionary</code> regression
    asserts every shipped locale file is valid JSON with non-empty
    string values; a sister test prevents typo-introduced keys (any
    key in a locale file that is absent from <code>en.json</code>
    fails CI).
</p>

<h2>Adding a new locale</h2>

<ol>
    <li>Drop a <code>lang/&lt;slug&gt;.json</code> file. That alone
        makes the locale appear in the picker and accept
        <code>?locale=&lt;slug&gt;</code> via the SetLocale
        middleware. <code>LocaleResolver::supported()</code>
        auto-discovers the file on the next request.</li>
    <li>(Optional, but recommended) Add an entry in
        <code>app/Services/I18n/LocaleCatalog::ENTRIES</code> with
        the locale's <code>native</code> name, <code>english</code>
        name, <code>flag</code> emoji, and <code>rtl</code> flag.
        Without an entry, the picker still works — it just shows
        the locale code as the label and a 🌐 globe.</li>
    <li>(Optional) Copy
        <code>lang/en/&#123;auth,validation,passwords,pagination&#125;.php</code>
        into <code>lang/&lt;slug&gt;/</code> if you want Fortify /
        validation messages translated. Without these files,
        Laravel falls through to English.</li>
    <li>Run <code>php artisan test --filter=I18nTest</code> and
        <code>--filter=LocaleResolverTest</code> to confirm the
        new locale doesn't introduce phantom keys.</li>
    <li>(Optional) Update <code>lang/_glossary.md</code> with a
        column so future translations stay coherent.</li>
</ol>

<p>
    There is no code change required to enable a locale. The previous
    <code>LocaleResolver::SUPPORTED</code> constant has been removed;
    the picker, validation rules, and middleware all read from
    <code>LocaleResolver::supported()</code> which returns the
    auto-discovered set.
</p>

<h2>Per-user vs per-visitor locale</h2>

<p>
    <strong>Admins and operators</strong> set their preferred language at
    <code>/settings/locale</code>. The choice is persisted to
    <code>users.locale</code> and applies on every subsequent request,
    including emails sent on their behalf.
</p>

<p>
    <strong>Visitors</strong> see the agent's
    <code>language_default</code> by default. If the agent has no
    language pinned, the widget falls back to the visitor's browser
    locale, then to English. There is no in-widget locale switcher —
    that's a deliberate decision so the visitor experience matches what
    the admin configured.
</p>

<h2>Coverage</h2>

<p>
    Every customer-facing surface — visitor widget, marketing site
    (home, pricing, how-it-works, integrations, changelog, privacy,
    terms), the admin SPA (every customer-admin and platform-admin
    page including agent customize, sources, curated answers,
    knowledge, playground, behavior, CTAs, leads, conversations,
    experiments, billing, integrations, analytics, workflows, every
    settings tab), the auth + onboarding flows, transactional emails,
    and validation messages — is wired through <code>useT()</code>
    or <code>__()</code>. The English source (<code>lang/en.json</code>)
    is the source of truth and currently holds about 2,300 keys.
</p>

<p>
    Per-locale coverage varies. <code>en</code>,
    <code>es</code>, <code>fr</code>, and <code>tr</code> are
    fully translated end-to-end (every key in <code>en.json</code>
    has a localised value). The other 130-ish auto-discovered
    locale files start with the most-common UI chrome translated
    (~130 keys: buttons, navigation, forms, status pills) and
    expand from there as translators contribute. Keys not yet
    translated for a given locale fall back to the English source
    via Laravel's standard JSON-key behaviour, so the UI never
    breaks; users see partial translation while the long tail
    fills in.
</p>

<p>
    To check coverage for a locale at any time:
</p>

<pre><code>node -e 'const fs=require("fs"); const en=JSON.parse(fs.readFileSync("lang/en.json")); const m=JSON.parse(fs.readFileSync("lang/&lt;code&gt;.json")); const total=Object.keys(en).length; const translated=Object.keys(en).filter(k=>k in m && m[k]!==en[k]).length; console.log(`${translated}/${total} = ${Math.round(translated/total*100)}% covered`);'</code></pre>

<p>
    The documentation pages under <code>resources/views/documentation/pages/</code>
    stay in English by policy — translating dense technical writeups
    is a copywriting project, not engineering. Track follow-up on the
    Kanban board if a specific deal needs translated docs.
</p>

<h2>Browser SEO + accessibility</h2>

<ul>
    <li>The <code>&lt;html lang&gt;</code> attribute on the marketing
        layout, the admin Inertia root, and transactional emails reflects
        the resolved locale on every render.</li>
    <li>Validation messages, password-reset emails, and Fortify auth
        messages all flow through Laravel's translator — they pick up
        the user's locale automatically.</li>
    <li>The widget's first paint already speaks the right language —
        the server materialises <code>agent.copy</code> at <code>/init</code>
        time, so there is never a moment of English flash before the
        translated copy hydrates.</li>
</ul>

<h2>Right-to-left (RTL) languages</h2>

<p>
    When an agent's resolved locale is a right-to-left language —
    Arabic (<code>ar</code>), Hebrew (<code>he</code>), Persian
    (<code>fa</code>), Urdu (<code>ur</code>), and the rest of the RTL
    set — the widget mirrors itself automatically. No admin toggle: the
    direction follows the agent's <code>language_default</code> (or the
    visitor's <code>Accept-Language</code> when no default is pinned).
</p>

<ul>
    <li>On <code>/init</code> the widget sets <code>dir="rtl"</code> on
        its shadow-root container, so every child element inherits the
        writing direction without per-component code.</li>
    <li>Message bubbles, the composer, starter prompts, the lead form,
        and inline product/pricing blocks all mirror — text aligns to
        the inline start and the visitor's bubbles flip to the opposite
        edge from the assistant's.</li>
    <li>The launcher's on-screen position stays where the operator put
        it (bottom-left / bottom-right / centred) — placement is a
        layout choice, not a function of reading direction.</li>
</ul>
