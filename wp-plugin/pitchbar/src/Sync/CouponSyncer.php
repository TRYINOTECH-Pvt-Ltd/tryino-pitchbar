<?php

namespace Pitchbar\Sync;

use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Support\Logger;
use WC_Coupon;
use WP_Post;

/**
 * Snapshots the WooCommerce store's currently-valid coupons and POSTs
 * the list to `/v1/wp/coupons/sync` on Pitchbar so the LLM prompt
 * fragment can mention them by name without inventing codes.
 *
 * Called automatically after every successful product sync. Cheap —
 * one extra HTTP round-trip per full product sync; no per-turn cost.
 */
final class CouponSyncer
{
    public const MAX_COUPONS = 50;

    /**
     * @return array{ok: bool, count: int, error: string|null}
     */
    public function run(): array
    {
        // `wc_get_coupons()` is NOT a public WooCommerce function on
        // every release — confirmed missing in some 3.x/4.x branches
        // and removed from the plugin's public surface even where it
        // exists. We enumerate the `shop_coupon` CPT directly and
        // hydrate each id into a `WC_Coupon`, which IS a stable
        // public class.
        if (! class_exists('WooCommerce') || ! class_exists('WC_Coupon')) {
            return ['ok' => false, 'count' => 0, 'error' => 'woocommerce_inactive'];
        }

        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return ['ok' => false, 'count' => 0, 'error' => 'plugin_unconfigured'];
        }

        $client = new PitchbarClient(
            (string) $plugin->setting('base_url'),
            (string) $plugin->setting('api_token')
        );
        $agentId = (string) $plugin->setting('agent_id');
        $siteUrl = home_url('/');

        $couponPosts = $this->loadCouponPosts(self::MAX_COUPONS);

        $payload = [];
        foreach ($couponPosts as $post) {
            $coupon = $this->hydrate($post);
            if ($coupon === null) {
                continue;
            }
            $row = $this->serialize($coupon);
            if ($row === null) {
                continue;
            }
            $payload[] = $row;
        }

        $result = $client->couponsSync([
            'agent_id' => $agentId,
            'site_url' => $siteUrl,
            'plugin_version' => PITCHBAR_PLUGIN_VERSION,
            'coupons' => $payload,
        ]);

        if (! $result['ok']) {
            Logger::warn('CouponSyncer failed', [
                'status' => $result['status'],
                'error' => $result['error'],
            ]);

            return ['ok' => false, 'count' => 0, 'error' => $result['error']];
        }

        return ['ok' => true, 'count' => count($payload), 'error' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize(WC_Coupon $coupon): ?array
    {
        $code = (string) $coupon->get_code();
        if ($code === '') {
            return null;
        }

        $expires = $coupon->get_date_expires();
        if ($expires !== null && $expires->getTimestamp() < time()) {
            return null;
        }

        $usageLimit = (int) $coupon->get_usage_limit();
        $used = (int) $coupon->get_usage_count();
        if ($usageLimit > 0 && $used >= $usageLimit) {
            return null;
        }

        $type = (string) $coupon->get_discount_type();
        $amount = (string) $coupon->get_amount();
        $label = $this->humanLabel($type, $amount);

        return [
            'code' => strtoupper($code),
            'label' => $label,
            'discount' => $amount.($type === 'percent' ? '%' : ''),
            'expires_at' => $expires !== null ? $expires->date('c') : null,
        ];
    }

    /**
     * @return list<WP_Post>
     */
    private function loadCouponPosts(int $limit): array
    {
        if (! function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);
        if (! is_array($posts)) {
            return [];
        }
        $out = [];
        foreach ($posts as $post) {
            if ($post instanceof WP_Post) {
                $out[] = $post;
            }
        }

        return $out;
    }

    private function hydrate(WP_Post $post): ?WC_Coupon
    {
        try {
            $coupon = new WC_Coupon((int) $post->ID);
            if ((string) $coupon->get_code() === '') {
                return null;
            }

            return $coupon;
        } catch (\Throwable $e) {
            Logger::warn('CouponSyncer hydrate failed', [
                'post_id' => $post->ID,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function humanLabel(string $type, string $amount): string
    {
        switch ($type) {
            case 'percent':
                return $amount.'% off';
            case 'fixed_cart':
                return $amount.' off your order';
            case 'fixed_product':
                return $amount.' off each qualifying item';
            default:
                return 'Discount available';
        }
    }
}
