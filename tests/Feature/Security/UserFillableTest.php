<?php

use App\Models\User;

/**
 * Regression guard for the mass-assignment audit. `byok_enabled` and
 * `default_workspace_id` were both fillable until the security audit
 * caught the leak. Any future caller that mistakenly does
 * `$user->fill($request->all())` (a stale habit from older Laravel
 * codebases) must NOT be able to flip these privilege-bearing fields.
 *
 * The fields are still WRITABLE via `forceFill` — that's the only
 * sanctioned path (Admin\Platform\UserController::updateByok plus the
 * workspace-switch controllers).
 */
test('User::fill cannot mass-assign byok_enabled', function () {
    $user = User::factory()->create(['byok_enabled' => null]);

    $user->fill([
        'name' => 'New Name',
        'byok_enabled' => true,
    ])->save();

    expect($user->fresh()->name)->toBe('New Name');
    expect($user->fresh()->byok_enabled)->toBeNull();
});

test('User::fill cannot mass-assign default_workspace_id', function () {
    $user = User::factory()->create();
    $original = $user->default_workspace_id;

    $user->fill([
        'name' => 'Renamed',
        'default_workspace_id' => 'evil-workspace-id-from-request',
    ])->save();

    expect($user->fresh()->default_workspace_id)->toBe($original);
});

test('User::fill cannot mass-assign role / super_admin status', function () {
    // `role` was never in Fillable — assert here as a tripwire for
    // any future Fillable churn.
    $user = User::factory()->create();

    $user->fill([
        'name' => 'Still Just A User',
        'role' => 'super_admin',
    ])->save();

    expect($user->fresh()->role->value)->not->toBe('super_admin');
});

test('forceFill still works for the sanctioned admin path', function () {
    $user = User::factory()->create(['byok_enabled' => null]);

    $user->forceFill(['byok_enabled' => true])->save();

    expect($user->fresh()->byok_enabled)->toBeTrue();
});
