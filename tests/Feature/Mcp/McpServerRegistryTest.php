<?php

use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\McpServerRegistry;
use App\Support\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function seedServerAndTool(string $workspaceId, string $agentId, bool $grantEnabled, string $serverStatus = McpServer::STATUS_ACTIVE): array
{
    $server = new McpServer;
    $server->forceFill([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspaceId,
        'label' => 'Linear',
        'server_url' => 'https://linear/mcp',
        'server_url_hash' => hash('sha256', 'https://linear/mcp-'.uniqid()),
        'transport' => 'http',
        'auth_type' => McpServer::AUTH_BEARER,
        'credentials_encrypted' => ['api_key' => 'k'],
        'status' => $serverStatus,
        'failure_count' => 0,
    ])->save();

    $tool = new McpTool;
    $tool->forceFill([
        'id' => (string) Str::uuid7(),
        'mcp_server_id' => $server->id,
        'workspace_id' => $workspaceId,
        'name' => 'search',
        'namespaced_name' => 'linear.search',
        'description' => 'Search issues',
        'input_schema' => ['type' => 'object'],
        'is_destructive' => false,
        'is_idempotent' => true,
        'requires_open_world' => true,
        'catalogue_revision' => 1,
    ])->save();

    DB::table('agent_mcp_tool_grants')->insert([
        'agent_id' => $agentId,
        'mcp_tool_id' => $tool->id,
        'workspace_id' => $workspaceId,
        'enabled' => $grantEnabled,
        'enabled_at' => $grantEnabled ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['server' => $server, 'tool' => $tool];
}

test('serversForAgent returns servers with enabled tool grants only', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    seedServerAndTool($bag['workspace']->id, $bag['agent']->id, true);

    $registry = app(McpServerRegistry::class);
    $servers = $registry->serversForAgent($bag['agent']);

    expect($servers)->toHaveCount(1);
    expect($servers->first()->label)->toBe('Linear');
});

test('serversForAgent excludes when grant is disabled', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    seedServerAndTool($bag['workspace']->id, $bag['agent']->id, false);

    $registry = app(McpServerRegistry::class);
    expect($registry->serversForAgent($bag['agent']))->toHaveCount(0);
});

test('serversForAgent excludes when server status is disabled', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    seedServerAndTool($bag['workspace']->id, $bag['agent']->id, true, McpServer::STATUS_DISABLED);

    expect(app(McpServerRegistry::class)->serversForAgent($bag['agent']))->toHaveCount(0);
});

test('grantedToolsForAgent excludes tombstoned tools', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    $seed = seedServerAndTool($bag['workspace']->id, $bag['agent']->id, true);
    $seed['tool']->forceFill(['removed_at' => now()])->save();

    expect(app(McpServerRegistry::class)->grantedToolsForAgent($bag['agent']))->toHaveCount(0);
});

test('invalidate busts the registry cache', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    seedServerAndTool($bag['workspace']->id, $bag['agent']->id, true);
    $registry = app(McpServerRegistry::class);

    expect($registry->serversForAgent($bag['agent']))->toHaveCount(1);

    // Disable directly in DB, registry cache still hits the cached array.
    DB::table('agent_mcp_tool_grants')->update(['enabled' => false]);
    expect($registry->serversForAgent($bag['agent']))->toHaveCount(1); // stale cache

    $registry->invalidate($bag['agent']);
    expect($registry->serversForAgent($bag['agent']))->toHaveCount(0); // fresh
});

test('cross-tenant isolation: agent in workspace B cannot see server attached to workspace A', function () {
    $a = workspaceMemberWithAgent();
    $b = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    seedServerAndTool($a['workspace']->id, $a['agent']->id, true);

    $registry = app(McpServerRegistry::class);
    expect($registry->serversForAgent($b['agent']))->toHaveCount(0);
});

test('attachServer creates row with hashed URL + bearer creds + active status', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $registry = app(McpServerRegistry::class);
    $server = $registry->attachServer(
        workspaceId: $bag['workspace']->id,
        label: 'Linear',
        serverUrl: 'https://example.com/mcp',
        authType: McpServer::AUTH_BEARER,
        credentials: ['api_key' => 'k1'],
    );

    expect($server->server_url_hash)->toBe(McpServer::hashUrl('https://example.com/mcp'));
    expect($server->status)->toBe(McpServer::STATUS_ACTIVE);
    expect($server->credentials_encrypted['api_key'])->toBe('k1');
});

test('attachServer with OAuth marks status pending_auth', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $server = app(McpServerRegistry::class)->attachServer(
        workspaceId: $bag['workspace']->id,
        label: 'GitHub',
        serverUrl: 'https://github/mcp',
        authType: McpServer::AUTH_OAUTH2_PKCE,
        credentials: null,
    );

    expect($server->status)->toBe(McpServer::STATUS_PENDING_AUTH);
});

test('attachServer duplicate URL raises unique violation', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $registry = app(McpServerRegistry::class);
    $registry->attachServer(
        workspaceId: $bag['workspace']->id,
        label: 'Linear',
        serverUrl: 'https://example.com/mcp',
        authType: McpServer::AUTH_BEARER,
        credentials: ['api_key' => 'k1'],
    );

    expect(fn () => $registry->attachServer(
        workspaceId: $bag['workspace']->id,
        label: 'Linear 2',
        serverUrl: 'https://example.com/mcp',
        authType: McpServer::AUTH_BEARER,
        credentials: ['api_key' => 'k2'],
    ))->toThrow(UniqueConstraintViolationException::class);
});

test('setGrant is idempotent on (agent, tool)', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $seed = seedServerAndTool($bag['workspace']->id, $bag['agent']->id, false);
    $registry = app(McpServerRegistry::class);

    $registry->setGrant($bag['agent'], $seed['tool'], enabled: true);
    $registry->setGrant($bag['agent'], $seed['tool'], enabled: true);

    expect(DB::table('agent_mcp_tool_grants')->count())->toBe(1);
});
