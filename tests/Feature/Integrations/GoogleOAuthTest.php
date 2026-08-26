<?php

use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Google\GoogleClient;

function googleUser(): array
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
    config()->set('services.google.client_id', 'test-client');
    config()->set('services.google.client_secret', 'test-secret');
    config()->set('services.google.redirect_uri', 'https://app.test/app/oauth/google/callback');
});

test('start redirects to Google authorize with the right scopes and access_type=offline', function () {
    ['user' => $user] = googleUser();

    $response = $this->actingAs($user)->get('/app/oauth/google/connect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth');
    expect($location)->toContain('client_id=test-client');
    expect($location)->toContain('access_type=offline');
    expect($location)->toContain('prompt=consent');
    expect($location)->toContain('drive.readonly');
    expect($location)->toContain('documents.readonly');
    // Sheets ingest scope (added 2026-04 for Google Sheets source
    // type, doc-synced 2026-05-29 client report). All three scopes
    // must ship together — omitting `spreadsheets.readonly` blocks
    // the Google Sheets tab without explanation.
    expect($location)->toContain('spreadsheets.readonly');
});

test('start fails gracefully when Google is not configured', function () {
    config()->set('services.google.client_id', null);
    ['user' => $user] = googleUser();

    $response = $this->actingAs($user)->get('/app/oauth/google/connect');

    $response->assertRedirect('/app/integrations');
    expect(session('error'))->toContain('GOOGLE_CLIENT_ID');
});

test('callback exchanges code, stores access + refresh tokens', function () {
    ['user' => $user, 'workspace' => $ws] = googleUser();

    $mock = Mockery::mock(GoogleClient::class);
    $mock->shouldReceive('exchangeCode')->once()->andReturn([
        'access_token' => 'ya29.access',
        'refresh_token' => '1//refresh',
        'expires_in' => 3600,
        'scope' => 'https://www.googleapis.com/auth/drive.readonly',
        'token_type' => 'Bearer',
    ]);
    app()->instance(GoogleClient::class, $mock);

    $response = $this->actingAs($user)
        ->withSession(['google.oauth_state' => ['state' => 'good', 'workspace_id' => $ws->id]])
        ->get('/app/oauth/google/callback?code=abc&state=good');

    $response->assertRedirect('/app/integrations');
    $row = IntegrationConnection::query()->where('workspace_id', $ws->id)->where('kind', 'google')->first();
    expect($row)->not->toBeNull();
    expect($row->credentials_encrypted['access_token'])->toBe('ya29.access');
    expect($row->credentials_encrypted['refresh_token'])->toBe('1//refresh');
});

test('callback rejects bad state', function () {
    ['user' => $user, 'workspace' => $ws] = googleUser();

    $response = $this->actingAs($user)
        ->withSession(['google.oauth_state' => ['state' => 'good', 'workspace_id' => $ws->id]])
        ->get('/app/oauth/google/callback?code=abc&state=tampered');

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

    $this->actingAs($viewer)->get('/app/oauth/google/connect')->assertForbidden();
});
