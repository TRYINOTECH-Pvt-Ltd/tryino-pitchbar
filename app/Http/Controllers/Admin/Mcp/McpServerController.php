<?php

namespace App\Http\Controllers\Admin\Mcp;

use App\Http\Requests\Admin\Mcp\StoreMcpServerRequest;
use App\Jobs\Mcp\SyncMcpCatalogueJob;
use App\Models\Agent;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Exceptions\McpException;
use App\Services\Mcp\McpServerRegistry;
use App\Services\Mcp\McpToolDiscovery;
use App\Support\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-agent MCP server management. Buyers attach MCP servers from
 * the agent's settings page; one row per (workspace, server URL).
 *
 * Authorisation: every action gates on `manageMembers` on the
 * agent's workspace, matching the rest of the integrations admin
 * area. Workspace-membership + role gating handled upstream by the
 * route group's auth + workspace middleware.
 */
class McpServerController
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly McpServerRegistry $registry,
        private readonly McpToolDiscovery $discovery,
        private readonly McpClient $client,
    ) {}

    public function index(Request $request, Agent $agent): Response
    {
        $this->authorize($request, $agent);

        $servers = McpServer::query()
            ->where('workspace_id', $agent->workspace_id)
            ->whereHas('tools', fn ($q) => $q->whereHas('grants', fn ($qq) => $qq->where('agent_id', $agent->id)))
            ->orWhere(fn ($q) => $q
                ->where('workspace_id', $agent->workspace_id)
                ->whereDoesntHave('tools'))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('app/agents/mcp', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'servers' => $servers->map(fn (McpServer $s) => [
                'id' => $s->id,
                'label' => $s->label,
                'server_url' => $s->server_url,
                'auth_type' => $s->auth_type,
                'status' => $s->status,
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'tools_synced_at' => $s->tools_synced_at?->toIso8601String(),
                'tools_sync_error' => $s->tools_sync_error,
                'server_name' => $s->server_info['name'] ?? null,
                'server_version' => $s->server_info['version'] ?? null,
                'tools_count' => $s->tools()->whereNull('removed_at')->count(),
                'granted_count' => $s->tools()
                    ->whereNull('removed_at')
                    ->whereHas('grants', fn ($q) => $q
                        ->where('agent_id', $agent->id)
                        ->where('enabled', true))
                    ->count(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(StoreMcpServerRequest $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $authType = $request->string('auth_type')->value();
        $credentials = match ($authType) {
            McpServer::AUTH_BEARER => ['api_key' => $request->string('api_key')->value()],
            default => null,
        };

        try {
            $server = $this->registry->attachServer(
                workspaceId: $workspace->id,
                label: $request->string('label')->value(),
                serverUrl: $request->string('server_url')->value(),
                authType: $authType,
                credentials: $credentials,
                createdByUserId: $request->user()->id,
            );
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'server_url' => 'This MCP server URL is already configured for this workspace. Manage the existing connection instead.',
            ])->withInput();
        }

        // Run discovery synchronously so the admin sees the tool list
        // immediately on the next page (not after a queue worker tick).
        // If the server is unreachable, discovery marks status=degraded
        // and the error appears in the UI.
        try {
            $this->discovery->sync($server);
        } catch (\Throwable $e) {
            Log::warning('mcp.attach.sync_failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('agents.mcp.index', $agent)
            ->with('success', "MCP server [{$server->label}] attached. Enable the tools you want the agent to call.");
    }

    public function destroy(Request $request, Agent $agent, McpServer $mcpServer): RedirectResponse
    {
        $this->authorize($request, $agent);
        abort_unless($mcpServer->workspace_id === $agent->workspace_id, 404);

        $mcpServer->delete();
        $this->registry->invalidate($agent);

        return back()->with('success', 'MCP server disconnected. Tool grants for this agent were removed.');
    }

    public function testConnection(Request $request, Agent $agent, McpServer $mcpServer): RedirectResponse
    {
        $this->authorize($request, $agent);
        abort_unless($mcpServer->workspace_id === $agent->workspace_id, 404);

        $conn = $this->registry->connectionFor($mcpServer);
        try {
            $info = $this->client->initialize($conn);
            $this->client->ping($conn);
            $mcpServer->forceFill([
                'connection_test_at' => now(),
                'connection_test_result' => [
                    'ok' => true,
                    'server' => $info->serverName,
                    'version' => $info->serverVersion,
                ],
            ])->save();

            return back()->with('success', "Connection ok — {$info->serverName} v{$info->serverVersion}.");
        } catch (McpException $e) {
            $mcpServer->forceFill([
                'connection_test_at' => now(),
                'connection_test_result' => ['ok' => false, 'error' => $e->getMessage()],
            ])->save();

            return back()->with('error', "Connection failed: {$e->getMessage()}");
        }
    }

    public function refreshTools(Request $request, Agent $agent, McpServer $mcpServer): RedirectResponse
    {
        $this->authorize($request, $agent);
        abort_unless($mcpServer->workspace_id === $agent->workspace_id, 404);

        SyncMcpCatalogueJob::dispatch($mcpServer->id);
        $this->registry->invalidate($agent);

        return back()->with('success', 'Tool catalogue refresh scheduled. Reload in a moment to see the updated list.');
    }

    public function tools(Request $request, Agent $agent, McpServer $mcpServer): Response
    {
        $this->authorize($request, $agent);
        abort_unless($mcpServer->workspace_id === $agent->workspace_id, 404);

        $tools = McpTool::query()
            ->withoutWorkspaceScope()
            ->where('mcp_server_id', $mcpServer->id)
            ->whereNull('removed_at')
            ->orderBy('name')
            ->get();

        $grantsByToolId = \DB::table('agent_mcp_tool_grants')
            ->where('agent_id', $agent->id)
            ->whereIn('mcp_tool_id', $tools->pluck('id'))
            ->get()
            ->keyBy('mcp_tool_id');

        return Inertia::render('app/agents/mcp-tools', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'server' => [
                'id' => $mcpServer->id,
                'label' => $mcpServer->label,
                'status' => $mcpServer->status,
            ],
            'tools' => $tools->map(fn (McpTool $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'namespaced_name' => $t->namespaced_name,
                'description' => $t->description,
                'is_destructive' => $t->is_destructive,
                'is_idempotent' => $t->is_idempotent,
                'input_schema' => $t->input_schema,
                'enabled' => (bool) ($grantsByToolId[$t->id]->enabled ?? false),
            ])->values(),
        ]);
    }

    public function bulkUpdateGrants(Request $request, Agent $agent, McpServer $mcpServer): RedirectResponse
    {
        $this->authorize($request, $agent);
        abort_unless($mcpServer->workspace_id === $agent->workspace_id, 404);

        $data = $request->validate([
            'grants' => ['required', 'array'],
            'grants.*.tool_id' => ['required', 'string'],
            'grants.*.enabled' => ['required', 'boolean'],
        ]);

        $toolIds = collect($data['grants'])->pluck('tool_id')->all();
        $serverTools = McpTool::query()
            ->withoutWorkspaceScope()
            ->where('mcp_server_id', $mcpServer->id)
            ->whereIn('id', $toolIds)
            ->get()
            ->keyBy('id');

        foreach ($data['grants'] as $grant) {
            $tool = $serverTools[$grant['tool_id']] ?? null;
            if ($tool === null) {
                continue;
            }
            $this->registry->setGrant(
                agent: $agent,
                tool: $tool,
                enabled: (bool) $grant['enabled'],
                actorUserId: $request->user()->id,
            );
        }

        return back()->with('success', 'Tool grants updated.');
    }

    private function authorize(Request $request, Agent $agent): void
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($agent->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);
    }
}
