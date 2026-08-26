<?php

use App\Models\Source;

test('admin can delete a source and is redirected to the agent sources page', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'config' => ['url' => 'https://example.com'],
        'status' => 'indexed',
    ]);

    $this->actingAs($user)
        ->from(route('agents.sources.index', ['agent' => $agent->id]))
        ->delete(route('sources.destroy', ['source' => $source->id]))
        ->assertRedirect(route('agents.sources.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('sources', ['id' => $source->id]);
});

test('viewer cannot delete a source', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'viewer']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'config' => ['url' => 'https://example.com'],
        'status' => 'indexed',
    ]);

    $this->actingAs($user)
        ->delete(route('sources.destroy', ['source' => $source->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('sources', ['id' => $source->id]);
});

test('cross-workspace delete attempt is blocked', function () {
    ['user' => $userA] = workspaceMemberWithAgent(['role' => 'owner']);
    ['agent' => $agentB] = workspaceMemberWithAgent(['role' => 'owner']);

    $foreignSource = Source::create([
        'agent_id' => $agentB->id,
        'type' => 'url',
        'config' => ['url' => 'https://foreign.test'],
        'status' => 'indexed',
    ]);

    $response = $this->actingAs($userA)
        ->delete("/app/sources/{$foreignSource->id}");

    expect($response->status())->toBeIn([403, 404]);
    $this->assertDatabaseHas('sources', ['id' => $foreignSource->id]);
});

test('Inertia delete returns 303 with correct Location header for the agent sources page', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'config' => ['url' => 'https://example.com'],
        'status' => 'indexed',
    ]);

    $deleteResponse = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => route('agents.sources.index', ['agent' => $agent->id]),
        ])
        ->from(route('agents.sources.index', ['agent' => $agent->id]))
        ->delete(route('sources.destroy', ['source' => $source->id]));

    expect($deleteResponse->status())->toBeIn([302, 303]);
    expect($deleteResponse->headers->get('Location'))
        ->toBe(route('agents.sources.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('sources', ['id' => $source->id]);
});

test('redirect target page still renders after the only source for the agent is deleted', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'config' => ['url' => 'https://example.com'],
        'status' => 'indexed',
    ]);

    $this->actingAs($user)
        ->delete(route('sources.destroy', ['source' => $source->id]));

    $this->actingAs($user)
        ->get(route('agents.sources.index', ['agent' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/agents/sources'));
});

test('failed-status source can be deleted without 500', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'config' => ['url' => 'https://porifyx.com'],
        'status' => 'failed',
        'error' => "Couldn't index this page.",
    ]);

    $this->actingAs($user)
        ->delete(route('sources.destroy', ['source' => $source->id]))
        ->assertRedirect(route('agents.sources.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('sources', ['id' => $source->id]);
});

test('auto-indexed source can be deleted without 500', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'auto',
        'config' => ['label' => 'Auto-indexed from visitors'],
        'status' => 'indexed',
    ]);

    $this->actingAs($user)
        ->delete(route('sources.destroy', ['source' => $source->id]))
        ->assertRedirect(route('agents.sources.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('sources', ['id' => $source->id]);
});
