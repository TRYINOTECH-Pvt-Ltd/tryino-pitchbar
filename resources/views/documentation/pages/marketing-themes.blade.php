<h1>Pluggable marketing themes</h1>

<p class="lede">
    The whole marketing site — home, pricing, how-it-works, integrations,
    privacy, terms, changelog — is a swappable theme. Operators add a new
    theme by dropping a folder; switching is one click in
    <strong>Settings &rarr; System &rarr; Marketing</strong>.
</p>

<h2>How it works</h2>

<p>
    Every marketing controller looks up its Inertia component through
    <code>App\Support\MarketingTheme::component($page)</code> instead of
    hardcoding a string. The resolver reads
    <code>app_settings.marketing_theme</code>, falls back to
    <code>harvest</code> (the built-in theme) when the column is empty or
    points at a slug that isn't installed, and returns either:
</p>

<ul>
    <li>The legacy component path for <code>harvest</code>
        (<code>welcome</code>, <code>marketing/pricing</code>,
        <code>marketing/how-it-works</code>, etc.) — so existing installs
        keep rendering the original layout.</li>
    <li><code>marketing-themes/{slug}/{page}</code> for any other theme —
        points Inertia at a self-contained per-theme folder.</li>
</ul>

<h2>Add a new theme</h2>

<ol>
    <li>
        <p>Create a folder under
        <code>resources/js/pages/marketing-themes/</code> named after the
        theme's slug (kebab-case, no spaces). For example,
        <code>resources/js/pages/marketing-themes/meadow/</code>.</p>
    </li>
    <li>
        <p>Add a <code>theme.json</code> manifest at the root of that
        folder. Minimum shape:</p>
        <pre><code>{
    "name": "Meadow",
    "description": "Calm, editorial layout with green accents."
}</code></pre>
        <p>Themes without a <code>theme.json</code> are ignored — the
        manifest is what makes a folder a theme.</p>
        <p>If a theme only ships a subset of the seven pages, add a
        <code>pages</code> whitelist so the resolver knows which keys
        the theme provides — every other page silently falls back to
        the Harvest legacy component:</p>
        <pre><code>{
    "name": "Aurora",
    "description": "Editorial brutalist — home page only.",
    "pages": ["home"]
}</code></pre>
        <p>Omitting <code>pages</code> means the theme is assumed to
        provide all seven; useful when you're shipping a full bundle.</p>
    </li>
    <li>
        <p>Drop the seven page components inside the folder, each
        receiving the same props the matching controller passes today.
        Names must match exactly:</p>
        <ul>
            <li><code>home.tsx</code></li>
            <li><code>pricing.tsx</code></li>
            <li><code>how-it-works.tsx</code></li>
            <li><code>integrations.tsx</code></li>
            <li><code>privacy.tsx</code></li>
            <li><code>terms.tsx</code></li>
            <li><code>changelog.tsx</code></li>
        </ul>
        <p>Use <code>resources/js/layouts/marketing-shell.tsx</code> as
        the shared shell, or ship your own per-theme shell inside the
        folder.</p>
    </li>
    <li>
        <p>Run <code>npm run build</code> (or keep <code>npm run dev</code>
        running while you iterate) so Vite picks up the new files.</p>
    </li>
    <li>
        <p>Open <strong>Settings &rarr; System &rarr; Marketing</strong>,
        pick the new theme from the dropdown, and save. Visit
        <code>/</code>, <code>/pricing</code>, etc. — they now render
        from your folder.</p>
    </li>
</ol>

<h2>Props your theme components receive</h2>

<p>
    The controllers pass the same props regardless of theme. Build your
    page components against this shape and a theme swap is a pure visual
    change:
</p>

<table>
    <thead>
        <tr><th>Page</th><th>Notable props</th></tr>
    </thead>
    <tbody>
        <tr><td><code>home</code></td><td><code>canRegister</code>, <code>demoAgentId</code>, <code>content</code>, <code>seo</code></td></tr>
        <tr><td><code>pricing</code></td><td><code>plans</code>, <code>lifetime_plans</code>, <code>currency</code>, <code>currencies</code>, <code>matrix</code>, <code>faqs</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
        <tr><td><code>how-it-works</code></td><td><code>steps</code>, <code>latency</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
        <tr><td><code>integrations</code></td><td><code>native</code>, <code>data_sources</code>, <code>roadmap</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
        <tr><td><code>privacy</code></td><td><code>content</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
        <tr><td><code>terms</code></td><td><code>intro</code>, <code>sections</code>, <code>effective_date</code>, <code>contact_email</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
        <tr><td><code>changelog</code></td><td><code>entries</code>, <code>shell</code>, <code>brand</code>, <code>seo</code></td></tr>
    </tbody>
