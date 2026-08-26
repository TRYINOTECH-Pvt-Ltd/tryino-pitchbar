<?php

use App\Models\Agent;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Client report 2026-05-23: free-plan source limit defined as 4, but
 * /app/agents Knowledge column rendered "3" instead of "3/4". The
 * column now ships a `sourceQuota` shared prop so the UI can render
 * `total/limit` (or just `total` when the plan is unlimited).
 */
function agentIndexMember(?Plan $plan = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan?->id,
    ]);
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

test('agent index ships sourceQuota with the plan limit', function () {
    $plan = Plan::factory()->create(['sources_limit' => 4]);
    ['user' => $user, 'workspace' => $workspace] = agentIndexMember($plan);
    Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertInertia(fn ($p) => $p
            ->component('app/agents/index')
            ->where('sourceQuota.limit', 4)
            ->where('sourceQuota.current', 0)
        );
});

test('agent index sourceQuota.limit is null on an unlimited plan', function () {
    $plan = Plan::factory()->create(['sources_limit' => null]);
    ['user' => $user, 'workspace' => $workspace] = agentIndexMember($plan);
    Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertInertia(fn ($p) => $p->where('sourceQuota.limit', null));
});

test('agent index sourceQuota.current counts sources across all workspace agents', function () {
    $plan = Plan::factory()->create(['sources_limit' => 4]);
    ['user' => $user, 'workspace' => $workspace] = agentIndexMember($plan);

    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::factory()->count(3)->create(['agent_id' => $agent->id]);

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertInertia(fn ($p) => $p
            ->where('sourceQuota.limit', 4)
            ->where('sourceQuota.current', 3)
        );
});
