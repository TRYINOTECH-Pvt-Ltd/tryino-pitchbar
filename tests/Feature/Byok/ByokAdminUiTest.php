<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function byokSuperAdmin(): User
{
    return User::factory()->create([
        'role' => PlatformRole::SuperAdmin->value,
    ]);
}

test('super_admin can toggle byok_enabled_globally via /settings/system/byok', function () {
    $admin = byokSuperAdmin();

    $this->actingAs($admin)
        ->patch('/settings/system/byok', [
            'byok_enabled_globally' => true,
        ])
        ->assertRedirect();

    expect(AppSetting::singleton()->byok_enabled_globally)->toBeTrue();
});

test('byok_enabled_globally is projected back to the React form props', function () {
    AppSetting::singleton()->forceFill([
        'byok_enabled_globally' => true,
    ])->save();

    $this->actingAs(byokSuperAdmin())
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('settings/system')
                ->where('form.byok_enabled_globally', true),
        );
});

test('non-super_admin cannot hit the byok system endpoint', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer->value]);

    // super_admin middleware existence-hides the route surface from
    // non-super_admins, so customers see a 404 not a 403.
    $this->actingAs($user)
        ->patch('/settings/system/byok', [
            'byok_enabled_globally' => true,
        ])
        ->assertStatus(404);

    // The middleware rejection means the setting never flips. The
    // column default may be null on a fresh row, so a falsy check is
    // the right invariant — true would be the regression we're guarding.
    expect((bool) AppSetting::singleton()->byok_enabled_globally)->toBeFalse();
});

test('admin users index exposes byok_mode + byok_globally_enabled', function () {
    User::factory()->create(['byok_enabled' => true]);
    User::factory()->create(['byok_enabled' => false]);
    User::factory()->create(['byok_enabled' => null]);

    AppSetting::singleton()->forceFill([
        'byok_enabled_globally' => true,
    ])->save();

    $response = $this->actingAs(byokSuperAdmin())->get('/admin/users');
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props['byok_globally_enabled'])->toBeTrue();

    $modes = collect($props['users'])->pluck('byok_mode')->unique()->values();
    expect($modes)
        ->toContain('inherit')
        ->toContain('enabled')
        ->toContain('disabled');
});

test('admin can set a user byok_mode to enabled / disabled / inherit', function () {
    $admin = byokSuperAdmin();
    $target = User::factory()->create(['byok_enabled' => null]);

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/byok", ['mode' => 'enabled'])
        ->assertRedirect();
    expect($target->fresh()->byok_enabled)->toBeTrue();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/byok", ['mode' => 'disabled'])
        ->assertRedirect();
    expect($target->fresh()->byok_enabled)->toBeFalse();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/byok", ['mode' => 'inherit'])
        ->assertRedirect();
    expect($target->fresh()->byok_enabled)->toBeNull();
});
