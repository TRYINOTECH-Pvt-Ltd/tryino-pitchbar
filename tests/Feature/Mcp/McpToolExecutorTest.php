<?php

use App\Models\AgentMcpToolGrant;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Exceptions\McpTimeoutException;
use App\Services\Mcp\Exceptions\McpToolErrorException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\Fakes\FakeMcpClient;
use App\Services\Mcp\McpToolExecutor;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function setupGrantedTool(array $bag, string $url = 'https://x/mcp', string $namespacedName = 'srv.search', bool $destructive = false): array
{
    app(CurrentWorkspace::class)->clear();
    Cache::flush();

    $server = tap(new McpServer, function (McpServer $s) use ($bag, $url) {
        $s->forceFill([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $bag['workspace']->id,
            'label' => 'srv',
            'server_url' => $url,
            'server_url_hash' => McpServer::hashUrl($url),
            'transport' => 'http',
            'auth_type' => McpServer::AUTH_NONE,
            'status' => McpServer::STATUS_ACTIVE,
            'failure_count' => 0,
        ])->save();
    });
    $tool = tap(new McpTool, function (McpTool $t) use ($bag, $server, $namespacedName, $destructive) {
        $name = explode('.', $namespacedName)[1] ?? 'search';
        $t->forceFill([
            'id' => (string) Str::uuid7(),
            'mcp_server_id' => $server->id,
            'workspace_id' => $bag['workspace']->id,
            'name' => $name,
            'namespaced_name' => $namespacedName,
            'description' => 'x',
            'input_schema' => ['type' => 'object'],
            'is_destructive' => $destructive,
            'is_idempotent' => true,
            'requires_open_world' => true,
            'catalogue_revision' => 1,
        ])->save();
    });

    AgentMcpToolGrant::query()->forceCreate([
        'agent_id' => $bag['agent']->id,
        'mcp_tool_id' => $tool->id,
        'workspace_id' => $bag['workspace']->id,
        'enabled' => true,
        'enabled_at' => now(),
    ]);

    return ['server' => $server, 'tool' => $tool];
}

function bindFakeExec(): FakeMcpClient
{
    $fake = new FakeMcpClient;
    app()->instance(McpClient::class, $fake);

    return $fake;
}

test('execute calls tool + wraps result in <tool-result> tags', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult('order #42 shipped'));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: ['q' => 'foo'],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_SUCCESS);
    expect($result['wrapped'])->toContain('<tool-result name="srv.search"');
    expect($result['wrapped'])->toContain('trusted="false"');
    expect($result['wrapped'])->toContain('order #42 shipped');
    expect($result['log_id'])->not->toBeNull();
});

test('execute denies tool not in agent grants', function () {
    $bag = workspaceMemberWithAgent();
    bindFakeExec();

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'nonexistent.tool',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_DENIED_NOT_GRANTED);
    expect($result['wrapped'])->toContain('External integration unavailable');
});

test('execute on timeout records circuit breaker failure', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolException('https://x/mcp', 'search', new McpTimeoutException('slow'));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_TIMEOUT);
    expect($result['wrapped'])->toContain('External integration unavailable');
});

test('execute on transport error records failure', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolException('https://x/mcp', 'search', new McpTransportException('500 server fault'));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_TRANSPORT);
});

test('execute on unauthorized flags server as pending_auth', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolException('https://x/mcp', 'search', new McpUnauthorizedException('token expired'));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_UNAUTHORIZED);
    expect($seed['server']->fresh()->status)->toBe(McpServer::STATUS_PENDING_AUTH);
});

test('execute on tool error passes the error text through (no breaker bump)', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolException('https://x/mcp', 'search', new McpToolErrorException('order not found'));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_TOOL_ERROR);
    expect($result['wrapped'])->toContain('order not found');
    expect($result['wrapped'])->toContain('error="true"');
});

test('execute truncates large output + marks truncated', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();

    $huge = str_repeat('x', 200_000); // way over 1200 tokens
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult($huge));

    $result = app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($result['status'])->toBe(McpToolExecutor::STATUS_SUCCESS);
    expect($result['wrapped'])->toContain('truncated:');
});

test('execute writes mcp_call_logs row with status + latency + tool name', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult('ok'));

    app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: ['q' => 'visible'],
    );

    $row = DB::table('mcp_call_logs')->first();
    expect($row->status)->toBe('success');
    expect($row->tool_name)->toBe('srv.search');
    expect($row->workspace_id)->toBe($bag['workspace']->id);
    $args = json_decode($row->args_redacted_preview, true);
    expect($args['q'])->toBe('visible');
});

test('execute redacts long bare digit runs in audit args', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult('ok'));

    app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: ['notes' => 'cc 4242424242424242'],
    );

    $row = DB::table('mcp_call_logs')->first();
    $args = json_decode($row->args_redacted_preview, true);
    expect($args['notes'])->toContain('[redacted-number]');
});

test('execute records last_used_at on the server row', function () {
    $bag = workspaceMemberWithAgent();
    $seed = setupGrantedTool($bag);
    $fake = bindFakeExec();
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult('ok'));

    app(McpToolExecutor::class)->execute(
        agent: $bag['agent'],
        conversation: null,
        namespacedName: 'srv.search',
        rawArgs: [],
    );

    expect($seed['server']->fresh()->last_used_at)->not->toBeNull();
});
