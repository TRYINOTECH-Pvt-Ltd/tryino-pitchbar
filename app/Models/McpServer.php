<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One MCP (Model Context Protocol) server attached to a workspace.
 * Buyers connect their CRM / calendar / inventory / internal API via
 * an MCP server URL + credentials; agents call tools mid-conversation.
 *
 * Per-agent attachment is via `agent_mcp_tool_grants`, not a column
 * here — a server attached to two agents is one row with two grant
 * rows pointing in.
 *
 * `server_url_hash` is a SHA-256 of the canonicalised URL (host
 * lowercased, default port stripped, fragment removed, trailing
 * slash trimmed). The unique constraint on (workspace_id, server_url_hash)
 * makes "re-add the same server" a no-op detected at the DB layer.
 */
class McpServer extends Model
{
    use BelongsToWorkspace;
    use HasUuidV7;
    use SoftDeletes;

    public const STATUS_PENDING_AUTH = 'pending_auth';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_REVOKED = 'revoked';

    public const AUTH_NONE = 'none';

    public const AUTH_BEARER = 'bearer';

    public const AUTH_OAUTH2_PKCE = 'oauth2_pkce';

    protected $fillable = [
        'workspace_id', 'label', 'server_url', 'server_url_hash',
        'transport', 'auth_type', 'credentials_encrypted', 'oauth_state',
        'server_info', 'status', 'connection_test_at', 'connection_test_result',
        'tools_synced_at', 'tools_sync_error', 'last_used_at', 'failure_count',
        'created_by_user_id',
    ];

    protected $casts = [
        'credentials_encrypted' => 'encrypted:array',
        'oauth_state' => 'array',
        'server_info' => 'array',
        'connection_test_at' => 'datetime',
        'connection_test_result' => 'array',
        'tools_synced_at' => 'datetime',
        'last_used_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public function tools(): HasMany
    {
        return $this->hasMany(McpTool::class, 'mcp_server_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Canonicalise a URL for dedup hashing. Host lowercased, default
     * port stripped, fragment removed, trailing slash on path
     * trimmed. Query string preserved (different ?tenant=x are
     * different servers).
     */
    public static function canonicaliseUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '').$path.$query;
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', self::canonicaliseUrl($url));
    }
}
