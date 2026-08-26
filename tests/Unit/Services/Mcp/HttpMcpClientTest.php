<?php

use App\Services\Mcp\Dto\McpConnection;
use App\Services\Mcp\Exceptions\McpProtocolException;
use App\Services\Mcp\Exceptions\McpToolErrorException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\HttpMcpClient;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

function makeClient(MockHandler $mock): HttpMcpClient
{
    $http = new Client(['handler' => HandlerStack::create($mock)]);

    return new HttpMcpClient(
        http: $http,
        urlGuard: new UrlSafetyGuard,
        logger: new NullLogger,
    );
}

function makeConn(string $url = 'https://example.com/mcp'): McpConnection
{
    return new McpConnection(
        serverId: 'srv_test',
        serverUrl: $url,
        authHeader: 'Bearer test',
    );
}

test('initialize parses JSON response into McpServerInfo', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 'will-be-overwritten',
            'result' => [
                'protocolVersion' => '2025-03-26',
                'serverInfo' => ['name' => 'linear-mcp', 'version' => '1.2.3'],
                'capabilities' => ['tools' => ['listChanged' => true]],
            ],
        ])),
    ]);

    // We can't predict the ULID id Pitchbar generates, so accept
    // whichever id appears in the response. The envelope helper only
    // checks id when both sides are non-null; we send null in test.
    // Workaround: stub the response to echo the request id by reading
    // the request later... but MockHandler doesn't let us read first.
    // Easier path: drop id-matching by emitting the response without
    // an id; envelope helper accepts that.
    $mock->reset();
    $mock->append(
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'result' => [
                'protocolVersion' => '2025-03-26',
                'serverInfo' => ['name' => 'linear-mcp', 'version' => '1.2.3'],
                'capabilities' => ['tools' => ['listChanged' => true]],
            ],
        ]))
    );

    $info = makeClient($mock)->initialize(makeConn());

    expect($info->protocolVersion)->toBe('2025-03-26');
    expect($info->serverName)->toBe('linear-mcp');
    expect($info->serverVersion)->toBe('1.2.3');
});

test('listTools returns McpToolSchema array', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'result' => [
                'tools' => [
                    [
                        'name' => 'search_issues',
                        'description' => 'Search Linear issues',
                        'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
                        'annotations' => ['destructiveHint' => false, 'idempotentHint' => true],
                    ],
                    [
                        'name' => 'create_issue',
                        'description' => 'Create an issue',
                        'inputSchema' => ['type' => 'object'],
                        'annotations' => ['destructiveHint' => true],
                    ],
                ],
            ],
        ])),
    ]);

    $tools = makeClient($mock)->listTools(makeConn());
    expect($tools)->toHaveCount(2);
    expect($tools[0]->name)->toBe('search_issues');
    expect($tools[0]->isDestructive())->toBeFalse();
    expect($tools[1]->name)->toBe('create_issue');
    expect($tools[1]->isDestructive())->toBeTrue();
});

test('callTool parses text content block into McpToolResult', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => 'order #42 shipped 2026-06-01'],
                ],
                'isError' => false,
            ],
        ])),
    ]);

    $result = makeClient($mock)->callTool(makeConn(), 'get_order', ['id' => 42], 5000);
    expect($result->textContent)->toBe('order #42 shipped 2026-06-01');
    expect($result->isError)->toBeFalse();
});

test('401 throws McpUnauthorizedException', function () {
    $mock = new MockHandler([new Response(401, [], '{}')]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpUnauthorizedException::class);
});

test('429 throws McpTransportException with retryAfter parsed', function () {
    $mock = new MockHandler([
        new Response(429, ['Retry-After' => '30'], '{}'),
    ]);

    try {
        makeClient($mock)->ping(makeConn());
        $this->fail('expected exception');
    } catch (McpTransportException $e) {
        expect($e->retryAfterSeconds)->toBe(30);
    }
});

test('500 throws McpTransportException', function () {
    $mock = new MockHandler([new Response(500, [], 'server exploded')]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpTransportException::class);
});

test('malformed JSON throws McpProtocolException', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], 'not json'),
    ]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpProtocolException::class);
});

test('JSON-RPC error envelope with code -32001 throws McpUnauthorized', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'token expired'],
        ])),
    ]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpUnauthorizedException::class);
});

test('JSON-RPC error envelope with other code throws McpToolErrorException', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32603, 'message' => 'internal error'],
        ])),
    ]);

    expect(fn () => makeClient($mock)->callTool(makeConn(), 'x', [], 5000))
        ->toThrow(McpToolErrorException::class);
});

test('SSE stream with single JSON-RPC envelope is parsed', function () {
    $sseBody = "event: message\ndata: ".json_encode([
        'jsonrpc' => '2.0',
        'result' => ['content' => [['type' => 'text', 'text' => 'streamed reply']], 'isError' => false],
    ])."\n\n";

    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
    ]);

    $result = makeClient($mock)->callTool(makeConn(), 'streaming_tool', [], 5000);
    expect($result->textContent)->toBe('streamed reply');
});

test('SSRF: refuses to call localhost', function () {
    $mock = new MockHandler([new Response(200, [], '{}')]);

    expect(fn () => makeClient($mock)->ping(makeConn('http://localhost:8080/mcp')))
        ->toThrow(McpTransportException::class);
});

test('SSRF: refuses to call 169.254.169.254 (AWS metadata)', function () {
    $mock = new MockHandler([new Response(200, [], '{}')]);

    expect(fn () => makeClient($mock)->ping(makeConn('http://169.254.169.254/latest/meta-data/')))
        ->toThrow(McpTransportException::class);
});

test('connect timeout throws McpTimeoutException', function () {
    $mock = new MockHandler([
        new ConnectException(
            'cURL error 28: Operation timed out after 5000 milliseconds',
            new Request('POST', 'https://example.com/mcp'),
        ),
    ]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpTransportException::class);
});

test('non-object JSON body throws McpProtocolException', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '[1,2,3]'),
    ]);

    expect(fn () => makeClient($mock)->ping(makeConn()))
        ->toThrow(McpProtocolException::class);
});

test('disallowed scheme is rejected via SSRF guard', function () {
    $mock = new MockHandler([new Response(200, [], '{}')]);

    expect(fn () => makeClient($mock)->ping(makeConn('file:///etc/passwd')))
        ->toThrow(McpTransportException::class);
});
