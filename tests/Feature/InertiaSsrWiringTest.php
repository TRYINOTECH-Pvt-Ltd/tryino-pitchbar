<?php

/**
 * Static-asserts the SSR wiring stays intact across refactors:
 *   - Inertia SSR is enabled in config.
 *   - The SSR entry file exists (so npm run build:ssr can find it).
 *   - The committed SSR bundle exists at the path PM2 expects.
 *   - The PM2 ecosystem file declares pitchbar-ssr pointing at the
 *     same bundle path with `watch` enabled.
 *
 * Smoke-test only — does NOT boot the Node SSR process. The end-to-end
 * "page actually renders as HTML" assertion lives in the ops runbook
 * (curl after PM2 reload) because spawning Node in CI is fragile.
 */
it('has Inertia SSR enabled in config', function () {
    expect(config('inertia.ssr.enabled'))->toBeTrue();
    expect(config('inertia.ssr.url'))->toBeString()->not->toBeEmpty();
});

it('ships the SSR entry source file', function () {
    $entry = base_path('resources/js/ssr.tsx');
    expect(file_exists($entry))
        ->toBeTrue('resources/js/ssr.tsx is the Vite SSR entry; build:ssr will silently emit nothing without it.');
});

it('commits the built SSR bundle so deploy needs no build step', function () {
    $bundle = base_path('bootstrap/ssr/ssr.js');
    expect(file_exists($bundle))
        ->toBeTrue('bootstrap/ssr/ssr.js must be checked in alongside public/build/* — the deploy host does not run npm run build:ssr.');
    expect(filesize($bundle))->toBeGreaterThan(100 * 1024);
});

it('declares pitchbar-ssr in the PM2 ecosystem with watch enabled', function () {
    $config = file_get_contents(base_path('ecosystem.config.cjs'));

    expect($config)->toContain("name: 'pitchbar-ssr'");
    expect($config)->toContain("args: 'bootstrap/ssr/ssr.js'");
    expect($config)->toContain("watch: ['bootstrap/ssr/ssr.js']");
    expect($config)->toContain('autorestart: true');
});

it('has the bootstrap/ssr directory tracked (not gitignored)', function () {
    $gitignore = file_get_contents(base_path('.gitignore'));

    // The line must either be absent or commented out — otherwise
    // git add would skip the SSR bundle and PM2 has nothing to run.
    foreach (explode("\n", $gitignore) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        expect($trimmed)->not->toBe('/bootstrap/ssr');
        expect($trimmed)->not->toBe('bootstrap/ssr');
    }
});

it('keeps translate() defensive against undefined translations payload', function () {
    // SSR may receive a partial i18n prop when middleware skips the
    // catalog for unauthenticated marketing routes — the helper must
    // not throw "Cannot read properties of undefined" and crash the
    // whole render path.
    $i18n = file_get_contents(base_path('resources/js/lib/i18n.ts'));
    expect($i18n)->toContain('translations?.[key] ?? key');
});
