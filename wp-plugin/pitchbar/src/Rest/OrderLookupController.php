<?php

namespace Pitchbar\Rest;

use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Pitchbar -> Plugin callback for the `lookup_order` tool. Returns
 * the last N WooCommerce orders belonging to a single `wp_user_id`.
 *
 * Auth: HMAC X-Pitchbar-Signature using the workspace's
 * shopper_signing_secret. No WordPress nonce / cookie auth — the
 * caller is the Pitchbar server, not a logged-in browser session.
 */
final class OrderLookupController extends RestController
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::NAMESPACE, '/orders/lookup', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $verify = $this->verifyOrReject($request);
        if ($verify instanceof WP_REST_Response) {
            return $verify;
        }

        if (! class_exists('WooCommerce') || ! function_exists('wc_get_orders')) {
            return $this->ok(['orders' => [], 'count' => 0, 'note' => 'woocommerce_inactive']);
        }

        $body = json_decode($verify, true);
        if (! is_array($body)) {
            return $this->badRequest('invalid_body', 'Body must be JSON.');
        }

        $wpUserId = isset($body['wp_user_id']) ? (int) $body['wp_user_id'] : 0;
        if ($wpUserId <= 0) {
            return $this->badRequest('missing_wp_user_id', 'wp_user_id is required.');
        }

        $limit = isset($body['limit']) ? max(1, min(10, (int) $body['limit'])) : 5;
        $orderNumber = isset($body['order_number']) ? (string) $body['order_number'] : '';

        $query = [
            'customer_id' => $wpUserId,
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $orders = wc_get_orders($query);
        if (! is_array($orders)) {
            $orders = [];
        }

        if ($orderNumber !== '') {
            $orders = array_values(array_filter($orders, function ($o) use ($orderNumber) {
                return $o instanceof WC_Order && (string) $o->get_order_number() === $orderNumber;
            }));
        }

        $rows = [];
        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }
            $rows[] = $this->serializeOrder($order);
        }

        return $this->ok([
            'orders' => $rows,
            'count' => count($rows),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $items[] = [
                'name' => (string) $item->get_name(),
                'qty' => (int) $item->get_quantity(),
                'sku' => $product !== null && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
                'total' => (string) $item->get_total(),
            ];
        }

        $created = $order->get_date_created();
        $tracking = $this->resolveTracking($order);

        return [
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'total' => (string) $order->get_total(),
            'currency' => (string) $order->get_currency(),
            'date_created' => $created !== null ? $created->date('c') : null,
            'items' => $items,
            'tracking_url' => $tracking,
            'order_url' => (string) $order->get_view_order_url(),
        ];
    }

    private function resolveTracking(WC_Order $order): string
    {
        $candidates = [
            '_aftership_tracking_url',
            '_tracking_url',
            '_st_tracking_link',
        ];
        foreach ($candidates as $metaKey) {
            $value = (string) $order->get_meta($metaKey);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
