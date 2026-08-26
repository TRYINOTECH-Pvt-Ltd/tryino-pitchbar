<?php

use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function asWorkspaceOwner(): array
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

test('onboarding page renders with starter agent for new workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = asWorkspaceOwner();

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/index')
        ->has('agent')
        ->where('agent.sources_count', 0)
        ->has('widget.src')
        ->has('widget.agent_id')
    );
    expect(Agent::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('onboarding page does not create a duplicate agent on revisit', function () {
    ['user' => $user, 'workspace' => $workspace] = asWorkspaceOwner();
    Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Existing']);

    $this->actingAs($user)->get('/onboarding')->assertOk();
    $this->actingAs($user)->get('/onboarding')->assertOk();

    expect(Agent::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('onboarding-status returns indexed/total counts', function () {
    ['user' => $user, 'workspace' => $workspace] = asWorkspaceOwner();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'crawling']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'failed']);

    $response = $this->actingAs($user)->getJson("/api/v1/agents/{$agent->id}/onboarding-status");

    $response->assertOk();
    $response->assertJsonPath('data.sources_count', 3);
    $response->assertJsonPath('data.sources_indexed', 1);
});

test('onboarding-status enforces per-agent authorisation', function () {
    ['user' => $user] = asWorkspaceOwner();
    $other = Workspace::factory()->create();
    $otherAgent = Agent::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/agents/{$otherAgent->id}/onboarding-status")
        ->assertForbidden();
});

test('unauthenticated visitors cannot reach onboarding', function () {
    $this->get('/onboarding')->assertRedirect('/login');
});

test('shared prop needs_onboarding is true for empty workspace', function () {
    ['user' => $user] = asWorkspaceOwner();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('auth.needs_onboarding', true));
});

test('shared prop needs_onboarding is false once any source is indexed', function () {
    ['user' => $user, 'workspace' => $workspace] = asWorkspaceOwner();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('auth.needs_onboarding', false));
});
