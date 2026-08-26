<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function bulkActor(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
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

test('bulk-destroy hard-deletes every agent in the workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = bulkActor('admin');
    $a = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $b = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $c = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post('/app/agents/bulk-destroy', [
        'ids' => [$a->id, $b->id, $c->id],
    ])->assertRedirect();

    foreach ([$a, $b, $c] as $row) {
        expect(
            Agent::query()->withTrashed()->withoutGlobalScopes()->find($row->id),
        )->toBeNull();
    }
});

test('bulk-destroy silently drops cross-workspace ids', function () {
    ['user' => $user, 'workspace' => $workspace] = bulkActor('owner');
    $mine = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $other = Agent::factory()->create();

    $this->actingAs($user)->post('/app/agents/bulk-destroy', [
        'ids' => [$mine->id, $other->id],
    ])->assertRedirect();

    expect(
        Agent::query()->withTrashed()->withoutGlobalScopes()->find($mine->id),
    )->toBeNull();
    // Foreign workspace agent untouched (still present, not trashed).
    $foreign = Agent::query()->withoutGlobalScopes()->find($other->id);
    expect($foreign)->not->toBeNull();
    expect($foreign->trashed())->toBeFalse();
});

test('viewer cannot bulk-destroy', function () {
    ['user' => $user, 'workspace' => $workspace] = bulkActor('viewer');
    $a = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post('/app/agents/bulk-destroy', [
        'ids' => [$a->id],
    ])->assertRedirect();

    // Viewer denied: row stays.
    expect(Agent::query()->withoutGlobalScopes()->find($a->id))->not->toBeNull();
});

test('bulk-destroy validation: ids array required, max 100', function () {
    ['user' => $user] = bulkActor('admin');

    $this->actingAs($user)->post('/app/agents/bulk-destroy', [])
        ->assertSessionHasErrors('ids');

    $this->actingAs($user)->post('/app/agents/bulk-destroy', [
        'ids' => range(1, 101),
    ])->assertSessionHasErrors('ids');
});
