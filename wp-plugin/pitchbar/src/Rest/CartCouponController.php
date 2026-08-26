<?php

namespace Pitchbar\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Pitchbar -> Plugin callback for the visitor's Apply-coupon click on
 * a `<coupon/>` chat block. WC's apply_coupon ordinarily runs in the
 * cart-page context, which a REST request doesn't have. We stash the
 * code in a per-conversation transient that a tiny front-end hook
 * picks up on the visitor's NEXT page load (or immediately if the
 * cart is already loaded), then calls $cart->apply_coupon().
 */
final class CartCouponController extends RestController
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::NAMESPACE, '/cart/coupon', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });

        // Front-end pickup: if the visitor has a pending coupon code
        // stashed for their conversation, apply it on cart load.
        add_action('woocommerce_load_cart_from_session', [$this, 'applyPendingCoupon']);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $verify = $this->verifyOrReject($request);
        if ($verify instanceof WP_REST_Response) {
            return $verify;
        }

        if (! class_exists('WooCommerce')) {
            return $this->badRequest('woocommerce_inactive', 'WooCommerce is not active.');
        }

        $body = json_decode($verify, true);
        if (! is_array($body)) {
            return $this->badRequest('invalid_body', 'Body must be JSON.');
        }

        $code = isset($body['code']) ? sanitize_text_field((string) $body['code']) : '';
        $conversationId = isset($body['conversation_id']) ? sanitize_text_field((string) $body['conversation_id']) : '';

        if ($code === '') {
            return $this->badRequest('missing_code', 'Coupon code is required.');
        }
        if ($conversationId === '') {
            return $this->badRequest('missing_conversation_id', 'conversation_id is required.');
        }

        $coupon = new \WC_Coupon($code);
        if (! $coupon->get_id()) {
            return $this->badRequest('invalid_coupon', 'Coupon code does not exist or has expired.');
        }

        // 15-minute pickup window — long enough for the visitor to
        // click through to the cart, short enough that a stale code
        // doesn't haunt later sessions.
        set_transient(
            'pitchbar_pending_coupon_'.$conversationId,
            $code,
            15 * MINUTE_IN_SECONDS
        );

        return $this->ok([
            'applied' => false,
            'pending' => true,
            'message' => 'Coupon staged. It will apply when the visitor opens their cart.',
        ]);
    }

    /**
     * Hooked to `woocommerce_load_cart_from_session`. Looks at every
     * `pitchbar_pending_coupon_*` transient for this visitor's known
     * conversation IDs (stored in a per-visitor cookie) and applies
     * the first matching code.
     *
     * To keep the front-end lookup cheap we accept ALL transients of
     * this prefix that exist; the visitor only ever has 0-1 pending
     * at a time so the scan is O(1) in practice.
     */
    public function applyPendingCoupon(): void
    {
        if (! function_exists('WC') || WC()->cart === null) {
            return;
        }

        $cookie = $_COOKIE['pitchbar_conv_id'] ?? '';
        $cookie = is_string($cookie) ? sanitize_text_field(wp_unslash($cookie)) : '';
        if ($cookie === '') {
            return;
        }

        $code = get_transient('pitchbar_pending_coupon_'.$cookie);
        if (! is_string($code) || $code === '') {
            return;
        }

        if (WC()->cart->has_discount($code)) {
            return;
        }

        WC()->cart->apply_coupon($code);
        delete_transient('pitchbar_pending_coupon_'.$cookie);
    }
}
