<p>
    When WooCommerce is active on the WordPress site, the Pitchbar
    plugin unlocks a deep integration that goes far beyond the
    standard content sync: logged-in shopper context, the
    <code>lookup_order</code> tool, coupon emission &amp; apply, lead
    push-back, and the <code>abandoned_cart</code> trigger.
</p>

<p>
    None of these endpoints fire if WooCommerce isn't loaded — the
    plugin defers every WC-dependent hookup to the
    <code>woocommerce_loaded</code> action so alphabetical plugin
    load order doesn't matter.
</p>

<h2>WooCommerce load-order race</h2>

<p>
    Plenty of pre-v2.0 WordPress integrations have a subtle bug:
    they call <code>class_exists('WooCommerce')</code> at
    <code>plugins_loaded</code> priority 10 and silently disable WC
    features when the check returns false. On sites where the
    integration plugin loads alphabetically <em>before</em>
    <code>woocommerce/</code> (e.g. <em>pitchbar</em> &lt;
    <em>woocommerce</em>), the WooCommerce main class hasn't been
    registered yet at priority 10 → false → broken integration with
    no error message.
</p>

<p>
    Pitchbar v2.0.0 uses a two-layer guard:
</p>

<pre><code>if (function_exists('did_action') &amp;&amp; did_action('woocommerce_loaded') &gt; 0) {
    $this-&gt;bootWoo();   // WC already loaded — wire up immediately.
} else {
    add_action('woocommerce_loaded', [$this, 'bootWoo'], 10);
}</code></pre>

<p>
    <code>woocommerce_loaded</code> fires after the WC main class is
    fully registered, so by the time <code>bootWoo()</code> runs every
    <code>class_exists('WooCommerce')</code> check passes
    deterministically.
</p>

<h2>Logged-in shopper context</h2>

<p>
    When a WooCommerce customer is signed in and visits a page that
    loads the widget, the plugin attaches a short-lived signed
    <code>data-shopper-token</code> to the widget script tag. The
    widget forwards it to <code>/api/v1/widget/init</code>; Pitchbar
    verifies it and bakes <code>wp_user_id</code> +
    <code>email_hash</code> claims into the widget JWT for the chat
    session.
</p>

<p>What's encoded in the token:</p>

<ul>
    <li><code>wp_user_id</code> — the WordPress user ID (integer, sent as string in the token).</li>
    <li><code>email_hash</code> — SHA-256 of the lowercased email. Never the raw email.</li>
    <li><code>source</code> — literal <code>"wordpress"</code>.</li>
    <li><code>exp</code> — unix timestamp, ~24h out so the same token is valid across a normal browsing session.</li>
</ul>

<p>
    The token is signed with the workspace's
    <strong>shopper_signing_secret</strong> (per-token plaintext,
    captured by the plugin on first handshake). A bad signature is
    silently dropped — the visitor still chats anonymously, no error
    surfaces.
</p>

<h2>The <code>lookup_order</code> tool</h2>

<p>
    EcommercePreset's <code>order_status</code> capability maps to a
    real tool. When the visitor asks about orders, shipping, returns,
    or refunds, the LLM may call
    <code>lookup_order(limit, order_number?)</code>. The tool:
</p>

<ol>
    <li>Reads the conversation's stored shopper claims (set at <code>/widget/init</code>).</li>
    <li>Resolves the agent's <code>woocommerce_products</code> Source's <code>site_url</code>.</li>
    <li>Pulls a <code>shopper_signing_secret</code> from any active workspace API token.</li>
    <li>POSTs an HMAC-signed body to <code>{site_url}/wp-json/pitchbar/v1/orders/lookup</code> with a 5-second hard timeout.</li>
    <li>Returns the orders payload to the LLM verbatim for it to summarise.</li>
</ol>

<p>Refusal paths (no callback fired):</p>

<ul>
    <li>No shopper claim on conversation → <code>not_signed_in</code>. The LLM asks the visitor to sign in.</li>
    <li>No <code>woocommerce_products</code> source → <code>no_wordpress_source</code>.</li>
    <li>No active API token with a signing secret → <code>no_signing_secret</code>.</li>
</ul>

