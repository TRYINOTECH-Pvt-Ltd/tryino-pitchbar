<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Cache;

function plgResetMember(): array
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

test('reset clears the cached conversation history', function () {
    ['user' => $user, 'workspace' => $workspace] = plgResetMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'is_playground' => true,
    ]);

    Cache::put("conv:{$conv->id}:history", [
        ['role' => 'user', 'content' => 'old turn'],
    ], 3600);

    expect(Cache::has("conv:{$conv->id}:history"))->toBeTrue();

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/playground/reset", [
            'conversation_id' => $conv->id,
        ])
        ->assertOk();

    expect(Cache::has("conv:{$conv->id}:history"))->toBeFalse();
});

test('reset refuses to clear a foreign-agent conversation', function () {
    ['user' => $user, 'workspace' => $workspace] = plgResetMember();
    $myAgent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    // Conversation under a DIFFERENT agent (still in same workspace
    // for ease of setup, but the controller scopes to agent_id).
    $otherAgent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $otherAgent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $otherAgent->id,
        'visitor_id' => $visitor->id,
    ]);

    Cache::put("conv:{$conv->id}:history", [['role' => 'user', 'content' => 'x']], 3600);

    $this->actingAs($user)
        ->postJson("/app/agents/{$myAgent->id}/playground/reset", [
            'conversation_id' => $conv->id,
        ])
        ->assertOk();

    // The cache key for the OTHER agent's conversation is untouched.
    expect(Cache::has("conv:{$conv->id}:history"))->toBeTrue();
});

test('reset on a cross-tenant agent is rejected', function () {
    ['user' => $user] = plgResetMember();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $foreignWorkspace->id]);

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$foreignAgent->id}/playground/reset", [
            'conversation_id' => 'whatever',
        ]);

    expect($response->getStatusCode())->toBeIn([403, 404]);
});
