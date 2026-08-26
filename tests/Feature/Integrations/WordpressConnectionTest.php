<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wordpressConnectionUser(): array
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

test('integrations page surfaces WordPress connections from wp_integration column', function () {
    ['user' => $user, 'workspace' => $workspace] = wordpressConnectionUser();
    $now = now()->toIso8601String();

    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'wp_integration' => [
            'site_url' => 'https://shop.example.com',
            'plugin_version' => '2.0.4',
            'wordpress_version' => '6.6',
            'woocommerce_active' => true,
            'last_seen_at' => $now,
        ],
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props)->toHaveKey('wordpressConnections');
    expect($props['wordpressConnections'])->toHaveCount(1);

    $row = $props['wordpressConnections'][0];
    expect($row['agent_id'])->toBe($agent->id);
    expect($row['site_url'])->toBe('https://shop.example.com');
    expect($row['plugin_version'])->toBe('2.0.4');
    expect($row['woocommerce_active'])->toBeTrue();
    expect($row['last_seen_at'])->toBe($now);
});

test('agents without a wp_integration stamp do not appear on the integrations page', function () {
    ['user' => $user, 'workspace' => $workspace] = wordpressConnectionUser();
    Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'wp_integration' => null,
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props['wordpressConnections'])->toBe([]);
});

test('agents from other workspaces never leak into the integrations page', function () {
    ['user' => $user, 'workspace' => $workspace] = wordpressConnectionUser();

    $otherWorkspace = Workspace::factory()->create();
    Agent::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'wp_integration' => [
            'site_url' => 'https://other.example.com',
            'plugin_version' => '2.0.4',
            'last_seen_at' => now()->toIso8601String(),
        ],
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props['wordpressConnections'])->toBe([]);
});
