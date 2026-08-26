<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Customer-side workspace rename — buyer-reported gap (Lucian, 2026-05-15).
 * Owners + Admins can rename via /settings/workspace; everyone below
 * (Editor / Member / Viewer) is blocked by WorkspacePolicy::update.
 */
function wsOwner(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $ws = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'name' => 'Initial name',
    ]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return ['user' => $user, 'workspace' => $ws];
}

test('owner can rename their workspace', function () {
    ['user' => $user, 'workspace' => $ws] = wsOwner('owner');

    $this->actingAs($user)
        ->patch('/settings/workspace', ['name' => 'New Co'])
        ->assertRedirect();

    expect($ws->fresh()->name)->toBe('New Co');
});

test('admin can rename the workspace', function () {
    ['user' => $user, 'workspace' => $ws] = wsOwner('admin');

    $this->actingAs($user)
        ->patch('/settings/workspace', ['name' => 'Renamed'])
        ->assertRedirect();

    expect($ws->fresh()->name)->toBe('Renamed');
});

test('editor cannot rename the workspace', function () {
    ['user' => $user] = wsOwner('editor');

    $this->actingAs($user)
        ->patch('/settings/workspace', ['name' => 'Hijacked'])
        ->assertStatus(403);
});

test('rename rejects empty / too-short / too-long names', function () {
    ['user' => $user, 'workspace' => $ws] = wsOwner('owner');

    $this->actingAs($user)
        ->patch('/settings/workspace', ['name' => 'a'])
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->patch('/settings/workspace', ['name' => str_repeat('x', 200)])
        ->assertSessionHasErrors('name');

    expect($ws->fresh()->name)->toBe('Initial name');
});

test('edit page renders for an owner', function () {
    ['user' => $user] = wsOwner('owner');

    $this->actingAs($user)
        ->get('/settings/workspace')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('settings/workspace')
            ->where('workspace.name', 'Initial name'));
});
