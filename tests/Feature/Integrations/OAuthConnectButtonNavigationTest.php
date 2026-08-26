<?php

/*
 * Static guard: the Notion + Google OAuth Connect/Reconnect buttons
 * in /app/integrations must trigger a TOP-LEVEL navigation, never an
 * Inertia router.visit (which is XHR under the hood). The OAuth
 * `/app/oauth/{provider}/connect` endpoint returns a 302 redirect to
 * accounts.google.com / api.notion.com; following that via XHR
 * trips CORS and breaks the connect flow. Buyer report 2026-05-29.
 *
 * This test fails LOUDLY if anyone re-introduces `router.visit(startNotion.url())`
 * or `router.visit(startGoogle.url())` in the integrations page.
 */

test('integrations page uses window.location.href for the Notion OAuth start', function () {
    $contents = file_get_contents(resource_path('js/pages/app/integrations/index.tsx'));
    // Prettier may wrap the assignment across two lines; match both
    // forms with a tolerant regex.
    expect((bool) preg_match('/window\.location\.href\s*=\s*startNotion\.url\(\);/', $contents))
        ->toBeTrue();
    expect($contents)->not->toContain('router.visit(startNotion.url())');
});

test('integrations page uses window.location.href for the Google OAuth start', function () {
    $contents = file_get_contents(resource_path('js/pages/app/integrations/index.tsx'));
    expect((bool) preg_match('/window\.location\.href\s*=\s*startGoogle\.url\(\);/', $contents))
        ->toBeTrue();
    expect($contents)->not->toContain('router.visit(startGoogle.url())');
});
