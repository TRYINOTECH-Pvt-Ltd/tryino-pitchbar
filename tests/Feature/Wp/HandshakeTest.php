<?php

use App\Models\Agent;
use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function issueWpToken(string $workspaceId, array $abilities = ['wp:integration']): string
{
    $plaintext = 'pbar_'.Str::random(48);

    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'test token',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => $abilities,
    ]);

    return $plaintext;
}

test('handshake returns workspace and agents for a valid token', function () {
    ['workspace' => $workspace] = workspaceMember();
    Agent::factory()->count(2)->create(['workspace_id' => $workspace->id]);

    $plaintext = issueWpToken($workspace->id);

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'woocommerce_active' => true,
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])
        ->assertOk()
        ->assertJsonPath('data.workspace.id', $workspace->id)
        ->assertJsonPath('data.recommended_site_type', 'ecommerce')
        ->assertJsonCount(2, 'data.agents');
});

test('handshake rejects a missing token', function () {
    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_token');
});

test('handshake rejects an unknown token', function () {
    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ], [
        'Authorization' => 'Bearer pbar_'.str_repeat('x', 48),
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');
});

test('handshake rejects a revoked token', function () {
    ['workspace' => $workspace] = workspaceMember();
    $plaintext = 'pbar_'.Str::random(48);

    WorkspaceApiToken::create([
        'workspace_id' => $workspace->id,
        'name' => 'revoked',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
        'revoked_at' => now(),
    ]);

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])
        ->assertUnauthorized();
});

test('handshake rejects a token missing the wp:integration ability', function () {
    ['workspace' => $workspace] = workspaceMember();
    $plaintext = issueWpToken($workspace->id, ['some:other']);

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability');
});

test('handshake only returns agents from the token workspace', function () {
    ['workspace' => $workspaceA] = workspaceMember();
    ['workspace' => $workspaceB] = workspaceMember();

    Agent::factory()->create(['workspace_id' => $workspaceA->id]);
    Agent::factory()->count(3)->create(['workspace_id' => $workspaceB->id]);

    $plaintext = issueWpToken($workspaceA->id);

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data.agents');
});

test('handshake validates the request body', function () {
    ['workspace' => $workspace] = workspaceMember();
    $plaintext = issueWpToken($workspace->id);

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'not-a-url',
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['site_url', 'plugin_version']);
});

test('handshake updates last_used_at', function () {
    ['workspace' => $workspace] = workspaceMember();
    $plaintext = issueWpToken($workspace->id);

    $token = WorkspaceApiToken::query()->where('workspace_id', $workspace->id)->sole();
    expect($token->last_used_at)->toBeNull();

    $this->postJson('/api/v1/wp/handshake', [
        'site_url' => 'https://example.com',
        'plugin_version' => '1.0.0',
    ], [
        'Authorization' => 'Bearer '.$plaintext,
    ])->assertOk();

    expect($token->refresh()->last_used_at)->not()->toBeNull();
});
