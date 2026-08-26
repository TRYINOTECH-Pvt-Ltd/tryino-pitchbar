<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AppBranding;

function platformAdminForAuthAside(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

test('the branding form defaults the new auth-aside fields to empty', function () {
    $admin = platformAdminForAuthAside();

    $response = $this->actingAs($admin)->get('/settings/branding');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/system')
        ->where('form.auth_aside_eyebrow', '')
        ->where('form.auth_aside_heading', '')
        ->where('form.auth_aside_lede', '')
        ->where('form.auth_aside_bullets', []));
});

test('super_admin can save admin-editable auth-aside copy', function () {
    $admin = platformAdminForAuthAside();

    $this->actingAs($admin)->patch('/settings/system/branding', [
        'auth_aside_eyebrow' => 'WORKSPACE LOGIN',
        'auth_aside_heading' => 'Welcome back.',
        'auth_aside_lede' => 'Pick up where you left off.',
        'auth_aside_bullets' => ['Single sign-on', 'Two-factor auth', ''],
    ])->assertSessionHas('success');

    $row = AppSetting::singleton()->fresh();
    expect($row->auth_aside_eyebrow)->toBe('WORKSPACE LOGIN')
        ->and($row->auth_aside_heading)->toBe('Welcome back.')
        ->and($row->auth_aside_lede)->toBe('Pick up where you left off.')
        // Empty rows persisted as-is; AppBranding strips them on read.
        ->and($row->auth_aside_bullets)->toBe(['Single sign-on', 'Two-factor auth', null]);
});

test('AppBranding::shared strips empty bullets + returns nulls for blank fields', function () {
    $row = AppSetting::singleton();
    $row->auth_aside_eyebrow = '';
    $row->auth_aside_heading = '   ';
    $row->auth_aside_lede = null;
    $row->auth_aside_bullets = ['Keeper', '', '   ', 'Another'];
    $row->save();

    $shared = AppBranding::shared();
    expect($shared['auth_aside_eyebrow'])->toBeNull()
        ->and($shared['auth_aside_heading'])->toBeNull()
        ->and($shared['auth_aside_lede'])->toBeNull()
        ->and($shared['auth_aside_bullets'])->toBe(['Keeper', 'Another']);
});

test('AppBranding::shared returns null bullets when no entries persisted', function () {
    $shared = AppBranding::shared();
    expect($shared['auth_aside_bullets'])->toBeNull();
});

test('non-super_admin cannot save auth-aside copy', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/system/branding', [
        'auth_aside_heading' => 'hijacked',
    ])->assertNotFound();
});
