<p>
    A huge portion of real-world WordPress sites use a page builder
    instead of vanilla Gutenberg. The Pitchbar plugin renders each
    supported builder's pages with the builder's own native API
    before sending HTML to your Pitchbar agent, so the synced content
    is what the visitor actually sees.
</p>

<p>
    This page explains how detection + rendering works, which
    builders are supported, and how to override the behaviour with a
    filter.
</p>

<h2>Why this matters</h2>

<p>
    Most page builders don't store the rendered page in
    <code>post_content</code>. They keep the layout tree in
    <code>postmeta</code> and recompose the HTML at render time. If
    your sync pipeline naively reads <code>post_content</code> and
    calls <code>apply_filters('the_content', …)</code>, you'll get:
</p>

<ul>
    <li><strong>Elementor</strong>: empty string. The layout lives entirely in <code>_elementor_data</code> postmeta.</li>
    <li><strong>Bricks</strong>: empty. Layout in <code>_bricks_page_content_2</code>.</li>
    <li><strong>Oxygen</strong>: a single <code>[oxygen_html]</code> shortcode stub. The real layout is in <code>ct_builder_shortcodes</code> postmeta.</li>
    <li><strong>Beaver Builder</strong>: a stripped-down "fallback" HTML if the builder never gets a chance to override.</li>
    <li><strong>Divi</strong>: the shortcode-encoded layout in <code>post_content</code>. It only renders correctly when the post loop is properly primed.</li>
</ul>

<p>
    The plugin's <code>PageBuilderContent</code> helper handles each
    case explicitly. First match wins; a post that isn't owned by any
    detected builder falls through to the vanilla
    <code>the_content</code> path.
</p>

<h2>Supported builders</h2>

<table>
    <thead>
        <tr>
            <th>Builder</th>
            <th>Detection postmeta</th>
            <th>Renderer</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Elementor (Free + Pro)</td>
            <td><code>_elementor_edit_mode</code> = <code>builder</code></td>
            <td><code>\Elementor\Plugin::$instance-&gt;frontend-&gt;get_builder_content_for_display($id, true)</code></td>
        </tr>
        <tr>
            <td>Beaver Builder</td>
            <td><code>_fl_builder_enabled</code> = <code>1</code></td>
            <td><code>FLBuilder::render_content_by_id($id)</code></td>
        </tr>
        <tr>
            <td>Oxygen Builder</td>
            <td><code>ct_builder_shortcodes</code> (non-empty)</td>
            <td><code>do_shortcode($shortcodes)</code></td>
        </tr>
        <tr>
            <td>Bricks Builder</td>
            <td><code>_bricks_page_content_2</code> (non-empty) AND <code>BRICKS_VERSION</code> defined</td>
            <td><code>\Bricks\Frontend::render_content($payload)</code></td>
        </tr>
        <tr>
            <td>Divi</td>
            <td><code>_et_pb_use_builder</code> = <code>on</code></td>
            <td>Routes through <code>the_content</code> filter (with <code>setup_postdata</code> primed)</td>
        </tr>
    </tbody>
</table>

<p>
    Every renderer is wrapped in <code>try/catch (Throwable)</code>.
    A throwing builder renderer falls through silently to the next
    detection or to the vanilla path, so an upgraded builder version
    that changes its internal API can never fatal-out the sync.
</p>

<h2>Why Divi is different</h2>

<p>
    Divi <em>does</em> store its layout in <code>post_content</code> —
    as shortcode-encoded markup like
    <code>[et_pb_section][et_pb_row][et_pb_column][et_pb_text]…</code>.
    The shortcodes resolve correctly through the WordPress filter
    chain <em>only when the post loop is primed</em>: <code>$GLOBALS['post']</code>
    set and <code>setup_postdata()</code> called.
</p>

<p>
    Plenty of third-party plugins (Yoast, Jetpack, embed handlers)
    also gate their <code>the_content</code> callbacks on a valid
    <code>$post</code> global. Without postdata priming, every one of
    them early-returns and the synced HTML is missing the chrome that
    makes the page actually useful.
