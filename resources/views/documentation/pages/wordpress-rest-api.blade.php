<p>
    The plugin and the Pitchbar server talk to each other in two
    directions over HTTP, with two different auth schemes. This
    reference documents every endpoint involved — request shape,
    response shape, status codes, and how authentication is
    enforced.
</p>

<h2>Auth at a glance</h2>

<table>
    <thead>
        <tr><th>Direction</th><th>Credential</th><th>Where it lives</th><th>Replay window</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Plugin → Pitchbar</td>
            <td>Bearer API token <code>pbar_…</code></td>
            <td>Pitchbar stores only the SHA-256 hash. Plugin keeps plaintext in <code>wp_options</code>.</td>
            <td>—</td>
        </tr>
        <tr>
            <td>Plugin → Pitchbar (mutating)</td>
            <td>Bearer + HMAC body signature</td>
            <td>HMAC key is the bearer plaintext itself.</td>
            <td>5 min</td>
        </tr>
        <tr>
            <td>Pitchbar → Plugin</td>
            <td>HMAC body signature</td>
            <td>Per-token <code>shopper_signing_secret</code> (plaintext on both sides).</td>
            <td>5 min</td>
        </tr>
    </tbody>
</table>

<h3>HMAC signature scheme</h3>

<pre><code>X-Pitchbar-Signature: t=&lt;unix_ts&gt;,v1=&lt;hex_sig&gt;
sig = hmac_sha256(secret, "{t}.{raw_body}")</code></pre>

<p>
    Both sides reject requests whose <code>t</code> is more than 300
    seconds away from the verifying server's clock. Both sides
    constant-time-compare the signature with <code>hash_equals</code>.
    The same scheme is used for outbound webhooks documented under
    <a href="/documentation/webhooks">Outgoing webhooks</a>.
</p>

<h2>Pitchbar endpoints (plugin → Pitchbar)</h2>

<p>
    Base: your Pitchbar workspace URL. All routes are POST and
    require <code>Authorization: Bearer pbar_…</code> with the
    <code>wp:integration</code> ability. Mutating routes
    additionally require <code>X-Pitchbar-Signature</code>.
</p>

<h3>POST /api/v1/wp/handshake</h3>

<p>
    Called by the plugin's <strong>Test connection</strong> button to
    discover the workspace, list available agents, and capture the
    token's <code>shopper_signing_secret</code>. No HMAC required
    (the bearer is sufficient — the handshake doesn't mutate state
    beyond writing <code>last_used_at</code>).
</p>

<p>Request:</p>

<pre><code>{
  "site_url": "https://shop.example.com",
  "plugin_version": "2.0.4",
  "woocommerce_active": true,
  "wordpress_version": "6.6"
}</code></pre>

<p>Response 200:</p>

<pre><code>{
  "data": {
    "workspace": { "id": "01HZ…", "name": "Acme" },
    "agents": [
      { "id": "01HZ…", "name": "Storefront bot", "site_type": "ecommerce", "language_default": "en", "is_published": true }
    ],
    "token": {
      "id": "01HZ…",
      "name": "shop.example.com",
      "abilities": ["wp:integration"],
      "shopper_signing_secret": "sek_…"
    },
    "recommended_site_type": "ecommerce",
    "echo": { "site_url": "https://shop.example.com", "plugin_version": "2.0.4" }
  }
}</code></pre>

<p>
    <code>recommended_site_type</code> hints at which vertical the
    agent should adopt: <code>ecommerce</code> when WooCommerce is
    active on the calling site, otherwise <code>null</code>.
    <code>shopper_signing_secret</code> is plaintext — the plugin
    stashes it in <code>wp_options</code> silently.
</p>

<p>Errors:</p>

<ul>
    <li><code>401 invalid_token</code> — bearer missing, malformed, or revoked.</li>
    <li><code>403 insufficient_ability</code> — token doesn't grant <code>wp:integration</code>.</li>
