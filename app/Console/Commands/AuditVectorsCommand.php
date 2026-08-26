<?php

namespace App\Console\Commands;

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Console\Command;

/**
 * Compares DB chunks against the vector index. Reports drift and (with
 * --reindex) re-queues IndexDocumentJob for documents missing from
 * Vectorize.
 *
 * Drift can creep in when:
 *   - A vector upsert silently 4xx'd in production
 *   - A doc was force-deleted via tinker without observer cleanup
 *   - A reindex was interrupted mid-flight
 *
 * The audit uses a zero-vector search with a large limit per agent. This
 * pulls back the chunk_ids currently indexed; we then diff against the DB.
 *
 * NOTE: For agents with > $perAgentSampleLimit chunks the audit is partial.
 */
class AuditVectorsCommand extends Command
{
    protected $signature = 'pitchbar:audit-vectors
        {--agent= : Audit only one agent (UUID)}
        {--reindex : Re-queue IndexDocumentJob for any DB chunks missing from Vectorize}
        {--limit=2000 : Max chunks queried per agent from the vector index}';

    protected $description = 'Compare DB chunks against the vector index and report drift';

    public function handle(QdrantClient $vector): int
    {
        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
        $perAgentSampleLimit = max(1, (int) $this->option('limit'));
        $reindex = (bool) $this->option('reindex');
        $singleAgent = (string) ($this->option('agent') ?? '');

        $agents = Agent::query()->withoutGlobalScopes();
        if ($singleAgent !== '') {
            $agents->where('id', $singleAgent);
        }
        $agents = $agents->get();

        if ($agents->isEmpty()) {
            $this->info('No agents to audit.');

            return self::SUCCESS;
        }

        $totalDrift = 0;

        foreach ($agents as $agent) {
            // Stream chunk ids in batches so a 100K-chunk agent doesn't
            // load every UUID into one array.
            $dbChunkIds = [];
            Chunk::query()->withoutWorkspaceScope()
                ->where('agent_id', $agent->id)
                ->orderBy('id')
                ->chunkById(2000, function ($rows) use (&$dbChunkIds): void {
                    foreach ($rows as $row) {
                        $dbChunkIds[] = $row->id;
                    }
                });

            if (empty($dbChunkIds)) {
                continue;
            }

            // Zero-vector search: grabs up to $limit points for this agent.
            // We don't care about score order — only which chunk_ids exist.
            $dim = (int) config('services.cloudflare.vector_dim', 768);
            $zeroVec = array_fill(0, $dim, 0.0);
            $hits = $vector->search(
                $collection,
                $zeroVec,
                ['agent_id' => $agent->id],
                $perAgentSampleLimit,
            );

            $vectorChunkIds = array_filter(array_map(
                fn (array $hit) => $hit['payload']['chunk_id'] ?? null,
                $hits,
            ));

            $dbSet = array_flip($dbChunkIds);
            $vecSet = array_flip($vectorChunkIds);

            $missingFromVector = array_keys(array_diff_key($dbSet, $vecSet));
            $orphansInVector = array_keys(array_diff_key($vecSet, $dbSet));
            $partial = count($vectorChunkIds) >= $perAgentSampleLimit;

            $this->line(sprintf(
                ' · agent %s · db=%d · vec=%d · missing=%d · orphan=%d%s',
                mb_substr($agent->id, 0, 8),
                count($dbChunkIds),
                count($vectorChunkIds),
                count($missingFromVector),
                count($orphansInVector),
                $partial ? ' (PARTIAL — raise --limit)' : '',
            ));

            $totalDrift += count($missingFromVector) + count($orphansInVector);

            if ($reindex && $missingFromVector !== []) {
                $docIds = Chunk::query()->withoutWorkspaceScope()
                    ->whereIn('id', $missingFromVector)
                    ->distinct()
                    ->pluck('document_id');

                foreach ($docIds as $documentId) {
                    $text = Chunk::query()->withoutWorkspaceScope()
                        ->where('document_id', $documentId)
                        ->orderBy('ord')
                        ->pluck('text')
                        ->implode("\n\n");

                    if ($text !== '') {
                        IndexDocumentJob::dispatch($documentId, $text)->onQueue('index');
                    }
                }
                $this->line('   ↳ re-queued '.count($docIds).' document(s) for reindex');
            }
        }

        if ($totalDrift === 0) {
            $this->info('Vector index is consistent with DB.');
        } else {
            $this->warn("Detected {$totalDrift} drift entr".($totalDrift === 1 ? 'y' : 'ies').' across '.$agents->count().' agent(s).');
        }

        return self::SUCCESS;
    }
}
