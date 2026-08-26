<?php

namespace App\Http\Controllers\Admin\Mcp;

use App\Models\Agent;
use App\Models\McpServer;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-server activity view. When a buyer reports "the MCP integration
 * isn't working", operators land here and see the last 100 tool calls
 * with their status + latency + error summary.
 */
class McpActivityController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(Request $request, Agent $agent, McpServer $mcpServer): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($agent->workspace_id === $workspace->id, 404);
        abort_unless($mcpServer->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $status = (string) $request->query('status', '');
        $query = \DB::table('mcp_call_logs')
            ->where('mcp_server_id', $mcpServer->id)
            ->orderByDesc('created_at')
            ->limit(100);

        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->get();

        return Inertia::render('app/agents/mcp-activity', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'server' => [
                'id' => $mcpServer->id,
                'label' => $mcpServer->label,
                'status' => $mcpServer->status,
            ],
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'tool_name' => $r->tool_name,
                'status' => $r->status,
                'latency_ms' => (int) $r->latency_ms,
                'request_id' => $r->request_id,
                'conversation_id' => $r->conversation_id,
                'error_summary' => $r->error_summary,
                'output_truncated' => (bool) $r->output_truncated,
                'created_at' => $r->created_at,
            ])->values(),
            'filter' => ['status' => $status],
        ]);
    }
}
