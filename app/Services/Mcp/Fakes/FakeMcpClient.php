<?php

namespace App\Services\Mcp\Fakes;

use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Dto\McpConnection;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Exceptions\McpException;
use App\Services\Mcp\Exceptions\McpTimeoutException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;

/**
 * In-process MCP client double for tests + local dev. Mirrors the
 * pattern of FakeOpenAi: tests bind this in place of HttpMcpClient
 * via the container so executor / discovery / controllers exercise
 * real code paths without touching real MCP servers.
 *
 * Public mutators let tests script the responses for specific
 * (server URL, tool name) pairs. Public read-only fields let tests
 * inspect what the production code actually called.
 *
 * Bound in AppServiceProvider::register() when
 * app()->runningUnitTests().
 */
class FakeMcpClient implements McpClient
{
    /** @var array<int, array{type: string, server: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, McpServerInfo> serverUrl → server info */
    private array $serverInfoByUrl = [];

    /** @var array<string, array<int, McpToolSchema>> serverUrl → tool list */
    private array $toolListByUrl = [];

    /** @var array<string, McpToolResult|McpException> "serverUrl::toolName" → result OR exception to throw */
    private array $toolResults = [];

    /** @var array<string, bool> serverUrl → whether ping fails */
    private array $pingFails = [];

    public function queueServerInfo(string $serverUrl, McpServerInfo $info): void
    {
        $this->serverInfoByUrl[$serverUrl] = $info;
    }

    /**
     * @param  array<int, McpToolSchema>  $tools
     */
    public function queueToolList(string $serverUrl, array $tools): void
    {
        $this->toolListByUrl[$serverUrl] = $tools;
    }

    public function queueToolResult(string $serverUrl, string $toolName, McpToolResult $result): void
    {
        $this->toolResults["{$serverUrl}::{$toolName}"] = $result;
    }

    public function queueToolException(string $serverUrl, string $toolName, McpException $exception): void
    {
        $this->toolResults["{$serverUrl}::{$toolName}"] = $exception;
    }

    public function makePingFail(string $serverUrl): void
    {
        $this->pingFails[$serverUrl] = true;
    }

    public function initialize(McpConnection $conn): McpServerInfo
    {
        $this->calls[] = ['type' => 'initialize', 'server' => $conn->serverUrl, 'args' => []];

        return $this->serverInfoByUrl[$conn->serverUrl] ?? new McpServerInfo(
            protocolVersion: $conn->protocolVersion,
            serverName: 'fake-mcp',
            serverVersion: '1.0',
            capabilities: ['tools' => ['listChanged' => false]],
        );
    }

    public function ping(McpConnection $conn): bool
    {
        $this->calls[] = ['type' => 'ping', 'server' => $conn->serverUrl, 'args' => []];

        if ($this->pingFails[$conn->serverUrl] ?? false) {
            throw new McpTransportException('Fake ping failure.');
        }

        return true;
    }

    public function listTools(McpConnection $conn): array
    {
        $this->calls[] = ['type' => 'listTools', 'server' => $conn->serverUrl, 'args' => []];

        return $this->toolListByUrl[$conn->serverUrl] ?? [];
    }

    public function callTool(McpConnection $conn, string $toolName, array $args, int $timeoutMs): McpToolResult
    {
        $this->calls[] = [
            'type' => 'callTool',
            'server' => $conn->serverUrl,
            'args' => ['tool' => $toolName, 'arguments' => $args, 'timeoutMs' => $timeoutMs],
        ];

        $queued = $this->toolResults["{$conn->serverUrl}::{$toolName}"] ?? null;
        if ($queued instanceof McpException) {
            throw $queued;
        }
        if ($queued instanceof McpToolResult) {
            return $queued;
        }

        return new McpToolResult(textContent: "Fake result for {$toolName}");
    }

    /** Convenience helpers used by tests to queue common failures. */
    public function queueTimeout(string $serverUrl, string $toolName): void
    {
        $this->queueToolException($serverUrl, $toolName, new McpTimeoutException('Fake timeout.'));
    }

    public function queueTransportError(string $serverUrl, string $toolName): void
    {
        $this->queueToolException($serverUrl, $toolName, new McpTransportException('Fake transport error.'));
    }

    public function queueUnauthorized(string $serverUrl, string $toolName): void
    {
        $this->queueToolException($serverUrl, $toolName, new McpUnauthorizedException('Fake 401.'));
    }
}
