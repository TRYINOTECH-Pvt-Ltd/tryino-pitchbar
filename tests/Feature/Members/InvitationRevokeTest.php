<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function attachMember(Workspace $ws, User $user, string $role = 'owner'): void
{
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();
}

test('owner can revoke a pending invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    attachMember($workspace, $owner, 'owner');

    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'pending@example.com',
        'role' => 'editor',
        'token' => 'tok'.str_pad((string) random_int(0, 999999), 6, '0'),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('invitations.destroy', ['invitation' => $invitation->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
});

test('admin can revoke a pending invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    attachMember($workspace, $owner, 'owner');

    $admin = User::factory()->create();
    attachMember($workspace, $admin, 'admin');

    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'pending@example.com',
        'role' => 'editor',
        'token' => 'tokA'.str_pad((string) random_int(0, 999999), 6, '0'),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $owner->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('invitations.destroy', ['invitation' => $invitation->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
});

test('viewer cannot revoke an invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    attachMember($workspace, $owner, 'owner');

    $viewer = User::factory()->create();
    attachMember($workspace, $viewer, 'viewer');

    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'pending@example.com',
        'role' => 'editor',
        'token' => 'tokV'.str_pad((string) random_int(0, 999999), 6, '0'),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $owner->id,
    ]);

    $this->actingAs($viewer)
        ->delete(route('invitations.destroy', ['invitation' => $invitation->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
});

test('cannot revoke invitation from a different workspace', function () {
    $owner = User::factory()->create();
    $workspaceA = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    attachMember($workspaceA, $owner, 'owner');

    $stranger = User::factory()->create();
    $workspaceB = Workspace::factory()->create(['owner_user_id' => $stranger->id]);
    attachMember($workspaceB, $stranger, 'owner');

    // Owner of workspace A tries to revoke invitation of workspace B.
    $invitation = Invitation::create([
        'workspace_id' => $workspaceB->id,
        'email' => 'pending@example.com',
        'role' => 'editor',
        'token' => 'tokX'.str_pad((string) random_int(0, 999999), 6, '0'),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $stranger->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('invitations.destroy', ['invitation' => $invitation->id]))
        ->assertNotFound();

    $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
});

test('cannot revoke an already-accepted invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    attachMember($workspace, $owner, 'owner');

    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'pending@example.com',
        'role' => 'editor',
        'token' => 'tokQ'.str_pad((string) random_int(0, 999999), 6, '0'),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $owner->id,
        'accepted_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner)
        ->delete(route('invitations.destroy', ['invitation' => $invitation->id]))
        ->assertRedirect();

    $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
});
