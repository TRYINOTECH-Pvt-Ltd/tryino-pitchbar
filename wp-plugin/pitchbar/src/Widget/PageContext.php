<?php

namespace Pitchbar\Widget;

use WC_Product;
use WP_Post;
use WP_Term;

/**
 * Builds the page-context payload the plugin injects on every front-end
 * page. The widget's retrieval pipeline reads this from the script
 * tag's `data-page-context` attribute and prefers it over heuristic
 * DOM scraping. No PII — only public taxonomies + Woo product fields.
 */
final class PageContext
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $context = [
            'source' => 'wordpress',
            'site_url' => home_url('/'),
            'page_url' => $this->currentUrl(),
            'page_title' => $this->title(),
            'post_id' => null,
            'post_type' => null,
            'permalink' => null,
            'categories' => [],
            'tags' => [],
        ];

        $post = get_post();
        if ($post instanceof WP_Post) {
            $context['post_id'] = (int) $post->ID;
            $context['post_type'] = (string) $post->post_type;
            $context['permalink'] = (string) get_permalink($post);

            if (is_singular()) {
                $context['categories'] = $this->termSlugs($post->ID, 'category');
                $context['tags'] = $this->termSlugs($post->ID, 'post_tag');
            }
        }

        $woo = $this->wooProduct();
        if ($woo !== null) {
            $context['woo'] = $woo;
        }

        return $context;
    }

    private function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
        if ($host === '') {
            return home_url($uri);
        }

        return $scheme.'://'.$host.$uri;
    }

    private function title(): string
    {
        if (is_singular()) {
            return wp_strip_all_tags((string) get_the_title());
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();

            return $term instanceof WP_Term ? (string) $term->name : '';
        }

        return wp_strip_all_tags((string) wp_get_document_title());
    }

    /**
     * @return list<string>
     */
    private function termSlugs(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (! is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $out[] = (string) $term->slug;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function wooProduct(): ?array
    {
        if (! function_exists('is_product') || ! function_exists('wc_get_product')) {
            return null;
        }
        if (! is_product()) {
            return null;
        }

        $product = wc_get_product(get_the_ID());
        if (! $product instanceof WC_Product) {
            return null;
        }

        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';

        return [
            'id' => (int) $product->get_id(),
            'sku' => (string) $product->get_sku(),
            'name' => (string) $product->get_name(),
            'permalink' => (string) $product->get_permalink(),
            'price' => (string) $product->get_price(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'currency' => $currency,
            'stock_status' => (string) $product->get_stock_status(),
            'on_sale' => (bool) $product->is_on_sale(),
        ];
    }
}
