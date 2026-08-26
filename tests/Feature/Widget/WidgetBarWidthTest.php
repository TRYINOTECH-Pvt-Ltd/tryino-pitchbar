<?php

/**
 * Regression: visitors on narrow viewports saw the widget bar clip
 * off the right edge when position was `bottom-right` (and the mirror
 * issue on `bottom-left`). The pill `<form>` had width = barWidthCss
 * AND padding = 10px 18px, but no `box-sizing: border-box`. With
 * content-box the padding adds 36px on top of the responsive width
 * cap, so on a 600px viewport the pill spilled past the viewport.
 *
 * This test guards the JS source for both:
 *   1. The responsive width cap `min(... , calc(100vw - 32px))` so
 *      the bar shrinks on narrow screens.
 *   2. `boxSizing: 'border-box'` on the pill form so padding is
 *      absorbed into the width.
 *   3. The admin Customize page still exposes a `bar_width` slider
 *      so operators can pick a max between 320–800.
 *
 * These are source-string sanity assertions because we don't run a
 * real browser DOM check in pest — the bug is a single-line CSS
 * regression, easy to catch by grep.
 */
test('widget pill form applies box-sizing border-box so padding does not overflow', function () {
    $source = file_get_contents(base_path('resources/widget/src/ui/Bar.tsx'));

    // The pill form chunk should declare border-box.
    expect($source)->toContain("boxSizing: 'border-box'");

    // The responsive width cap should still subtract 32px (16px on
    // each side) from 100vw so the bar never crosses the viewport
    // edge — even at the smallest 320px screens.
    expect($source)->toContain('calc(100vw - 32px)');
});

test('admin customize page exposes a bar width slider with the 320–800 range', function () {
    $source = file_get_contents(
        base_path('resources/js/pages/app/agents/customize.tsx'),
    );

    expect($source)->toContain('bar_width');
    expect($source)->toContain('min={320}');
    expect($source)->toContain('max={800}');
});
