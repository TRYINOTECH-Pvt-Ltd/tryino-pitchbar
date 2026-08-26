<?php

use App\Models\Agent;

/**
 * Widget position is a per-agent customization (theme.position) that
 * the widget runtime reads in resources/widget/src/ui/Bar.tsx. Three
 * values are supported: bottom-center (default), bottom-right (the
 * Intercom / Drift / Tawk-style floating bubble), and bottom-left
 * (mirrored for sites whose right edge is busy).
 *
 * Buyer ask: pagenet on CodeCanyon — they want the floating-corner
 * bubble pattern that's the de-facto industry standard.
 */
test('PATCH /app/agents/{id} accepts theme.position', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'theme' => [
            'primary' => '#111827',
            'accent' => '#10b981',
            'position' => 'bottom-right',
        ],
    ])->assertRedirect();

    expect($agent->fresh()->theme)->toMatchArray([
        'position' => 'bottom-right',
    ]);
});

test('init payload exposes theme.position so the widget can render in the right corner', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'theme' => [
            'primary' => '#111827',
            'accent' => '#10b981',
            'position' => 'bottom-right',
        ],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.theme.position'))->toBe('bottom-right');
});

test('init payload returns no position when none was customized — widget falls back to bottom-center', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'theme' => ['primary' => '#111827'],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.theme.position'))->toBeNull();
});
