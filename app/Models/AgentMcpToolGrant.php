<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-agent whitelist for an MCP tool. `enabled = false` is the
 * default; admin must explicitly opt in before the tool is exposed
 * to the LLM. Rows for disabled tools still exist so the admin UI
 * can show "discovered but disabled" instead of "missing".
 *
 * `config_overrides.alias` lets the admin rename the LLM-facing
 * function name (e.g. shorten "github.create_issue" to "github.new").
 * `config_overrides.output_token_budget` shrinks chatty tools.
 */
class AgentMcpToolGrant extends Model
{
    use BelongsToWorkspace;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'agent_id', 'mcp_tool_id', 'workspace_id', 'enabled',
        'enabled_at', 'enabled_by_user_id', 'config_overrides',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'config_overrides' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(McpTool::class, 'mcp_tool_id');
    }
}
