<?php

namespace Pitchbar\Sync;

use WP_Post;

/**
 * Turns a WordPress post into the shape Pitchbar's sync endpoint
 * expects: HTML content fully expanded (page-builder layouts, Gutenberg
 * blocks, and shortcodes resolved), excerpt resolved, taxonomy terms
 * flattened, and a stable content hash computed off the normalized
 * text.
 *
 * Page-builder posts (Elementor, Divi, Beaver, Oxygen, Bricks) leave
 * `post_content` empty or as a stub — `PageBuilderContent` calls the
 * builder's native renderer first, and we fall back to the regular
 * `the_content` filter for vanilla Gutenberg/Classic posts.
 *
 * The HTML is sent as-is; Pitchbar strips tags server-side so future
 * tooling can preserve heading hierarchy for chunking.
 */
final class PostContentExtractor
{
    /** @var PageBuilderContent */
    private $builder;

    public function __construct(?PageBuilderContent $builder = null)
    {
        $this->builder = $builder ?? new PageBuilderContent;
    }

    /**
     * @return array<string, mixed> Shape matches `posts.*` schema in
     *                              `PostSyncController` and `posts` in
     *                              `PostDeltaController`.
     */
    public function extract(WP_Post $post): array
    {
        $contentHtml = $this->expandedContent($post);
        $excerpt = $this->resolveExcerpt($post);
        $terms = $this->collectTerms($post);

        // Synthesize a body-fallback when the page-builder pass + the
        // standard the_content filter both produced nothing. Without
        // this, posts that are featured-image-only or builder-owned
        // with empty `post_content` go out as `content_html: ""`,
        // which Laravel servers <v2.0.0 rejected via `required, string`
        // (whole batch died on "posts.N.content_html field is required").
        // Pulling title + excerpt + taxonomy into the body keeps the
        // post indexable on every server version and means the agent
        // still has SOMETHING to answer from.
        if (trim(wp_strip_all_tags($contentHtml)) === '') {
            $contentHtml = $this->synthesizeFallbackBody($post, $excerpt, $terms);
        }

        $hash = $this->hash($post->post_title, $contentHtml, $excerpt, $terms);

        return [
            'wp_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'permalink' => (string) get_permalink($post),
            'title' => $this->plainTitle($post),
            'content_html' => $contentHtml,
            'excerpt' => $excerpt,
            'content_hash' => $hash,
            'modified_at' => mysql2date('c', $post->post_modified_gmt ?: $post->post_modified, false),
            'language' => $this->detectLanguage($post),
            'taxonomy_terms' => $terms,
        ];
    }

    /**
     * Resolves the visible HTML for a post, accommodating page-builder
     * sites where `post_content` is empty. The sequence:
     *
     *   1. Ask `PageBuilderContent` — if the post is owned by Elementor,
     *      Beaver, Oxygen, or Bricks, their native renderer returns
     *      fully expanded HTML and we're done.
     *   2. Otherwise prime the post loop via `setup_postdata` so
     *      `the_content` (Divi, Yoast, embeds, jetpack, etc.) can read
     *      `$post` from the global. Critical: without this, plenty of
     *      third-party filters early-return and emit nothing.
     *   3. Run `do_blocks` → `the_content` → `do_shortcode`.
     *   4. Always restore the post loop with `wp_reset_postdata`,
     *      including on exception, so calling this in a non-loop
     *      context (Action Scheduler, cron) doesn't leak state.
     */
    private function expandedContent(WP_Post $post): string
    {
        $builderHtml = $this->builder->renderIfBuilder($post);
        if ($builderHtml !== '') {
            return apply_filters('pitchbar_post_content_html', $builderHtml, $post, $this->builder->detectBuilder($post));
        }

        $raw = (string) $post->post_content;

        // Some themes/plugins hook `the_content` with global-$post
        // dependencies. Mirror what `the_loop` does — set up postdata
        // around the filter chain, then tear it down even if a hooked
        // callback throws.
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        if (function_exists('setup_postdata')) {
            setup_postdata($post);
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

        return apply_filters('pitchbar_post_content_html', (string) $expanded, $post, null);
    }

    /**
     * @param  list<string>  $terms
     */
    private function synthesizeFallbackBody(WP_Post $post, string $excerpt, array $terms): string
    {
        $parts = [];
        $title = $this->plainTitle($post);
        if ($title !== '') {
            $parts[] = '<p>'.esc_html($title).'</p>';
        }
        if ($excerpt !== '') {
            $parts[] = '<p>'.esc_html($excerpt).'</p>';
        }
        if ($terms !== []) {
            $parts[] = '<p>'.esc_html(implode(', ', $terms)).'</p>';
        }
        if ($parts === []) {
            // Last resort: emit a sentinel so the field is never empty.
            // Title-less, body-less, term-less posts are exotic but exist
            // (auto-generated stubs, archive landing pages).
            $parts[] = '<p>(no body)</p>';
        }

        return implode("\n", $parts);
    }

    private function resolveExcerpt(WP_Post $post): string
    {
        $excerpt = (string) $post->post_excerpt;
        if ($excerpt !== '') {
            return wp_strip_all_tags($excerpt);
        }
        // Fall back to a short autogenerated excerpt off the content.
        $clean = wp_strip_all_tags($post->post_content);
        $words = preg_split('/\s+/', trim($clean));
        if (! is_array($words) || count($words) === 0) {
            return '';
        }

        return implode(' ', array_slice($words, 0, 40));
    }

    /**
     * @return list<string>
     */
    private function collectTerms(WP_Post $post): array
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'names');
        if (! is_array($taxonomies) || $taxonomies === []) {
            return [];
        }
        $out = [];
        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post, $tax);
            if (! is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $name = isset($term->name) ? (string) $term->name : '';
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function plainTitle(WP_Post $post): string
    {
        $title = (string) $post->post_title;
        $decoded = function_exists('html_entity_decode')
            ? html_entity_decode($title, ENT_QUOTES, 'UTF-8')
            : $title;

        return trim(wp_strip_all_tags($decoded));
    }

    private function detectLanguage(WP_Post $post): ?string
    {
        // Polylang + WPML expose the post language via meta or filters;
        // a thin fallback to the site's locale keeps the field useful
        // for the common case of a single-language site.
        $locale = function_exists('get_locale') ? (string) get_locale() : '';
        if ($locale === '') {
            return null;
        }

        return substr(strtolower(str_replace('_', '-', $locale)), 0, 5);
    }

    /**
     * @param  list<string>  $terms
     */
    private function hash(string $title, string $contentHtml, string $excerpt, array $terms): string
    {
        $payload = $title."\n".$contentHtml."\n".$excerpt."\n".implode(',', $terms);
        $normalized = preg_replace('/\s+/u', ' ', $payload);

        return hash('sha256', (string) $normalized);
    }
}
