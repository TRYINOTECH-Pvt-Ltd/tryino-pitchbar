<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function asMemberWithWorkspace(string $role = 'admin'): array
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

test('apply on a fresh agent fills empty starter_prompts/max_chars/launcher_label', function () {
    ['user' => $user, 'workspace' => $workspace] = asMemberWithWorkspace();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'starter_prompts' => null,
        'guardrails' => null,
        'theme' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/apply", [
            'site_type' => 'ecommerce',
        ]);

    $response->assertOk();
    $agent->refresh();

    expect($agent->site_type)->toBe('ecommerce');
    expect($agent->starter_prompts)->not->toBe(null);
    expect(count($agent->starter_prompts))->toBeGreaterThan(0);
    expect($agent->guardrails['max_chars'])->toBe(1800);
    expect($agent->theme['launcher_label'])->toBe('Browse our shop');
});

test('apply does NOT mutate agent.system_prompt', function () {
    ['user' => $user, 'workspace' => $workspace] = asMemberWithWorkspace();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'system_prompt' => 'Custom rules.',
    ]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/apply", [
            'site_type' => 'documentation',
        ])
        ->assertOk();

    expect($agent->fresh()->system_prompt)->toBe('Custom rules.');
});

test('re-apply with force=false leaves existing starter_prompts alone', function () {
    ['user' => $user, 'workspace' => $workspace] = asMemberWithWorkspace();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'starter_prompts' => ['my custom prompt'],
    ]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/apply", [
            'site_type' => 'ecommerce',
            'force' => false,
        ])
        ->assertOk();

    expect($agent->fresh()->starter_prompts)->toBe(['my custom prompt']);
});

test('re-apply with force=true replaces starter_prompts', function () {
    ['user' => $user, 'workspace' => $workspace] = asMemberWithWorkspace();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'starter_prompts' => ['my custom prompt'],
    ]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/apply", [
            'site_type' => 'ecommerce',
            'force' => true,
        ])
        ->assertOk();

    $fresh = $agent->fresh();
    expect($fresh->starter_prompts)->not->toBe(['my custom prompt']);
    expect(count($fresh->starter_prompts))->toBeGreaterThan(0);
});

test('cross-workspace agent is forbidden', function () {
    ['user' => $user] = asMemberWithWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    // AgentPolicy::update returns false for an agent in a different
    // workspace, which `abort(403)` then turns into a 403.
    $this->actingAs($user)
        ->postJson("/app/agents/{$foreignAgent->id}/vertical/apply", [
            'site_type' => 'ecommerce',
        ])
        ->assertForbidden();
});

test('invalid slug returns 422', function () {
    ['user' => $user, 'workspace' => $workspace] = asMemberWithWorkspace();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/apply", [
            'site_type' => 'not-a-real-slug',
        ])
        ->assertStatus(422);
});
