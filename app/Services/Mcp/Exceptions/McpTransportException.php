<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Thrown when the HTTP transport itself fails — connection refused,
 * DNS unreachable, TLS handshake failure, 5xx with no useful body.
 * The MCP server is unhealthy; circuit breaker should increment.
 *
 * Distinguished from McpToolErrorException (server is up but the
 * tool reported a domain error) and McpProtocolException (server
 * is up but returned something not JSON-RPC).
 */
class McpTransportException extends McpException
{
    public ?int $retryAfterSeconds = null;
}
