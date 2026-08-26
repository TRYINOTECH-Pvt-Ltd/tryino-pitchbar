<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Base class for every MCP-layer failure. Catching this swallows
 * everything; catch the typed subclasses (Transport / Protocol /
 * ToolError / Unauthorized / Timeout) when the executor needs to
 * branch on failure mode.
 */
class McpException extends \RuntimeException {}
