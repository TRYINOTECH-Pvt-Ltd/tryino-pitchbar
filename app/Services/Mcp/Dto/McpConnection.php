<?php

namespace App\Services\Mcp\Dto;

/**
 * Resolved connection details for one MCP server call. Produced by
 * the registry/credential-resolver, consumed by McpClient.
 *
 * The DTO carries the already-assembled auth header rather than the
 * raw API key / OAuth token — this is the seam that keeps decrypted
 * credentials out of every code path except McpCredentialResolver.
 * Tests can construct this directly without going through the
 * resolver.
 *
 * Octane safety: the McpClient never holds onto a connection between
 * requests; one DTO per call, garbage-collected at the end.
 */
final class McpConnection
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $serverUrl,
        public readonly ?string $authHeader,
        public readonly string $protocolVersion = '2025-03-26',
        public readonly string $clientName = 'pitchbar',
        public readonly string $clientVersion = '1.0',
    ) {}
}
