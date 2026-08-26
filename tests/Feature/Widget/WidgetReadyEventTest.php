<?php

use App\Models\Agent;
use App\Models\Event;
use App\Models\Workspace;

/**
 * End-to-end verification that the widget.ready telemetry event added
 * in Phase 1 lands in the events table tagged with the agent's
 * site_type. The widget's App.tsx fires this on every successful init;
 * here we exercise the same backend contract from the server side.
 */
test('init returns site_type + capabilities, then logEvents persists widget.ready with site_type', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'site_type' => 'ecommerce',
    ]);

    // Step 1: init — same call the widget makes on mount.
    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.site_type', 'ecommerce');

    $jwt = $init->json('data.jwt');
    expect($jwt)->toBeString()->not->toBe('');

    $siteType = $init->json('data.agent.site_type');

    // Step 2: logEvents — same call App.tsx fires after init success.
    $this->withHeaders([
        'Authorization' => "Bearer {$jwt}",
        'Origin' => 'https://example.com',
    ])->postJson('/api/v1/widget/events', [
        'events' => [
            [
                'kind' => 'widget.ready',
                'payload' => ['site_type' => $siteType],
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('data.accepted', 1);

    // Step 3: assert the row landed with the right shape.
    $row = Event::query()
        ->where('agent_id', $agent->id)
        ->where('kind', 'widget.ready')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->payload['site_type'])->toBe('ecommerce');
});

test('agents with site_type=null tag the event as generic', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'site_type' => null,
    ]);

    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.site_type', null)
        ->assertJsonPath('data.agent.capabilities', []);

    $jwt = $init->json('data.jwt');

    // The widget's siteType() helper returns 'generic' for null —
    // mirror that here so the persisted event matches what dashboards
    // see in production.
    $this->withHeaders([
        'Authorization' => "Bearer {$jwt}",
        'Origin' => 'https://example.com',
    ])->postJson('/api/v1/widget/events', [
        'events' => [
            ['kind' => 'widget.ready', 'payload' => ['site_type' => 'generic']],
        ],
    ])->assertOk();

    $row = Event::query()
        ->where('agent_id', $agent->id)
        ->where('kind', 'widget.ready')
        ->latest('id')
        ->first();

    expect($row->payload['site_type'])->toBe('generic');
});
