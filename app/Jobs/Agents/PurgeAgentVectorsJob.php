<?php

namespace App\Jobs\Agents;

use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purge every vector embedding belonging to an agent.
 *
 * Dispatched on hard-delete from both customer and platform admin
 * destroy paths. The agent row + every Chunk row cascades from the DB,
 * but Vectorize/Qdrant lives outside the DB so without this job the
 * vector store accumulates orphan rows that re-index audits flag
 * forever after. Idempotent: repeated runs on a missing agent_id are
 * a no-op.
 */
class PurgeAgentVectorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    // Vectorize delete-by-filter pages 100 ids at a time up to 20
    // pages (~2k vectors per call) — large agents may need a few
    // dispatches but each one is bounded.
    public int $timeout = 120;

    public function __construct(public string $agentId) {}

    public function handle(QdrantClient $vector): void
    {
        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');

        try {
            $vector->deleteByFilter($collection, ['agent_id' => $this->agentId]);
        } catch (Throwable $e) {
            // Swallow rather than retry-spam: the agent row is already
            // gone, so retries can't help if the vector store is hard
            // down. The AuditVectorsCommand reconciliation pass will
            // catch any leftovers.
            Log::warning('jobs.purge_agent_vectors.failed', [
                'agent_id' => $this->agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
