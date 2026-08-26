<?php

use App\Mail\WorkspaceInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Mail;

function memberOf(Workspace $ws, User $user, string $role = 'owner'): void
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

test('owner can invite a new email; an invitation row is written and mail is queued', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    memberOf($workspace, $owner, 'owner');

    $response = $this->actingAs($owner)->post(route('members.store'), [
        'email' => 'invitee@example.com',
        'role' => 'editor',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('invitations', [
        'workspace_id' => $workspace->id,
        'email' => 'invitee@example.com',
        'role' => 'editor',
    ]);
    Mail::assertQueued(WorkspaceInvitation::class);
});

test('inviting an existing user email auto-adds them without sending mail', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    memberOf($workspace, $owner, 'owner');

    $existing = User::factory()->create(['email' => 'team@example.com']);

    $this->actingAs($owner)->post(route('members.store'), [
        'email' => 'team@example.com',
        'role' => 'admin',
    ])->assertRedirect();

    $this->assertDatabaseHas('workspace_users', [
        'workspace_id' => $workspace->id,
        'user_id' => $existing->id,
        'role' => 'admin',
    ]);
    Mail::assertNothingQueued();
});

test('a viewer cannot invite members', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    memberOf($workspace, $owner, 'owner');
    memberOf($workspace, $viewer, 'viewer');

    $this->actingAs($viewer)->post(route('members.store'), [
        'email' => 'someone@example.com',
        'role' => 'editor',
    ])->assertForbidden();
});

test('accepting an invitation creates a workspace_user pivot for the inviting workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    memberOf($workspace, $owner, 'owner');

    $newUser = User::factory()->create(['email' => 'newcomer@example.com']);
    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'newcomer@example.com',
        'role' => 'editor',
        'token' => 'tok123tok123tok123tok123',
        'expires_at' => now()->addDay(),
        'invited_by_user_id' => $owner->id,
    ]);

    $response = $this->actingAs($newUser)->post(route('invitations.accept', ['token' => $invitation->token]));

    $response->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('workspace_users', [
        'workspace_id' => $workspace->id,
        'user_id' => $newUser->id,
        'role' => 'editor',
    ]);
    expect(Invitation::find($invitation->id)->accepted_at)->not->toBeNull();
    expect($newUser->fresh()->default_workspace_id)->toBe($workspace->id);
});

test('accepting an invitation with the wrong logged-in email is forbidden', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    memberOf($workspace, $owner, 'owner');

    $other = User::factory()->create(['email' => 'someone-else@example.com']);
    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'newcomer@example.com',
        'role' => 'editor',
        'token' => 'tokABCtokABCtokABCtokABC',
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($other)->post(route('invitations.accept', ['token' => $invitation->token]))
        ->assertForbidden();
});
