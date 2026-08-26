<?php

namespace App\Jobs\Mcp;

use App\Models\McpServer;
use App\Services\Mcp\McpToolDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper for McpToolDiscovery::sync. Dispatched on:
 *  - server attach (controller dispatches sync so admin sees tools
 *    immediately, but background path is available too)
 *  - admin "Refresh tools" click
 *  - scheduled daily sweep
 *
 * `tries=2`: if the MCP server flakes once, retry; if it flakes twice
 * the discovery method already marks status=degraded and persists
 * the error message so the admin UI shows what went wrong.
 */
class SyncMcpCatalogueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $mcpServerId)
    {
        $this->onQueue('analytics');
    }

    public function handle(McpToolDiscovery $discovery): void
    {
        $server = McpServer::query()
            ->withoutWorkspaceScope()
            ->find($this->mcpServerId);

        if ($server === null) {
            return;
        }

        $discovery->sync($server);
    }
}
