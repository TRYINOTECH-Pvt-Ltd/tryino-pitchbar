<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Buyer-reported (2026-05-20): non-admin workspace members saw the
 * "Workspace name" sidebar entry but clicking it 403'd. Fix surfaces
 * the workspace role as a shared Inertia prop so SettingsLayout can
 * gate visibility on the client. These tests pin the prop shape.
 */
function roleMember(string $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('workspaceRole shared prop reads admin for an admin member', function () {
    ['user' => $user] = roleMember('admin');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('workspaceRole', 'admin'));
});

test('workspaceRole shared prop reads editor for an editor member', function () {
    ['user' => $user] = roleMember('editor');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('workspaceRole', 'editor'));
});

test('workspaceRole shared prop reads viewer for a viewer member', function () {
    ['user' => $user] = roleMember('viewer');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('workspaceRole', 'viewer'));
});

test('viewer hitting /settings/workspace still 403s (defence-in-depth)', function () {
    ['user' => $user] = roleMember('viewer');

    $this->actingAs($user)
        ->get('/settings/workspace')
        ->assertForbidden();
});

test('admin can reach /settings/workspace', function () {
    ['user' => $user] = roleMember('admin');

    $this->actingAs($user)
        ->get('/settings/workspace')
        ->assertOk();
});

test('super_admin workspaceRole shared prop is null when no workspace pivot', function () {
    // Super-admins manage the platform via /admin/* and do not carry
    // a workspace pivot row by default. The shared prop should be
    // null so SettingsLayout hides the Workspace section entirely.
    $admin = \App\Models\User::factory()->create([
        'role' => \App\Enums\PlatformRole::SuperAdmin,
    ]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('workspaceRole', null));
});
