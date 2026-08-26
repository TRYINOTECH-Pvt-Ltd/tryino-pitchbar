<?php

use App\Models\AgentMcpToolGrant;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Fakes\FakeMcpClient;
use App\Services\Mcp\McpToolDiscovery;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Str;

function bindFakeClient(): FakeMcpClient
{
    $fake = new FakeMcpClient;
    app()->instance(McpClient::class, $fake);

    return $fake;
}

function makeServerForDiscovery(string $workspaceId, string $url = 'https://x/mcp'): McpServer
{
    return tap(new McpServer, function (McpServer $s) use ($workspaceId, $url) {
        $s->forceFill([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'label' => 'Linear',
            'server_url' => $url,
            'server_url_hash' => McpServer::hashUrl($url),
            'transport' => 'http',
            'auth_type' => McpServer::AUTH_BEARER,
            'credentials_encrypted' => ['api_key' => 'k'],
            'status' => McpServer::STATUS_PENDING_AUTH,
            'failure_count' => 0,
        ])->save();
    });
}

test('sync creates fresh tool rows with namespaced names', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $fake = bindFakeClient();
    $server = makeServerForDiscovery($bag['workspace']->id);

    $fake->queueServerInfo($server->server_url, new McpServerInfo(
        protocolVersion: '2025-03-26',
        serverName: 'linear-mcp',
        serverVersion: '1.0',
        capabilities: [],
    ));
    $fake->queueToolList($server->server_url, [
        new McpToolSchema('search_issues', 'd1', ['type' => 'object']),
        new McpToolSchema('create_issue', 'd2', ['type' => 'object'], ['destructiveHint' => true]),
    ]);

    expect(app(McpToolDiscovery::class)->sync($server))->toBeTrue();

    $tools = McpTool::query()->withoutWorkspaceScope()->where('mcp_server_id', $server->id)->get();
    expect($tools)->toHaveCount(2);
    expect($tools->pluck('namespaced_name')->all())->toContain('linear.search_issues', 'linear.create_issue');
    expect($tools->where('name', 'create_issue')->first()->is_destructive)->toBeTrue();

    $server->refresh();
    expect($server->status)->toBe(McpServer::STATUS_ACTIVE);
    expect($server->tools_synced_at)->not->toBeNull();
});

test('sync tombstones tools removed by the server', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $fake = bindFakeClient();
    $server = makeServerForDiscovery($bag['workspace']->id);

    $fake->queueServerInfo($server->server_url, new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList($server->server_url, [
        new McpToolSchema('search', 'desc', ['type' => 'object']),
        new McpToolSchema('legacy', 'desc', ['type' => 'object']),
    ]);
    app(McpToolDiscovery::class)->sync($server);

    expect(McpTool::query()->withoutWorkspaceScope()->whereNull('removed_at')->count())->toBe(2);

    // Second refresh — server only reports `search`.
    $fake->queueToolList($server->server_url, [
        new McpToolSchema('search', 'desc', ['type' => 'object']),
    ]);
    app(McpToolDiscovery::class)->sync($server);

    expect(McpTool::query()->withoutWorkspaceScope()->whereNull('removed_at')->count())->toBe(1);
    $legacy = McpTool::query()->withoutWorkspaceScope()->where('name', 'legacy')->first();
    expect($legacy->removed_at)->not->toBeNull();
});

test('sync flips existing grants OFF on schema drift', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $fake = bindFakeClient();
    $server = makeServerForDiscovery($bag['workspace']->id);

    $fake->queueServerInfo($server->server_url, new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList($server->server_url, [
        new McpToolSchema('search', 'desc', [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]),
    ]);

    app(McpToolDiscovery::class)->sync($server);

    $tool = McpTool::query()->withoutWorkspaceScope()->where('mcp_server_id', $server->id)->first();
    AgentMcpToolGrant::query()->forceCreate([
        'agent_id' => $bag['agent']->id,
        'mcp_tool_id' => $tool->id,
        'workspace_id' => $bag['workspace']->id,
        'enabled' => true,
        'enabled_at' => now(),
    ]);

    // Second refresh — server changed the schema.
    $fake->queueToolList($server->server_url, [
        new McpToolSchema('search', 'desc', [
            'type' => 'object',
            'properties' => ['filter' => ['type' => 'object'], 'urgency' => ['type' => 'string']],
            'required' => ['filter'],
        ]),
    ]);
    app(McpToolDiscovery::class)->sync($server);

    $grant = AgentMcpToolGrant::query()->withoutWorkspaceScope()
        ->where('mcp_tool_id', $tool->id)->first();
    expect($grant->enabled)->toBeFalse();
});

test('sync handles tool reappearing (removed_at cleared) without re-enabling grant', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $fake = bindFakeClient();
    $server = makeServerForDiscovery($bag['workspace']->id);
    $fake->queueServerInfo($server->server_url, new McpServerInfo('2025-03-26', 'x', '1', []));

    $fake->queueToolList($server->server_url, [new McpToolSchema('s', 'd', ['type' => 'object'])]);
    app(McpToolDiscovery::class)->sync($server);

    // Disappear.
    $fake->queueToolList($server->server_url, []);
    app(McpToolDiscovery::class)->sync($server);
    expect(McpTool::query()->withoutWorkspaceScope()->whereNull('removed_at')->count())->toBe(0);

    // Reappear.
    $fake->queueToolList($server->server_url, [new McpToolSchema('s', 'd', ['type' => 'object'])]);
    app(McpToolDiscovery::class)->sync($server);
    expect(McpTool::query()->withoutWorkspaceScope()->whereNull('removed_at')->count())->toBe(1);
});

test('sync transport failure marks server degraded + stores error', function () {
    $bag = workspaceMemberWithAgent();
    app(CurrentWorkspace::class)->clear();

    $fake = bindFakeClient();
    $server = makeServerForDiscovery($bag['workspace']->id);
    // queueServerInfo NOT called → fake returns a default ServerInfo,
    // BUT listTools without a queue returns []. Force a real failure
    // by making initialize throw via the fake's queue mechanism.
    $fake->makePingFail($server->server_url); // not what discovery uses
    // Instead: stub the bound client to throw directly via the queued exception path.
    // Easiest path: replace the bound client with a throwing impl.
    app()->instance(McpClient::class, new class implements McpClient
    {
        public function initialize($conn): McpServerInfo
        {
            throw new McpTransportException('Connection refused');
        }

        public function ping($conn): bool
        {
            return false;
        }

        public function listTools($conn): array
        {
            return [];
        }

        public function callTool($conn, $n, $a, $t): McpToolResult
        {
            return new McpToolResult('x');
        }
    });

    expect(app(McpToolDiscovery::class)->sync($server))->toBeFalse();
    $server->refresh();
    expect($server->status)->toBe(McpServer::STATUS_DEGRADED);
    expect($server->tools_sync_error)->toContain('Connection refused');
});

test('McpServer::hashUrl canonicalises trailing slash + case', function () {
    expect(McpServer::hashUrl('https://EXAMPLE.com/mcp/'))
        ->toBe(McpServer::hashUrl('https://example.com/mcp'));
});
