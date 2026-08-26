<?php

use App\Models\CannedReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function cannedMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('store creates a canned reply scoped to the active workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = cannedMember();

    $this->actingAs($user)
        ->post('/app/settings/canned-replies', [
            'label' => 'Reset password',
            'content' => 'Click the "Forgot password" link on the sign-in page.',
        ])
        ->assertRedirect();

    $reply = CannedReply::query()->withoutGlobalScopes()->first();
    expect($reply)->not->toBeNull();
    expect($reply->workspace_id)->toBe($workspace->id);
    expect($reply->position)->toBe(1);
    expect($reply->created_by)->toBe($user->id);
});

test('reorder updates positions for the supplied IDs', function () {
    ['user' => $user, 'workspace' => $workspace] = cannedMember();
    $a = CannedReply::create(['workspace_id' => $workspace->id, 'label' => 'A', 'content' => 'a', 'position' => 1]);
    $b = CannedReply::create(['workspace_id' => $workspace->id, 'label' => 'B', 'content' => 'b', 'position' => 2]);
    $c = CannedReply::create(['workspace_id' => $workspace->id, 'label' => 'C', 'content' => 'c', 'position' => 3]);

    $this->actingAs($user)
        ->patch('/app/settings/canned-replies/reorder', [
            'ordered_ids' => [$c->id, $a->id, $b->id],
        ])
        ->assertRedirect();

    expect(CannedReply::find($c->id)->position)->toBe(1);
    expect(CannedReply::find($a->id)->position)->toBe(2);
    expect(CannedReply::find($b->id)->position)->toBe(3);
});

test('viewer member cannot store / reorder / update / destroy canned replies', function () {
    // Role gate: Viewer can read but not mutate.
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $viewer = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $workspace->id])->save();
    $existing = CannedReply::create([
        'workspace_id' => $workspace->id,
        'label' => 'Hello',
        'content' => 'Hi there',
        'position' => 1,
    ]);

    $this->actingAs($viewer)
        ->post('/app/settings/canned-replies', [
            'label' => 'Sneaky',
            'content' => 'should not save',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patch("/app/settings/canned-replies/{$existing->id}", [
            'label' => 'Tampered',
            'content' => 'should not save',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patch('/app/settings/canned-replies/reorder', [
            'ordered_ids' => [$existing->id],
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->delete("/app/settings/canned-replies/{$existing->id}")
        ->assertForbidden();

    expect($existing->fresh()->label)->toBe('Hello');
    expect(CannedReply::query()->withoutGlobalScopes()->count())->toBe(1);

    // index() must still work — Viewer needs to USE canned replies in
    // the chat reply textarea even if they can't manage them.
    $this->actingAs($viewer)
        ->get('/app/settings/canned-replies?json=1')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('cross-tenant canned reply is invisible to update / destroy', function () {
    ['user' => $user] = cannedMember();
    $foreignWorkspace = Workspace::factory()->create();
    $reply = CannedReply::create([
        'workspace_id' => $foreignWorkspace->id,
        'label' => 'Foreign',
        'content' => 'no touch',
        'position' => 1,
    ]);

    // BelongsToWorkspace global scope hides the row → 404 from
    // route-model binding.
    $this->actingAs($user)
        ->patch("/app/settings/canned-replies/{$reply->id}", [
            'label' => 'pwned',
            'content' => 'pwned',
        ])
        ->assertNotFound();

    expect($reply->fresh()->label)->toBe('Foreign');
});
