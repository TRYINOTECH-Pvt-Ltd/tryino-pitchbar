<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    // The sync queue would otherwise run CrawlSourceJob → CrawlPageJob,
    // which calls Cloudflare Browser Rendering with empty creds and
    // surfaces a 401. We only care that the endpoint persisted the
    // source row + dispatched the job; the job itself is exercised by
    // CrawlSourceJobTest.
    Queue::fake();
});

function tokenWith(string $ability, ?Workspace $workspace = null): array
{
    $workspace = $workspace ?? Workspace::factory()->create();
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => null,
        'name' => 'Test token',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => [$ability],
        'shopper_signing_secret' => Str::random(48),
    ]);

    return ['token' => $plaintext, 'workspace' => $workspace];
}

test('POST /api/v1/workspace/sources creates a URL source bound to the token workspace', function () {
    ['token' => $token, 'workspace' => $workspace] = tokenWith('sources:write');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workspace/sources', [
            'agent_id' => $agent->id,
            'kind' => 'url',
            'url' => 'https://example.com/pricing',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.kind', 'url')
        ->assertJsonPath('data.agent_id', $agent->id)
        ->assertJsonPath('data.status', 'pending');

    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())
        ->toBe(1);
    Queue::assertPushed(CrawlSourceJob::class);
});

test('POST /api/v1/workspace/sources creates a text source', function () {
    ['token' => $token, 'workspace' => $workspace] = tokenWith('sources:write');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workspace/sources', [
            'agent_id' => $agent->id,
            'kind' => 'text',
            'title' => 'Pricing breakdown',
            'content' => str_repeat('Pricing details here. ', 8),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.kind', 'text');

    $source = Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->first();
    expect($source->config['title'])->toBe('Pricing breakdown');
});

test('rejects requests without the sources:write ability', function () {
    ['token' => $wpToken, 'workspace' => $workspace] = tokenWith('wp:integration');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->withHeaders(['Authorization' => "Bearer {$wpToken}"])
        ->postJson('/api/v1/workspace/sources', [
            'agent_id' => $agent->id,
            'kind' => 'url',
            'url' => 'https://example.com',
        ])
        ->assertForbidden();
});

test('rejects requests without any token', function () {
    $this->postJson('/api/v1/workspace/sources', [
        'agent_id' => '019e0000-0000-0000-0000-000000000000',
        'kind' => 'url',
        'url' => 'https://example.com',
    ])->assertUnauthorized();
});

test('refuses to attach a source to an agent in a different workspace (tenancy guard)', function () {
    ['token' => $token, 'workspace' => $myWorkspace] = tokenWith('sources:write');
    $otherWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workspace/sources', [
            'agent_id' => $foreignAgent->id,
            'kind' => 'url',
            'url' => 'https://example.com',
        ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'agent_not_found');

    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $foreignAgent->id)->count())
        ->toBe(0);
});

test('GET /api/v1/workspace/sources lists only this workspace\'s sources', function () {
    ['token' => $token, 'workspace' => $myWorkspace] = tokenWith('sources:write');
    $myAgent = Agent::factory()->create(['workspace_id' => $myWorkspace->id]);
    Source::create([
        'agent_id' => $myAgent->id,
        'type' => 'text',
        'status' => 'indexed',
        'config' => ['title' => 'Mine'],
    ]);

    $otherWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $otherWorkspace->id]);
    Source::create([
        'agent_id' => $foreignAgent->id,
        'type' => 'text',
        'status' => 'indexed',
        'config' => ['title' => 'Theirs'],
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/workspace/sources');

    $response->assertOk();
    $payload = $response->json('data');
    expect(count($payload))->toBe(1);
    expect($payload[0]['config']['title'])->toBe('Mine');
});

test('validates kind enum', function () {
    ['token' => $token, 'workspace' => $workspace] = tokenWith('sources:write');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workspace/sources', [
            'agent_id' => $agent->id,
            'kind' => 'bogus',
            'url' => 'https://example.com',
        ])
        ->assertStatus(422);
});
