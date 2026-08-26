=== Pitchbar ===
Contributors: pitchbar
Tags: ai chat, sales chatbot, woocommerce, customer support, live chat, lead generation, ai assistant
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sales-AI chat widget for WordPress and WooCommerce. Connect your site to a Pitchbar workspace and embed a streaming AI chat that answers from your own content.

== Description ==

Pitchbar embeds a streaming AI chat widget on your WordPress site that answers visitor questions from your own content, captures leads, and offers human handoff. This companion plugin handles the connection between WordPress and your Pitchbar workspace.

**What this plugin does in v1.0**

* One-click widget embed on every public page via `wp_footer`.
* Sends page context (post type, post id, categories, WooCommerce product hint) so the widget's retrieval prefers content from the page the visitor is on.
* Settings page under **Settings → Pitchbar** for entering the workspace URL + API token and selecting which agent to attach.
* Connection test against your Pitchbar workspace.

**Coming next**

* WordPress post / page sync as a Pitchbar knowledge source.
* WooCommerce product cards rendered inline in chat.
* Logged-in shopper context and order lookup tool.
* Coupon emission and abandoned-cart engagement.

**Requirements**

* A Pitchbar workspace and a workspace API token. Create one in your Pitchbar admin at **Settings → API tokens**.
* WordPress 6.4 or newer.
* PHP 7.4 or newer.

== Installation ==

1. Upload the `pitchbar` folder to `/wp-content/plugins/` (or install the zip from the plugin uploader).
2. Activate Pitchbar through the **Plugins** screen in WordPress.
3. Open **Settings → Pitchbar** and enter your Pitchbar base URL + API token.
4. Click **Test connection** and pick which agent to attach.
5. Enable the widget. Visit any public page on your site to confirm the chat bar loads.

== Frequently Asked Questions ==

= Where do I get an API token? =

Inside your Pitchbar workspace, open **Settings → API tokens**, click **Create token**, name it after your WordPress site, and copy the plaintext value shown once. Paste it into the plugin settings page.

= Does this plugin send my content anywhere? =

In v1.0 the plugin only injects the widget loader and a small page-context blob describing the current post (id, type, categories, Woo product fields). The widget itself talks to your Pitchbar workspace directly. Full content sync as a knowledge source lands in a future release.

= Where is my API token stored? =

In `wp_options` under the key `pitchbar_settings` as plaintext. WordPress does not encrypt options at rest — treat the token as a `wp-config.php`-level secret. Revoke it from your Pitchbar workspace any time.

== Translations ==

Bundled language packs in this release:

* English (default) — `languages/pitchbar.pot` template.
* Simplified Chinese — `languages/pitchbar-zh_CN.po` + compiled `pitchbar-zh_CN.mo`. WordPress picks it up automatically when the site is set to a Chinese locale (zh_CN / 中文 in Settings → General).

Translators: copy `pitchbar.pot` to `pitchbar-{LOCALE}.po`, translate, and email the file to hello@pitchbar.app for inclusion in the next release. The build pipeline compiles every `.po` in `languages/` to a matching `.mo` at packaging time, so no `msgfmt` install is required.

== Documentation ==

A full standalone reference ships inside the plugin at `pitchbar/documentation.html` — open in any browser; no internet required. Covers install, configuration, sync, page-builder support, WooCommerce features, REST API, security model, and troubleshooting.

== Changelog ==

= 2.0.5 =
* **Simplified Chinese (zh_CN) language pack.** Buyer-requested. The plugin's admin UI now renders fully in Chinese when WordPress is set to zh_CN. Bundles both `pitchbar-zh_CN.po` (editable) and `pitchbar-zh_CN.mo` (compiled, what WordPress actually loads).
* **Standalone documentation.** `pitchbar/documentation.html` ships inside the install — Mintlify-style reference for install, configuration, sync, page builders, WooCommerce features, REST API, security, and troubleshooting. Open in any browser; no internet required.
* **Build pipeline compiles `.po` to `.mo` automatically.** `php artisan pitchbar:build-wp-plugin` now generates a matching `.mo` for every `.po` in `languages/` before zipping, so future language packs ship correctly without needing GNU `msgfmt` installed on the build host.

= 2.0.4 =
* **Pitchbar admin column width.** On the WooCommerce Products list (and other crowded list tables) the "Pitchbar" column was rendering one letter per line because WP's auto-width algorithm starved it. Forced to 110px via an admin_head style block.

= 2.0.3 =
* **"Indexed" badge in the Posts, Pages, and Products admin lists.** The plugin stamps a sync timestamp and content-hash on every post/product it ships to Pitchbar. The admin list table now renders a green "Indexed" pill, a yellow "Out of date" pill (post edited after the last sync), or a gray "Not indexed" pill for entries that have never been synced. Buyer ask — admins can now tell at a glance which entries are searchable.
* **Empty-body posts no longer require a server-side validation deploy.** If `the_content` collapses to an empty string (featured-image-only posts, builder-owned posts with empty `post_content`, stub pages), the plugin now synthesizes a body from `title + excerpt + taxonomy terms` before sending. The Pitchbar agent still has indexable text and older Pitchbar servers (pre-v1.3.0) no longer reject the batch with "posts.N.content_html field is required."

