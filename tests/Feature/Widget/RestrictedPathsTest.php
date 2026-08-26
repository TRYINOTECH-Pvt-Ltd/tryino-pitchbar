<?php

use App\Models\Agent;

test('PATCH /app/agents/{id} accepts a restricted_paths list', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'restricted_paths' => ['/admin/*', '/checkout', '/account/*'],
    ])->assertRedirect();

    expect($agent->fresh()->restricted_paths)
        ->toBe(['/admin/*', '/checkout', '/account/*']);
});

test('restricted_paths null is accepted as "no restrictions"', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();
    $agent->forceFill(['restricted_paths' => ['/admin/*']])->save();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'restricted_paths' => null,
    ])->assertRedirect();

    expect($agent->fresh()->restricted_paths)->toBeNull();
});

test('rejects more than 32 restricted_paths entries', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();
    $tooMany = array_fill(0, 33, '/foo');

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'restricted_paths' => $tooMany,
    ])->assertSessionHasErrors('restricted_paths');
});

test('rejects an entry over 200 characters', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'restricted_paths' => [str_repeat('/x', 105)],  // 210 chars
    ])->assertSessionHasErrors('restricted_paths.0');
});

test('/api/v1/widget/init exposes restricted_paths in the agent payload', function () {
    ['workspace' => $workspace] = workspaceMemberWithAgent();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://shop.example.com'],
        'restricted_paths' => ['/admin/*', '/checkout'],
    ]);

    $response = $this->postJson(
        '/api/v1/widget/init',
        ['agent_id' => $agent->id, 'page_url' => 'https://shop.example.com/'],
        ['Origin' => 'https://shop.example.com'],
    );
    $response->assertOk();
    expect($response->json('data.agent.restricted_paths'))
        ->toBe(['/admin/*', '/checkout']);
});

test('a Phase-1 agent without restricted_paths returns an empty array on /init', function () {
    ['workspace' => $workspace] = workspaceMemberWithAgent();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://shop.example.com'],
        // intentionally NO restricted_paths set.
    ]);

    $response = $this->postJson(
        '/api/v1/widget/init',
        ['agent_id' => $agent->id, 'page_url' => 'https://shop.example.com/'],
        ['Origin' => 'https://shop.example.com'],
    );
    $response->assertOk();
    expect($response->json('data.agent.restricted_paths'))->toBe([]);
});
