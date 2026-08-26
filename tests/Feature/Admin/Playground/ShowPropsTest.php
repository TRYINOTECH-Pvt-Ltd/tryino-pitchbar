<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Inertia\Testing\AssertableInertia;

function plgShowMember(): array
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

test('show passes verticals preview map keyed by slug', function () {
    ['user' => $user, 'workspace' => $workspace] = plgShowMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'site_type' => 'ecommerce',
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/playground")
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('app/agents/playground')
                ->has('agent.id')
                ->has('agent.site_type')
                ->has('verticals.ecommerce.label')
                ->has('verticals.ecommerce.starter_prompts')
                ->has('verticals.documentation.label')
                ->has('verticals.saas.label')
                ->has('verticals.help_center.label')
                ->has('verticals.marketing.label')
                ->has('verticals.internal_kb.label')
                ->has('verticals.generic.label');
        });
});
