<?php

namespace Pitchbar\Sync;

use Pitchbar\Admin\SyncStatusColumn;
use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Settings\SettingsPage;
use Pitchbar\Support\Logger;
use WC_Product;

/**
 * Full WooCommerce catalog sync. Pages `wc_get_products` in batches
 * of 50 across every product type WooCommerce knows about — simple,
 * variable, grouped, external, plus subscription, bundle, membership,
 * booking, and any custom type that extensions add. Skips drafts.
 * POSTs each page to `/v1/wp/products/sync`.
 *
 * Resumable: see PostSyncer for the time-budget rationale. Each batch
 * is idempotent on `external_id`, so re-runs are safe.
 *
 * Falls back to a raw `WP_Query` on `post_type=product` when
 * `wc_get_products` returns 0 — some hosts (multilingual scopes,
 * subscription-extension gates) hook the WC query in ways that hide
 * the entire catalog.
 */
final class ProductSyncer
{
    public const BATCH_SIZE = 50;

    public const TIME_BUDGET_SECONDS = 20;

    public const RESUME_TRANSIENT = 'pitchbar_product_sync_resume';

    /** @var ProductContentExtractor */
    private $extractor;

    /** @var int|null */
    private $startedAt = null;

    public function __construct(?ProductContentExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new ProductContentExtractor;
    }

    /**
     * @return array{ok: bool, batches: int, products: int, queued: int, skipped: int, resumed: bool, more: bool, next_page: int|null, errors: list<string>}
     */
    public function runFullSync(): array
    {
        if (! class_exists('WooCommerce') || ! function_exists('wc_get_products')) {
            return $this->failure(__('WooCommerce is not active.', 'pitchbar'));
        }

        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return $this->failure(__('Plugin is not configured.', 'pitchbar'));
        }

        $client = new PitchbarClient(
            (string) $plugin->setting('base_url'),
            (string) $plugin->setting('api_token')
        );
        $agentId = (string) $plugin->setting('agent_id');
        $siteUrl = home_url('/');

        $startPage = $this->resolveStartPage();
        $resumed = $startPage > 1;
        $page = $startPage;
        $batches = 0;
        $totalProducts = 0;
        $totalQueued = 0;
        $totalSkipped = 0;
        $errors = [];
        $more = false;
        $nextPage = null;

        $this->startedAt = $this->now();

        do {
            // No `type` filter. Pre-fix we limited to
            // [simple, variable, grouped, external], which silently dropped
            // subscription, bundle, membership, booking, and any custom
            // WooCommerce product types — common on real stores. The
            // buyer's site had 10 products but the syncer returned 0.
            // We still filter by status='publish' so drafts stay private.
            $products = wc_get_products([
                'status' => 'publish',
                'limit' => self::BATCH_SIZE,
                'page' => $page,
                'paginate' => false,
                'orderby' => 'ID',
                'order' => 'ASC',
                'return' => 'objects',
            ]);

            if (! is_array($products) || $products === []) {
                // Fallback: some hosts / WC versions ignore certain
                // wc_get_products args. Re-try via raw WP_Query in case
                // the first call missed everything on page 1.
                if ($page === $startPage) {
                    $products = $this->fallbackProductLookup($page);
                }
                if (! is_array($products) || $products === []) {
                    break;
                }
            }

            $batch = [];
            foreach ($products as $product) {
                if (! $product instanceof WC_Product) {
                    continue;
                }
                $batch[] = $this->extractor->extract($product);
            }

            if ($batch === []) {
                break;
            }

            $result = $client->productsSync([
                'agent_id' => $agentId,
                'site_url' => $siteUrl,
                'plugin_version' => PITCHBAR_PLUGIN_VERSION,
                'products' => $batch,
            ]);

            $batches++;
            $totalProducts += count($batch);

            if (! $result['ok']) {
                $errors[] = sprintf(
                    /* translators: 1: page number, 2: error message */
                    __('Batch %1$d failed: %2$s', 'pitchbar'),
                    $page,
                    (string) ($result['error'] ?? __('unknown error', 'pitchbar'))
                );
                Logger::warn('ProductSyncer batch failed', ['page' => $page, 'error' => $result['error']]);
            } else {
                $totalQueued += (int) ($result['data']['queued'] ?? 0);
                $totalSkipped += (int) ($result['data']['skipped_unchanged'] ?? 0);
                // Stamp sync meta on each product so the admin Products
                // list table renders an "Indexed" badge. Same fields as
                // PostSyncer — the admin column treats both uniformly.
                $now = time();
                foreach ($batch as $entry) {
                    $wpId = (int) ($entry['wp_id'] ?? 0);
                    if ($wpId > 0) {
                        update_post_meta($wpId, SyncStatusColumn::META_SYNCED_AT, $now);
                        update_post_meta($wpId, SyncStatusColumn::META_SYNCED_HASH, (string) ($entry['content_hash'] ?? ''));
                    }
                }
            }

            $pageWasFull = count($products) === self::BATCH_SIZE;
            $page++;

            if ($this->budgetExceeded() && $pageWasFull) {
                $more = true;
                $nextPage = $page;
                $this->persistResume($nextPage);
                $this->scheduleResume();
                break;
            }

            if (! $pageWasFull) {
                break;
            }
        } while (true);