</ul>

<h3>POST /api/v1/wp/posts/sync</h3>

<p>Bulk post upsert. Up to 50 posts per request. Requires HMAC.</p>

<pre><code>{
  "agent_id": "01HZ…",
  "site_url": "https://shop.example.com",
  "plugin_version": "2.0.4",
  "posts": [
    {
      "wp_id": 142,
      "post_type": "page",
      "permalink": "https://shop.example/pricing",
      "title": "Pricing",
      "content_html": "&lt;div class=\"elementor-…\"&gt;…&lt;/div&gt;",
      "excerpt": "Three plans, two outcomes…",
      "content_hash": "ab12…ef90",
      "modified_at": "2026-05-09T14:30:00+00:00",
      "language": "en-us",
      "taxonomy_terms": ["pricing", "plans"]
    }
  ]
}</code></pre>

<p>Response 200:</p>

<pre><code>{
  "data": {
    "queued": 1,
    "skipped_unchanged": 0,
    "deleted": 0
  }
}</code></pre>

<p>
    <code>queued</code> = number of posts whose hash differed from
    the stored Document and were queued for embedding.
    <code>skipped_unchanged</code> = number whose hash matched (no
    cost). The vector store is updated asynchronously by
    <code>IndexDocumentJob</code>; subsequent retrievals start
    finding the new content within seconds.
</p>

<h3>POST /api/v1/wp/posts/changed</h3>

<p>
    Single-post delta. Same shape as <code>posts/sync</code> but with
    a <code>posts</code> array of length 1 and an extra
    <code>action</code> field of <code>"upsert"</code> or
    <code>"delete"</code>. HMAC required.
</p>

<h3>POST /api/v1/wp/products/sync</h3>

<p>Bulk WooCommerce product upsert. Up to 50 per batch. HMAC required.</p>

<pre><code>{
  "agent_id": "01HZ…",
  "site_url": "https://shop.example.com",
  "plugin_version": "2.0.4",
  "products": [
    {
      "wp_id": 9001,
      "sku": "T-BLU-M",
      "name": "Blue tee",
      "permalink": "https://shop.example/product/blue-tee",
      "image_url": "https://shop.example/wp-content/uploads/2026/05/blue-tee-300x300.jpg",
      "short_description": "&lt;p&gt;Crew-neck cotton tee.&lt;/p&gt;",
      "description": "&lt;p&gt;100% combed ring-spun cotton…&lt;/p&gt;",
      "price": "29.00",
      "regular_price": "39.00",
      "sale_price": "29.00",
      "currency": "USD",
      "stock_status": "instock",
      "on_sale": true,
      "content_hash": "cd34…12ef",
      "modified_at": "2026-05-09T14:30:00+00:00",
      "categories": ["tees", "summer"],
      "attributes": ["color: blue", "size: S, M, L"]
    }
  ]
}</code></pre>

<p>
    On first upsert against an agent with <code>site_type =
    "generic"</code>, the agent is silently switched to
    <code>"ecommerce"</code>. See
    <a href="/documentation/wordpress-sync">Content sync</a> for the
    full rules.
</p>

<h3>POST /api/v1/wp/products/changed</h3>

<p>
    Single-product delta. Same as <code>posts/changed</code> but for
    WC products. HMAC required.
</p>

<h3>POST /api/v1/wp/coupons/sync</h3>

<p>Snapshots the store's active coupons. Idempotent (full-replace).</p>

<pre><code>{
  "agent_id": "01HZ…",
  "site_url": "https://shop.example.com",
  "plugin_version": "2.0.4",
  "coupons": [
    { "code": "WELCOME10", "label": "10% off", "discount": "10%", "expires_at": null },
    { "code": "FREESHIP",  "label": "5 off your order", "discount": "5", "expires_at": "2026-12-31T00:00:00+00:00" }
  ]
}</code></pre>

