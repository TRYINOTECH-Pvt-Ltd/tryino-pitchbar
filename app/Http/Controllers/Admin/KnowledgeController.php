<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\UploadController as UploadCtl;
use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing "what does the AI actually know?" view. Shows every
 * document the agent has indexed, grouped by source, with a sample of
 * the extracted text — so the owner can confirm the crawl pulled real
 * content (not a login wall, not a JS-only shell, etc.) before going
 * live.
 *
 * Distinct from the Sources page: that one is for managing inputs
 * (add/remove/reindex). Knowledge is for inspecting outputs.
 */
class KnowledgeController
{
    public function index(Request $request, Agent $agent): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $q = trim((string) $request->query('q', ''));

        // Aggregate stats across the whole agent's index.
        $totalDocs = (int) Document::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->count();

        $totalChunks = (int) DB::table('chunks')
            ->where('agent_id', $agent->id)
            ->count();

        $totalChars = (int) DB::table('chunks')
            ->where('agent_id', $agent->id)
            ->selectRaw('COALESCE(SUM(LENGTH(text)), 0) as t')
            ->value('t');

        // The list itself — most recently fetched first, optionally filtered
        // by URL or title substring. Capped at 50 per page; chunks per
        // document are loaded separately so the JSON stays small.
        $docsQuery = Document::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->with('source:id,type,config,status,error')
            ->orderByDesc('fetched_at');

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $docsQuery->where(fn ($qq) => $qq
                ->where('url', 'like', $like)
                ->orWhere('title', 'like', $like));
        }

        $documents = $docsQuery
            ->paginate(50)
            ->through(function (Document $d) {
                $chunkSample = DB::table('chunks')
                    ->where('document_id', $d->id)
                    ->orderBy('ord')
                    ->limit(20)
                    ->get(['id', 'ord', 'token_count', 'text']);

                $totalChunks = (int) DB::table('chunks')
                    ->where('document_id', $d->id)
                    ->count();

                $totalChars = $chunkSample
                    ->sum(fn ($c) => mb_strlen((string) $c->text));

                return [
                    'id' => $d->id,
                    'url' => $d->url,
                    'title' => $d->title,
                    'fetched_at' => $d->fetched_at?->toIso8601String(),
                    'source_type' => $d->source?->type,
                    'source_label' => $this->sourceLabel($d),
                    'crawler' => $this->crawlerLabel($d->crawler),
                    'chunks_count' => $totalChunks,
                    'preview' => mb_substr((string) ($chunkSample->first()->text ?? ''), 0, 600),
                    'chunks' => $chunkSample->map(fn ($c) => [
                        'id' => $c->id,
                        'ord' => (int) $c->ord,
                        'tokens' => (int) $c->token_count,
                        'text' => mb_substr((string) $c->text, 0, 4000),
                        'chars' => mb_strlen((string) $c->text),
                    ]),
                    'sample_chars' => $totalChars,
                    // Surface the real source-level error so the UI
                    // can replace the generic "transient embedding-API
                    // hiccup" copy with what actually happened (e.g.
                    // "No extractable text — likely a scanned PDF").
                    'source_status' => $d->source?->status,
                    'source_error' => $d->source?->error,
                    // Reindex requires either a URL (web crawl) or
                    // persisted segment text (uploaded file). Flag the
                    // latter so the UI can render the right CTA.
                    'reindexable' => $d->url !== null
                        || ($d->source?->type === 'file' && is_string($d->text_path) && $d->text_path !== ''),
                ];
            });

        return Inertia::render('app/agents/knowledge', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'totals' => [
                'documents' => $totalDocs,
                'chunks' => $totalChunks,
                'characters' => $totalChars,
            ],
            'documents' => $documents,
            'q' => $q,
        ]);
    }

    /**
     * Friendly label for where this document came from. Mirrors the
     * Sources page's display logic — if it's an auto-indexed visitor
     * page, say so explicitly so the owner doesn't think they added it.
     */
    /**
     * Re-run the crawl + index pipeline for a single document. Used
     * when a document was crawled (Document row exists) but indexing
     * failed and produced 0 chunks — the visible "0 chunks" state on
     * the Knowledge page invites the owner to retry without rerunning
     * every URL on the source.
     */
    public function reindex(Request $request, Document $document): RedirectResponse
    {
        $request->user()->can('view', $document->agent) || abort(403);

        if ($document->source_id === null) {
            return back()->with('error', 'This document has no source — cannot reindex.');
        }

        // Uploaded files (type=file) have no URL but their parsed text
        // is stored on the local disk by UploadController. Re-dispatch
        // IndexDocumentJob with that text instead of trying to crawl
        // a URL that doesn't exist. Pre-fix the reindex button was a
        // silent no-op on PDFs because the controller short-circuited
        // on document.url === null.
        $sourceType = $document->source?->type;
        if ($document->url === null && $sourceType === 'file') {
            $text = $this->readPersistedSegmentText($document);
            if ($text === null || trim($text) === '') {
                return back()->with(
                    'error',
                    'Could not find the original file on disk. Re-upload the file to retry.',
                );
            }

            // Clear the prior source error so the UI doesn't keep
            // showing the old "no chunks created" message after a
            // successful reindex.
            $document->source?->forceFill([
                'status' => 'crawling',
                'error' => null,
            ])->save();

            IndexDocumentJob::dispatch($document->id, $text)->onQueue('index');

            return back()->with('success', 'Reindex queued — refresh in a moment.');
        }

        if ($document->url === null) {
            return back()->with('error', 'This document has no source URL to re-crawl.');
        }

        CrawlPageJob::dispatch($document->source_id, $document->url)->onQueue('crawl');

        return back()->with('success', 'Reindex queued — refresh in a moment.');
    }

    /**
     * Read back the persisted segment text for an uploaded-file
     * document. UploadController writes one text file per segment
     * under `uploads/{source_id}/segment-{N}.txt`; the path is
     * recorded on `document.text_path`. Returns null when the file
     * is missing (old uploads from before the persistence change, or
     * a disk wipe).
     */
    private function readPersistedSegmentText(Document $document): ?string
    {
        $path = $document->text_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        $disk = Storage::disk(UploadCtl::DISK);
        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Friendly label for which crawler engine pulled this HTML. Returns
     * null for non-HTML sources (text paste, Notion, Google Doc, uploads),
     * which never went through a crawler.
     */
    private function crawlerLabel(?string $engine): ?string
    {
        if ($engine === null || $engine === '') {
            return null;
        }

        return match ($engine) {
            'CloudflareBrowserClient' => 'Cloudflare Browser',
            'BrowserlessClient' => 'Browserless',
            'PlainHttpCrawler' => 'Plain HTTP',
            default => $engine,
        };
    }

    private function sourceLabel(Document $doc): string
    {
        $type = $doc->source?->type;

        return match ($type) {
            'url' => 'URL',
            'sitemap' => 'Sitemap',
            'feed' => 'Feed',
            'notion' => 'Notion',
            'google_doc' => 'Google Doc',
            'text' => 'Pasted text',
            'auto' => 'Auto-indexed (visitor visit)',
            'upload' => 'Uploaded file',
            default => $type ?: 'Source',
        };
    }
}
