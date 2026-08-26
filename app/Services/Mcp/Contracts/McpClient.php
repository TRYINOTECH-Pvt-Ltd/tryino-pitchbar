<?php

namespace App\Services\Mcp\Contracts;

use App\Services\Mcp\Dto\McpConnection;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Exceptions\McpException;

/**
 * Thin abstraction over the MCP JSON-RPC surface that Pitchbar uses
 * as a client.
 *
 * Concrete implementations:
 *   - HttpMcpClient: real Guzzle calls over Streamable HTTP.
 *   - FakeMcpClient: deterministic in-process double for tests
 *     (CLAUDE.md rule #4 — no real MCP servers in CI).
 *
 * The interface intentionally hides the JSON-RPC envelope, request
 * id generation, and SSE plumbing. Callers (executor, discovery
 * job) work in DTOs only.
 *
 * Methods throw typed exceptions on failure: McpTimeoutException,
 * McpUnauthorizedException, McpTransportException, McpProtocolException,
 * McpToolErrorException. Callers must catch the typed subclasses;
 * never let an MCP failure bubble unhandled into the SSE stream.
 */
interface McpClient
{
    /**
     * Perform the MCP initialize handshake. Asserts the server
     * speaks a compatible protocol version and returns its
     * advertised capabilities.
     *
     * @throws McpException
     */
    public function initialize(McpConnection $conn): McpServerInfo;

    /**
     * Cheap health probe used by the admin "Test connection" button.
     * Returns true on success, false on a recoverable failure;
     * throws on a permanent failure (SSRF, unauthorized).
     *
     * @throws McpException
     */
    public function ping(McpConnection $conn): bool;

    /**
     * Fetch the full tool catalogue from the server. Used by
     * McpToolDiscovery on attach + on scheduled refresh.
     *
     * @return array<int, McpToolSchema>
     *
     * @throws McpException
     */
    public function listTools(McpConnection $conn): array;

    /**
     * Invoke a single tool. Caller passes the server-local name
     * (not the namespaced LLM-facing name) and the validated args.
     * Timeout is the hard ceiling for the round trip; the client
     * splits it across connect + read internally.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws McpException
     */
    public function callTool(
        McpConnection $conn,
        string $toolName,
        array $args,
        int $timeoutMs,
    ): McpToolResult;
}
