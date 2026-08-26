<p>
    Once the plugin is connected to your workspace, you need to push
    your WordPress content into Pitchbar so the agent can retrieve
    from it. The plugin syncs in two complementary modes: a manual
    bulk sync you trigger from the Settings page, and automatic
    deltas that fire on every WP hook for the post / product types
    you opted in.
</p>

<p>
    Both modes are idempotent on <code>external_id</code> — re-running
    them is free.
</p>

<h2>Content sources</h2>

<table>
    <thead><tr><th>WP entity</th><th>Pitchbar Source type</th><th>External ID format</th></tr></thead>
    <tbody>
        <tr><td>Posts, pages, custom post types</td><td><code>wordpress</code></td><td><code>wp:{post_id}</code></td></tr>
        <tr><td>WooCommerce products</td><td><code>woocommerce_products</code></td><td><code>wc:{product_id}</code></td></tr>
        <tr><td>WC coupons</td><td>Embedded in <code>woocommerce_products</code> source <code>config['coupons']</code></td><td>—</td></tr>
    </tbody>
</table>

<p>
    One Source row per (agent, site host). The two types coexist on
    the same agent — posts answer FAQ-style questions, products
    answer "do you have X" style shopping intent. Pitchbar
    auto-creates each Source on the first sync.
</p>

<h2>Bulk sync (manual)</h2>

<p>
    Open <strong>Settings → Pitchbar</strong>. Two buttons appear
    once the plugin is configured:
</p>

<ul>
    <li><strong>Sync posts now</strong> — always visible. Pages through every <code>publish</code>-status post of the post types you enabled, 50 per HTTP batch.</li>
    <li><strong>Sync products now</strong> — visible only when WooCommerce is active. Pages through <code>simple</code>, <code>variable</code>, <code>grouped</code>, and <code>external</code> products, 50 per batch.</li>
</ul>

<p>
    Click either button. The status line shows progress and final
    counts: <em>"Sync complete. (124 posts, 87 queued, 37 skipped)"</em>.
    Skipped means the content hash matched the previous sync — no
    re-embedding needed, no LLM cost.
</p>

<h2>Resumable sync (large sites)</h2>

<p>
    Shared hosting commonly enforces a 30-second PHP execution cap.
    Pitchbar's syncers respect this: each pass enforces a
    <code>TIME_BUDGET_SECONDS = 20</code> wall-clock guard. When the
    budget is exhausted AND more pages remain to process, the syncer:
</p>

<ol>
    <li>Persists a resume marker as a WP transient: <code>pitchbar_post_sync_resume</code> for posts, <code>pitchbar_product_sync_resume</code> for products. Shape: <code>{ "page": 7, "at": 1715472000 }</code>.</li>
    <li>Schedules a WP-Cron continuation 30 seconds out on the same hook (<code>pitchbar_run_full_sync_event</code> / <code>pitchbar_run_product_sync_event</code>).</li>
    <li>Returns immediately with <code>more: true</code>, <code>next_page: 7</code> in the response so the admin UI knows to show "large site detected, the rest is continuing in the background."</li>
</ol>

<p>
    Re-running "Sync now" while a resume marker exists picks up where
    the last pass left off. Successful completion (no more pages)
    clears the resume marker.
</p>

<p>
    While a chunked sync is mid-flight, every WP admin page shows a
    soft notice: <em>"Pitchbar is finishing a large-site sync in the
    background (posts). The widget already works; new content shows
    up once this completes."</em>
</p>

<h2>Delta sync (automatic)</h2>

<p>
    On every <code>save_post</code>, <code>wp_trash_post</code>, or
    <code>before_delete_post</code> for an opted-in post type with
    <code>publish</code> status, the plugin fires a single delta call
    to Pitchbar:
</p>

<table>
    <thead><tr><th>Endpoint</th><th>Trigger</th><th>Body shape</th></tr></thead>
    <tbody>
        <tr>
            <td><code>POST /api/v1/wp/posts/changed</code></td>
            <td><code>save_post</code> / <code>wp_trash_post</code> / <code>before_delete_post</code> on an opted-in post type.</td>
            <td>One post payload + <code>action: "upsert"</code> or <code>"delete"</code>.</td>
        </tr>
        <tr>
            <td><code>POST /api/v1/wp/products/changed</code></td>
            <td><code>woocommerce_new_product</code>, <code>woocommerce_update_product</code>, <code>woocommerce_delete_product</code>, <code>woocommerce_trash_product</code>.</td>
            <td>One product payload + <code>action: "upsert"</code> or <code>"delete"</code>.</td>
        </tr>
    </tbody>
