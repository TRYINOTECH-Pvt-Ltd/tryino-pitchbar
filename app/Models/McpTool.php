<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tool exposed by an MCP server, as known to Pitchbar from the
 * last successful discovery refresh. Acts as the LLM-facing
 * catalogue — when an agent's runToolLoop builds its tools[] payload,
 * it joins through agent_mcp_tool_grants to this table.
 */
class McpTool extends Model
{
    use BelongsToWorkspace;
    use HasUuidV7;

    protected $fillable = [
        'mcp_server_id', 'workspace_id', 'name', 'namespaced_name',
        'description', 'input_schema', 'output_token_budget',
        'is_destructive', 'is_idempotent', 'requires_open_world',
        'catalogue_revision', 'removed_at',
    ];

    protected $casts = [
        'input_schema' => 'array',
        'output_token_budget' => 'integer',
        'is_destructive' => 'boolean',
        'is_idempotent' => 'boolean',
        'requires_open_world' => 'boolean',
        'catalogue_revision' => 'integer',
        'removed_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'mcp_server_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AgentMcpToolGrant::class, 'mcp_tool_id');
    }
}
