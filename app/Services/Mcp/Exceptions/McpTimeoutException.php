<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Thrown when the call exceeds the per-tool timeout budget. Distinct
 * from McpTransportException because the executor wants to track
 * timeout-vs-other-transport-failures separately (timeouts are the
 * primary signal for "server is slow" tuning).
 *
 * Circuit breaker increments.
 */
class McpTimeoutException extends McpException {}
