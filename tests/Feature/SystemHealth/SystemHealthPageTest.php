<?php

use App\Models\Agent;
use App\Models\CronTickLog;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;

function shAsMember(string $role = 'admin'): array
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

test('admin can load system-health page', function () {
    ['user' => $user] = shAsMember('admin');

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/system-health/index')
            ->has('llm')
            ->has('cloudflare')
            ->has('queue')
            ->has('embedding_fallback')
            ->has('env')
            ->has('sources')
        );
});

test('viewer-role member is blocked', function () {
    ['user' => $user] = shAsMember('viewer');

    $response = $this->actingAs($user)->get('/app/system-health');

    expect($response->status())->toBeIn([302, 403, 404]);
});

test('guest is redirected to login', function () {
    $this->get('/app/system-health')->assertRedirect('/login');
});

test('llm provider flagged when Fake client resolved', function () {
    ['user' => $user] = shAsMember('admin');
    $this->app->instance(OpenAiClient::class, new FakeOpenAi);

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('llm.provider', 'fake')
            ->where('llm.ok', false)
        );
});

test('queue liveness reports inline when driver is sync', function () {
    ['user' => $user] = shAsMember('admin');

    config(['queue.default' => 'sync']);

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('queue.driver', 'sync')
            ->where('queue.liveness', 'inline')
            ->where('queue.seconds_since_tick', null)
        );
});

test('queue liveness reports live when a tick arrived in the last 90 seconds', function () {
    ['user' => $user] = shAsMember('admin');
    config(['queue.default' => 'database']);

    CronTickLog::create([
        'received_at' => now()->subSeconds(10),
        'processed' => 3,
        'failed_in_tick' => 0,
        'remaining_pending' => 0,
        'elapsed_ms' => 120,
    ]);

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('queue.driver', 'database')
            ->where('queue.liveness', 'live')
        );
});

test('queue liveness reports dead when last tick is older than 5 minutes', function () {
    ['user' => $user] = shAsMember('admin');
    config(['queue.default' => 'database']);

    CronTickLog::create([
        'received_at' => now()->subMinutes(10),
        'processed' => 0,
        'failed_in_tick' => 0,
        'remaining_pending' => 0,
        'elapsed_ms' => 100,
    ]);

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('queue.liveness', 'dead')
            ->where('queue.hint', fn ($h) => is_string($h) && str_contains($h, 'Worker is down'))
        );
});

test('admin can probe the LLM via /system-health/probe-llm', function () {
    ['user' => $user] = shAsMember('admin');

    $response = $this->actingAs($user)
        ->postJson('/app/system-health/probe-llm');

    $response->assertOk()
        ->assertJson(fn ($json) => $json
            ->has('ok')
            ->has('provider')
            ->has('model')
            ->has('elapsed_ms')
            ->etc());
});

test('probe endpoint blocks non-admin members', function () {
    ['user' => $user] = shAsMember('viewer');

    $response = $this->actingAs($user)->postJson('/app/system-health/probe-llm');

    expect($response->status())->toBeIn([302, 403, 404]);
});

test('source problems show only this workspace agents', function () {
    ['user' => $user, 'workspace' => $workspace] = shAsMember('admin');

    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'failed',
        'error' => '[OpenAiRateLimitException] 429 from Workers AI',
    ]);
    Document::query();

    $otherAgent = Agent::factory()->create();
    Source::create([
        'agent_id' => $otherAgent->id,
        'type' => 'url',
        'status' => 'failed',
        'error' => 'should not leak',
    ]);

    $this->actingAs($user)
        ->get('/app/system-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sources', 1)
            ->where('sources.0.id', (string) $source->id)
            ->where('sources.0.error', '[OpenAiRateLimitException] 429 from Workers AI')
        );
});
