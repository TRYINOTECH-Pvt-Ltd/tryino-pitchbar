<?php

namespace App\Services\Mcp\Exceptions;

/**
 * Thrown when the tool ran on the server but returned a domain
 * error (e.g. "order not found", "permission denied", "rate limit").
 * The server is healthy; only this specific call failed.
 *
 * Circuit breaker does NOT increment — tool errors are useful
 * feedback for the LLM, which often self-corrects on the next hop.
 * The executor surfaces the error text back into the conversation
 * so the LLM can recover.
 */
class McpToolErrorException extends McpException {}
