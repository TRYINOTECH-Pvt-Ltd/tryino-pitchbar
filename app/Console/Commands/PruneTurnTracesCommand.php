<?php

namespace App\Console\Commands;

use App\Models\TurnTrace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('turn-traces:prune {--days= : Override the configured retention window}')]
#[Description('Delete turn traces older than the retention window (TURN_TRACE_RETENTION_DAYS, default 14)')]
class PruneTurnTracesCommand extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('services.turn_traces.retention_days', 14));
        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        // Global sweep across workspaces — pruning is platform
        // housekeeping, not a tenant-scoped query.
        $deleted = TurnTrace::query()->withoutWorkspaceScope()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} turn traces older than {$days} days.");

        return self::SUCCESS;
    }
}
