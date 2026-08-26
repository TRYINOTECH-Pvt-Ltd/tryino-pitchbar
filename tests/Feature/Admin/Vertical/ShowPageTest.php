<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Inertia\Testing\AssertableInertia as Assert;

function makeOwnedAgent(string $role = 'admin'): array
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
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'site_type' => 'ecommerce',
    ]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('vertical settings page renders with all 7 preset previews', function () {
    ['user' => $user, 'agent' => $agent] = makeOwnedAgent();

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/vertical")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/agents/vertical')
            ->has('agent')
            ->has('preset_preview', 7)
            ->where('preset_preview.ecommerce.slug', 'ecommerce')
            ->where('preset_preview.documentation.slug', 'documentation')
        );
});

test('cross-workspace agent is forbidden', function () {
    ['user' => $user] = makeOwnedAgent();
    $otherWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->actingAs($user)
        ->get("/app/agents/{$foreignAgent->id}/vertical")
        ->assertForbidden();
});
