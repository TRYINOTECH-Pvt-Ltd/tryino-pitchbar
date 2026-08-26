<?php

namespace App\Services\Mcp\Dto;

/**
 * One entry in the server's `tools/list` response. We persist this
 * to mcp_tools, where namespacedName is computed from the server
 * label + tool name to give the LLM a collision-free name across
 * multiple connected servers.
 *
 * `annotations` carries MCP's optional hints (destructiveHint,
 * idempotentHint, openWorldHint) which drive the per-tool
 * confirmation modal in the admin UI.
 */
final class McpToolSchema
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        /** @var array<string, mixed> */
        public readonly array $inputSchema,
        /** @var array<string, mixed> */
        public readonly array $annotations = [],
    ) {}

    public function isDestructive(): bool
    {
        return (bool) ($this->annotations['destructiveHint'] ?? false);
    }

    public function isIdempotent(): bool
    {
        return (bool) ($this->annotations['idempotentHint'] ?? true);
    }

    public function requiresOpenWorld(): bool
    {
        return (bool) ($this->annotations['openWorldHint'] ?? true);
    }
}
