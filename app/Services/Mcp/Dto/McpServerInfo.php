<?php

namespace App\Services\Mcp\Dto;

/**
 * Result of an MCP initialize handshake. The server announces its
 * own version + protocol revision + capability flags. We persist
 * these to mcp_servers.server_info so the admin UI can show "Linear
 * MCP v2.3.1 (protocol 2025-03-26)".
 */
final class McpServerInfo
{
    public function __construct(
        public readonly string $protocolVersion,
        public readonly string $serverName,
        public readonly string $serverVersion,
        /** @var array<string, mixed> */
        public readonly array $capabilities,
    ) {}
}
