<?php

use App\Models\McpServer;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\McpCredentialResolver;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Str;

function makeServer(array $attrs = []): McpServer
{
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    return tap(new McpServer, function (McpServer $s) use ($bag, $attrs) {
        $s->forceFill(array_merge([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $bag['workspace']->id,
            'label' => 'Test',
            'server_url' => 'https://example.com/mcp',
            'server_url_hash' => str_repeat('c', 64),
            'transport' => 'http',
            'auth_type' => McpServer::AUTH_NONE,
            'status' => McpServer::STATUS_ACTIVE,
            'failure_count' => 0,
        ], $attrs))->save();
    });
}

test('auth_type=none returns null header', function () {
    $server = makeServer(['auth_type' => McpServer::AUTH_NONE]);
    $result = (new McpCredentialResolver)->resolve($server);

    expect($result['header'])->toBeNull();
    expect($result['expires_at'])->toBeNull();
});

test('auth_type=bearer returns Bearer header from encrypted creds', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_BEARER,
        'credentials_encrypted' => ['api_key' => 'sk-secret-123'],
    ]);
    $result = (new McpCredentialResolver)->resolve($server);

    expect($result['header'])->toBe('Bearer sk-secret-123');
});

test('auth_type=bearer with missing api_key throws unauthorized', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_BEARER,
        'credentials_encrypted' => [],
    ]);

    expect(fn () => (new McpCredentialResolver)->resolve($server))
        ->toThrow(McpUnauthorizedException::class);
});

test('revoked server status throws unauthorized', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_BEARER,
        'status' => McpServer::STATUS_REVOKED,
        'credentials_encrypted' => ['api_key' => 'ok'],
    ]);

    expect(fn () => (new McpCredentialResolver)->resolve($server))
        ->toThrow(McpUnauthorizedException::class);
});

test('disabled server status throws unauthorized', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_BEARER,
        'status' => McpServer::STATUS_DISABLED,
        'credentials_encrypted' => ['api_key' => 'ok'],
    ]);

    expect(fn () => (new McpCredentialResolver)->resolve($server))
        ->toThrow(McpUnauthorizedException::class);
});

test('auth_type=oauth2_pkce returns Bearer header from access_token', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_OAUTH2_PKCE,
        'credentials_encrypted' => ['access_token' => 'oauth-token-xyz', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    $result = (new McpCredentialResolver)->resolve($server);
    expect($result['header'])->toBe('Bearer oauth-token-xyz');
    expect($result['expires_at'])->not->toBeNull();
});

test('auth_type=oauth2_pkce with no access_token throws unauthorized', function () {
    $server = makeServer([
        'auth_type' => McpServer::AUTH_OAUTH2_PKCE,
        'credentials_encrypted' => [],
    ]);

    expect(fn () => (new McpCredentialResolver)->resolve($server))
        ->toThrow(McpUnauthorizedException::class);
});
