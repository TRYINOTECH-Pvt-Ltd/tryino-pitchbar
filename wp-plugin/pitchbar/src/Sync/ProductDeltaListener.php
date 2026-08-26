<?php

namespace Pitchbar\Sync;

use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Support\Logger;
use WC_Product;

/**
 * Hooks WooCommerce write events to push single-product deltas at
 * `/v1/wp/products/changed`.
 *
 * Registered only when `class_exists('WooCommerce')` (see Plugin::boot)
 * — the file itself does nothing on non-Woo installs.
 */
final class ProductDeltaListener
{
    /** @var ProductContentExtractor */
    private $extractor;

    public function __construct(?ProductContentExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new ProductContentExtractor;
    }

    public function register(): void
    {
        add_action('woocommerce_new_product', [$this, 'onUpsert'], 20, 1);
        add_action('woocommerce_update_product', [$this, 'onUpsert'], 20, 1);
        add_action('woocommerce_delete_product', [$this, 'onDelete'], 20, 1);
        add_action('woocommerce_trash_product', [$this, 'onDelete'], 20, 1);
    }

    public function onUpsert(int $productId): void
    {
        if (! function_exists('wc_get_product')) {
            return;
        }
        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return;
        }
        if ($product->get_status() !== 'publish') {
            return;
        }

        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return;
        }

        $payload = $this->extractor->extract($product);
        $this->push('upsert', $payload);
    }

    public function onDelete(int $productId): void
    {
        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return;
        }

        $this->push('delete', ['wp_id' => $productId]);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function push(string $action, array $product): void
    {
        $plugin = Plugin::instance();
        $client = new PitchbarClient(
            (string) $plugin->setting('base_url'),
            (string) $plugin->setting('api_token'),
        );

        $result = $client->productsChanged([
            'agent_id' => (string) $plugin->setting('agent_id'),
            'site_url' => home_url('/'),
            'plugin_version' => PITCHBAR_PLUGIN_VERSION,
            'action' => $action,
            'product' => $product,
        ]);

        if (! $result['ok']) {
            Logger::warn('Product delta push failed', [
                'action' => $action,
                'product_id' => $product['wp_id'] ?? null,
                'status' => $result['status'],
                'error' => $result['error'],
            ]);
        }
    }
}
