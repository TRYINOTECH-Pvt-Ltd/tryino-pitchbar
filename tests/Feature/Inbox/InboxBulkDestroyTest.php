<?php

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function inboxBulkActor(string $role = 'admin'): array
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
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('inbox bulk-destroy deletes every lead the user can delete', function () {
    ['user' => $user, 'agent' => $agent] = inboxBulkActor('admin');
    $a = Lead::factory()->create(['agent_id' => $agent->id]);
    $b = Lead::factory()->create(['agent_id' => $agent->id]);
    $c = Lead::factory()->create(['agent_id' => $agent->id]);

    $this->actingAs($user)->post('/app/inbox/bulk-destroy', [
        'ids' => [$a->id, $b->id, $c->id],
    ])->assertRedirect();

    foreach ([$a, $b, $c] as $row) {
        expect(Lead::query()->withoutGlobalScopes()->find($row->id))->toBeNull();
    }
});

test('inbox bulk-destroy silently drops cross-workspace ids', function () {
    ['user' => $user, 'agent' => $agent] = inboxBulkActor('owner');
    $mine = Lead::factory()->create(['agent_id' => $agent->id]);
    $foreignAgent = Agent::factory()->create();
    $other = Lead::factory()->create(['agent_id' => $foreignAgent->id]);

    $this->actingAs($user)->post('/app/inbox/bulk-destroy', [
        'ids' => [$mine->id, $other->id],
    ])->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->find($mine->id))->toBeNull();
    expect(Lead::query()->withoutGlobalScopes()->find($other->id))->not->toBeNull();
});

test('viewer cannot bulk-destroy leads', function () {
    ['user' => $user, 'agent' => $agent] = inboxBulkActor('viewer');
    $a = Lead::factory()->create(['agent_id' => $agent->id]);

    $this->actingAs($user)->post('/app/inbox/bulk-destroy', [
        'ids' => [$a->id],
    ])->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->find($a->id))->not->toBeNull();
});

test('inbox bulk-destroy validation rejects empty + >100 ids', function () {
    ['user' => $user] = inboxBulkActor('admin');

    $this->actingAs($user)->post('/app/inbox/bulk-destroy', [])
        ->assertSessionHasErrors('ids');

    $this->actingAs($user)->post('/app/inbox/bulk-destroy', [
        'ids' => array_map('strval', range(1, 101)),
    ])->assertSessionHasErrors('ids');
});

test('inbox single destroy works for an admin', function () {
    ['user' => $user, 'agent' => $agent] = inboxBulkActor('admin');
    $lead = Lead::factory()->create(['agent_id' => $agent->id]);

    $this->actingAs($user)->delete('/app/inbox/'.$lead->id)->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->find($lead->id))->toBeNull();
});