<p>
    The list is persisted on the agent's <code>woocommerce_products</code>
    source under <code>config['coupons']</code>. Subsequent prompt
    assembly includes the codes verbatim so the LLM never invents
    them.
</p>

<h3>POST /api/v1/widget/coupon/apply</h3>

<p>
    Called by the widget's <strong>Apply</strong> button on a
    <code>&lt;coupon/&gt;</code> chat block. Auth is the widget JWT
    (not a bearer token), throttle 30/min/IP.
</p>

<pre><code>{ "code": "WELCOME10" }</code></pre>

<p>
    Pitchbar resolves the agent's WordPress / WooCommerce source,
    HMAC-signs the body with the workspace's shopper signing secret,
    and forwards to <code>/wp-json/pitchbar/v1/cart/coupon</code> on
    the WP site. The forwarded body includes
    <code>conversation_id</code> so the plugin can stage the coupon
    in a per-conversation transient.
</p>

<h2>Plugin endpoints (Pitchbar → plugin)</h2>

<p>
    Base: <code>{wp_site_url}/wp-json/pitchbar/v1/</code>. All routes
    are POST. Auth: <code>X-Pitchbar-Signature</code> verified against
    the plugin's stored <code>shopper_signing_secret</code>. No
    WordPress nonce or cookie auth — the caller is the Pitchbar
    server, not a logged-in browser.
</p>

<h3>Verification path</h3>

<p>
    Every plugin REST controller extends
    <code>Pitchbar\Rest\RestController</code> which gates each request
    via <code>verifyOrReject($request)</code>:
</p>

<ol>
    <li>Read the <code>X-Pitchbar-Signature</code> header. If missing → 401 <code>missing_signature</code>.</li>
    <li>Read the plugin's stored <code>shopper_signing_secret</code> from <code>wp_options</code>. If empty → 401 <code>plugin_unconfigured</code>.</li>
    <li>Compute the expected signature over the raw request body. If <code>hash_equals</code> fails → 401 <code>signature_mismatch</code>.</li>
    <li>If the timestamp <code>t</code> is &gt; 300s away from <code>time()</code> → reject with <code>signature_mismatch</code> too (the secret never matched the replayed timestamp).</li>
</ol>

<p>
    The error response is always JSON-wrapped:
</p>

<pre><code>{ "error": { "code": "signature_mismatch", "message": "HMAC signature did not verify." } }</code></pre>

<h3>POST /wp-json/pitchbar/v1/orders/lookup</h3>

<p>Looks up a customer's recent WooCommerce orders.</p>

<pre><code>{
  "wp_user_id": 42,
  "limit": 5,
  "order_number": "WC-1234"   // optional — filter the result set
}</code></pre>

<p>Response 200:</p>

<pre><code>{
  "data": {
    "count": 2,
    "orders": [
      {
        "id": 9001,
        "number": "9001",
        "status": "completed",
        "total": "49.99",
        "currency": "USD",
        "date_created": "2026-05-08T11:23:00+00:00",
        "items": [{ "name": "Blue tee", "qty": 1, "sku": "T-BLU-M", "total": "29.00" }],
        "tracking_url": "https://aftership.com/…",
        "order_url": "https://shop.example/my-account/view-order/9001/"
      }
    ]
  }
}</code></pre>

<p>
    <code>tracking_url</code> is best-effort: the controller checks
    <code>_aftership_tracking_url</code>,
    <code>_tracking_url</code>, and <code>_st_tracking_link</code>
    order meta keys. If none match, the field is an empty string and
    the LLM falls back to surfacing <code>order_url</code>.
</p>

<p>
    When WooCommerce isn't active, the controller returns 200 with
    <code>{ "orders": [], "count": 0, "note": "woocommerce_inactive" }</code>
    so the agent can answer gracefully.
</p>

<h3>POST /wp-json/pitchbar/v1/leads</h3>

<p>Pushes a captured Pitchbar lead back into WordPress.</p>

