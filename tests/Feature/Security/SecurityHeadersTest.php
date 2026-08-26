<?php

/**
 * Pins the defence-in-depth headers AddSecurityHeaders emits on
 * every web response. These are layered on top of the controller-
 * level fixes (SafeMarkdown for KB pages, Origin re-check for
 * widget endpoints) — the headers stop the BROWSER from honouring
 * a future XSS sink before any new sink even has a chance to slip
 * past the controller guards.
 */
test('marketing homepage carries a CSP header', function () {
    $response = $this->get('/');

    $response->assertOk();
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("default-src 'self'");
    expect($csp)->toContain("frame-ancestors 'self'");
    expect($csp)->toContain("object-src 'none'");
});

test('every web response carries X-Content-Type-Options: nosniff', function () {
    $response = $this->get('/');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('every web response carries a Referrer-Policy header', function () {
    $response = $this->get('/');

    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

test('CSP header is appended only once even on repeated requests', function () {
    // Sanity check that the middleware doesn't accumulate multiple
    // CSP headers across requests under Octane (header dedup).
    $first = $this->get('/');
    $second = $this->get('/');

    expect($first->headers->all('Content-Security-Policy'))->toHaveCount(1);
    expect($second->headers->all('Content-Security-Policy'))->toHaveCount(1);
});