<p>
    The plugin's <code>OrderLookupController</code> returns up to 10
    orders (default 5), each with id, number, status, total,
    currency, items array, tracking URL (best-effort across
    AfterShip, ST tracking, generic <code>_tracking_url</code>
    meta), and the customer's order view URL.
</p>

<p>
    Filter to <code>order_number</code> if the visitor asks about a
    specific order — the plugin filters the result set after the
    customer-id query so you never expose another customer's data.
</p>

<h2>Lead push-back</h2>

<p>
    When the chat agent captures a lead (a visitor leaves an email or
    submits a lead form), Pitchbar mirrors the contact back into the
    WordPress site:
</p>

<table>
    <thead><tr><th>Trigger</th><th>What happens</th></tr></thead>
    <tbody>
        <tr>
            <td><code>LeadCapturedEvent</code></td>
            <td><code>PushLeadToWordPress</code> queued listener fires. It resolves the agent's WordPress or WooCommerce source, signs an HMAC body with the workspace's shopper signing secret, and POSTs to <code>/wp-json/pitchbar/v1/leads</code>.</td>
        </tr>
        <tr>
            <td>WP receives the call</td>
            <td>If WooCommerce is active, plugin creates a WC customer via <code>wc_create_new_customer</code>. Otherwise creates a WP <em>subscriber</em>. <code>pitchbar_lead_id</code> + <code>pitchbar_conversation_id</code> land in user meta so the store owner can correlate the WP user with the Pitchbar transcript.</td>
        </tr>
    </tbody>
</table>

<p>
    Idempotent on email: a second push for the same address updates
    the existing user's <code>first_name</code>, <code>billing_phone</code>,
    <code>pitchbar_lead_id</code>, and <code>pitchbar_conversation_id</code>
    meta instead of creating a duplicate user. Transport failures
    are logged and swallowed — the listener never blocks the
    visitor's chat turn.
</p>

<h2 id="coupon-emission">Coupon emission &amp; apply</h2>

<p>
    Ecommerce agents can emit a <code>&lt;coupon/&gt;</code> block in
    chat replies when a visitor's intent + an active WC coupon line
    up. The full data flow:
</p>

<ol>
    <li>Plugin's <code>CouponSyncer</code> runs after every product sync, enumerates publish-status <code>shop_coupon</code> posts, filters expired / over-limit, and POSTs the snapshot to <code>/api/v1/wp/coupons/sync</code>.</li>
    <li>Pitchbar persists the list on the source's <code>config['coupons']</code> array (max 50).</li>
    <li><code>PromptBuilder</code> reads them when assembling the system prompt for ecommerce agents. The LLM gets the full code list and an explicit instruction never to invent codes.</li>
    <li>When the LLM decides a coupon helps the visitor, it emits an XML marker: <code>&lt;coupon code="WELCOME10" label="10% off your first order" discount="10%"/&gt;</code>.</li>
    <li><code>InlineBlockParser</code> extracts the marker post-stream and emits a <code>block</code> SSE event of kind <code>coupon_card</code>.</li>
    <li>The widget's <code>blocks.tsx</code> renderer materialises a card with a <strong>Copy</strong> button (writes the code to the clipboard) and an <strong>Apply</strong> button.</li>
    <li>The Apply button POSTs to <code>/api/v1/widget/coupon/apply</code> on Pitchbar.</li>
    <li>Pitchbar HMAC-signs the body with the workspace's shopper signing secret and forwards it to <code>/wp-json/pitchbar/v1/cart/coupon</code> on the WP site.</li>
    <li>Because a REST request usually doesn't have a loaded <code>WC()-&gt;cart</code>, the plugin stages the code in a 15-minute transient: <code>pitchbar_pending_coupon_{conversation_id}</code>.</li>
    <li>On the visitor's next page load, the plugin's <code>woocommerce_load_cart_from_session</code> hook reads the transient via the <code>pitchbar_conv_id</code> cookie (set by the widget at init) and calls <code>WC()-&gt;cart-&gt;apply_coupon($code)</code>.</li>
    <li>The transient is deleted on successful apply; expired transients self-clean after 15 minutes.</li>
</ol>

<p>
    Net result: the visitor clicks <strong>Apply</strong> in chat,
    walks to the cart page, and sees the discount line already
    applied — no copy-paste, no missed codes.
