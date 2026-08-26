<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Thrown on 401 / 403 from the MCP server. The caller (executor /
 * credential resolver) should NOT retry blindly; it should attempt
 * a token refresh or flag the server as needing re-authentication.
 *
 * Circuit breaker does NOT increment — auth state is orthogonal to
 * server health.
 */
class McpUnauthorizedException extends McpException {}
