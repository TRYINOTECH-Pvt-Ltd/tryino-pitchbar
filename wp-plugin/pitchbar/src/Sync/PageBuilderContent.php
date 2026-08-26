<?php

namespace Pitchbar\Sync;

use WP_Post;

/**
 * Resolves the visible HTML for a post when a page builder owns the
 * layout. Most builders stash the page tree in postmeta and leave
 * `post_content` empty (Elementor, Oxygen, Bricks) or as a shortcode
 * stub (Divi, Beaver). Each builder ships a public renderer the
 * server can call to materialize the same HTML the browser sees.
 *
 * The order matters: detection is based on postmeta keys the builder
 * itself sets, so a site that disables a builder mid-flight (e.g.
 * Divi → Elementor migration) doesn't double-render. First match wins.
 */
final class PageBuilderContent
{
    /**
     * Returns the rendered HTML if a known builder owns the post, or
     * an empty string when the regular `post_content` path should
     * handle extraction.
     */
    public function renderIfBuilder(WP_Post $post): string
    {
        $postId = (int) $post->ID;
        if ($postId <= 0) {
            return '';
        }

        if ($this->isElementor($postId)) {
            $html = $this->renderElementor($postId);
            if ($html !== '') {
                return $html;
            }
        }

        if ($this->isBeaverBuilder($postId)) {
            $html = $this->renderBeaverBuilder($postId);
            if ($html !== '') {
                return $html;
            }
        }

        if ($this->isOxygen($postId)) {
            $html = $this->renderOxygen($postId);
            if ($html !== '') {
                return $html;
            }
        }

        if ($this->isBricks($postId)) {
            $html = $this->renderBricks($postId);
            if ($html !== '') {
                return $html;
            }
        }

        if ($this->isDivi($postId)) {
            // Divi renders through `the_content` filter once
            // `setup_postdata` is called, so the caller's regular
            // expansion path handles it. We just signal "I am Divi"
            // so the caller can keep its filter expectations.
            return '';
        }

        return '';
    }

    public function detectBuilder(WP_Post $post): ?string
    {
        $postId = (int) $post->ID;
        if ($postId <= 0) {
            return null;
        }
        if ($this->isElementor($postId)) {
            return 'elementor';
        }
        if ($this->isBeaverBuilder($postId)) {
            return 'beaver';
        }
        if ($this->isOxygen($postId)) {
            return 'oxygen';
        }
        if ($this->isBricks($postId)) {
            return 'bricks';
        }
        if ($this->isDivi($postId)) {
            return 'divi';
        }

        return null;
    }

    private function isElementor(int $postId): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }
        $mode = (string) get_post_meta($postId, '_elementor_edit_mode', true);

        return $mode === 'builder' && class_exists('\\Elementor\\Plugin');
    }

    private function renderElementor(int $postId): string
    {
        try {
            $elementor = call_user_func(['\\Elementor\\Plugin', 'instance']);
            if (! is_object($elementor) || ! isset($elementor->frontend)) {
                return '';
            }
            $frontend = $elementor->frontend;
            if (! is_object($frontend) || ! method_exists($frontend, 'get_builder_content_for_display')) {
                return '';
            }

            return (string) $frontend->get_builder_content_for_display($postId, true);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function isBeaverBuilder(int $postId): bool
    {
        if (! function_exists('get_post_meta') || ! class_exists('FLBuilderModel')) {
            return false;
        }

        return (string) get_post_meta($postId, '_fl_builder_enabled', true) === '1';
    }

    private function renderBeaverBuilder(int $postId): string
    {
        if (! class_exists('FLBuilder') || ! method_exists('FLBuilder', 'render_content_by_id')) {
            return '';
        }
        try {
            return (string) call_user_func(['FLBuilder', 'render_content_by_id'], $postId);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function isOxygen(int $postId): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }
        $shortcodes = get_post_meta($postId, 'ct_builder_shortcodes', true);

        return ! empty($shortcodes) && function_exists('do_shortcode');
    }

    private function renderOxygen(int $postId): string
    {
        if (! function_exists('get_post_meta') || ! function_exists('do_shortcode')) {
            return '';
        }
        $shortcodes = (string) get_post_meta($postId, 'ct_builder_shortcodes', true);
        if ($shortcodes === '') {
            return '';
        }

        return (string) do_shortcode($shortcodes);
    }

    private function isBricks(int $postId): bool
    {
        if (! function_exists('get_post_meta') || ! defined('BRICKS_VERSION')) {
            return false;
        }
        $payload = get_post_meta($postId, '_bricks_page_content_2', true);

        return ! empty($payload);
    }

    private function renderBricks(int $postId): string
    {
        if (! class_exists('\\Bricks\\Frontend')
            || ! method_exists('\\Bricks\\Frontend', 'render_content')
        ) {
            return '';
        }
        try {
            $payload = get_post_meta($postId, '_bricks_page_content_2', true);
            if (empty($payload) || ! is_array($payload)) {
                return '';
            }

            return (string) call_user_func(['\\Bricks\\Frontend', 'render_content'], $payload);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function isDivi(int $postId): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }

        return (string) get_post_meta($postId, '_et_pb_use_builder', true) === 'on';
    }
}
