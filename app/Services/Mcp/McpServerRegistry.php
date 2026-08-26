<?php

namespace App\Services\Mcp;

use App\Models\Agent;
use App\Models\AgentMcpToolGrant;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Dto\McpConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Workspace-scoped catalogue of MCP servers attached to an agent
 * plus the tools the agent is allowed to call.
 *
 * Two read paths the hot path uses:
 *  - serversForAgent(Agent) → list of MCP servers any tool grant
 *    on this agent points at, filtered to non-disabled.
 *  - grantedToolsForAgent(Agent) → enabled, non-tombstoned tools.
 *
 * Both are cached in the request cache (60s TTL) keyed by agent id,
 * because runToolLoop hits these per turn.
 *
 * Tenancy: all queries flow through Eloquent's BelongsToWorkspace
 * scope. Callers from the widget hot path pass the resolved Agent
 * (from the JWT), the registry never reads CurrentWorkspace.
 *
 * Octane safety: stateless wrt requests; bound `scoped` in
 * AppServiceProvider.
 */
class McpServerRegistry
{
    public function __construct(
        private readonly McpCredentialResolver $credentials,
    ) {}

    /**
     * Return the agent's active MCP servers (any server with at
     * least one enabled tool grant).
     */
    public function serversForAgent(Agent $agent): Collection
    {
        $key = "mcp:servers:agent:{$agent->id}";

        return Cache::remember($key, 60, fn () => McpServer::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $agent->workspace_id)
            ->whereIn('status', [McpServer::STATUS_ACTIVE, McpServer::STATUS_DEGRADED])
            ->whereIn('id', function ($q) use ($agent) {
                $q->select('mcp_server_id')
                    ->from('mcp_tools')
                    ->whereNull('removed_at')
                    ->whereIn('id', function ($qq) use ($agent) {
                        $qq->select('mcp_tool_id')
                            ->from('agent_mcp_tool_grants')
                            ->where('agent_id', $agent->id)
                            ->where('enabled', true);
                    });
            })
            ->get());
    }

    /**
     * Return the enabled, non-tombstoned tools the agent may call.
     */
    public function grantedToolsForAgent(Agent $agent): Collection
    {
        $key = "mcp:tools:agent:{$agent->id}";

        return Cache::remember($key, 60, fn () => McpTool::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $agent->workspace_id)
            ->whereNull('removed_at')
            ->whereIn('mcp_server_id', function ($q) use ($agent) {
                $q->select('id')
                    ->from('mcp_servers')
                    ->where('workspace_id', $agent->workspace_id)
                    ->whereIn('status', [McpServer::STATUS_ACTIVE, McpServer::STATUS_DEGRADED]);
            })
            ->whereIn('id', function ($q) use ($agent) {
                $q->select('mcp_tool_id')
                    ->from('agent_mcp_tool_grants')
                    ->where('agent_id', $agent->id)
                    ->where('enabled', true);
            })
            ->get());
    }

    /**
     * Build an McpConnection DTO for a single server. Resolves
     * credentials at the same time so the executor doesn't need to
     * touch them.
     */
    public function connectionFor(McpServer $server): McpConnection
    {
        $resolved = $this->credentials->resolve($server);

        return new McpConnection(
            serverId: $server->id,
            serverUrl: $server->server_url,
            authHeader: $resolved['header'],
        );
    }

    /**
     * Cache invalidation hook — call after attach / detach / refresh.
     * Cheap; the cache TTL is 60s so worst-case staleness was
     * already bounded.
     */
    public function invalidate(Agent $agent): void
    {
        Cache::forget("mcp:servers:agent:{$agent->id}");
        Cache::forget("mcp:tools:agent:{$agent->id}");
    }

    /**
     * Attach a brand-new server row for a workspace. Throws on
     * duplicate (workspace_id, server_url_hash) — caller catches
     * the unique violation and routes the admin to the existing
     * connection.
     */
    public function attachServer(
        string $workspaceId,
        string $label,
        string $serverUrl,
        string $authType,
        ?array $credentials,
        ?int $createdByUserId = null,
    ): McpServer {
        $server = new McpServer;
        $server->forceFill([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'label' => $label,
            'server_url' => $serverUrl,
            'server_url_hash' => McpServer::hashUrl($serverUrl),
            'transport' => 'http',
            'auth_type' => $authType,
            'credentials_encrypted' => $credentials,
            'status' => $authType === McpServer::AUTH_OAUTH2_PKCE
                ? McpServer::STATUS_PENDING_AUTH
                : McpServer::STATUS_ACTIVE,
            'created_by_user_id' => $createdByUserId,
            'failure_count' => 0,
        ])->save();

        return $server;
    }

    /**
     * Grant a single tool to a single agent. Idempotent on
     * (agent_id, mcp_tool_id). Setting enabled stamps audit fields.
     */
    public function setGrant(
        Agent $agent,
        McpTool $tool,
        bool $enabled,
        ?int $actorUserId = null,
        ?array $configOverrides = null,
    ): AgentMcpToolGrant {
        $grant = AgentMcpToolGrant::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('mcp_tool_id', $tool->id)
            ->firstOrNew([]);

        $grant->forceFill([
            'agent_id' => $agent->id,
            'mcp_tool_id' => $tool->id,
            'workspace_id' => $agent->workspace_id,
            'enabled' => $enabled,
            'enabled_at' => $enabled ? now() : null,
            'enabled_by_user_id' => $enabled ? $actorUserId : null,
            'config_overrides' => $configOverrides,
        ])->save();

        $this->invalidate($agent);

        return $grant;
    }
}
