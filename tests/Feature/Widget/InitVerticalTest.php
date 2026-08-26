<?php

use App\Models\Agent;
use App\Models\Workspace;

test('init exposes site_type and capabilities for an ecommerce agent', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'site_type' => 'ecommerce',
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.site_type', 'ecommerce');

    $caps = $response->json('data.agent.capabilities');
    expect($caps)->toBeArray();
    expect($caps)->toContain('product_card');
});

test('init returns null site_type and empty capabilities when not set', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'site_type' => null,
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.site_type', null)
        ->assertJsonPath('data.agent.capabilities', []);
});

test('vertical_overrides.capabilities replaces preset capabilities', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'site_type' => 'ecommerce',
        'vertical_overrides' => [
            'capabilities' => ['custom_cap', 'another_one'],
        ],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.capabilities', ['custom_cap', 'another_one']);
});