= 2.0.2 =
* **Posts with empty content now sync cleanly.** Pre-fix, a batch died with `posts.N.content_html field is required` the first time any post had an empty body (featured-image-only posts, builder-owned posts with a blank `post_content`, stub pages). The plugin already sends those — the server now accepts an empty body and indexes the post by title + excerpt + taxonomy. Reported by a buyer who had 10 posts where the third was a featured-image-only entry, so all 10 dropped on the floor.
* **WooCommerce: every product type is synced now.** Pre-fix the syncer filtered to `[simple, variable, grouped, external]`, which silently dropped subscriptions, bundles, memberships, bookings, and any custom product types — common on real stores. A reproducible "0 products, 0 queued" was the symptom on a buyer's catalog of 10 products. The type filter is removed; we also added a fallback `WP_Query` for hosts whose `wc_get_products` hook chain hides everything (`Logger::warn` records when the fallback was needed so admins can debug).

= 2.0.1 =
* **Test connection now tells you exactly what broke.** The "Connection failed" generic message is gone. The plugin now surfaces the HTTP status, the URL it tried, the transport-level cURL/DNS code on a network failure, and the first 800 chars of the upstream response body. A new "Show details" toggle in the admin reveals the full diagnostic block so you can copy/paste it into an issue without re-running the request.
* HTTP-status-aware advice. 401 hints to reissue the API token, 403 hints at the missing `wp:integration` ability, 404 hints that the Pitchbar app isn't fully deployed, 5xx hints at the server log.
* JS catch-block now prints the underlying error message (`JS: <msg>`) instead of swallowing it into the generic "Connection failed."

= 2.0.0 =
* **Page builder support.** Elementor, Beaver Builder, Oxygen, and Bricks pages now sync their fully-rendered HTML to Pitchbar — previously these returned empty content because the layout lives in postmeta, not `post_content`. Divi posts route through `the_content` correctly now that `setup_postdata` primes the global `$post` before the filter chain runs.
* **WooCommerce load-order race fixed.** The plugin no longer trusts `class_exists('WooCommerce')` at `plugins_loaded` priority 10. It listens to the `woocommerce_loaded` action and re-checks if WC already fired, so alphabetical plugin-load order ("pitchbar" before "woocommerce") no longer silently disables product sync, coupon sync, and the cart-coupon REST endpoint.
* **Resumable sync.** Both post and product sync now enforce a 20-second time budget per pass. When a large site can't finish in one tick, the plugin persists a resume marker (transient) and schedules a WP-Cron continuation 30 seconds out — no more 504s on shared hosting with a 30s `max_execution_time` cap. Re-running "Sync now" simply resumes from where the last pass left off.
* **Coupon enumeration via `shop_coupon` CPT.** Replaces `wc_get_coupons()` (not public WC API on every release) with a direct `get_posts(['post_type' => 'shop_coupon'])` enumeration plus `new WC_Coupon($id)` hydration. Coupon sync now works on every WC version since coupons were introduced.
* **`abandoned_cart` admin trigger.** The widget evaluator already knew how to engage on cart staleness; the plugin admin UI now exposes `abandoned_cart` in the trigger-kind dropdown so workspace owners can wire it up.
* **RTL widget mirroring.** Embed loader now emits `data-page-dir` and `data-page-locale` attributes so the widget mirrors correctly on Arabic, Hebrew, Persian, and Urdu sites.
* **Background-sync admin notice.** While a chunked sync is mid-run the Plugins admin shows a soft info notice so the operator knows WP-Cron is still finishing the job.
* **`pitchbar_post_content_html` filter** added so themes/sites can post-process the synced HTML (strip nav, force a custom Elementor template, etc.) without forking the plugin.

= 1.2.0 =
* Mirrors captured Pitchbar leads back into WordPress as WooCommerce customers (or WP subscribers on non-Woo sites) without leaving the WP admin.
* Adds `<coupon/>` blocks emitted by the chat agent: the widget renders code + Copy + Apply, the Apply button stages the code on the WP cart so it kicks in on the next cart load.
* Plugin's CouponSyncer pushes the store's currently-valid WC coupons to Pitchbar after every product sync so the LLM only ever offers real codes.
* Adds `abandoned_cart` widget trigger kind: a small cart-state script mirrors WC `added_to_cart` / `removed_from_cart` events into localStorage; the widget engages when a cart sits idle longer than the rule's threshold.
* Adds `/wp-json/pitchbar/v1/leads` + `/wp-json/pitchbar/v1/cart/coupon` REST endpoints.

= 1.1.0 =
* Adds logged-in shopper context: when a WooCommerce customer is signed in, the widget surfaces their identity to the agent (signed token, never the raw email).
* Adds the `lookup_order` tool: the agent can fetch the visitor's recent orders, status, items, and tracking on demand via a new plugin REST endpoint (`/wp-json/pitchbar/v1/orders/lookup`).
* Test connection flow now picks up the per-token shopper signing secret automatically — no extra config.

= 1.0.1 =
* Adds WordPress post / page sync as a Pitchbar knowledge source.
* Adds WooCommerce product sync with automatic ecommerce vertical switch and product card emission in chat replies.
* Sync now buttons added under Settings → Pitchbar with delta hooks on `save_post` / `woocommerce_update_product` / delete events.

= 1.0.0 =
* Initial release. Widget embed + connection settings.
