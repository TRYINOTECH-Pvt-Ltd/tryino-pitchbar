<?php

namespace App\Services\Mcp;

use App\Models\AgentMcpToolGrant;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Exceptions\McpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Discovers the tool catalogue of an MCP server and reconciles it
 * with the local mcp_tools table. Runs on:
 *
 *  - server attach (synchronous in the controller, so the admin
 *    sees the tool list immediately)
 *  - admin "Refresh" click
 *  - scheduled daily sweep with per-server jitter
 *
 * Schema-drift defence: if the input_schema of an already-known
 * tool changes between refreshes, EVERY existing grant for that
 * tool is flipped to enabled=false. The admin must re-approve.
 * Rationale: a tool that used to be "search_issues(query: string)"
 * may have become "search_issues(filter: object, urgency: enum)"
 * and the LLM cannot guess the new shape. Forcing re-approval also
 * gives the admin a chance to inspect new parameters that may have
 * security implications.
 *
 * Tombstone: when a tool reported in a previous refresh disappears
 * from the catalogue, we set removed_at=now() rather than deleting
 * — preserves audit-log foreign keys and lets admins see "tool
 * removed by server on {date}" in the UI.
 */
class McpToolDiscovery
{
    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRegistry $registry,
    ) {}

    /**
     * Sync the catalogue for one server. Returns true on success,
     * false on transport failure (caller sees `tools_sync_error`
     * for details).
     */
    public function sync(McpServer $server): bool
    {
        $conn = $this->registry->connectionFor($server);

        try {
            $serverInfo = $this->client->initialize($conn);
            $schemas = $this->client->listTools($conn);
        } catch (McpException $e) {
            Log::warning('mcp.discovery.failed', [
                'server_id' => $server->id,
                'workspace_id' => $server->workspace_id,
                'error' => $e->getMessage(),
            ]);

            $server->forceFill([
                'tools_sync_error' => mb_substr($e->getMessage(), 0, 1000),
                'status' => McpServer::STATUS_DEGRADED,
            ])->save();

            return false;
        }

        DB::transaction(function () use ($server, $serverInfo, $schemas) {
            $server->forceFill([
                'server_info' => [
                    'protocol_version' => $serverInfo->protocolVersion,
                    'name' => $serverInfo->serverName,
                    'version' => $serverInfo->serverVersion,
                    'capabilities' => $serverInfo->capabilities,
                ],
                'status' => McpServer::STATUS_ACTIVE,
                'tools_synced_at' => now(),
                'tools_sync_error' => null,
            ])->save();

            $seenNames = [];
            $serverLabelSlug = Str::slug($server->label, '_');
            if ($serverLabelSlug === '') {
                $serverLabelSlug = substr($server->id, 0, 8);
            }

            foreach ($schemas as $schema) {
                $seenNames[] = $schema->name;

                $existing = McpTool::query()
                    ->withoutWorkspaceScope()
                    ->where('mcp_server_id', $server->id)
                    ->where('name', $schema->name)
                    ->first();

                if ($existing === null) {
                    $tool = new McpTool;
                    $tool->forceFill([
                        'id' => (string) Str::uuid7(),
                        'mcp_server_id' => $server->id,
                        'workspace_id' => $server->workspace_id,
                        'name' => $schema->name,
                        'namespaced_name' => "{$serverLabelSlug}.{$schema->name}",
                        'description' => $schema->description,
                        'input_schema' => $schema->inputSchema,
                        'is_destructive' => $schema->isDestructive(),
                        'is_idempotent' => $schema->isIdempotent(),
                        'requires_open_world' => $schema->requiresOpenWorld(),
                        'catalogue_revision' => 1,
                        'removed_at' => null,
                    ])->save();

                    continue;
                }

                $schemaChanged = $this->hasSchemaChanged($existing->input_schema, $schema->inputSchema);

                $existing->forceFill([
                    'description' => $schema->description,
                    'input_schema' => $schema->inputSchema,
                    'is_destructive' => $schema->isDestructive(),
                    'is_idempotent' => $schema->isIdempotent(),
                    'requires_open_world' => $schema->requiresOpenWorld(),
                    'catalogue_revision' => ($existing->catalogue_revision ?? 0) + 1,
                    'removed_at' => null,
                    'namespaced_name' => "{$serverLabelSlug}.{$schema->name}",
                ])->save();

                if ($schemaChanged) {
                    Log::warning('mcp.tool_schema_changed', [
                        'tool_id' => $existing->id,
                        'server_id' => $server->id,
                        'workspace_id' => $server->workspace_id,
                    ]);

                    AgentMcpToolGrant::query()
                        ->withoutWorkspaceScope()
                        ->where('mcp_tool_id', $existing->id)
                        ->update([
                            'enabled' => false,
                            'enabled_at' => null,
                            'enabled_by_user_id' => null,
                        ]);
                }
            }

            // Tombstone tools no longer reported.
            McpTool::query()
                ->withoutWorkspaceScope()
                ->where('mcp_server_id', $server->id)
                ->whereNotIn('name', $seenNames !== [] ? $seenNames : [''])
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);
        });

        return true;
    }

    /**
     * Schema-equality check. Compare the canonical JSON-encoded
     * representation (sorted keys) so re-ordering of properties
     * doesn't trigger a false-positive drift.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $incoming
     */
    private function hasSchemaChanged(?array $stored, array $incoming): bool
    {
        if ($stored === null) {
            return false;
        }

        return $this->canonicalJson($stored) !== $this->canonicalJson($incoming);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->sortKeysRecursive($value), JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = $this->sortKeysRecursive($v);
            }
        }

        return $value;
    }
}
