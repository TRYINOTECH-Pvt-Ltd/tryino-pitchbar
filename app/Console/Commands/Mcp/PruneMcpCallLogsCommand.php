<?php

namespace App\Console\Commands\Mcp;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trim mcp_call_logs older than the configured retention. Default
 * 30 days — operational telemetry, not financial records.
 *
 * Scheduled via routes/console.php to run daily.
 */
class PruneMcpCallLogsCommand extends Command
{
    protected $signature = 'pitchbar:prune-mcp-call-logs {--days=30}';

    protected $description = 'Delete MCP tool call audit rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        $deleted = DB::table('mcp_call_logs')
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("Pruned {$deleted} mcp_call_logs row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
