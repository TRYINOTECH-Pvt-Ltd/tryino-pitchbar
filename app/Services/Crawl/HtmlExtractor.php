<?php

namespace App\Services\Crawl;

class HtmlExtractor
{
    /**
     * Element classes/ids whose contents we drop wholesale: pure UI chrome
     * that adds noise to embeddings without helping retrieval.
     */
    private const CHROME_PATTERNS = [
        'cart', 'compare', 'breadcrumb', 'menu', 'navigation', 'navbar',
        'sidebar', 'drawer', 'modal', 'share', 'social', 'cookie', 'consent',
        'banner', 'popup', 'toolbar', 'pagination', 'related', 'recommended',
        'similar-product', 'similar-products', 'review-form', 'comment-form',
        'subscribe', 'newsletter', 'skip-link', 'announcement',
    ];

    /**
     * Material Icons / Material Symbols ligature names with underscores. These
     * never appear in natural English ("shopping_basket"), so they're safe to
     * strip wholesale. Single-word icons like "search", "menu", "home" are NOT
     * stripped — they collide with real product copy. We rely on chrome-class
     * blocks (CHROME_PATTERNS) to drop the elements that contain them.
     */
    private const ICON_LIGATURES = [
        'shopping_basket', 'shopping_cart', 'add_shopping_cart',
        'library_add', 'library_add_check', 'library_books',
        'bookmark_border', 'bookmark_add',
        'favorite_border', 'star_border', 'star_rate',
        'menu_open', 'expand_more', 'expand_less',
        'chevron_left', 'chevron_right',
        'arrow_back', 'arrow_forward', 'arrow_drop_down', 'arrow_drop_up',
        'keyboard_arrow_down', 'keyboard_arrow_up',
        'account_circle', 'person_outline',
        'notifications_none',
        'filter_list', 'view_list', 'view_module',
        'visibility_off', 'delete_outline', 'content_copy',
        'local_shipping', 'local_offer', 'verified_user',
        'access_time', 'location_on',
        'help_outline', 'info_outline',
        'error_outline', 'check_circle',
        'file_download', 'file_upload',
        'cloud_upload', 'cloud_download',
        'fullscreen_exit', 'zoom_in', 'zoom_out',
        'attach_file', 'video_library',
        'play_arrow', 'skip_previous', 'skip_next',
        'volume_up', 'volume_off', 'volume_mute',
    ];

    /**
     * Common e-commerce / chrome phrases that survive even after element
     * stripping (because they live in inline buttons/links). Killed at the
     * text level.
     */
    private const TEXT_NOISE_PATTERNS = [
        '/\bAdd to (?:cart|bag|wishlist|compare|favourites?|favorites?)\b/i',
        '/\bRemove from (?:cart|wishlist|compare)\b/i',
        '/\bCompare Product\b/i',
        '/\bBuy Now\b/i',
        '/\bYOUR CART\b/i',
        '/\bCart\s*\d+\b/i',
        '/\bCompare\s*\d+\b/i',
        '/\bWishlist\s*\d+\b/i',
        '/\b0\s*items?\b/i',
        '/\bContinue Shopping\b/i',
        '/\bWrite a Review\b/i',
        '/\bAsk Question\b/i',
        '/\bShare:\s*/i',
        '/\bSubscribe to (?:our )?newsletter\b/i',
    ];

    /**
     * Strip nav/footer/script/style elements and return whitespace-collapsed
     * main text. Returns ['title' => string|null, 'text' => string].
     *
     * @return array{title: ?string, text: string}
     */
    public function extract(string $html): array
    {
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5);
        }

        // 1. Drop entire noisy regions by tag.
        $stripped = preg_replace([
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<nav\b[^>]*>.*?<\/nav>/is',
            '/<footer\b[^>]*>.*?<\/footer>/is',
            '/<header\b[^>]*>.*?<\/header>/is',
            '/<form\b[^>]*>.*?<\/form>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<aside\b[^>]*>.*?<\/aside>/is',
            '/<button\b[^>]*>.*?<\/button>/is',
            '/<svg\b[^>]*>.*?<\/svg>/is',
            '/<select\b[^>]*>.*?<\/select>/is',
        ], ' ', $html) ?? $html;

        // 2. Drop elements whose class or id matches known UI-chrome patterns.
        // Iterate to handle nested chrome (e.g. <div class="sidebar"><div class="menu">…).
        $stripped = $this->dropChromeBlocks($stripped);

        // 3. Strip remaining tags and collapse whitespace.
        $text = trim(html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // 4. Strip Material Icons ligatures and e-commerce phrases at the text level.
        $iconRegex = '/\b(?:'.implode('|', array_map('preg_quote', self::ICON_LIGATURES)).')\b/i';
        $text = preg_replace($iconRegex, ' ', $text) ?? $text;
        foreach (self::TEXT_NOISE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        // 5. Final whitespace collapse.
        $text = preg_replace('/\s+/u', ' ', trim((string) $text)) ?? '';

        return ['title' => $title, 'text' => (string) $text];
    }

    private function dropChromeBlocks(string $html): string
    {
        $needle = implode('|', array_map('preg_quote', self::CHROME_PATTERNS));
        $tags = 'div|section|ul|ol|aside|article';

        // Match an opening tag whose class/id contains a chrome word, then
        // its matching close tag. Non-greedy across DOTALL for inner content.
        // Iterate so nested chrome is handled inside-out.
        $pattern = '/<(?<tag>'.$tags.')\b[^>]*\b(?:class|id)\s*=\s*["\'][^"\']*\b(?:'.$needle.')\b[^"\']*["\'][^>]*>(?:(?!<\g{tag}\b).)*?<\/\g{tag}>/is';

        for ($i = 0; $i < 6; $i++) {
            $next = preg_replace($pattern, ' ', $html);
            if ($next === null || $next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }
}
