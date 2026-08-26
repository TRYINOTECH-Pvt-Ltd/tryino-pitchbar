<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Models\Conversation;
use App\Services\Scoring\LeadScoringEngine;
use Illuminate\Console\Command;

/**
 * Bulk-recompute `conversations.lead_score` for existing rows. Useful
 * after tuning weights / adding intent keywords / introducing the
 * feature on a workspace that already has months of conversation
 * history.
 *
 *   php artisan pitchbar:recompute-lead-scores
 *     --workspace=01h…   # restrict to one workspace
 *     --agent=01h…       # restrict to one agent (overrides workspace)
 *     --queue            # dispatch RecomputeLeadScoreJob per row
 *                        # instead of computing inline
 *     --chunk=500        # row batch size (default 500)
 */
class RecomputeLeadScoresCommand extends Command
{
    protected $signature = 'pitchbar:recompute-lead-scores
        {--workspace= : Limit to a specific workspace_id}
        {--agent= : Limit to a specific agent_id (overrides --workspace)}
        {--queue : Dispatch via the queue instead of computing inline}
        {--chunk=500 : Number of conversations per chunk}';

    protected $description = 'Recompute lead_score on existing conversations (one-shot backfill).';

    public function handle(LeadScoringEngine $engine): int
    {
        $query = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('is_playground', false);

        $agentFilter = $this->option('agent');
        $workspaceFilter = $this->option('workspace');

        if (is_string($agentFilter) && $agentFilter !== '') {
            $query->where('agent_id', $agentFilter);
        } elseif (is_string($workspaceFilter) && $workspaceFilter !== '') {
            $query->whereHas('agent', fn ($q) => $q->where('workspace_id', $workspaceFilter)->withoutGlobalScopes());
        }

        $total = (int) (clone $query)->count();
        if ($total === 0) {
            $this->info('No conversations match — nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Recomputing lead score for %d conversation(s)…', $total));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $useQueue = (bool) $this->option('queue');
        $chunk = max(50, (int) $this->option('chunk'));
        $updated = 0;

        $query->orderBy('id')->chunkById($chunk, function ($conversations) use ($engine, $bar, $useQueue, &$updated): void {
            foreach ($conversations as $conversation) {
                if ($useQueue) {
                    RecomputeLeadScoreJob::dispatch($conversation->id);
                } else {
                    $result = $engine->compute($conversation);
                    $conversation->forceFill([
                        'lead_score' => $result['score'],
                        'lead_score_bucket' => $result['bucket'],
                        'lead_score_reasons' => $result['reasons'],
                        'lead_score_updated_at' => now(),
                    ])->save();
                }

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info(sprintf(
            '%s %d conversation(s).',
            $useQueue ? 'Dispatched' : 'Recomputed inline for',
            $updated,
        ));

        return self::SUCCESS;
    }
}