</p>

<p>
    The fix shipped in plugin v2.0.0:
    <code>PostContentExtractor</code> always primes
    <code>$GLOBALS['post']</code> + calls <code>setup_postdata()</code>
    around the filter chain, wraps the work in
    <code>try/finally</code>, and restores <code>$GLOBALS['post']</code>
    + calls <code>wp_reset_postdata()</code> even if a filter throws.
</p>

<h2>What the wire looks like</h2>

<p>
    For an Elementor page titled "Pricing", the plugin sends the
    fully-rendered HTML to <code>/api/v1/wp/posts/sync</code> exactly
    as the browser would receive it — including section wrappers,
    widget HTML, and Elementor's own classnames. Pitchbar then
    strips tags server-side for chunking, so the classnames don't
    end up in the embedding.
</p>

<p>
    Sample sliced payload (truncated for readability):
</p>

<pre><code>{
  "wp_id": 142,
  "post_type": "page",
  "permalink": "https://shop.example/pricing",
  "title": "Pricing",
  "content_html": "&lt;div class=\"elementor elementor-142\"&gt;&lt;section class=\"elementor-section …\"&gt;…&lt;/section&gt;…&lt;/div&gt;",
  "excerpt": "Three plans, two outcomes…",
  "content_hash": "ab12…ef90",
  "modified_at": "2026-05-09T14:30:00+00:00",
  "language": "en-us",
  "taxonomy_terms": []
}</code></pre>

<h2>Override the rendered HTML</h2>

<p>
    The <code>pitchbar_post_content_html</code> filter receives the
    final HTML <em>after</em> the builder renderer runs (or after the
    vanilla filter chain runs, for non-builder posts) — so you can
    post-process it without caring which builder owned the page:
</p>

<pre><code>add_filter('pitchbar_post_content_html', function ($html, $post, $builder) {
    // $builder is one of: 'elementor', 'beaver', 'oxygen', 'bricks',
    // 'divi', or null for plain Gutenberg/Classic posts.

    if ($builder === 'elementor') {
        // Strip Elementor's helper iframes that crawlers don't see.
        $html = preg_replace('#&lt;iframe[^&gt;]*data-elementor-[^&gt;]*&gt;.*?&lt;/iframe&gt;#is', '', $html);
    }

    return $html;
}, 10, 3);</code></pre>

<p>
    Returning an empty string suppresses sync for that post (Pitchbar
    will accept the empty content but the agent won't have anything
    to retrieve from). Returning <code>null</code> short-circuits the
    rest of the filter chain.
</p>

<h2>When a builder upgrades and breaks</h2>

<p>
    Page builders periodically rename their internal renderers — when
    that happens, the plugin's reflection-based call falls through
    to the next path and the post syncs as if it were a vanilla
    Gutenberg post. The result is degraded (you may get an empty
    string or a shortcode stub), but the sync itself doesn't fatal
    out.
</p>

<p>
    If you notice a specific builder version producing empty content,
    open an issue against the Pitchbar repo with the builder name +
    version. The fix is usually a single-line update to the renderer
    call in <code>PageBuilderContent</code>.
</p>

<h2>Limitations</h2>

<ul>
    <li><strong>Dynamic data widgets.</strong> Elementor Pro's "Dynamic Tags" that pull live data (cart counts, user names) render with their default fallback when there's no real visitor in scope.</li>
    <li><strong>Conditional display rules.</strong> Visibility rules that depend on the requesting visitor (logged-in only, geolocation, A/B variants) resolve against the sync request context, which is server-side. Sections gated to "logged-in users only" are not synced.</li>
    <li><strong>Lazy-loaded inline blocks.</strong> JavaScript-only content (React micro-frontends embedded as Elementor HTML widgets) never executes server-side, so the synced HTML reflects the placeholder, not the hydrated content.</li>
</ul>

<p>
    These are inherent to server-side rendering of a builder layout
    and apply to every CMS plugin that does the same thing (Yoast SEO
    sitemap generators, schema markup plugins, and so on).
</p>
