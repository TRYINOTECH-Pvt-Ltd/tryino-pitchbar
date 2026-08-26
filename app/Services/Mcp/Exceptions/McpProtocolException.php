<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Thrown when the server's response is not a valid JSON-RPC envelope:
 * malformed JSON, missing `jsonrpc` field, mismatched id, oversized
 * body. The server is reachable but speaking the wrong protocol.
 *
 * The circuit breaker increments — a server that consistently
 * returns garbage is broken even if its HTTP layer is fine.
 */
class McpProtocolException extends McpException {}
