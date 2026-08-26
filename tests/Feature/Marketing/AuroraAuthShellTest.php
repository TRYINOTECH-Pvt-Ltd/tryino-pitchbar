<?php

use App\Models\AppSetting;
use App\Support\MarketingTheme;

/**
 * Aurora theme ships its own auth shell (login / register / reset /
 * 2FA / verify / accept-invitation) so the brand identity carries
 * through to the sign-in surface. Client report 2026-05-23: "when we
 * change theme, all the frontend pages should follow the theme
 * style". These tests assert (a) Aurora is registered, (b) hitting
 * /login and /register while Aurora is active still 200s, and (c)
 * the auth-layout dispatcher file references AuroraAuthShell so the
 * dispatcher branch isn't silently removed in future refactors.
 */
test('aurora is the active theme when set', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'aurora'])->save();

    try {
        expect(MarketingTheme::active())->toBe('aurora');
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('login route renders 200 with aurora active', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'aurora'])->save();

    try {
        $this->get('/login')->assertOk();
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('register route renders 200 with aurora active', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'aurora'])->save();

    try {
        $this->get('/register')->assertOk();
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('forgot-password route renders 200 with aurora active', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'aurora'])->save();

    try {
        $this->get('/forgot-password')->assertOk();
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('auth-layout dispatcher references AuroraAuthShell', function () {
    $dispatcher = file_get_contents(
        resource_path('js/layouts/auth-layout.tsx'),
    );

    expect($dispatcher)->toContain('AuroraAuthShell');
    expect($dispatcher)->toContain("marketingTheme === 'aurora'");
});

test('aurora auth shell source file exists', function () {
    expect(file_exists(
        resource_path('js/pages/marketing-themes/aurora/auth-shell.tsx'),
    ))->toBeTrue();
});

test('aurora.css ships auth shell styles', function () {
    $css = file_get_contents(
        resource_path('js/pages/marketing-themes/aurora/aurora.css'),
    );

    expect($css)->toContain('.aurora-auth-shell');
    expect($css)->toContain('.aurora-auth-form-card');
});
