<?php

namespace App\Services\Mcp\Dto;

/**
 * Result of a single tool call as returned by the MCP server.
 *
 * Per MCP spec, `content` is an array of content blocks (each with
 * a `type` field — `text`, `image`, `resource`, etc). Pitchbar v1
 * only consumes `text` blocks; image/resource blocks are coerced to
 * a textual placeholder ("[image content — not rendered]") so the
 * LLM can still reference them in its reply.
 *
 * `isError` is the server's own signal that the call failed at the
 * tool level (e.g. "order not found"). The transport may be 200 OK
 * but isError=true. The executor surfaces the textual content back
 * to the LLM as a tool error so it can self-correct.
 */
final class McpToolResult
{
    public function __construct(
        public readonly string $textContent,
        public readonly bool $isError = false,
        /** @var array<int, array<string, mixed>> */
        public readonly array $rawContentBlocks = [],
    ) {}
}
