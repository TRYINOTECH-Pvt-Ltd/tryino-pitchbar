<?php

use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Notion\NotionClient;

function notionUser(): array
{
    $user = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return ['user' => $user, 'workspace' => $ws];
}

beforeEach(function () {
    config()->set('services.notion.client_id', 'test-client');
    config()->set('services.notion.client_secret', 'test-secret');
    config()->set('services.notion.redirect_uri', 'https://app.test/app/oauth/notion/callback');
});

test('start redirects to Notion authorize with state pinned to session', function () {
    ['user' => $user] = notionUser();

    $response = $this->actingAs($user)->get('/app/oauth/notion/connect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://api.notion.com/v1/oauth/authorize');
    expect($location)->toContain('client_id=test-client');
    expect($location)->toContain('response_type=code');

    expect(session('notion.oauth_state.state'))->toBeString();
});

test('start fails gracefully when Notion is not configured', function () {
    config()->set('services.notion.client_id', null);
    ['user' => $user] = notionUser();

    $response = $this->actingAs($user)->get('/app/oauth/notion/connect');

    $response->assertRedirect('/app/integrations');
    expect(session('error'))->toContain('NOTION_CLIENT_ID');
});

test('callback exchanges code, stores token, and redirects to integrations', function () {
    ['user' => $user, 'workspace' => $ws] = notionUser();

    $mock = Mockery::mock(NotionClient::class);
    $mock->shouldReceive('exchangeCode')
        ->once()
        ->andReturn([
            'access_token' => 'secret_xyz',
            'workspace_id' => 'ws-123',
            'workspace_name' => 'Acme',
            'bot_id' => 'bot-456',
        ]);
    app()->instance(NotionClient::class, $mock);

    $response = $this->actingAs($user)
        ->withSession(['notion.oauth_state' => ['state' => 'good-state', 'workspace_id' => $ws->id]])
        ->get('/app/oauth/notion/callback?code=abc&state=good-state');

    $response->assertRedirect('/app/integrations');
    $row = IntegrationConnection::query()->where('workspace_id', $ws->id)->where('kind', 'notion')->first();
    expect($row)->not->toBeNull();
    expect($row->credentials_encrypted['access_token'])->toBe('secret_xyz');
    expect($row->credentials_encrypted['workspace_name'])->toBe('Acme');
});

test('callback rejects bad state', function () {
    ['user' => $user, 'workspace' => $ws] = notionUser();

    $response = $this->actingAs($user)
        ->withSession(['notion.oauth_state' => ['state' => 'good', 'workspace_id' => $ws->id]])
        ->get('/app/oauth/notion/callback?code=abc&state=tampered');

    $response->assertRedirect('/app/integrations');
    expect(IntegrationConnection::query()->where('workspace_id', $ws->id)->count())->toBe(0);
});

test('callback rejects state older than the TTL', function () {
    ['user' => $user, 'workspace' => $ws] = notionUser();

    $response = $this->actingAs($user)
        ->withSession(['notion.oauth_state' => [
            'state' => 'good',
            'workspace_id' => $ws->id,
            'issued_at' => now()->subMinutes(30)->toIso8601String(),
        ]])
        ->get('/app/oauth/notion/callback?code=abc&state=good');

    $response->assertRedirect('/app/integrations');
    expect(IntegrationConnection::query()->where('workspace_id', $ws->id)->count())->toBe(0);
});

test('viewer cannot start the OAuth flow', function () {
    $viewer = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $ws->id])->save();

    $this->actingAs($viewer)->get('/app/oauth/notion/connect')->assertForbidden();
});
