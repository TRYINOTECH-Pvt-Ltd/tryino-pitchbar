<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationConnection extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    /**
     * Stable string identifiers for the kinds of integrations this
     * table holds. Code historically used raw string literals
     * ('slack', 'notion', 'google'); these constants give type-safe
     * references without renaming the persisted values.
     */
    public const KIND_SLACK = 'slack';

    public const KIND_NOTION = 'notion';

    public const KIND_GOOGLE = 'google';

    /**
     * MCP (Model Context Protocol) is the open standard for connecting
     * LLMs to external tool servers. Pitchbar acts as an MCP client:
     * buyers attach MCP servers per agent, the agent calls tools
     * exposed by those servers mid-conversation. NOTE: MCP storage
     * lives in the dedicated `mcp_servers` table, NOT in
     * integration_connections, because OAuth-PKCE state, token
     * refresh metadata, and per-server tool catalogues need a richer
     * schema than the JSON config column. The KIND_MCP constant is
     * kept for cross-cutting code that needs to recognise an MCP
     * integration alongside the others (e.g. workspace integrations
     * dashboard, audit log normalization).
     */
    public const KIND_MCP = 'mcp';

    protected $fillable = [
        'workspace_id', 'kind', 'credentials_encrypted',
        'config', 'status', 'last_sync_at',
    ];

    protected $casts = [
        'credentials_encrypted' => 'encrypted:array',
        'config' => 'array',
        'last_sync_at' => 'datetime',
    ];
}
