<?php

namespace App\Services\Crawl;

use App\Http\Controllers\Admin\UploadController;
use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Jobs\Crawl\SyncSqlSourceJob;
use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;

/**
 * The ONE canonical "re-run indexing for this source" implementation,
 * shared by the Sources page Reindex button (SourceController::reindex)
 * and the pitchbar:retry-sources self-healing sweep. Every source type
 * dispatches its own pipeline; having two copies of this map is exactly
 * how the "No URL configured" class of bug happened twice.
 *
 * Return shape: ['ok' => bool, 'queued' => int, 'reason' => ?string]
 *   - ok=false           → nothing dispatched; `reason` says why
 *                          ('text_body_missing' | 'auto_empty' | 'no_documents').
 *   - ok=true, queued=0  → nothing to re-fetch but the source is healthy
 *                          ('file_text_missing': legacy upload, content kept).
 *   - ok=true, queued>0  → that many jobs queued.
 *
 * Queue-backend exceptions bubble to the caller — the controller flashes
 * "queue unreachable", the sweep logs and moves on.
 */
class SourceRetrier
{
    /**
     * Substrings that mark a source error as TRANSIENT — safe for the
     * scheduled sweep to retry without a human. Everything else
     * (robots.txt blocks, 404s, login walls, revoked Google tokens,
     * quota, re-upload cases) stays failed until a person acts, so the
     * sweep never burns crawl/API quota on hopeless work.
     */
    private const TRANSIENT_ERROR_NEEDLES = [
        'rate limit',
        'rate-limited',
        'too many requests',
        '429',
        'timed out',
        'timeout',
        'maximum execution time',
        'server error',
        'bad gateway',
        'service unavailable',
        'connection reset',
        'curl error 28',
        'queue unavailable',
        // CrawlPageJob's humanized MaxAttemptsExceeded — "target is slow,
        // blocked, or unreachable" is worth another look later.
        'could not reach this url after',
        // Legacy scar from the pre-fix reindex routing file/auto sources
        // through the URL crawler; retrying through the fixed paths heals.
        'no url configured',
    ];

    /**
     * Whether the sweep may auto-retry a failed source with this error.
     */
    public static function isTransient(?string $error): bool
    {
        if ($error === null || trim($error) === '') {
            return false;
        }

        $needle = mb_strtolower($error);
        // HTTP 5xx anywhere in the message (but never 4xx).
        if (preg_match('/\bhttp\s*5\d\d\b/', $needle) === 1) {
            return true;
        }

        foreach (self::TRANSIENT_ERROR_NEEDLES as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-run the type-appropriate indexing pipeline for a source.
     *
     * @return array{ok: bool, queued: int, reason: ?string}
     */
    public function retry(Source $source): array
    {
        return match ($source->type) {
            // Pasted text can't be re-FETCHED (no external origin), but the
            // body is persisted on the source, so it CAN be re-indexed from
            // there — which restores a source scarred failed by an earlier
            // bug and lets the self-healing sweep recover it. (Was a no-op
            // 'not_refetchable', which left the "Click Reindex to restore
            // it" scar message pointing at a dead end — client-reported
            // 2026-07-05.)
            'text' => $this->retryText($source),
            'file' => $this->retryUploadedFile($source),
            'auto' => $this->retryAutoSource($source),
            'google_doc' => $this->dispatchSingle($source, IngestGoogleDocJob::class),
            'google_sheet' => $this->dispatchSingle($source, SyncGoogleSheetJob::class),
            'notion' => $this->dispatchSingle($source, IngestNotionPageJob::class),
            'sql' => $this->dispatchSingle($source, SyncSqlSourceJob::class),
            default => $this->dispatchSingle($source, CrawlSourceJob::class),
        };
    }

    /**
     * @param  class-string  $job
     * @return array{ok: bool, queued: int, reason: ?string}
     */
    private function dispatchSingle(Source $source, string $job): array
    {
        $source->forceFill(['status' => 'pending', 'error' => null])->save();
        $job::dispatch($source->id)->onQueue('crawl');

        return ['ok' => true, 'queued' => 1, 'reason' => null];
    }

    /**
     * Re-index a pasted-text source from its persisted body. The text
     * lives on `config['body']` (SourceController::storeText saves it so
     * updateText can prefill it), so a scarred/failed text source is fully
     * recoverable without the operator retyping anything. Legacy text
     * sources created before the body was persisted have nothing to
     * re-index from — only those report `text_body_missing`.
     *
     * @return array{ok: bool, queued: int, reason: ?string}
     */
    private function retryText(Source $source): array
    {
        $config = (array) ($source->config ?? []);
        $body = (string) ($config['body'] ?? '');

        if (trim($body) === '') {
            return ['ok' => false, 'queued' => 0, 'reason' => 'text_body_missing'];
        }

        $title = (string) ($config['title'] ?? 'Pasted content');
        $sourceUrl = $config['source_url'] ?? null;

        $source->forceFill(['status' => 'pending', 'error' => null])->save();
        IndexTextSourceJob::dispatch(
            $source->id,
            $title,
            $body,
            is_string($sourceUrl) ? $sourceUrl : null,
        )->onQueue('index');

        return ['ok' => true, 'queued' => 1, 'reason' => null];
    }

    /**
     * Uploaded files re-chunk + re-embed from the persisted segment text
     * (UploadController stores one file per document for exactly this).
     * The source is stamped `indexed` upfront — IndexDocumentJob only
     * writes back on FAILURE, so an optimistic `crawling` would strand.
     * A source whose content exists is NEVER left failed by a retry.
     *
     * @return array{ok: bool, queued: int, reason: ?string}
     */
    private function retryUploadedFile(Source $source): array
    {
        $documents = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->get();

        if ($documents->isEmpty()) {
            $source->forceFill([
                'status' => 'failed',
                'error' => 'The uploaded file produced no indexed content. Re-upload the file to retry.',
            ])->save();

            return ['ok' => false, 'queued' => 0, 'reason' => 'no_documents'];
        }

        $disk = Storage::disk(UploadController::DISK);
        $queued = 0;
        foreach ($documents as $document) {
            $path = $document->text_path;
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }
            $text = $disk->get($path);
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            IndexDocumentJob::dispatch($document->id, $text)->onQueue('index');
            $queued++;
        }

        // Whatever happens next, this source's content EXISTS — clear any
        // stale failure instead of preserving it.
        $source->forceFill([
            'status' => 'indexed',
            'error' => null,
            'last_synced_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'queued' => $queued,
            'reason' => $queued === 0 ? 'file_text_missing' : null,
        ];
    }

    /**
     * The "Auto-indexed from visitors" bucket re-crawls each stored page
     * URL. CrawlPageJob::finalize converges the source back to `indexed`
     * (docs exist, so even a failed page never reverts the whole source).
     *
     * @return array{ok: bool, queued: int, reason: ?string}
     */
    private function retryAutoSource(Source $source): array
    {
        $cap = (int) config('services.crawl.max_pages_per_source', 25);
        $urls = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->whereNotNull('url')
            ->orderByDesc('fetched_at')
            ->pluck('url')
            ->unique()
            ->take($cap)
            ->values();

        if ($urls->isEmpty()) {
            return ['ok' => false, 'queued' => 0, 'reason' => 'auto_empty'];
        }

        $source->forceFill(['status' => 'crawling', 'error' => null])->save();

        foreach ($urls as $url) {
            CrawlPageJob::dispatch($source->id, (string) $url)->onQueue('crawl');
        }

        return ['ok' => true, 'queued' => $urls->count(), 'reason' => null];
    }
}