</table>

<p>
    Bulk sync uses the parallel <code>/posts/sync</code> and
    <code>/products/sync</code> endpoints with up to 50 entities per
    batch. Both endpoints authenticate exactly the same way
    (<a href="/documentation/wordpress-rest-api">bearer + HMAC</a>).
</p>

<h2>Content normalization</h2>

<p>
    The plugin's <code>PostContentExtractor</code> resolves visible
    HTML for every post before sending it to Pitchbar:
</p>

<ol>
    <li><strong>Page-builder detection</strong> — if the post is owned by Elementor, Beaver Builder, Oxygen, or Bricks, the builder's native renderer is called instead of <code>post_content</code>. See <a href="/documentation/wordpress-page-builders">Page builders</a>.</li>
    <li><strong>Postdata priming</strong> — <code>$GLOBALS['post']</code> is set + <code>setup_postdata()</code> is called so third-party <code>the_content</code> filters (Yoast, Jetpack, Divi, embeds) see a valid <code>$post</code> global.</li>
    <li><strong>Block expansion</strong> — <code>do_blocks()</code> resolves every Gutenberg block.</li>
    <li><strong>Filter chain</strong> — <code>apply_filters('the_content', …)</code> runs every theme/plugin hook (lazy-loading, image replacement, related posts, etc.).</li>
    <li><strong>Shortcode resolution</strong> — <code>do_shortcode()</code> resolves any remaining shortcodes.</li>
    <li><strong>Filter override</strong> — the final HTML is run through your own <code>pitchbar_post_content_html</code> filter, which lets you strip navigation chrome, force a custom template, or short-circuit entirely. See "Override the synced HTML" below.</li>
    <li><strong>Cleanup</strong> — <code>$GLOBALS['post']</code> is restored and <code>wp_reset_postdata()</code> is called inside a <code>finally</code> block, so a throwing filter callback can't leak the loop state.</li>
</ol>

<h3>Content hash &amp; skip-on-unchanged</h3>

<p>
    Every sync (bulk or delta) sends a <code>content_hash</code>
    field. Pitchbar compares it to the existing Document's stored
    hash; on match the server returns immediately without re-chunking
    or re-embedding. Re-running "Sync now" against a stable site is
    effectively free — only the diff costs.
</p>

<p>The hash is SHA-256 of the normalized concatenation:</p>

<pre><code>sha256( title + "\n" + content_html + "\n" + excerpt + "\n" + taxonomy_term_names )</code></pre>

<p>
    Whitespace is collapsed to single spaces via <code>preg_replace('/\s+/u', ' ', …)</code>
    before hashing so trivial reflow doesn't trigger a re-index.
</p>

<h2>What gets indexed per post</h2>

<ul>
    <li>Post title (plain text, entity-decoded, tags stripped)</li>
    <li>Excerpt — explicit post excerpt if set, otherwise the first 40 words of stripped content</li>
    <li>Taxonomy term names — every term across every taxonomy the post type registers (categories, tags, custom taxonomies)</li>
    <li>Body HTML — fully expanded as described above, server-stripped of tags into chunks</li>
    <li>Permalink, post type, modified timestamp, detected language (from WP locale)</li>
</ul>

<h2>What gets indexed per WooCommerce product</h2>

<ul>
    <li>Name, SKU, permalink, primary image URL (medium size)</li>
    <li>Short description + long description (Gutenberg blocks expanded, shortcodes resolved, the_content filter applied)</li>
    <li>Price, regular_price, sale_price, currency, on_sale flag, stock_status</li>
    <li>Category names (via <code>product_cat</code> taxonomy)</li>
    <li>Attribute name + value pairs flattened to <code>"color: blue, red"</code> strings so the embedding captures both</li>
    <li>SHA-256 content_hash + ISO 8601 modified_at</li>
</ul>

<h2>Coupon sync</h2>