</p>

<h2>Abandoned cart re-engagement</h2>

<p>
    A <code>BehaviorRule.kind = "abandoned_cart"</code> lets you
    re-engage a visitor whose cart has been sitting idle.
</p>

<h3>Mirror cart state to localStorage</h3>

<p>
    The plugin enqueues <code>cart-state.js</code> on every front-end
    page when WooCommerce is active. It mirrors every WC jQuery cart
    event into <code>localStorage.pitchbar_cart_state</code>:
</p>

<pre><code>{
  "items": 2,           // running count
  "timestamp": 1715472000000  // ms since epoch of last mutation
}</code></pre>

<p>
    Listens for:
</p>

<ul>
    <li><code>added_to_cart</code> jQuery event → increment count, refresh timestamp.</li>
    <li><code>removed_from_cart</code> jQuery event → decrement; clear if &lt;= 0.</li>
    <li>DOM <code>click</code> on <code>.add_to_cart_button</code> / <code>.single_add_to_cart_button</code> as a jQuery-free fallback.</li>
    <li>URL pathname includes <code>/checkout</code>, <code>/order-received</code>, <code>/order-pay</code>, <code>/thank-you</code> → clear the cart state entirely.</li>
</ul>

<p>
    Storage failures (private browsing, quota exhausted) are silent
    no-ops — the trigger just doesn't fire for that visitor.
</p>

<h3>Configure the trigger</h3>

<p>
    On the agent's <strong>Behavior triggers</strong> admin page, add
    a new rule of kind <code>abandoned_cart</code>:
</p>

<pre><code>{
  "kind": "abandoned_cart",
  "conditions": { "idle_minutes": 5 },
  "action": {
    "kind": "open_with_message",
    "message": "Still thinking it over? Happy to help you decide — want a hand?"
  }
}</code></pre>

<p>
    The widget's <code>TriggerEngine</code> polls the localStorage
    entry every 30 seconds (and once immediately on mount). When it
    finds a non-empty cart older than the rule's
    <code>idle_minutes</code> threshold, it fires the rule's action
    — typically a proactive engagement bubble.
</p>

<p>
    A 5-minute global trigger cooldown still applies, so the visitor
    isn't ambushed on every page transition.
</p>

<h2>Page-context payload (for Woo products)</h2>

<p>
    When the visitor is on a single-product page (<code>is_product()</code>),
    the plugin enriches the <code>data-page-context</code> JSON with
    a <code>woo</code> object:
</p>

<pre><code>{
  "source": "wordpress",
  "page_url": "https://shop.example/product/blue-tee",
  "post_id": 142,
  "post_type": "product",
  "permalink": "…",
  "categories": ["tees", "summer"],
  "tags": [],
  "woo": {
    "id": 142,
    "sku": "T-BLU-M",
    "name": "Blue tee",
    "permalink": "https://shop.example/product/blue-tee",
    "price": "29.00",
    "regular_price": "39.00",
    "sale_price": "29.00",
    "currency": "USD",
    "stock_status": "instock",
    "on_sale": true
  }
}</code></pre>

<p>
    The widget folds this into the retrieval envelope so the agent
    prefers content from the page the visitor is currently on
    (current-page boost of +0.15 on chunk score).
</p>

<h2>What WooCommerce versions are supported?</h2>

<p>
    The plugin's WooCommerce dependencies are all on stable public
    classes:
</p>

<ul>
    <li><code>WC_Coupon</code> — public since WC 2.0.</li>
    <li><code>WC_Product</code> — public since WC 2.0.</li>
    <li><code>WC_Order</code> — public since WC 2.0.</li>
    <li><code>WC_Customer</code> + <code>wc_create_new_customer</code> — public since WC 2.5.</li>
    <li><code>wc_get_products</code> — public since WC 2.7.</li>
    <li><code>wc_get_orders</code> — public since WC 2.7.</li>
</ul>

<p>
    Effective floor: <strong>WooCommerce 3.0+</strong>. The plugin
    actively tests on 8.x and 9.x. HPOS (High-Performance Order
    Storage) is supported because the plugin uses the public
    <code>wc_get_orders()</code> + <code>WC_Order</code> API rather
    than reading order posts directly.
</p>
