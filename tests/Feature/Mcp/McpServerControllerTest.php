<?php

use App\Models\AgentMcpToolGrant;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Fakes\FakeMcpClient;
use App\Support\CurrentWorkspace;

function bindFakeMcpForCtrl(): FakeMcpClient
{
    $fake = new FakeMcpClient;
    app()->instance(McpClient::class, $fake);

    return $fake;
}

function attachWorkspaceCtx(array $bag): void
{
    app(CurrentWorkspace::class)->setForRequest($bag['workspace']);
}

test('GET /app/agents/{agent}/mcp shows the form + empty server list', function () {
    $bag = workspaceMemberWithAgent();
    $this->actingAs($bag['user']);

    $res = $this->get("/app/agents/{$bag['agent']->id}/mcp");
    $res->assertOk();
    $props = $res->viewData('page')['props'];
    expect($props['servers'])->toBe([]);
});

test('POST /app/agents/{agent}/mcp attaches a bearer server + runs discovery', function () {
    $bag = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();

    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo(
        protocolVersion: '2025-03-26',
        serverName: 'linear-mcp',
        serverVersion: '1.0',
        capabilities: [],
    ));
    $fake->queueToolList('https://example.com/mcp', [
        new McpToolSchema('search', 'desc', ['type' => 'object']),
    ]);

    $this->actingAs($bag['user']);
    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'Linear',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_BEARER,
        'api_key' => 'sk-test-123',
    ]);
    $res->assertRedirect();

    $server = McpServer::query()
        ->withoutWorkspaceScope()
        ->where('workspace_id', $bag['workspace']->id)
        ->where('label', 'Linear')
        ->first();
    expect($server)->not->toBeNull();
    expect($server->credentials_encrypted['api_key'])->toBe('sk-test-123');
    expect($server->status)->toBe(McpServer::STATUS_ACTIVE);

    expect(McpTool::query()->withoutWorkspaceScope()->where('mcp_server_id', $server->id)->count())->toBe(1);
});

test('POST /app/agents/{agent}/mcp rejects SSRF URLs at validation time', function () {
    $bag = workspaceMemberWithAgent();
    bindFakeMcpForCtrl();

    $this->actingAs($bag['user']);
    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'Bad',
        'server_url' => 'http://169.254.169.254/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);
    $res->assertSessionHasErrors('server_url');
    expect(McpServer::query()->withoutWorkspaceScope()->count())->toBe(0);
});

test('POST /app/agents/{agent}/mcp duplicate URL surfaces error', function () {
    $bag = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();
    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList('https://example.com/mcp', []);

    $this->actingAs($bag['user']);
    $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'First',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ])->assertRedirect();

    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'Second',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);
    $res->assertSessionHasErrors('server_url');
});

test('DELETE /app/agents/{agent}/mcp/{mcpServer} removes server', function () {
    $bag = workspaceMemberWithAgent();
    bindFakeMcpForCtrl();

    $this->actingAs($bag['user']);
    $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);

    $server = McpServer::query()->withoutWorkspaceScope()->first();
    $this->delete("/app/agents/{$bag['agent']->id}/mcp/{$server->id}")->assertRedirect();

    expect(McpServer::query()->withoutWorkspaceScope()->count())->toBe(0);
});

test('POST /app/agents/{agent}/mcp/{mcpServer}/test calls ping + records result', function () {
    $bag = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();
    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo('2025-03-26', 'linear', '2.0', []));

    $this->actingAs($bag['user']);
    $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);

    $server = McpServer::query()->withoutWorkspaceScope()->first();
    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp/{$server->id}/test");
    $res->assertRedirect();

    $server->refresh();
    expect($server->connection_test_result['ok'])->toBeTrue();
    expect($server->connection_test_result['server'])->toBe('linear');
});

test('GET /app/agents/{agent}/mcp/{mcpServer}/tools lists tools + their grants', function () {
    $bag = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();
    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList('https://example.com/mcp', [
        new McpToolSchema('search', 'desc', ['type' => 'object']),
        new McpToolSchema('delete', 'desc', ['type' => 'object'], ['destructiveHint' => true]),
    ]);

    $this->actingAs($bag['user']);
    $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);

    $server = McpServer::query()->withoutWorkspaceScope()->first();
    $res = $this->get("/app/agents/{$bag['agent']->id}/mcp/{$server->id}/tools");
    $res->assertOk();
    $props = $res->viewData('page')['props'];
    expect($props['tools'])->toHaveCount(2);
    expect(collect($props['tools'])->where('is_destructive', true)->count())->toBe(1);
});

test('PATCH /app/agents/{agent}/mcp/{mcpServer}/tools updates grants', function () {
    $bag = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();
    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList('https://example.com/mcp', [
        new McpToolSchema('search', 'd', ['type' => 'object']),
    ]);

    $this->actingAs($bag['user']);
    $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);

    $server = McpServer::query()->withoutWorkspaceScope()->first();
    $tool = McpTool::query()->withoutWorkspaceScope()->first();

    $res = $this->patch("/app/agents/{$bag['agent']->id}/mcp/{$server->id}/tools", [
        'grants' => [['tool_id' => $tool->id, 'enabled' => true]],
    ]);
    $res->assertRedirect();

    $grant = AgentMcpToolGrant::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $bag['agent']->id)
        ->where('mcp_tool_id', $tool->id)
        ->first();
    expect($grant)->not->toBeNull();
    expect($grant->enabled)->toBeTrue();
    expect($grant->enabled_by_user_id)->toBe($bag['user']->id);
});

test('cross-tenant isolation: workspace B cannot view workspace A server', function () {
    $a = workspaceMemberWithAgent();
    $b = workspaceMemberWithAgent();
    $fake = bindFakeMcpForCtrl();
    $fake->queueServerInfo('https://example.com/mcp', new McpServerInfo('2025-03-26', 'x', '1', []));
    $fake->queueToolList('https://example.com/mcp', []);

    $this->actingAs($a['user']);
    $this->post("/app/agents/{$a['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);

    $server = McpServer::query()->withoutWorkspaceScope()->first();

    // User B tries to delete A's server.
    $this->actingAs($b['user']);
    $res = $this->delete("/app/agents/{$b['agent']->id}/mcp/{$server->id}");
    expect($res->status())->toBe(404);
});

test('non-authenticated request bounces with redirect', function () {
    $bag = workspaceMemberWithAgent();
    $this->get("/app/agents/{$bag['agent']->id}/mcp")
        ->assertStatus(302);
});

test('non-https URL is rejected by url:http,https rule', function () {
    $bag = workspaceMemberWithAgent();
    bindFakeMcpForCtrl();

    $this->actingAs($bag['user']);
    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'Bad',
        'server_url' => 'ftp://example.com/mcp',
        'auth_type' => McpServer::AUTH_NONE,
    ]);
    $res->assertSessionHasErrors('server_url');
});

test('bearer auth without api_key is rejected', function () {
    $bag = workspaceMemberWithAgent();
    bindFakeMcpForCtrl();

    $this->actingAs($bag['user']);
    $res = $this->post("/app/agents/{$bag['agent']->id}/mcp", [
        'label' => 'X',
        'server_url' => 'https://example.com/mcp',
        'auth_type' => McpServer::AUTH_BEARER,
    ]);
    $res->assertSessionHasErrors('api_key');
});
