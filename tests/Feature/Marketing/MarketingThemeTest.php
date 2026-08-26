<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\MarketingTheme;
use Illuminate\Support\Facades\DB;

test('default theme is harvest when app_settings.marketing_theme is empty', function () {
    // Column is NOT NULL with default `harvest`; an "unset" state at
    // runtime presents as empty string after a manual DB clear.
    AppSetting::singleton();
    DB::table('app_settings')->update(['marketing_theme' => '']);

    expect(MarketingTheme::active())->toBe('harvest');
});

test('harvest theme maps every page to its legacy component path', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();

    expect(MarketingTheme::component('home'))->toBe('welcome');
    expect(MarketingTheme::component('pricing'))->toBe('marketing/pricing');
    expect(MarketingTheme::component('how-it-works'))->toBe('marketing/how-it-works');
    expect(MarketingTheme::component('integrations'))->toBe('marketing/integrations');
    expect(MarketingTheme::component('privacy'))->toBe('marketing/privacy');
    expect(MarketingTheme::component('terms'))->toBe('marketing/terms');
    expect(MarketingTheme::component('changelog'))->toBe('marketing/changelog');
});

test('unknown theme slug falls back to harvest at active() time', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'definitely-not-installed'])->save();

    expect(MarketingTheme::active())->toBe('harvest');
    // And component resolution follows the active fallback.
    expect(MarketingTheme::component('home'))->toBe('welcome');
});

test('component() rejects unknown page keys', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();

    expect(fn () => MarketingTheme::component('not-a-page'))
        ->toThrow(InvalidArgumentException::class);
});

test('componentFor() returns marketing-themes/{slug}/{page} for non-default themes', function () {
    $base = resource_path('js/pages/marketing-themes');
    $themeDir = $base.'/meadow';
    @mkdir($themeDir, 0755, true);
    file_put_contents($themeDir.'/theme.json', json_encode([
        'name' => 'Meadow',
    ]));

    try {
        expect(MarketingTheme::componentFor('meadow', 'home'))
            ->toBe('marketing-themes/meadow/home');
        expect(MarketingTheme::componentFor('meadow', 'pricing'))
            ->toBe('marketing-themes/meadow/pricing');
    } finally {
        @unlink($themeDir.'/theme.json');
        @rmdir($themeDir);
    }
});

test('theme with a pages whitelist falls back to harvest for unprovided pages', function () {
    $base = resource_path('js/pages/marketing-themes');
    $themeDir = $base.'/partial-theme';
    @mkdir($themeDir, 0755, true);
    file_put_contents($themeDir.'/theme.json', json_encode([
        'name' => 'Partial',
        'pages' => ['home'],
    ]));

    try {
        AppSetting::singleton()->forceFill(['marketing_theme' => 'partial-theme'])->save();

        // home is declared → resolves to theme path
        expect(MarketingTheme::component('home'))
            ->toBe('marketing-themes/partial-theme/home');

        // pricing not declared → falls back to harvest legacy path
        expect(MarketingTheme::component('pricing'))
            ->toBe('marketing/pricing');
        expect(MarketingTheme::component('changelog'))
            ->toBe('marketing/changelog');

        // componentFor() mirrors the per-page check
        expect(MarketingTheme::componentFor('partial-theme', 'home'))
            ->toBe('marketing-themes/partial-theme/home');
        expect(MarketingTheme::componentFor('partial-theme', 'pricing'))
            ->toBe('marketing/pricing');
    } finally {
        @unlink($themeDir.'/theme.json');
        @rmdir($themeDir);
        AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();
    }
});

test('available() includes the built-in harvest theme', function () {
    $themes = MarketingTheme::available();
    $slugs = array_map(fn ($t) => $t['slug'], $themes);

    expect($slugs)->toContain('harvest');
});

test('available() discovers themes with a theme.json manifest', function () {
    $base = resource_path('js/pages/marketing-themes');
    $themeDir = $base.'/meadow';
    @mkdir($themeDir, 0755, true);
    file_put_contents($themeDir.'/theme.json', json_encode([
        'name' => 'Meadow',
        'description' => 'A second theme for testing.',
    ]));

    try {
        $themes = MarketingTheme::available();
        $slugs = array_map(fn ($t) => $t['slug'], $themes);
        $meadow = collect($themes)->firstWhere('slug', 'meadow');

        expect($slugs)->toContain('meadow');
        expect($meadow['name'])->toBe('Meadow');
        expect($meadow['description'])->toBe('A second theme for testing.');
    } finally {
        @unlink($themeDir.'/theme.json');
        @rmdir($themeDir);
    }
});

test('available() skips theme dirs missing a theme.json', function () {
    $base = resource_path('js/pages/marketing-themes');
    $themeDir = $base.'/orphan-no-manifest';
    @mkdir($themeDir, 0755, true);

    try {
        $slugs = MarketingTheme::availableSlugs();
        expect($slugs)->not->toContain('orphan-no-manifest');
    } finally {
        @rmdir($themeDir);
    }
});

test('available() ignores theme.json files with invalid JSON', function () {
    $base = resource_path('js/pages/marketing-themes');
    $themeDir = $base.'/broken-manifest';
    @mkdir($themeDir, 0755, true);
    file_put_contents($themeDir.'/theme.json', '{ not valid json');

    try {
        $slugs = MarketingTheme::availableSlugs();
        expect($slugs)->not->toContain('broken-manifest');
    } finally {
        @unlink($themeDir.'/theme.json');
        @rmdir($themeDir);
    }
});

test('marketing home renders with default theme', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'harvest'])->save();

    $this->get('/')->assertOk();
});

test('marketing home renders even when configured theme is missing (fallback to harvest)', function () {
    AppSetting::singleton()->forceFill(['marketing_theme' => 'ghost-theme'])->save();

    $this->get('/')->assertOk();
});

test('system settings save rejects an unknown theme slug', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $response = $this->actingAs($admin)->patch('/settings/system/marketing', [
        'marketing_theme' => 'totally-fake-theme',
    ]);

    $response->assertSessionHasErrors('marketing_theme');
    expect(AppSetting::singleton()->fresh()->marketing_theme)->not->toBe('totally-fake-theme');
});

test('system settings save accepts a known theme slug', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->patch('/settings/system/marketing', [
        'marketing_theme' => 'harvest',
    ])->assertSessionDoesntHaveErrors();

    expect(AppSetting::singleton()->fresh()->marketing_theme)->toBe('harvest');
});