<p>
    Coupons ride along with the product sync. After every successful
    product sync completes (resumed-to-completion, not mid-flight),
    the plugin's <code>CouponSyncer</code> runs a snapshot:
</p>

<ol>
    <li>Enumerates up to 50 publish-status entries of the <code>shop_coupon</code> custom post type via <code>get_posts()</code> — this works on every WooCommerce version since coupons shipped in WC 2.0. (Previously the plugin used <code>wc_get_coupons()</code>, which is not public WC API on every release.)</li>
    <li>Hydrates each match through <code>new WC_Coupon($id)</code> (a stable WC class).</li>
    <li>Filters out coupons that are expired (<code>get_date_expires()</code> &lt; now) or already over their usage limit.</li>
    <li>POSTs the result to <code>POST /api/v1/wp/coupons/sync</code>. Pitchbar persists them on the source's <code>config['coupons']</code> array.</li>
</ol>

<p>
    For each coupon the plugin sends: <code>code</code> (uppercased),
    <code>label</code> (humanized: "10% off", "5 off your order", etc.),
    <code>discount</code>, <code>expires_at</code>. See
    <a href="/documentation/wordpress-woocommerce#coupon-emission">WooCommerce
    deep links</a> for how the LLM uses these.
</p>

<h2>Auto-switch to the ecommerce vertical</h2>

<p>
    On the first successful product upsert against an agent whose
    <code>site_type</code> is <code>generic</code>, Pitchbar
    automatically switches the agent to
    <code>site_type = "ecommerce"</code>. This enables the
    <code>EcommercePreset</code> system-prompt fragment and the
    <code>&lt;product/&gt;</code> inline-block emission rules so the
    LLM can recommend products as rich cards instead of plain text.
</p>

<p>
    The switch fires only when <code>site_type</code> is
    <code>generic</code>. Agents already on <code>saas</code>,
    <code>documentation</code>, or explicit <code>ecommerce</code> are
    never overwritten.
</p>

<h2>Delete semantics</h2>

<p>
    <code>before_delete_post</code> / <code>woocommerce_delete_product</code>
    fire a single <code>delete</code> delta. Pitchbar tears down the
    Document, its chunks, and the vector points associated with it.
    Deleting an entity Pitchbar never saw is a silent no-op
    (HTTP 200 with <code>deleted: false</code>) so repeated cleanup is
    safe.
</p>

<h2>Override the synced HTML</h2>

<p>
    Apply your own filter to mutate the HTML the plugin sends to
    Pitchbar — strip the site header, force an Elementor template,
    redact a section, etc.
</p>

<pre><code>add_filter('pitchbar_post_content_html', function ($html, $post, $builder) {
    // $builder is the detected page-builder slug, or null for
    // plain Gutenberg posts. e.g. 'elementor', 'divi', 'bricks'.
    if ($post->post_type === 'product') {
        return $html;
    }

    // Strip any leftover navigation chrome our theme injects via
    // the_content. Pitchbar already does its own tag-stripping,
    // but doing it here reduces the chunk-overhead.
    $html = preg_replace('#&lt;nav[^&gt;]*&gt;.*?&lt;/nav&gt;#is', '', $html);

    return $html;
}, 10, 3);</code></pre>

<p>
    The filter receives the fully-rendered HTML <em>before</em> hashing
    and POSTing. Returning <code>''</code> effectively disables sync
    for that post (Pitchbar will see empty content and decline to
    embed anything).
</p>

<h2>Programmatic sync (from PHP)</h2>

<p>
    You can trigger a sync from your own code — useful for migrations
    or testing:
</p>

<pre><code>// Posts
$result = (new \Pitchbar\Sync\PostSyncer)->runFullSync();
// $result['ok'], $result['posts'], $result['more'], $result['next_page']

// Products (requires WooCommerce)
$result = (new \Pitchbar\Sync\ProductSyncer)->runFullSync();

// Coupons
$result = (new \Pitchbar\Sync\CouponSyncer)->run();</code></pre>

<p>
    None of these throw — they return result arrays with an
    <code>ok</code> key, an <code>errors</code> array, and (for the
    chunked syncers) <code>more</code> + <code>next_page</code>
    fields for resume tracking.
</p>
