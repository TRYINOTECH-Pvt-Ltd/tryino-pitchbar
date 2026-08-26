<?php

namespace Pitchbar\Sync;

use WC_Product;

/**
 * Converts a WC_Product into the wire payload `ProductSyncController`
 * expects. Mirrors `PostContentExtractor` for posts but operates on
 * WooCommerce's product object graph.
 *
 * Image URL: primary product image (gallery position 0) at the
 * "medium" size — large enough for a product card, small enough not
 * to bloat the sync payload.
 *
 * Attributes are flattened to "name: value, value" strings so the
 * RAG embedding captures both the attribute name (e.g. "color") and
 * its values (e.g. "blue, red") in the same vector.
 */
final class ProductContentExtractor
{
    /**
     * @return array<string, mixed> Shape matches `products.*` schema
     *                              in `ProductSyncController`.
     */
    public function extract(WC_Product $product): array
    {
        $productId = (int) $product->get_id();
        $shortHtml = $this->expand($product->get_short_description(), $productId);
        $longHtml = $this->expand($product->get_description(), $productId);

        $categories = $this->termNames($product->get_id(), 'product_cat');
        $attributes = $this->collectAttributes($product);
        $imageUrl = $this->imageUrl($product);

        $hash = $this->hash(
            (string) $product->get_name(),
            (string) $product->get_price(),
            (string) $product->get_stock_status(),
            $shortHtml,
            $longHtml,
            $categories,
            $attributes,
        );

        $modified = $product->get_date_modified();

        return [
            'wp_id' => (int) $product->get_id(),
            'sku' => (string) $product->get_sku(),
            'name' => (string) $product->get_name(),
            'permalink' => (string) $product->get_permalink(),
            'image_url' => $imageUrl,
            'short_description' => $shortHtml,
            'description' => $longHtml,
            'price' => (string) $product->get_price(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'stock_status' => (string) $product->get_stock_status(),
            'on_sale' => (bool) $product->is_on_sale(),
            'content_hash' => $hash,
            'modified_at' => $modified !== null ? $modified->date('c') : null,
            'categories' => $categories,
            'attributes' => $attributes,
        ];
    }

    private function expand(string $raw, ?int $productId = null): string
    {
        if ($raw === '') {
            return '';
        }

        // Prime the post loop with the product's underlying WP_Post so
        // `the_content` filters (Yoast, page builders, Flatsome hooks)
        // don't early-return on `is_null($post)`. Always restore.
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        if ($productId !== null && function_exists('get_post')) {
            $wpPost = get_post($productId);
            if ($wpPost !== null) {
                $GLOBALS['post'] = $wpPost;
                if (function_exists('setup_postdata')) {
                    setup_postdata($wpPost);
                }
            }
        }

        try {
            $expanded = function_exists('do_blocks') ? do_blocks($raw) : $raw;
            $expanded = function_exists('apply_filters') ? apply_filters('the_content', $expanded) : $expanded;
            $expanded = function_exists('do_shortcode') ? do_shortcode($expanded) : $expanded;
        } finally {
            $GLOBALS['post'] = $previous;
            if (function_exists('wp_reset_postdata')) {
                wp_reset_postdata();
            }
        }

        return (string) $expanded;
    }

    /**
     * @return list<string>
     */
    private function termNames(int $productId, string $taxonomy): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        if (! is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            $name = isset($term->name) ? (string) $term->name : '';
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string> Each entry like "color: blue, red".
     */
    private function collectAttributes(WC_Product $product): array
    {
        $rows = [];
        foreach ($product->get_attributes() as $attribute) {
            if (! is_object($attribute) || ! method_exists($attribute, 'get_name')) {
                continue;
            }
            $rawName = (string) $attribute->get_name();
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($rawName, $product) : $rawName;
            $label = (string) ($label ?: $rawName);

            $values = [];
            if (method_exists($attribute, 'get_options')) {
                foreach ($attribute->get_options() as $option) {
                    $values[] = is_string($option) ? $option : (string) $option;
                }
            }
            $values = array_values(array_filter(array_map('trim', $values), fn ($v) => $v !== ''));

            if ($values === []) {
                continue;
            }

            $rows[] = $label.': '.implode(', ', $values);
        }

        return $rows;
    }

    private function imageUrl(WC_Product $product): string
    {
        $imageId = (int) $product->get_image_id();
        if ($imageId === 0) {
            return '';
        }
        $src = wp_get_attachment_image_src($imageId, 'medium');
        if (! is_array($src) || ! isset($src[0])) {
            return '';
        }

        return (string) $src[0];
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $attributes
     */
    private function hash(
        string $name,
        string $price,
        string $stockStatus,
        string $shortHtml,
        string $longHtml,
        array $categories,
        array $attributes,
    ): string {
        $payload = implode("\n", [
            $name,
            $price,
            $stockStatus,
            $shortHtml,
            $longHtml,
            implode(',', $categories),
            implode(',', $attributes),
        ]);
        $normalized = preg_replace('/\s+/u', ' ', $payload);

        return hash('sha256', (string) $normalized);
    }
}