</table>

<h2>Built-in: the Harvest theme</h2>

<p>
    The shipped theme is called <code>harvest</code>. For back-compat its
    files live where they always did
    (<code>resources/js/pages/welcome.tsx</code> +
    <code>resources/js/pages/marketing/*.tsx</code>) rather than under
    <code>marketing-themes/harvest/</code>. The resolver maps to those
    legacy paths so existing installs upgrade with no rendering
    difference.
</p>

<h2>Shipped extra themes</h2>

<p>
    Two additional themes ship in the box. Both are full bundles
    covering all seven pages and use the live demo agent for the hero
    chat preview.
</p>

<ul>
    <li>
        <strong>Aurora</strong> (slug <code>aurora</code>) — editorial
        brutalist with a paper/ink palette and an electric-lime signal
        accent. Lives at
        <code>resources/js/pages/marketing-themes/aurora/</code>.
        Ships <code>auth-shell.tsx</code> so login, register, and
        password-reset flows render in the same paper/ink/lime palette
        as the marketing site.
    </li>
    <li>
        <strong>Prism</strong> (slug <code>prism</code>) — purple/coral
        gradient identity, Inter Tight body with Instrument Serif
        italic accents, glossy hero mockup with floating context cards,
        gradient-bar footer. Lives at
        <code>resources/js/pages/marketing-themes/prism/</code>. Also
        ships <code>auth-shell.tsx</code> for theme-matched sign-in.
    </li>
</ul>

<h3>Prism try-now demo (anonymous URL ingest)</h3>

<p>
    Prism's hero ships an interactive <em>Try it</em> form. A visitor
    pastes a URL, the server fetches the page synchronously
    (<code>POST /api/v1/widget/try-now</code>), extracts readable text
    via <code>HtmlExtractor</code>, chunks it, and stashes the chunks
    under a short-lived cache token (1h TTL). The hero chat then
    switches to that cached context — every visitor message routes
    through <code>POST /api/v1/widget/try-now/stream</code>, which
    streams an LLM reply grounded in the cached chunks via
    <code>&lt;source&gt;</code> tags.
</p>

<p>
    Lives at <code>app/Services/TryNow/TryNowSession.php</code> and
    <code>app/Http/Controllers/Widget/TryNowController.php</code>. No
    agent, no workspace, no DB writes — it can't pollute tenant data.
    Rate-limited per IP via the <code>try-now-start</code> and
    <code>try-now-stream</code> limiters defined in
    <code>AppServiceProvider::configureRateLimiting</code>.
</p>

<h3>Theme-matched auth shells</h3>

<p>
    Both Aurora and Prism ship an <code>auth-shell.tsx</code> alongside
    their seven marketing pages. The dispatcher at
    <code>resources/js/layouts/auth-layout.tsx</code> picks the right
    shell based on <code>marketingTheme</code> (the shared Inertia
    prop). When the active theme doesn't ship a shell, the dispatcher
    falls back to the default Harvest two-panel layout. To add a new
    theme's auth shell:
</p>

<ol>
    <li>Create <code>resources/js/pages/marketing-themes/&lt;slug&gt;/auth-shell.tsx</code> exporting a component with <code>{ title, description, children }</code> props.</li>
    <li>Add a branch in <code>auth-layout.tsx</code>: <code>if (marketingTheme === '&lt;slug&gt;')</code>.</li>
    <li>Add a Pest test under <code>tests/Feature/Marketing/</code> that hits <code>/login</code> and <code>/register</code> with the theme active.</li>
</ol>

<p>
    Flip between them under <strong>Settings &rarr; System &rarr;
    Marketing</strong>, or via tinker:
</p>

<pre><code>php artisan tinker --execute 'App\Models\AppSetting::singleton()->forceFill(["marketing_theme" => "prism"])->save();'</code></pre>

<h2>Resetting if a theme breaks</h2>

<p>
    If a theme's folder is deleted, its manifest becomes invalid, or the
    slug stored in <code>app_settings.marketing_theme</code> doesn't match
    any installed theme, the resolver silently falls back to
    <code>harvest</code>. The marketing site can't be blanked by a stale
    setting. To reset explicitly, run:
</p>

<pre><code>php artisan tinker --execute 'App\Models\AppSetting::singleton()->forceFill(["marketing_theme" => "harvest"])->save();'</code></pre>
