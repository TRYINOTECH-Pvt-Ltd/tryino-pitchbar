<?php

use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

test('admin can reorder curated answers and priorities are persisted top-down', function () {
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
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $a = CuratedAnswer::factory()->create(['agent_id' => $agent->id, 'priority' => 1]);
    $b = CuratedAnswer::factory()->create(['agent_id' => $agent->id, 'priority' => 2]);
    $c = CuratedAnswer::factory()->create(['agent_id' => $agent->id, 'priority' => 3]);

    // Reverse order: a should now be highest priority
    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/curated/reorder", ['order' => [$a->id, $b->id, $c->id]])
        ->assertRedirect();

    expect($a->fresh()->priority)->toBe(3);
    expect($b->fresh()->priority)->toBe(2);
    expect($c->fresh()->priority)->toBe(1);
});

test('viewer cannot reorder', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/curated/reorder", ['order' => []])
        ->assertForbidden();
});
