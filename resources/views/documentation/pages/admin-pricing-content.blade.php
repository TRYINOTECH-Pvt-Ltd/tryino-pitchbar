<p>
    Every piece of copy on the public <code>/pricing</code> page
    (besides the plan + add-on data which lives in
    <code>plans</code> / <code>plan_addons</code>) is editable from
    <strong>Settings → System → Pricing page copy</strong>. The form
    sits right under <em>Marketing homepage</em> in
    <code>/settings/system</code> and groups the 28 editable fields
    into five tabs (Hero / Plans &amp; toggle / Lifetime &amp; add-on /
    Comparison &amp; FAQ / CTA &amp; footer). Use it to rewrite the
    hero title, billing toggle labels, lifetime &amp; add-on section
    copy, comparison table heading, FAQ heading, bottom CTA, and the
    Enterprise footer line.
</p>

<p>
    Saved values are deep-merged over the shipped defaults at render
    time, so you can leave the fields you don't want to touch blank —
    only the changed keys are persisted and surfaced to the visitor.
</p>

<h2>What you can edit</h2>

<table>
    <thead>
        <tr><th>Field</th><th>Where it appears</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Hero eyebrow</strong></td>
            <td>The small UPPERCASE pill above the headline ("Pricing" by default).</td>
        </tr>
        <tr>
            <td><strong>Hero title prefix / accent / suffix</strong></td>
            <td>"Simple, <em>conversation-based</em> pricing" — three editable parts so you can rewrite either side of the italic accent.</td>
        </tr>
        <tr>
            <td><strong>Hero subtitle</strong></td>
            <td>The paragraph under the title.</td>
        </tr>
        <tr>
            <td><strong>Billing toggle labels</strong></td>
            <td>"Monthly" / "Yearly" — useful if you sell yearly only and want to relabel as "Annual" / "Monthly".</td>
        </tr>
        <tr>
            <td><strong>Save badge template</strong></td>
            <td>"Save :percent% with yearly" — use <code>:percent</code> as the placeholder.</td>
        </tr>
        <tr>
            <td><strong>Currency label</strong></td>
            <td>The "Currency" label next to the currency dropdown.</td>
        </tr>
        <tr>
            <td><strong>Lifetime section</strong></td>
            <td>Eyebrow + title + subtitle for the LTD section. Only renders when at least one lifetime plan is published.</td>
        </tr>
        <tr>
            <td><strong>Comparison table</strong></td>
            <td>Heading + subheading + the "Feature" column label.</td>
        </tr>
        <tr>
            <td><strong>FAQ heading</strong></td>
            <td>"Frequently asked" by default.</td>
        </tr>
        <tr>
            <td><strong>CTA section</strong></td>
            <td>Bottom black band with title + subtitle + button label + button href. Customise the href to point at /register?trial=14 etc.</td>
        </tr>
        <tr>
            <td><strong>Enterprise footer</strong></td>
            <td>"Need higher limits or a self-hosted license? Talk to us about Enterprise" — both halves editable.</td>
        </tr>
        <tr>
            <td><strong>Add-on section</strong></td>
            <td>Eyebrow + footnote shown under the addon card on the pricing page.</td>
        </tr>
    </tbody>
</table>

<h2>How the values are stored</h2>

<p>
    A single JSON blob on <code>app_settings.pricing_page_content</code>.
    The <code>App\\Support\\MarketingPricingContent</code> resolver
    deep-merges it over the shipped defaults at every render so a
    partial customisation never breaks the page — any key you didn't
    touch falls back to the default.
</p>

<p>
    Translation: every string is still wrapped in the <code>t()</code>
    helper on the React side, so the translation system can localise
    your custom strings via the standard locale JSON files.
</p>

<h2>Marketing homepage: toggle the video walkthrough</h2>

<p>
    The <em>Marketing homepage</em> editor (the form directly above
    <em>Pricing page copy</em> in <strong>Settings → System</strong>) has
    a master switch for the <strong>video walkthrough</strong> section
    ("Watch Pitchbar handle a real buyer question"). On the
    <em>Preview &amp; video</em> tab, untick
    <strong>Show the video walkthrough section</strong> to hide the whole
    block from the landing page. The toggle only hides — your copy
    (title, bullets, labels, video URL) is kept, so re-ticking it later
    restores the section exactly. Stored as
    <code>marketing_home_content.video.enabled</code>; the home page
    renders the section unless this flag is explicitly
    <code>false</code>, so existing installs keep showing it by default.
</p>

<h2>Marketing homepage: show the documentation link</h2>

<p>
    The public Documentation link in the top navigation is
    <strong>off by default</strong> (self-hosted buyers usually don't want
    to expose a docs link on their landing nav). To show it, open the
    <em>Marketing homepage</em> editor → <em>Brand &amp; navigation</em>
    tab and tick <strong>Show the documentation link in the header</strong>
    (next to the <em>Header resources href</em>, which points at
    <code>/documentation</code> by default). Stored as
    <code>marketing_home_content.header.show_documentation</code>; demo
    installs always show the link regardless of the flag.
</p>

<h2>Point the docs links at your own docs site</h2>

<p>
    If you host documentation on your own domain, set
    <strong>External documentation URL</strong> (in the marketing editor's
    header section) to e.g. <code>https://docs.yoursite.com</code>. Every
    built-in <code>/documentation…</code> link — the top-nav Documentation
    link <em>and</em> all footer doc links — is then repointed at that
    domain, preserving the sub-path
    (<code>/documentation/quickstart</code> →
    <code>{base}/quickstart</code>). Leave it blank to keep the built-in
    <code>/documentation</code>. The rewrite happens at render time, so
    clearing the field restores the built-in links — nothing is baked into
    storage. Stored as
    <code>marketing_home_content.header.docs_external_url</code>.
</p>

<p>
    The first nav item is <strong>Home</strong> (it links to
    <code>/</code>); rename or remove any nav item from the same editor.
</p>

<h2>Themes other than the default</h2>

<p>
    The Aurora and Prism marketing themes also consume the same
    <code>content</code> prop and render the editor's customisations
    natively, including the <code>addon_section</code> card. Switch
    themes from <strong>Settings → System → Marketing → Theme</strong>;
    edited pricing copy survives the theme switch.
</p>

<p>
    See also: <a href="/documentation/admin-plans">Plans &amp; Stripe sync</a> for plan-level pricing data and
    <a href="/documentation/admin-addons">One-time add-ons</a> for the add-on catalog.
</p>
