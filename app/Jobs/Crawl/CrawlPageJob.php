<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\Source;
use App\Services\Crawl\Contracts\Crawler;
use App\Services\Crawl\ReadabilityExtractor;
use App\Services\Crawl\RobotsTxtParser;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CrawlPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 3 attempts (was 5) is enough for transient flaps — anything that
     * still fails after a 30s + 90s + 180s backoff is almost certainly
     * permanent (broken upstream, dead URL, persistent CF rate-limit).
     * Permanent failures now short-circuit via `$this->fail()` so we
     * never burn the full 3-try budget on a 404 / DNS error.
     */
    public int $tries = 3;

    /**
     * Per-job timeout independent of the worker's `--timeout` flag.
     * Cloudflare Browser Rendering routinely takes 30-60s per page when
     * the target site is slow to paint; capping at 90s gives one full
     * BR pass + the fallback to Browserless + plain HTTP. When the
     * worker's `--timeout` is lower than this, the worker's value wins.
     */
    public int $timeout = 90;

    /**
     * Run `failed()` when the job times out so the Source row flips to
     * `status='failed'` with a customer-readable error instead of being
     * stranded in `status='crawling'` for the rest of time. Without
     * this flag the worker SIGTERMs the job and never invokes the
     * failure callback.
     */
    public bool $failOnTimeout = true;

    /**
     * Backoff for sequential retries after a failure (mostly 429 from CF
     * Browser Rendering or a target site under temporary load).
     * Cloudflare's per-account concurrency for Browser Rendering is
     * small, so we wait longer between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 90, 180];
    }

    public function __construct(public string $sourceId, public string $url) {}

    public function handle(Crawler $crawler, ReadabilityExtractor $extractor, RobotsTxtParser $robots): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            \Illuminate\Support\Facades\Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }

        if (! $robots->isAllowed($this->url)) {
            $this->finalize($source, success: false, reason: "Blocked by robots.txt: {$this->url}");

            return;
        }

        try {
            $html = $crawler->content($this->url);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // 429 = rate-limited. Release back to queue with backoff
            // instead of burning a retry — every fan-out page on the
            // same workspace tends to hit the same 429 wave, so the
            // wait is shared productively.
            if ($this->looksRateLimited($msg)) {
                $this->release(60);

                return;
            }

            // Permanent failures: don't waste 2 more retry slots on a
            // dead URL or a malformed target. Call `fail()` directly so
            // the job's `failed()` callback runs immediately and the
            // Source row gets the real reason, not the generic
            // MaxAttemptsExceededException wrapper.
            if ($this->isPermanentFailure($msg)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $extracted = $extractor->extract($html);
        if (mb_strlen($extracted['text']) < 200) {
            // SPA, login wall, or bot-challenge page rendered to empty body.
            $this->finalize(
                $source,
                success: false,
                reason: "Page returned too little content (likely a JS-only or bot-protected site): {$this->url}"
            );

            return;
        }

        // Many sites return a 200-OK "page not found" page when a URL is
        // mistyped (Startech, Shopify, etc.). Without this check we'd index
        // the 404 boilerplate as if it were real content.
        $title = (string) ($extracted['title'] ?? '');
        $text = $extracted['text'];

        if ($this->looksLike404($title, $text)) {
            $this->finalize(
                $source,
                success: false,
                reason: "Page returned a 'not found' response (check the URL is correct): {$this->url}"
            );

            return;
        }

        $blockerReason = $this->detectBlocker($title, $text);
        if ($blockerReason !== null) {
            $this->finalize(
                $source,
                success: false,
                reason: "{$blockerReason}: {$this->url}"
            );

            return;
        }
        $hash = hash('sha256', $text);

        $existing = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            // This SOURCE already holds this exact content — a reindex of an
            // unchanged page, or a sitemap fan-out that re-discovered the
            // same URL. Nothing to do; treat as success.
            //
            // Dedup is scoped to the source (not the whole agent) on
            // purpose: Smart Segments lets an admin add an already-indexed
            // page as its OWN source to scope it to a segment. Segment
            // scoping runs through documents.source_id → sources.segment_id
            // (see Retriever), so each source must own its own Document —
            // an agent-wide dedup would leave the new source empty and its
            // content stranded in whatever segment first indexed it.
            $this->finalize($source, success: true, reason: null);

            return;
        }

        Document::create([
            'source_id' => $source->id,
            'agent_id' => $source->agent_id,
            'url' => $this->url,
            'title' => $extracted['title'],
            'content_hash' => $hash,
            'text_path' => null,
            'lang' => null,
            // Tag which crawler actually pulled the HTML — useful when a
            // page comes back blank and we need to know whether it was
            // CF Browser Rendering, Browserless, or the plain HTTP fallback.
            'crawler' => class_basename($crawler),
            'fetched_at' => now(),
        ]);

        $document = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->where('content_hash', $hash)
            ->firstOrFail();

        IndexDocumentJob::dispatch($document->id, $extracted['text'])->onQueue('index');

        $this->finalize($source, success: true, reason: null);
    }

    /**
     * Detect non-content pages (login walls, paywalls, JS-required shells,
     * cookie consent gates) that come through as 200-OK with a few hundred
     * chars of "please log in" copy. Returns a human-readable reason string
     * if a blocker is detected, or null if the page looks like real content.
     *
     * Each pattern is scoped to the title or first 600 chars of body — far
     * enough to catch pre-content gates, short enough that long articles
     * mentioning "login" in passing aren't false-positives.
     */
    private function detectBlocker(string $title, string $text): ?string
    {
        $head = mb_substr($text, 0, 600);
        $combined = $title.' '.$head;

        $checks = [
            'Page is behind a login wall' => '/(?:please (?:sign|log) in|sign in to (?:continue|view|read|access)|log in to (?:continue|view|read|access)|login required|you (?:must|need to) (?:be )?(?:logged|signed) in|members? only|access denied)/i',
            'Page is behind a paywall' => '/(?:subscribe to (?:read|continue|view|access)|premium (?:content|article|subscribers? only)|this (?:article|content) is for subscribers|become a (?:member|subscriber) to|paywall)/i',
            'Page requires JavaScript (the crawler couldn\'t render it)' => '/(?:javascript is (?:required|disabled|not enabled)|please enable javascript|this site requires javascript|enable javascript (?:in your browser )?to (?:continue|use|view)|you need to enable javascript)/i',
            'Page is a cookie-consent gate' => '/(?:we use cookies|this (?:site|website) uses cookies|cookie (?:notice|policy|consent)|accept cookies to continue)/i',
            // Pre-2026-06 this regex matched the standalone word
            // `cloudflare` AND `attention required` / `access denied` /
            // `just a moment` — all of which false-positive on legitimate
            // pages (any customer doc mentioning Cloudflare as their AI
            // backend tripped it; a customer how-it-works page had 17
            // legitimate "Cloudflare" mentions and was flagged as a
            // bot-challenge). Tightened to phrases that only appear on
            // the actual interstitial:
            //   - "Attention Required! | Cloudflare" is the exact title
            //     of the CF challenge page; the brand name alone is not
            //     enough.
            //   - `cf-please-wait` / `cf-error-details` are CSS classes
            //     CF only emits on the challenge interstitial.
            //   - `__cf_chl_jschl` is the JS-challenge URL parameter CF
            //     sets when sending the visitor through the JS gate.
            //   - The "Checking your browser..." / "Verifying you are
            //     human" / "Please complete the security check to access"
            //     phrases are the user-visible strings on the interstitial
            //     (kept the full clauses, not the fragments — fragments
            //     were the original false-positive source).
            'Page is a bot-challenge / verification gate' => '/(?:checking your browser before accessing|verifying you are human|please complete the security check to access|ddos protection by cloudflare|attention required!?\s*\|\s*cloudflare|cf-please-wait|cf-error-details|__cf_chl_jschl)/i',
        ];

        foreach ($checks as $reason => $pattern) {
            if (preg_match($pattern, $combined) === 1) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Detect 200-OK "page not found" pages that crawl looks like real HTML
     * but is actually a soft-404. Heuristic — only fires when the title or
     * first 500 chars of the body match common 404 phrasing.
     */
    private function looksLike404(string $title, string $text): bool
    {
        $needle = '/(?:^|\b)(?:404|page (?:not|cannot be) found|page (?:doesn\'?t|does not) exist|page unavailable|page no longer (?:exists|available)|requested page (?:was )?not found|the page you (?:requested|are looking for) (?:cannot be found|does(?:n\'?t| not) exist))/i';

        if ($title !== '' && preg_match($needle, $title) === 1) {
            return true;
        }

        if (preg_match($needle, mb_substr($text, 0, 500)) === 1) {
            return true;
        }

        return false;
    }

    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            return;
        }

        $this->finalize(
            $source,
            success: false,
            reason: 'Crawl failed: '.Str::limit($this->humanizeFailure($e), 480)
        );
    }

    /**
     * Whether an upstream error message indicates rate limiting. Covers
     * Cloudflare Browser Rendering (429), Browserless (429 + "rate
     * limit"), and any target site returning HTTP 429.
     */
    private function looksRateLimited(string $message): bool
    {
        $needle = strtolower($message);

        return str_contains($needle, '429')
            || str_contains($needle, 'rate-limited')
            || str_contains($needle, 'rate limit')
            || str_contains($needle, 'too many requests');
    }

    /**
     * Classify common cURL / HTTP errors that will fail every retry
     * the same way (dead host, malformed URL, hard 4xx, TLS broken).
     * Treating these as permanent saves 2 retry slots × backoff wait
     * per fan-out page on a sitemap full of dead URLs — the buyer's
     * report showed dozens of MaxAttemptsExceeded errors stacking up
     * for sources that were just unreachable.
     */
    private function isPermanentFailure(string $message): bool
    {
        $needle = strtolower($message);

        // cURL: 6=DNS, 7=connect refused, 35=TLS handshake, 60=cert,
        // 51=peer cert, 56=recv failure on dead host, 28=connect-time
        // timeout (separate from worker timeout — host never replied).
        foreach (['curl error 6', 'curl error 7', 'curl error 28', 'curl error 35', 'curl error 51', 'curl error 56', 'curl error 60'] as $curl) {
            if (str_contains($needle, $curl)) {
                return true;
            }
        }

        if (str_contains($needle, 'could not resolve host')
            || str_contains($needle, 'name or service not known')
            || str_contains($needle, 'no address associated')
            || str_contains($needle, 'connection refused')
            || str_contains($needle, 'malformed url')
            || str_contains($needle, 'unsupported scheme')
            || str_contains($needle, 'ssl certificate problem')
        ) {
            return true;
        }

        // Hard 4xx (but NOT 429 — that's rate-limited, transient).
        foreach (['400', '401', '403', '404', '410', '451'] as $code) {
            if (preg_match('/\bhttp\s*'.$code.'\b|\b'.$code.'\s+(?:bad request|unauthor|forbid|not found|gone|unavail)/i', $needle) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a low-level exception message into something the source
     * owner can read without `tail -f`. Generic worker errors
     * ("App\Jobs\Crawl\CrawlPageJob has been attempted too many
     * times") become "Could not reach this URL after N attempts."
     */
    private function humanizeFailure(\Throwable $e): string
    {
        $class = $e::class;

        if ($class === MaxAttemptsExceededException::class) {
            return "Could not reach this URL after {$this->tries} attempts. Most likely the target is slow, blocked, or unreachable from our crawler.";
        }

        // Worker-killed-on-timeout looks like an OS-level signal in
        // the exception class hierarchy; surface a friendlier message.
        if (str_contains($class, 'Timeout')
            || str_contains(strtolower($e->getMessage()), 'timed out')
            || str_contains(strtolower($e->getMessage()), 'maximum execution time')) {
            return "Crawl timed out after {$this->timeout}s — the target page took too long to respond.";
        }

        return $e->getMessage();
    }

    /**
     * Drive the source out of the 'crawling' state once a page completes.
     *
     * Convergence rules across concurrent page jobs (e.g. sitemap fan-out):
     *  - On failure for this URL, drop any prior doc for the same URL on
     *    this source (and its vectors) — yesterday's content can become a
     *    404 today, and we must not keep serving stale chunks.
     *  - If any document still exists for the source after that → 'indexed'
     *    wins (sticky), so a sibling sitemap page that succeeded earlier
     *    isn't reverted by a sibling that failed.
     *  - Else, record the most recent failure reason as 'failed'.
     */
    private function finalize(Source $source, bool $success, ?string $reason): void
    {
        if (! $success) {
            $stale = Document::query()->withoutWorkspaceScope()
                ->where('source_id', $source->id)
                ->where('url', $this->url)
                ->get();
            foreach ($stale as $doc) {
                $this->purgeVectorsForDocument($doc);
                $doc->delete(); // chunks cascade via FK
            }
        }

        $source = $source->fresh();
        if ($source === null || $source->status === 'indexed') {
            return;
        }

        $hasDocs = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->exists();

        if ($success || $hasDocs) {
            $source->forceFill([
                'status' => 'indexed',
                'error' => null,
                'last_synced_at' => now(),
            ])->save();

            return;
        }

        $source->forceFill([
            'status' => 'failed',
            'error' => $reason ?? 'Crawl produced no content.',
        ])->save();
    }

    private function purgeVectorsForDocument(Document $doc): void
    {
        try {
            $vector = app(QdrantClient::class);
            $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
            $vector->deleteByFilter($collection, ['document_id' => $doc->id]);
        } catch (\Throwable $e) {
            // Best-effort: don't fail the job (and burn retries) on vector cleanup.
            \Log::warning('purgeVectorsForDocument failed', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
