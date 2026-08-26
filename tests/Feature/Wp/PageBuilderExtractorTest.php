<?php

use Pitchbar\Sync\PageBuilderContent;
use Pitchbar\Sync\PostContentExtractor;
use Tests\Support\WordPressStubs;

/**
 * Battle-test for the v2.0.0 hardening: the post extractor now has to
 * survive page-builder content (Elementor postmeta, Bricks, Beaver,
 * Oxygen) where `post_content` is empty, and it has to call
 * `setup_postdata` so third-party `the_content` filters see a valid
 * `$post` global before they run.
 *
 * Catches: silent empty-content regressions (the v1.x bug that shipped
 * Elementor pages as zero-content documents into the RAG store).
 */
beforeEach(function () {
    require_once dirname(__DIR__, 2).'/Support/WordPressStubs.php';
    WordPressStubs::install();
    WordPressStubs::resetOptions();

    $pluginDir = dirname(__DIR__, 2).'/../wp-plugin/pitchbar/';
    spl_autoload_register(function ($class) use ($pluginDir) {
        if (strpos($class, 'Pitchbar\\') !== 0) {
            return;
        }
        $relative = substr($class, strlen('Pitchbar\\'));
        $path = $pluginDir.'src/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });

    $GLOBALS['__pitchbar_test_postmeta'] = [];
    $GLOBALS['__pitchbar_test_setup_postdata_calls'] = [];
    $GLOBALS['__pitchbar_test_reset_postdata_calls'] = 0;
    $GLOBALS['__pitchbar_test_filters'] = [];
});

test('PageBuilderContent detects Elementor when _elementor_edit_mode = builder', function () {
    $post = new WP_Post;
    $post->ID = 100;
    $GLOBALS['__pitchbar_test_postmeta'][100] = ['_elementor_edit_mode' => 'builder'];

    $builder = new PageBuilderContent;

    // Without Elementor's classes loaded, isElementor() short-circuits
    // to false because the helper guards on `class_exists`. That's the
    // contract — never call into a class that doesn't exist.
    expect($builder->detectBuilder($post))->toBeNull();
});

test('PageBuilderContent detects Divi via _et_pb_use_builder = on', function () {
    $post = new WP_Post;
    $post->ID = 200;
    $GLOBALS['__pitchbar_test_postmeta'][200] = ['_et_pb_use_builder' => 'on'];

    expect((new PageBuilderContent)->detectBuilder($post))->toBe('divi');
});

test('PageBuilderContent returns null for plain Gutenberg posts', function () {
    $post = new WP_Post;
    $post->ID = 300;

    expect((new PageBuilderContent)->detectBuilder($post))->toBeNull();
});

test('PostContentExtractor calls setup_postdata before applying the_content filter', function () {
    $post = new WP_Post;
    $post->ID = 42;
    $post->post_title = 'A post';
    $post->post_content = '<p>Hello world</p>';
    $post->post_excerpt = 'Hi.';
    $post->post_type = 'post';
    $post->post_modified = '2026-05-11 00:00:00';

    $extractor = new PostContentExtractor;
    $extractor->extract($post);

    expect($GLOBALS['__pitchbar_test_setup_postdata_calls'])->toBe([42]);
    expect($GLOBALS['__pitchbar_test_reset_postdata_calls'])->toBeGreaterThan(0);
});

test('PostContentExtractor exposes content through pitchbar_post_content_html filter so users can override', function () {
    $post = new WP_Post;
    $post->ID = 7;
    $post->post_title = 'Filtered';
    $post->post_content = '<p>raw</p>';
    $post->post_modified = '2026-05-11 00:00:00';

    $GLOBALS['__pitchbar_test_filters']['pitchbar_post_content_html'] = [
        function ($html, $postArg, $builder) {
            return $html.' [filtered]';
        },
    ];

    $payload = (new PostContentExtractor)->extract($post);
    expect($payload['content_html'])->toContain('[filtered]');
});

test('PostContentExtractor restores $GLOBALS[post] even after a throwing the_content filter', function () {
    $post = new WP_Post;
    $post->ID = 99;
    $post->post_content = '<p>x</p>';
    $post->post_modified = '2026-05-11 00:00:00';

    $previous = (object) ['ID' => 'outer-marker'];
    $GLOBALS['post'] = $previous;

    $GLOBALS['__pitchbar_test_filters']['the_content'] = [
        function ($html) {
            throw new RuntimeException('boom');
        },
    ];

    try {
        (new PostContentExtractor)->extract($post);
    } catch (RuntimeException $e) {
        // Expected.
    }

    // The finally block must restore the original $GLOBALS['post'].
    expect($GLOBALS['post'])->toBe($previous);
});
