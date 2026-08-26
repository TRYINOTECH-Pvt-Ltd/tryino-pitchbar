<p>
    The Translation manager at <code>/admin/translations</code>
    (super-admin only) lets you edit the value of any string in any
    shipped language and fill in missing strings — entirely from the UI,
    with changes live on the next request. No redeploy, no editing JSON
    files.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">Requires the multilingual layer</div>
        The Translation manager is part of the language system, which is
        <strong>off by default</strong>. Set <code>MULTILINGUAL_ENABLED=true</code>
        in your environment to turn on languages (the locale picker + runtime
        switching) and reveal this manager. With it off the site is
        English-only and <code>/admin/translations</code> returns 404.
    </div>
</div>

<h2>How it works — files stay the baseline, the DB wins</h2>

<p>
    The app ships ~2,500 strings across 130+ languages as
    <code>lang/{locale}.json</code> files. Those files remain the
    <strong>canonical set of keys</strong> (the English source string is
    the key) and the shipped defaults. Your edits are stored as
    <strong>overrides</strong> in the <code>translation_overrides</code>
    table and layered on top at runtime — an override wins for its
    <code>(locale, key)</code>. The original files are never modified, so
    a deploy can ship new baseline translations without clobbering your
    customisations, and resetting a string simply removes the override.
</p>

<p>
    Overrides apply everywhere the app reads a string: the admin + customer
    SPA, the visitor widget, and the marketing pages all resolve through
    the same override-aware loader. The bare <code>__()</code> helper
    (validation messages, emails) is override-aware too.
</p>

<h2>Editing a language</h2>

<ol>
    <li>Open <code>/admin/translations</code> — each language shows a
        progress bar (translated / total), a <em>missing</em> count, and
        an <em>edited</em> (override) count. Click a language to open its
        editor.</li>
    <li>Use the <strong>search</strong> box to find a string by its
        English source or current value, and the
        <strong>All / Missing / Edited</strong> tabs to narrow the list.
        <em>Missing</em> = a key with no translation in this language yet;
        <em>Edited</em> = a key you've overridden.</li>
    <li>Type the translation into the field and click <strong>Save</strong>.
        The change is live immediately.</li>
    <li><strong>Reset</strong> (the ↺ button, shown on edited rows)
        removes your override and restores the shipped file value.</li>
</ol>

<h2>Marketing page copy is translatable too</h2>

<p>
    The editor doesn't only cover the app's shipped strings — it also lists
    the editable <strong>marketing copy</strong> (the homepage hero, pricing,
    FAQ, and anything you customised in the marketing content editor). Those
    strings aren't shipped translation keys, so previously they fell back to
    English on the public site (you'd see a mixed-language hero). They now
    appear as normal rows here: translate or override them and the marketing
    page renders them in the visitor's language. URLs, icons, colors, and
    price glyphs are deliberately excluded — only human copy is offered.
</p>

<p>
    <strong>SEO meta too.</strong> The per-page <strong>title</strong> and
    <strong>meta description</strong> (the <code>&lt;title&gt;</code> tag,
    the search-result snippet, and the Open Graph / Twitter title +
    description social cards) are listed here as well, one row per marketing
    page. Translate them and each page's meta renders in the visitor's
    language. The <code>{brand}</code> token is preserved automatically — keep
    it in your translation where you want your site name to appear. The
    social-share image is an asset, not text, so it isn't translated.
</p>

<p>
    <strong>Chat widget chrome too.</strong> The visitor widget's preset
    <strong>starter-prompt chips</strong> and <strong>launcher label</strong>
    (e.g. the SaaS preset's “What does it cost?” and “Ask about the product”)
    are listed here as well. Translate them and the widget renders each in the
    visitor's language — the widget follows the host page's
    <code>&lt;html lang&gt;</code> (or the embed's <code>data-locale</code>),
    so a single agent on a multilingual site speaks each page's language. A
    chip you customised on the agent yourself is left as you wrote it unless
    you add a translation for it here. The widget's fixed labels (Send, Close,
    the handoff banners) are already part of the app's shipped strings above.
</p>

<p>
    <strong>Sign-in pages too.</strong> The login / register / password-reset /
    2FA / email-verification screens — including the decorative side-panel copy
    (“Welcome back to the conversations.”, the feature cards, “Back to site”)
    and each page's heading + subtitle — are translatable here and follow the
    visitor's language like everything else. They render in your active
    marketing theme's auth shell (Harvest, Aurora, or Prism), and all three are
    covered.
</p>

<h2>Notes</h2>

<ul>
    <li>You edit the <em>values</em> for the existing key set; you can't
        invent brand-new keys here, because only keys the app actually
        renders (the English source strings) are ever looked up. New keys
        appear when developers add new English strings to the source.</li>
    <li>English (<code>en</code>) is the source language. You can still
        override English wording if you want to reword the product copy.</li>
    <li>Octane note: the SPA, widget, and marketing surfaces reflect an
        edit on the very next request (a shared-cache version bump
        invalidates every worker). Server-side <code>__()</code> in
        long-lived workers (validation/emails) picks the change up on the
        next worker that loads the locale.</li>
</ul>
