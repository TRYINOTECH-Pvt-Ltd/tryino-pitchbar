<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Parsers\ParserRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController
{
    /**
     * Disk used for the persisted segment text + original bytes. Lives
     * under storage/app/private — never web-exposed. Files survive
     * deploys (local disk is mounted on the host). The reindex path
     * reads back from this disk; without it, "Reindex" was a no-op
     * for uploads because the original bytes were thrown away after
     * parsing.
     */
    public const DISK = 'local';

    public const DIR = 'uploads';

    public function __construct(
        private ParserRegistry $parsers,
        private PlanLimits $limits,
    ) {}

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        // Each upload batch creates ONE Source row regardless of how many
        // files the admin dropped in, so we gate against the plan's
        // sources_limit before parsing anything.
        $workspace = Workspace::query()->find($agent->workspace_id);
        if ($workspace !== null) {
            $check = $this->limits->check($workspace, PlanLimits::RESOURCE_SOURCE);
            if (! $check['allowed']) {
                return back()->with('error', $this->limits->reasonFor(PlanLimits::RESOURCE_SOURCE, (int) $check['limit']));
            }
        }

        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => [
                'file',
                'max:51200', // 50 MB
                // MIME allowlist matches every extension wired into
                // ParserRegistry. Laravel runs `mimes:` through finfo
                // against the bytes, NOT the client-supplied
                // extension — so an attacker renaming
                // `evil.html → evil.pdf` is rejected here before
                // the parser ever sees it.
                'mimes:pdf,docx,doc,xlsx,xls,csv,md,markdown,txt,odt,ods',
            ],
        ]);

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'file',
            'status' => 'crawling',
            'config' => ['filenames' => collect($request->file('files'))->map(fn ($f) => $f->getClientOriginalName())->all()],
        ]);

        $createdDocs = 0;
        /** @var array<int, string> $unsupported  filenames with no parser */
        $unsupported = [];
        /** @var array<int, string> $parseErrors  filename: reason */
        $parseErrors = [];
        /** @var array<int, string> $empty  filename returned 0 text */
        $empty = [];
        /** @var bool $needsCloudflare true when at least one rejected file needs CF (xlsx/xls/ods/odt) */
        $needsCloudflare = false;

        foreach ($request->file('files') as $file) {
            $name = (string) $file->getClientOriginalName();
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $parser = $this->parsers->for($extension);

            if ($parser === null) {
                $unsupported[] = $name;
                // Spreadsheet / OpenDocument formats only get a parser
                // when Cloudflare Workers AI is configured (toMarkdown
                // endpoint). When the customer is on BYOK OpenAI or
                // missing CF creds entirely the file falls through here
                // and the generic "Unsupported file type" message is
                // misleading — they need the actionable hint about
                // configuring Cloudflare.
                if (in_array($extension, ['xlsx', 'xls', 'ods', 'odt'], true)) {
                    $needsCloudflare = true;
                }

                continue;
            }

            // Parser exceptions (corrupted PDF, scanned-only PDF with
            // no text layer, encrypted DOCX, malformed CSV) used to
            // bubble out as 500 errors and abort the entire batch.
            // Catch per-file so one bad upload doesn't sink the rest;
            // record the reason on the source so the admin can see
            // exactly which file broke.
            try {
                $bytes = (string) file_get_contents($file->getRealPath());
                $segments = $parser->parse($bytes, $name);
            } catch (\Throwable $e) {
                Log::warning('upload.parser_failed', [
                    'agent_id' => $agent->id,
                    'source_id' => $source->id,
                    'filename' => $name,
                    'extension' => $extension,
                    'error' => $e->getMessage(),
                ]);
                $parseErrors[] = $name.': '.Str::limit($e->getMessage(), 160);

                continue;
            }

            // Drop blank segments before creating Document rows. Some
            // PDF parsers return empty pages for the title page or a
            // page that only contains an image — those would produce
            // a Document with no extractable text and the indexing job
            // would silently early-return on chunks=[], leaving an
            // orphan document with zero chunks.
            $segments = array_values(array_filter(
                array_map(static fn ($s) => trim((string) $s), $segments),
                static fn (string $s) => $s !== '',
            ));

            if ($segments === []) {
                $empty[] = $name;

                continue;
            }

            foreach ($segments as $i => $text) {
                // Persist the segment text to private storage so a
                // later Reindex can re-dispatch IndexDocumentJob
                // without the customer re-uploading the file. The
                // path is `uploads/{source_id}/{document_id}.txt`.
                $textPath = $this->persistSegmentText($source->id, $text, $i);

                $document = Document::create([
                    'source_id' => $source->id,
                    'agent_id' => $agent->id,
                    'url' => null,
                    'title' => $name.($i > 0 ? " (segment {$i})" : ''),
                    'content_hash' => hash('sha256', $text),
                    'text_path' => $textPath,
                    'lang' => null,
                    'fetched_at' => now(),
                ]);
                $createdDocs++;
                IndexDocumentJob::dispatch($document->id, $text)->onQueue('index');
            }
        }

        $issues = [];
        if ($unsupported !== []) {
            $msg = 'Unsupported file type: '.implode(', ', $unsupported);
            if ($needsCloudflare) {
                $msg .= ' (Spreadsheet / OpenDocument formats need Cloudflare Workers AI — set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN.)';
            }
            $issues[] = $msg;
        }
        if ($empty !== []) {
            $issues[] = 'No extractable text: '.implode(', ', $empty)
                .' (likely a scanned PDF with no text layer — OCR not yet supported).';
        }
        foreach ($parseErrors as $err) {
            $issues[] = $err;
        }

        $source->forceFill([
            'status' => $createdDocs > 0 ? 'indexed' : 'failed',
            'last_synced_at' => now(),
            'error' => $issues === [] ? null : Str::limit(implode(' | ', $issues), 480),
        ])->save();

        // The "searchable in ~60s" note matters because Cloudflare Vectorize
        // has eventual consistency on metadata-filtered queries: even after
        // a successful upsert, agent_id-filtered searches return 0 hits
        // for ~30-60s while the metadata index propagates. Without the
        // copy the admin uploads a file, immediately tests the chatbot,
        // gets "I don't know", and assumes indexing is broken.
        $msg = "Uploaded — {$createdDocs} segments queued for indexing. The agent can answer questions about these files within ~60 seconds.";
        if ($issues !== []) {
            // Surface per-file warnings even on partial success so the
            // admin doesn't think a corrupted file silently uploaded.
            $msg .= ' Issues: '.implode(' | ', $issues);

            return back()->with($createdDocs > 0 ? 'warning' : 'error', $msg);
        }

        return back()->with('success', $msg);
    }

    /**
     * Write a segment's plain text to the private disk under a path
     * KnowledgeController::reindex can read back without re-parsing
     * the original file. Returns the storage-disk path or null on
     * write failure (caller falls back to a path-less Document — the
     * Reindex button will still be disabled for that row).
     */
    private function persistSegmentText(string $sourceId, string $text, int $segmentIdx): ?string
    {
        $disk = Storage::disk(self::DISK);
        $path = self::DIR.'/'.$sourceId.'/segment-'.$segmentIdx.'.txt';

        try {
            $disk->put($path, $text);
        } catch (\Throwable $e) {
            Log::warning('upload.persist_segment_failed', [
                'source_id' => $sourceId,
                'segment' => $segmentIdx,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $path;
    }
}
