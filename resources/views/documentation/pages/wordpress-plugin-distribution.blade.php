<p>
    Pitchbar ships with two admin-editable knobs that control the
    "Need the plugin?" copy + download CTA rendered at the bottom of
    every workspace's <code>/app/integrations</code> page (in the
    WordPress / WooCommerce card). Use them when you're hosting the
    plugin somewhere other than the default CodeCanyon receipt — a
    WordPress.org listing, an S3 bucket, a corporate file share.
</p>

<h2>Where to set them</h2>

<p>
    Super-admin only. Sign in as a platform admin and visit
    <code>/settings/system#integrations</code> (the "Integrations" tab
    inside System settings — the WordPress plugin block lives there
    alongside the workspace-facing integration toggles). Two fields:
</p>

<ul>
    <li>
        <strong>Download URL</strong> — absolute <code>https://</code>
        link to the <code>.zip</code> (or to your WP.org listing).
        Becomes a "Download plugin" link rendered next to the
        existing "WordPress install &amp; connect" doc link. Validated
        as a URL, max 500 chars.
    </li>
    <li>
        <strong>Help text</strong> — short blurb that replaces the
        default "Need the plugin? Download it from your CodeCanyon
        receipt…" copy. Max 2000 chars. Leave blank to fall back to
        the default.
    </li>
</ul>

<h2>What customers see</h2>

<p>
    On <code>/app/integrations</code> the WordPress card footer reads:
</p>

<pre><code>{{ '{help_text}' }} <a>Download plugin</a> · <a>WordPress install &amp; connect</a></code></pre>

<p>
    When both fields are blank the page falls back to the original
    "Need the plugin? Download it from your CodeCanyon receipt, or
    read the setup guide:" copy. The "Download plugin" anchor is
    omitted entirely so we don't render a dead link.
</p>

<h2>Why customize this</h2>

<ul>
    <li>
        You're hosting the plugin on WordPress.org and want customers
        landing through their wp-admin's plugin search instead of
        CodeCanyon.
    </li>
    <li>
        You're running a Pitchbar fork under your own brand
        ("Replibar", "Whispbar", "Dirty Good") and the CodeCanyon
        reference is out of place.
    </li>
    <li>
        You run a paid SaaS license + a separate plugin distribution
        channel (Gumroad, Lemon Squeezy, your own storefront) and
        want the workspace integrations page to deep-link there.
    </li>
</ul>

<h2>Security notes</h2>

<ul>
    <li>
        The "Download URL" is rendered as a plain
        <code>&lt;a href&gt;</code> with <code>target="_blank"</code>
        + <code>rel="noopener noreferrer"</code>. Validation rejects
        non-URL strings, but the link still goes wherever you point
        it — only super-admins can change this field.
    </li>
    <li>
        Help text is rendered as plain text (no HTML escaping bypass).
        Putting markdown or HTML in there won't render — it shows as
        literal characters.
    </li>
</ul>

<h2>Related</h2>

<ul>
    <li>Visitor-facing install steps: <a href="/documentation/wordpress-install">WordPress install &amp; connect</a></li>
    <li>WooCommerce-specific behaviour: <a href="/documentation/wordpress-woocommerce">WooCommerce deep links</a></li>
</ul>