<pre><code>{
  "email": "shopper@example.com",
  "name": "Alex Visitor",
  "phone": "+1-555-0123",
  "conversation_id": "01HZ…",
  "pitchbar_lead_id": "01HZ…"
}</code></pre>

<p>Response 200:</p>

<pre><code>{ "data": { "user_id": 199 } }</code></pre>

<p>Behaviour:</p>

<ul>
    <li>If a WP user with that email exists, update its first_name, billing_phone, and Pitchbar meta keys.</li>
    <li>If no user, create a WC customer (<code>wc_create_new_customer</code>) when Woo is active, otherwise a WP subscriber via <code>wp_create_user</code> with a random 24-char password.</li>
    <li>Username is derived from the local part of the email + a numeric suffix until unique.</li>
    <li><code>pitchbar_lead_id</code> + <code>pitchbar_conversation_id</code> are written to user meta so the store owner can correlate.</li>
</ul>

<h3>POST /wp-json/pitchbar/v1/cart/coupon</h3>

<p>Stages a coupon code for the visitor's next cart load.</p>

<pre><code>{
  "code": "WELCOME10",
  "conversation_id": "01HZ…"
}</code></pre>

<p>Response 200:</p>

<pre><code>{
  "data": {
    "applied": false,
    "pending": true,
    "message": "Coupon staged. It will apply when the visitor opens their cart."
  }
}</code></pre>

<p>Behaviour:</p>

<ol>
    <li>Validates the coupon exists via <code>new WC_Coupon($code)</code> + <code>get_id()</code> ≠ 0. If not, returns 400 <code>invalid_coupon</code>.</li>
    <li>Stages the code in a 15-minute transient: <code>pitchbar_pending_coupon_{conversation_id}</code>.</li>
    <li>The plugin's <code>woocommerce_load_cart_from_session</code> hook reads the transient on the visitor's next cart load (located by the <code>pitchbar_conv_id</code> cookie the widget writes at init), calls <code>WC()-&gt;cart-&gt;apply_coupon($code)</code>, and clears the transient.</li>
</ol>

<p>
    When WooCommerce isn't active, returns 400
    <code>woocommerce_inactive</code>. The coupon-card Apply button
    in the widget is hidden via a feature flag in that case.
</p>

<h2>Error envelope</h2>

<p>Every Pitchbar endpoint returns errors as:</p>

<pre><code>{ "message": "…", "code": "…", "errors": { "field": ["…"] } }</code></pre>

<p>Every plugin endpoint returns errors as:</p>

<pre><code>{ "error": { "code": "…", "message": "…" } }</code></pre>

<p>
    The shape difference is intentional — Pitchbar follows Laravel's
    validator convention, plugin follows WP REST convention. Both
    sides parse the other transparently.
</p>

<h2>Throttling</h2>

<p>
    Pitchbar's <code>/api/v1/wp/*</code> routes throttle by API token
    (default 60 req/min per token). The plugin's
    <code>/wp-json/pitchbar/v1/*</code> routes don't enforce a quota
    — the HMAC check + 5-minute replay window already prevents abuse,
    and the upstream Pitchbar timeout (5s on
    <code>OrderLookupController</code>) bounds runtime.
</p>

<h2>Status codes summary</h2>

<table>
    <thead><tr><th>Code</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td>200</td><td>Success or "ignored as no-op" (delete of unknown post).</td></tr>
        <tr><td>400</td><td>Validation error — body shape wrong, missing field, invalid coupon.</td></tr>
        <tr><td>401</td><td>Auth failed (missing token / signature / wrong secret).</td></tr>
        <tr><td>403</td><td>Token missing required ability.</td></tr>
        <tr><td>404</td><td>Resource doesn't exist on this workspace (most often the agent_id).</td></tr>
        <tr><td>422</td><td>Validation error from Laravel's validator (Pitchbar side).</td></tr>
        <tr><td>429</td><td>Throttled.</td></tr>
    </tbody>
</table>
