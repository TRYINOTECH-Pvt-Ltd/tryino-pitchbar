<?php

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function superAdminUser(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

/**
 * Reads the live `Version:` header from wp-plugin/pitchbar/pitchbar.php
 * so tests don't go stale every time we bump the plugin version.
 */
function currentPluginVersion(): string
{
    $contents = (string) file_get_contents(base_path('wp-plugin/pitchbar/pitchbar.php'));
    if (preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $contents, $m) === 1) {
        return $m[1];
    }
    throw new RuntimeException('Could not parse plugin version from pitchbar.php');
}

test('super_admin sees the WordPress distribution page', function () {
    $admin = superAdminUser();

    $this->actingAs($admin)
        ->get('/admin/integrations/wordpress')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/integrations/wordpress')
            ->has('builds')
            ->has('source_version'));
});

test('non super_admin gets 404 (existence-hide)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/integrations/wordpress')
        ->assertNotFound();
});

test('super_admin can trigger a build via the index route', function () {
    $admin = superAdminUser();

    $this->actingAs($admin)
        ->post('/admin/integrations/wordpress/build')
        ->assertRedirect();

    // Source version is the value parsed out of pitchbar.php's
    // Version: header; the build command writes
    // pitchbar-{version}.zip into the storage builds directory.
    $expected = storage_path('app/private/wp-plugin-builds/pitchbar-'.currentPluginVersion().'.zip');
    expect(File::exists($expected))->toBeTrue();
});

test('super_admin can download an existing build', function () {
    $admin = superAdminUser();
    $this->artisan('pitchbar:build-wp-plugin')->assertSuccessful();

    $response = $this->actingAs($admin)
        ->get('/admin/integrations/wordpress/download/'.currentPluginVersion().'');

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toContain('pitchbar-'.currentPluginVersion().'.zip');
});

test('downloading a malformed version is 404', function () {
    $admin = superAdminUser();

    $this->actingAs($admin)
        ->get('/admin/integrations/wordpress/download/not-a-version')
        ->assertNotFound();
});

test('downloading a missing build is 404', function () {
    $admin = superAdminUser();

    $this->actingAs($admin)
        ->get('/admin/integrations/wordpress/download/99.99.99')
        ->assertNotFound();
});

test('non super_admin cannot download', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/integrations/wordpress/download/'.currentPluginVersion().'')
        ->assertNotFound();
});
