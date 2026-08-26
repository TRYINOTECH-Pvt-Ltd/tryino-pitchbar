<?php

namespace App\Jobs\Crawl;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\OpenAiHttpClient;
use App\Services\Rag\Chunker;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\EmbedModelDimensions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    // Covers Workers AI cold-start + a 4-batch document; the 60s
    // worker default kills mid-batch.
    public int $timeout = 180;

    /**
     * Run failed() on timeout so the parent source flips to `failed`
     * with a readable error instead of stranding in `crawling`.
     */
    public bool $failOnTimeout = true;

    /**
     * Spaced backoff — embedding + vector upserts hit provider rate
     * limits (429) in bursts; immediate retries just re-hit the same
     * window and burn the attempts in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function __construct(public string $documentId, public string $text) {}

    public function handle(Chunker $chunker, OpenAiClient $llm, QdrantClient $vector): void
    {
        $document = Document::query()->withoutWorkspaceScope()->find($this->documentId);
        if ($document === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->documentId]);

            return;
        }
        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');

        // Auto-create the index on first use. Without this, a fresh
        // Cloudflare install fails its very first indexing job with
        // `{"code":40040,"message":"The index was not found"}` because
        // nothing else in the request lifecycle ever calls
        // ensureCollection() — and Cloudflare's `deleteByFilter` path
        // (queries the index first) is the first call we make per job,
        // so it throws before we can even seed an empty index.
        // Idempotent + cheap when the index already exists (one HEAD
        // request); 2 min provisioning lag the first time per their docs.
        // Resolve the dim with model-aware priority:
        //   1. Explicit VECTOR_DIM env (operator override)
        //   2. Map the configured embed model to its known dim
        //   3. Hard fallback 768 (bge-base default)
        // Pre-2026-05-15 we always read `services.vector_dim` whose env
        // default is 768. Operators who switched CLOUDFLARE_EMBED_MODEL
        // to a 1024-dim model (bge-m3 / bge-large) without ALSO setting
        // VECTOR_DIM=1024 produced indexes at 768, embeddings at 1024,
        // and every IndexDocumentJob crashed with Cloudflare 40012.
        $dim = EmbedModelDimensions::resolveExpectedDim();

        try {
            $vector->ensureCollection($collection, $dim);
        } catch (\Throwable $e) {
            Log::warning('vectorize.ensure_collection_failed', [
                'collection' => $collection,
                'dim' => $dim,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Re-index: remove any prior points for this document
        $vector->deleteByFilter($collection, ['document_id' => $document->id]);
        Chunk::query()->withoutWorkspaceScope()->where('document_id', $document->id)->delete();

        $segments = $chunker->chunk($this->text);
        if ($segments === []) {
            // Document slipped through with parseable text but the
            // Chunker rejected everything (text under the minimum
            // chunk size, or all whitespace after normalisation).
            // Log instead of returning silently — admins were seeing
            // "Sources indexed" with zero retrieval results and no
            // diagnostic trail. Source-level error is set on the
            // parent so the operator sees it on the Sources page.
            Log::warning('index.empty_chunks', [
                'document_id' => $document->id,
                'source_id' => $document->source_id,
                'text_length' => mb_strlen($this->text),
            ]);
            $document->source?->forceFill([
                'error' => 'Document yielded no chunks (text too short / unparseable).',
            ])->save();

            return;
        }

        $chunkRows = [];
        foreach ($segments as $i => $segment) {
            $chunkRows[] = Chunk::create([
                'document_id' => $document->id,
                'agent_id' => $document->agent_id,
                'ord' => $i,
                'text' => $segment,
                'token_count' => (int) ceil(mb_strlen($segment) / 4),
            ]);
        }

        // Embed in batches of 100
        $batches = array_chunk($chunkRows, 100);
        foreach ($batches as $batch) {
            $vectors = $this->embedWithFallback(
                $llm,
                array_map(fn (Chunk $c) => $c->text, $batch),
            );
            $points = [];
            foreach ($batch as $idx => $chunk) {
                $pointId = (string) Str::uuid7();
                $chunk->forceFill(['qdrant_point_id' => $pointId])->save();
                $points[] = [
                    'id' => $pointId,
                    'vector' => $vectors[$idx],
                    'payload' => [
                        'workspace_id' => $document->agent->workspace_id ?? null,
                        'agent_id' => $document->agent_id,
                        'source_id' => $document->source_id,
                        'document_id' => $document->id,
                        'chunk_id' => $chunk->id,
                        'url' => $document->url,
                        'lang' => $document->lang,
                    ],
                ];
            }
            $vector->upsertPoints($collection, $points);
        }
    }

    /**
     * Embed with a built-in failover. When the configured primary client
     * (typically Cloudflare Workers AI in production) raises, we try
     * the resolved fallback (`embedding.fallback` binding — usually
     * OpenAI when OPENAI_API_KEY is set). This is the most common
     * "indexing didn't finish" failure mode: a transient CF outage
     * shouldn't strand customer documents at 0 chunks.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function embedWithFallback(OpenAiClient $primary, array $texts): array
    {
        try {
            return $primary->embed($texts);
        } catch (\Throwable $primaryError) {
            // Skip the fallback path if the primary IS the fallback (would
            // just hit the same provider twice with the same failure).
            if ($primary instanceof OpenAiHttpClient) {
                throw $primaryError;
            }

            $fallback = $this->resolveFallback();
            if ($fallback === null) {
                throw $primaryError;
            }

            Log::warning('Primary embed failed; falling back.', [
                'primary' => $primary::class,
                'fallback' => $fallback::class,
                'error' => $primaryError->getMessage(),
                'batch_size' => count($texts),
            ]);

            return $fallback->embed($texts);
        }
    }

    /**
     * Container-resolved fallback embedding client. Returns null when
     * no fallback is configured (production has only CF, etc.) so the
     * caller can rethrow the primary error cleanly.
     *
     * Tests bind a fake under 'embedding.fallback' to verify the
     * failover path without hitting a real provider.
     */
    private function resolveFallback(): ?OpenAiClient
    {
        if (! app()->bound('embedding.fallback')) {
            return null;
        }
        $resolved = app('embedding.fallback');

        return $resolved instanceof OpenAiClient ? $resolved : null;
    }

    /**
     * Laravel invokes this when all retries are exhausted. Stamp the
     * parent source with a human-readable error so the admin Sources
     * page surfaces the failure — previously the source stayed in
     * 'indexed' (set optimistically at upload time) while the
     * document had zero chunks, hiding the breakage.
     */
    public function failed(\Throwable $e): void
    {
        $document = Document::query()->withoutWorkspaceScope()->find($this->documentId);
        if ($document === null) {
            return;
        }
        $source = $document->source;
        if ($source === null) {
            return;
        }
        // Enriched error trail: include exception class so the customer
        // (or /app/system-health) can tell a rate-limit (429 →
        // OpenAiRateLimitException) apart from a bad-request
        // (OpenAiBadRequestException) apart from a Vectorize 40040.
        $exceptionClass = get_class($e);
        $shortClass = mb_substr((string) strrchr($exceptionClass, '\\') ?: $exceptionClass, 1) ?: $exceptionClass;
        $source->forceFill([
            'status' => 'failed',
            'error' => sprintf(
                '[%s] %s',
                $shortClass,
                Str::limit($e->getMessage(), 360),
            ),
        ])->save();
    }
}
