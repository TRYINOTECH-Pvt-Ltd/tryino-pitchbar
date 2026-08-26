<?php

namespace App\Console\Commands\Vector;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Services\Crawl\SourceRetrier;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\EmbedModelDimensions;
use Illuminate\Console\Command;

/**
 * Operator recovery flow for embedding-model changes.
 *
 * Buyer-reported (whispbar, 2026-05-15): switching
 * CLOUDFLARE_EMBED_MODEL from bge-base (768 dim) to bge-m3 (1024 dim)
 * without recreating the Vectorize index produced
 *   "expected 768 dimensions, and got 1024 dimensions"
 * on every CrawlPageJob → IndexDocumentJob, blocking the entire
 * knowledge-base indexing pipeline. This command:
 *
 *   1. Drops the existing Vectorize index.
 *   2. Drops every local Chunk row.
 *   3. Re-provisions the index at the dimension that matches the
 *      configured embedding model (resolved through
 *      `EmbedModelDimensions::resolveExpectedDim()`).
 *   4. Re-indexes EVERY source through its type-appropriate pipeline
 *      via `SourceRetrier` — the same canonical map the Reindex button
 *      and the self-healing sweep use, so url/text/file/google_doc/
 *      google_sheet/notion/sql/auto sources all refill.
 *
 * blengi-reported (2026-07-05): the previous step 4 only re-dispatched
 * `IndexDocumentJob` for documents whose parsed text was persisted on
 * disk. URL/crawled sources have no such text, so a bge-base→bge-m3
 * switch printed "Queued 0/132" and left the KB EMPTY until a manual
 * re-crawl — the command silently under-delivered on its own "re-index
 * every Source" promise. Routing through SourceRetrier fixes that.
 *
 * Confirmation prompt by default; pass `--force` for automation.
 */
class RebuildIndexCommand extends Command
{
    protected $signature = 'vector:rebuild-index
                            {--force : Skip the confirmation prompt}
                            {--confirm-production : Required when APP_ENV=production to authorize destructive drop+delete on live data}
                            {--dim= : Override the target dimension (otherwise resolved from the configured embedding model)}';

    protected $description = 'Drop + recreate the Vectorize index at the configured embedding model\'s dimension, then re-index every Source';

    public function handle(QdrantClient $vector, SourceRetrier $retrier): int
    {
        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
        $dim = $this->option('dim') !== null
            ? (int) $this->option('dim')
            : EmbedModelDimensions::resolveExpectedDim();

        if ($dim <= 0) {
            $this->error("Resolved dimension is {$dim}; refusing to provision a zero-dim index.");

            return self::FAILURE;
        }

        // Production requires --force AND --confirm-production — this
        // command drops the Vectorize index + every Chunk row.
        if (app()->isProduction() && $this->option('force') && ! $this->option('confirm-production')) {
            $this->error('Refusing to run in production with --force unless --confirm-production is ALSO passed.');
            $this->line('This command DROPS the Vectorize index and DELETES every Chunk row — losing it costs a re-crawl of every Source.');

            return self::FAILURE;
        }

        $sourceCount = Source::query()->withoutGlobalScopes()->count();
        $documentCount = Document::query()->withoutGlobalScopes()->count();
        $chunkCount = Chunk::query()->withoutGlobalScopes()->count();

        $this->line("Vectorize collection : <fg=cyan>{$collection}</>");
        $this->line("Target dimension     : <fg=cyan>{$dim}</>");
        $this->line("Sources              : <fg=yellow>{$sourceCount}</>");
        $this->line("Documents            : <fg=yellow>{$documentCount}</>");
        $this->line("Local chunks         : <fg=yellow>{$chunkCount}</>");
        $this->newLine();
        $this->warn('This will DROP the Vectorize index, DELETE every local chunk row, and re-index every Source through its type-appropriate pipeline (re-crawls URL sources, re-embeds text/file/API sources).');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $this->line('Dropping Vectorize index…');
        $vector->dropCollection($collection);

        $this->line('Re-creating index at the target dimension…');
        $vector->ensureCollection($collection, $dim);

        $this->line('Clearing local Chunk rows…');
        Chunk::query()->withoutGlobalScopes()->delete();

        // Re-index EVERY source through the canonical per-type pipeline
        // (SourceRetrier) — the SAME map the Reindex button and the
        // self-healing sweep use. Re-crawls url/auto sources, re-embeds
        // persisted text/file bodies, re-syncs google_doc/sheet/notion/sql.
        // SourceRetrier already flips each source to `pending` as it
        // dispatches, so the UI shows the re-index in progress.
        $this->line("Re-indexing {$sourceCount} source(s) through their type-appropriate pipeline…");
        $bar = $this->output->createProgressBar($sourceCount);
        $bar->start();

        $queued = 0;
        /** @var array<string, int> $skipped */
        $skipped = [];

        Source::query()
            ->withoutGlobalScopes()
            ->chunkById(200, function ($sources) use ($retrier, &$queued, &$skipped, $bar): void {
                foreach ($sources as $source) {
                    try {
                        $result = $retrier->retry($source);
                        if ($result['ok']) {
                            $queued += $result['queued'];
                        } else {
                            $reason = $result['reason'] ?? 'unknown';
                            $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                        }
                    } catch (\Throwable $e) {
                        $skipped['dispatch_error'] = ($skipped['dispatch_error'] ?? 0) + 1;
                        $source->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Queued {$queued} indexing job(s) across {$sourceCount} source(s).");
        foreach ($skipped as $reason => $count) {
            $this->warn("  Skipped {$count} source(s): {$reason}");
        }
        $this->line("Watch the 'crawl' + 'index' queue workers — the Knowledge view refills as each source re-embeds.");

        return self::SUCCESS;
    }
}
