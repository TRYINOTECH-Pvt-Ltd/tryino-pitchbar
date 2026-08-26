<?php

use App\Models\AppSetting;
use App\Support\MarketingTheme;

/**
 * Prism theme is shipped via files under
 * resources/js/pages/marketing-themes/prism/. These tests assert the
 * registry can see it, resolves each declared page to its theme path,
 * and that hitting the public marketing routes does not 500.
 */
test('prism theme is hidden from availableSlugs() so it never appears in the admin picker', function () {
    // Buyer request 2026-05-27: hide Prism from the marketing-theme
    // dropdown. The files stay on disk so existing tests still pass;
    // only the listing is filtered.
    expect(MarketingTheme::availableSlugs())->not->toContain('prism');
});

test('prism theme declares the full marketing page set', function () {
    foreach (
        ['home', 'pricing', 'how-it-works', 'integrations', 'privacy', 'terms', 'changelog'] as $page
    ) {
        expect(MarketingTheme::themeProvidesPage('prism', $page))
            ->toBeTrue("prism theme should declare {$page}");
    }
});

test('componentFor() resolves prism pages under marketing-themes/prism/{page}', function () {
    expect(MarketingTheme::componentFor('prism', 'home'))->toBe('marketing-themes/prism/home');
    expect(MarketingTheme::componentFor('prism', 'pricing'))->toBe('marketing-themes/prism/pricing');
    expect(MarketingTheme::componentFor('prism', 'how-it-works'))->toBe('marketing-themes/prism/how-it-works');
    expect(MarketingTheme::componentFor('prism', 'integrations'))->toBe('marketing-themes/prism/integrations');
    expect(MarketingTheme::componentFor('prism', 'privacy'))->toBe('marketing-themes/prism/privacy');
    expect(MarketingTheme::componentFor('prism', 'terms'))->toBe('marketing-themes/prism/terms');
    expect(MarketingTheme::componentFor('prism', 'changelog'))->toBe('marketing-themes/prism/changelog');
});

test('all seven marketing routes render with prism active', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'prism'])->save();

    try {
        $this->get('/')->assertOk();
        $this->get('/pricing')->assertOk();
        $this->get('/how-it-works')->assertOk();
        $this->get('/integrations')->assertOk();
        $this->get('/privacy')->assertOk();
        $this->get('/terms')->assertOk();
        $this->get('/changelog')->assertOk();
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('with prism hidden, a stale marketing_theme=prism setting silently falls back to harvest', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'prism'])->save();

    try {
        // active() checks isAvailable() before returning the slug;
        // hidden slugs fail the check and fall back to harvest.
        expect(MarketingTheme::active())->toBe(MarketingTheme::DEFAULT_SLUG);
        $this->get('/')->assertInertia(fn ($p) => $p->component('welcome'));
    } finally {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});
