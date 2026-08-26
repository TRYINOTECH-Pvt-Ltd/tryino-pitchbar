<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function asOwner(Workspace $ws, User $user): void
{
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();
}

test('invitation stores email in lowercase regardless of input case', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    asOwner($ws, $owner);

    $this->actingAs($owner)->post(route('members.store'), [
        'email' => 'MIXED.Case@Example.COM',
        'role' => 'editor',
    ])->assertRedirect();

    $this->assertDatabaseHas('invitations', [
        'workspace_id' => $ws->id,
        'email' => 'mixed.case@example.com',
    ]);
    $this->assertDatabaseMissing('invitations', [
        'email' => 'MIXED.Case@Example.COM',
    ]);
});

test('inviting an existing user with mixed case finds them', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    asOwner($ws, $owner);

    $existing = User::factory()->create(['email' => 'StoredAs.Mixed@Example.com']);

    $this->actingAs($owner)->post(route('members.store'), [
        'email' => 'storedas.mixed@example.com',
        'role' => 'admin',
    ])->assertRedirect();

    $this->assertDatabaseHas('workspace_users', [
        'workspace_id' => $ws->id,
        'user_id' => $existing->id,
        'role' => 'admin',
    ]);
    Mail::assertNothingQueued();
});

test('re-inviting a workspace member is rejected regardless of email case', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $existing = User::factory()->create(['email' => 'pre@example.com']);
    $ws = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    asOwner($ws, $owner);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $existing->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)->post(route('members.store'), [
        'email' => 'PRE@example.com',
        'role' => 'editor',
    ])->assertSessionHasErrors('email');
});

test('migration lowercases legacy mixed-case invitation rows', function () {
    $ws = Workspace::factory()->create();

    DB::table('invitations')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $ws->id,
        'email' => 'LEGACY@example.com',
        'role' => 'editor',
        'token' => 'legacy-token-'.bin2hex(random_bytes(8)),
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_05_16_083259_lowercase_existing_invitation_emails.php');
    $migration->up();

    $this->assertDatabaseHas('invitations', ['email' => 'legacy@example.com']);
    $this->assertDatabaseMissing('invitations', ['email' => 'LEGACY@example.com']);
});
