<?php

namespace App\Services\Mcp\Support;

use App\Models\McpServer;
use Illuminate\Support\Facades\Cache;

/**
 * Per-server breaker that pauses calls after repeated failures.
 *
 * Threshold + window are deliberately conservative so a buyer's
 * slightly-flaky MCP server doesn't completely vanish from the
 * agent's toolbox after a couple of hiccups — but a hard-down
 * server stops paying us 5s of latency on every turn.
 *
 * Cache keys live in the request cache (Redis in prod) — Octane-safe
 * because we never accumulate state on $this.
 */
class McpCircuitBreaker
{
    private const FAILURE_WINDOW_SECONDS = 60;

    private const OPEN_DURATION_SECONDS = 60;

    private const THRESHOLD = 5;

    public function shouldCall(McpServer $server): bool
    {
        return ! Cache::has($this->openKey($server));
    }

    public function recordSuccess(McpServer $server): void
    {
        Cache::forget($this->failuresKey($server));
    }

    public function recordFailure(McpServer $server): void
    {
        $count = (int) Cache::increment($this->failuresKey($server));
        if ($count === 1) {
            Cache::put($this->failuresKey($server), 1, self::FAILURE_WINDOW_SECONDS);
        }
        if ($count >= self::THRESHOLD) {
            Cache::put($this->openKey($server), 1, self::OPEN_DURATION_SECONDS);
        }
    }

    private function failuresKey(McpServer $server): string
    {
        return "mcp_circuit:{$server->id}:fails";
    }

    private function openKey(McpServer $server): string
    {
        return "mcp_circuit:{$server->id}:open";
    }
}
