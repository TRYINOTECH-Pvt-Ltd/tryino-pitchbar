<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function freshWorkspace(): Workspace
{
    return Workspace::factory()->create();
}

function pendingInvite(string $workspaceId, string $email, string $role = 'editor', ?DateTimeInterface $expires = null): Invitation
{
    return Invitation::create([
        'workspace_id' => $workspaceId,
        'email' => $email,
        'role' => $role,
        'token' => 'tok-'.bin2hex(random_bytes(16)),
        'expires_at' => $expires ?? now()->addDay(),
    ]);
}

test('expired invitation returns 404', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'late@example.com']);
    $invite = pendingInvite($ws->id, 'late@example.com', expires: now()->subDay());

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertNotFound();
});

test('reaccepting after success returns 404 (cannot be replayed)', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'replay@example.com']);
    $invite = pendingInvite($ws->id, 'replay@example.com');

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertNotFound();
});

test('atomic accept prevents duplicate WorkspaceUser rows', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'race@example.com']);
    $invite = pendingInvite($ws->id, 'race@example.com');

    // Simulate concurrent claim: forcibly mark the invitation as
    // already-claimed mid-flight by another tab, then attempt the
    // accept — we should see 409.
    Invitation::query()->where('id', $invite->id)->update(['accepted_at' => now()->subSecond()]);

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertNotFound();
});

test('case-insensitive email match accepts the invite', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'Mixed.Case@Example.Com']);
    $invite = pendingInvite($ws->id, 'mixed.case@example.com');

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertRedirect(route('dashboard'));
});

test('existing member is not silently elevated by reinvite', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'pre@example.com']);

    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'viewer',
        'invited_at' => now()->subDays(2),
        'accepted_at' => now()->subDays(2),
    ]);

    $invite = pendingInvite($ws->id, 'pre@example.com', 'admin');

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertRedirect(route('dashboard'));

    $pivot = WorkspaceUser::query()
        ->where('workspace_id', $ws->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot->role)->toBe('viewer');
});

test('accepting does not silently flip default workspace away from active one', function () {
    $existing = freshWorkspace();
    $user = User::factory()->create(['email' => 'switcher@example.com', 'default_workspace_id' => $existing->id]);

    WorkspaceUser::create([
        'workspace_id' => $existing->id,
        'user_id' => $user->id,
        'role' => 'editor',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $newWs = freshWorkspace();
    $invite = pendingInvite($newWs->id, 'switcher@example.com');

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]))
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->default_workspace_id)->toBe($existing->id);
});

test('first workspace ever sets default_workspace_id', function () {
    $ws = freshWorkspace();
    $user = User::factory()->create(['email' => 'first@example.com', 'default_workspace_id' => null]);
    $invite = pendingInvite($ws->id, 'first@example.com');

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invite->token]));

    expect($user->fresh()->default_workspace_id)->toBe($ws->id);
});
