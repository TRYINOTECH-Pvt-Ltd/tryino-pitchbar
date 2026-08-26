<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Pins the Inertia `byok.unlocked` shared prop that drives the
 * conditional "AI keys" link in SettingsLayout. Without this prop
 * the page exists at /settings/byok-keys but no customer can
 * discover it after their operator enrols BYOK.
 *
 * Matrix mirrored from ByokResolver:
 *
 *   global ON | user override   → unlocked?
 *   ----------|-----------------|----------
 *   true      | null            | true
 *   true      | true            | true
 *   true      | false           | false  (explicit deny)
 *   false     | null            | false
 *   false     | true            | true   (per-user force-on)
 *   false     | false           | false
 */
beforeEach(function () {
    // Reset the singleton between tests since it survives the
    // DB refresh.
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => false])->save();
});

test('byok.unlocked is true when global BYOK is enabled', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok.unlocked', true));
});

test('byok.unlocked is false when BYOK globally disabled and user has no override', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok.unlocked', false));
});

test('byok.unlocked is true when global is OFF but user is force-enabled', function () {
    ['user' => $user] = workspaceMember();
    $user->forceFill(['byok_enabled' => true])->save();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok.unlocked', true));
});

test('byok.unlocked is false when user is force-denied even though global is ON', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
    ['user' => $user] = workspaceMember();
    $user->forceFill(['byok_enabled' => false])->save();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok.unlocked', false));
});

test('byok.unlocked is null for unauthenticated visitors', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();

    // Hit a marketing page that runs Inertia middleware. The byok
    // prop should be absent (callable returns null) so anon users
    // don't see a stale state hint.
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok', null));
});

test('byok shared prop is null for super_admin (workspace-level BYOK is not their concern)', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
    ['user' => $user] = workspaceMember();
    // Promote to super_admin. The role bypasses the sidebar entry
    // because super_admins manage platform credentials via
    // /settings/system, not the workspace BYOK form.
    $user->forceFill(['role' => PlatformRole::SuperAdmin])->save();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byok', null));
});

test('/settings/byok-keys 404s for super_admin even when BYOK globally enabled', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
    ['user' => $user] = workspaceMember();
    $user->forceFill(['role' => PlatformRole::SuperAdmin])->save();

    // Belt-and-braces: even if a curious super_admin types the URL
    // directly, the controller hard-404s. Without this, BYOK keys
    // would leak through a route a super-admin shouldn't be using.
    $this->actingAs($user)->get('/settings/byok-keys')->assertNotFound();
});

test('regular workspace member can still load /settings/byok-keys when BYOK enabled', function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)->get('/settings/byok-keys')->assertOk();
});