        // Coupon snapshot rides along after the product catalog completes
        // — but only when we actually finished, not when we resumed-out
        // mid-stream. Best-effort: failure does NOT fail the product run.
        if (! $more && $errors === []) {
            $couponResult = (new CouponSyncer)->run();
            if (! $couponResult['ok']) {
                Logger::info('CouponSyncer skipped or failed', $couponResult);
            }
        }

        if (! $more) {
            $this->clearResume();
        }

        return [
            'ok' => $errors === [],
            'batches' => $batches,
            'products' => $totalProducts,
            'queued' => $totalQueued,
            'skipped' => $totalSkipped,
            'resumed' => $resumed,
            'more' => $more,
            'next_page' => $nextPage,
            'errors' => $errors,
        ];
    }

    private function resolveStartPage(): int
    {
        if (! function_exists('get_transient')) {
            return 1;
        }
        $stored = get_transient(self::RESUME_TRANSIENT);
        if (! is_array($stored) || ! isset($stored['page'])) {
            return 1;
        }
        $page = (int) $stored['page'];

        return $page > 1 ? $page : 1;
    }

    private function persistResume(int $page): void
    {
        if (! function_exists('set_transient')) {
            return;
        }
        set_transient(self::RESUME_TRANSIENT, ['page' => $page, 'at' => time()], HOUR_IN_SECONDS);
    }

    private function clearResume(): void
    {
        if (! function_exists('delete_transient')) {
            return;
        }
        delete_transient(self::RESUME_TRANSIENT);
    }

    private function scheduleResume(): void
    {
        if (! function_exists('wp_schedule_single_event') || ! function_exists('wp_next_scheduled')) {
            return;
        }
        $hook = SettingsPage::SCHEDULED_PRODUCT_SYNC_HOOK;
        if (wp_next_scheduled($hook)) {
            return;
        }
        wp_schedule_single_event(time() + 30, $hook);
    }

    private function budgetExceeded(): bool
    {
        if ($this->startedAt === null) {
            return false;
        }

        return ($this->now() - $this->startedAt) >= self::TIME_BUDGET_SECONDS;
    }

    private function now(): int
    {
        return time();
    }

    /**
     * Last-ditch product lookup. `wc_get_products` honours plugin filters
     * (subscription gates, multilingual scopes, custom-types-of-the-month)
     * that can hide the whole catalog. WP_Query against `post_type=product`
     * with no extra hooks is the floor — if THIS returns 0 the site
     * genuinely has no published products.
     *
     * @return array<int, WC_Product>
     */
    private function fallbackProductLookup(int $page): array
    {
        if (! function_exists('wc_get_product')) {
            return [];
        }

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        $ids = is_array($query->posts) ? $query->posts : [];
        wp_reset_postdata();

        if ($ids === []) {
            return [];
        }

        Logger::warn('ProductSyncer used fallback WP_Query', [
            'page' => $page,
            'count' => count($ids),
            'reason' => 'wc_get_products returned 0',
        ]);

        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product instanceof WC_Product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return array{ok: bool, batches: int, products: int, queued: int, skipped: int, resumed: bool, more: bool, next_page: int|null, errors: list<string>}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'batches' => 0,
            'products' => 0,
            'queued' => 0,
            'skipped' => 0,
            'resumed' => false,
            'more' => false,
            'next_page' => null,
            'errors' => [$error],
        ];
    }
}
