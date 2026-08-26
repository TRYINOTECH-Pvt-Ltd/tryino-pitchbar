<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\Source;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Indexes a plain-text source the user pasted in directly. Skips the
 * crawler + extractor stack entirely — the user gives us clean text,
 * we feed it straight to the chunker + embedder.
 *
 * This is the "100% works" path when URL crawling fails for whatever
 * reason (anti-bot, SPA, paywall, login wall, weird encoding).
 */
class IndexTextSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Spaced backoff so transient upstream errors (429, 5xx, network)
     * get a real second chance instead of three attempts in one second.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function __construct(
        public string $sourceId,
        public string $title,
        public string $body,
        public ?string $sourceUrl = null,
    ) {}

    public function handle(): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $text = trim($this->body);
        if (mb_strlen($text) < 50) {
            $source->forceFill(['status' => 'failed', 'error' => 'Pasted content is too short to index (need at least 50 characters).'])->save();

            return;
        }

        $hash = hash('sha256', $text);

        // Replace semantics: drop this source's prior document(s) and their
        // vectors before re-indexing. Without this an edit (or any re-run)
        // would leave the OLD pasted content embedded and answerable
        // alongside the new text. Chunk rows cascade with the document;
        // the vector points need an explicit purge by document_id.
        $stale = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->get();
        foreach ($stale as $old) {
            $this->purgeVectors($old);
            $old->delete();
        }

        $document = Document::create([
            'source_id' => $source->id,
            'agent_id' => $source->agent_id,
            'url' => $this->sourceUrl ?? "text://{$source->id}",
            'title' => Str::limit($this->title, 250) ?: 'Pasted content',
            'content_hash' => $hash,
            'lang' => null,
            'fetched_at' => now(),
        ]);

        IndexDocumentJob::dispatch($document->id, $text)->onQueue('index');

        $source->forceFill([
            'status' => 'indexed',
            'error' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    private function purgeVectors(Document $doc): void
    {
        try {
            app(QdrantClient::class)->deleteByFilter(
                (string) config('services.vector_collection', 'pitchbar-chunks'),
                ['document_id' => $doc->id],
            );
        } catch (\Throwable) {
            // best-effort; chunk rows still cascade on document delete.
        }
    }

    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            return;
        }
        $source->forceFill([
            'status' => 'failed',
            'error' => 'Index failed: '.Str::limit($e->getMessage(), 480),
        ])->save();
    }
}
