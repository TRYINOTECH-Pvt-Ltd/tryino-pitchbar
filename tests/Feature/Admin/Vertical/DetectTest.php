<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function makeUserWorkspace(): array
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

function bindMockHttp(MockHandler $mock): void
{
    app()->instance(Client::class, new Client(['handler' => HandlerStack::create($mock)]));
}

test('detect with mocked ecommerce HTML returns ecommerce + persists vertical_signals', function () {
    ['user' => $user, 'workspace' => $workspace] = makeUserWorkspace();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $html = (string) file_get_contents(base_path('tests/Fixtures/vertical/ecommerce.html'));
    bindMockHttp(new MockHandler([new Response(200, [], $html)]));

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/detect", [
            'url' => 'https://shop.example.com/products/widget',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.type', 'ecommerce');

    $signals = $agent->fresh()->vertical_signals;
    expect($signals['type'])->toBe('ecommerce');
    expect($signals['detected_at'])->toBeString();
    expect($signals['detected_url'])->toBe('https://shop.example.com/products/widget');
});

test('detect with a 5xx upstream falls back to generic without crashing', function () {
    ['user' => $user, 'workspace' => $workspace] = makeUserWorkspace();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    bindMockHttp(new MockHandler([new Response(503, [], 'gateway timeout')]));

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/detect", [
            'url' => 'https://broken.example.com',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.type', 'generic')
        ->assertJsonPath('data.confidence', 0)
        ->assertJsonPath('data.signals', ['fetch_failed']);
});

test('cross-workspace agent is forbidden', function () {
    ['user' => $user] = makeUserWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $otherWorkspace->id]);

    bindMockHttp(new MockHandler([new Response(200, [], '<html></html>')]));

    $this->actingAs($user)
        ->postJson("/app/agents/{$foreignAgent->id}/vertical/detect", [
            'url' => 'https://anything.example',
        ])
        ->assertForbidden();
});

test('missing url returns 422', function () {
    ['user' => $user, 'workspace' => $workspace] = makeUserWorkspace();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/vertical/detect", [])
        ->assertStatus(422);
});
