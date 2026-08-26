<?php

test('GET /manifest.webmanifest returns a Web App Manifest', function () {
    $response = $this->get('/manifest.webmanifest');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonStructure(['name', 'short_name', 'start_url', 'scope', 'display', 'icons']);

    $manifest = $response->json();
    expect($manifest['display'])->toBe('standalone');
    // start_url must point at a real route. Previously `/app` (which
    // doesn't resolve) → installed PWA opened to 404.
    expect($manifest['start_url'])->toBe('/dashboard');
    // Without a custom favicon, manifest defaults to the two bundled
    // /icons/icon-{192,512}.png placeholders.
    expect($manifest['icons'])->toHaveCount(2);
    expect($manifest['icons'][0]['sizes'])->toBe('192x192');
    expect($manifest['icons'][1]['sizes'])->toBe('512x512');
});

test('manifest is reachable even when public marketing is hidden', function () {
    // D1: the route sits outside the RedirectMarketingWhenDisabled
    // group so an "internal SaaS" install (no public marketing) still
    // serves the install prompt to admins. Hard requirement — without
    // it the installed shortcut breaks the moment an operator turns
    // the marketing toggle off.
    config(['branding.marketing_site_public' => false]);

    $this->get('/manifest.webmanifest')->assertOk();
});

test('service worker file ships at /public/sw.js (web server serves it directly)', function () {
    // The SW is a static file under `public/sw.js`, served by the web
    // server (Octane / nginx / FrankenPHP), not the Laravel router.
    // Test asserts the file is present so an accidental deploy that
    // forgets it gets caught in CI before going live.
    $path = public_path('sw.js');
    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain("self.addEventListener('install'");
    expect($contents)->toContain("self.addEventListener('fetch'");
});
