<?php

use App\Services\Mcp\Dto\McpConnection;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Exceptions\McpTimeoutException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\Fakes\FakeMcpClient;

test('FakeMcpClient returns queued server info', function () {
    $fake = new FakeMcpClient;
    $fake->queueServerInfo('https://x/mcp', new McpServerInfo(
        protocolVersion: '2025-03-26',
        serverName: 'queued',
        serverVersion: '9.9',
        capabilities: [],
    ));

    $info = $fake->initialize(new McpConnection('s', 'https://x/mcp', null));
    expect($info->serverName)->toBe('queued');
    expect($info->serverVersion)->toBe('9.9');
});

test('FakeMcpClient returns queued tool list', function () {
    $fake = new FakeMcpClient;
    $fake->queueToolList('https://x/mcp', [
        new McpToolSchema('search', 'desc', ['type' => 'object']),
    ]);

    $tools = $fake->listTools(new McpConnection('s', 'https://x/mcp', null));
    expect($tools)->toHaveCount(1);
    expect($tools[0]->name)->toBe('search');
});

test('FakeMcpClient returns queued tool result + records the call', function () {
    $fake = new FakeMcpClient;
    $fake->queueToolResult('https://x/mcp', 'search', new McpToolResult('hit'));

    $result = $fake->callTool(new McpConnection('s', 'https://x/mcp', null), 'search', ['q' => 'foo'], 5000);
    expect($result->textContent)->toBe('hit');
    expect(collect($fake->calls)->where('type', 'callTool'))->toHaveCount(1);
    expect($fake->calls[0]['args']['arguments']['q'])->toBe('foo');
});

test('FakeMcpClient throws queued timeout', function () {
    $fake = new FakeMcpClient;
    $fake->queueTimeout('https://x/mcp', 'slow_tool');

    expect(fn () => $fake->callTool(new McpConnection('s', 'https://x/mcp', null), 'slow_tool', [], 5000))
        ->toThrow(McpTimeoutException::class);
});

test('FakeMcpClient throws queued unauthorized', function () {
    $fake = new FakeMcpClient;
    $fake->queueUnauthorized('https://x/mcp', 'restricted');

    expect(fn () => $fake->callTool(new McpConnection('s', 'https://x/mcp', null), 'restricted', [], 5000))
        ->toThrow(McpUnauthorizedException::class);
});

test('FakeMcpClient ping fails when configured', function () {
    $fake = new FakeMcpClient;
    $fake->makePingFail('https://x/mcp');

    expect(fn () => $fake->ping(new McpConnection('s', 'https://x/mcp', null)))
        ->toThrow(McpTransportException::class);
});
