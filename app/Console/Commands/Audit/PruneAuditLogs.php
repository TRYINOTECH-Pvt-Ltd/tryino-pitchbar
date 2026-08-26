<?php

namespace App\Console\Commands\Audit;

use App\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prune AuditLog rows older than the retention window (default 365
 * days). Audit data is operational metadata, not compliance evidence;
 * we keep enough history to investigate "what changed two weeks ago?"
 * but not enough to balloon the DB indefinitely. Customers with
 * stricter compliance requirements (GDPR, SOC2, HIPAA) should export
 * their audit log on a separate cadence before this command runs.
 *
 * Scheduled daily at 03:15 server-local via routes/console.php so the
 * delete batches don't compete with peak traffic.
 */
#[Signature('audit:prune {--days=365 : Retention window in days} {--dry-run : Report what would be pruned without deleting}')]
#[Description('Prune AuditLog rows older than the retention window (default 365 days).')]
class PruneAuditLogs extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $query = AuditLog::query()->where('created_at', '<', $cutoff);

        $count = (int) $query->count();

        if ($count === 0) {
            $this->info("No audit rows older than {$days} days. Nothing to prune.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would prune {$count} audit rows older than {$days} days.");

            return self::SUCCESS;
        }

        $deleted = (int) $query->delete();
        $this->info("Pruned {$deleted} audit rows older than {$days} days.");

        return self::SUCCESS;
    }
}
