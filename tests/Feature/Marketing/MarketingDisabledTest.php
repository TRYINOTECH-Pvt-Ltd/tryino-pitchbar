<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Platform-level kill switch for the public marketing site. When
 * `app_settings.marketing_site_enabled` is false, unauthenticated
 * visitors hitting the marketing routes (home, pricing,
 * how-it-works, integrations, documentation, changelog) are
 * redirected to /login. /privacy and /terms stay always public.
 */
beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

function disableMarketing(): void
{
    AppSetting::singleton()->forceFill(['marketing_site_enabled' => false])->save();
    AppSetting::flushSingleton();
}

test('marketing site is enabled by default — every public route renders', function () {
    foreach (['/', '/pricing', '/how-it-works', '/integrations', '/changelog', '/documentation', '/privacy', '/terms'] as $path) {
        $this->get($path)->assertOk();
    }
});

test('disabling the marketing site redirects every sales surface to /login', function () {
    disableMarketing();

    foreach (['/', '/pricing', '/how-it-works', '/integrations', '/changelog', '/documentation'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});

test('/privacy and /terms stay accessible even when the marketing site is disabled', function () {
    disableMarketing();

    $this->get('/privacy')->assertOk();
    $this->get('/terms')->assertOk();
});

test('auth flows stay accessible regardless of the marketing toggle', function () {
    disableMarketing();

    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
    $this->get('/forgot-password')->assertOk();
});

test('an authenticated non-admin customer is redirected to /dashboard when the marketing site is disabled', function () {
    disableMarketing();
    $user = User::factory()->create();

    foreach (['/', '/pricing', '/how-it-works', '/integrations', '/changelog'] as $path) {
        $this->actingAs($user)->get($path)->assertRedirect('/dashboard');
    }
});

test('a platform super-admin still sees the marketing site even when public access is off', function () {
    disableMarketing();
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    // Super-admins get to preview the marketing site so they can
    // sanity-check it before re-enabling public access.
    $this->actingAs($admin)->get('/')->assertOk();
    $this->actingAs($admin)->get('/pricing')->assertOk();
});

test('robots.txt disallows everything when the marketing site is disabled', function () {
    disableMarketing();

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)
        ->toContain('User-agent: *')
        ->toContain('Disallow: /')
        ->not->toContain('Allow: /')
        ->not->toContain('Sitemap:');
});

test('sitemap.xml returns an empty urlset when the marketing site is disabled', function () {
    disableMarketing();
    cache()->forget('seo:sitemap.xml');

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain('<urlset')
        ->not->toContain('<loc>');
});
