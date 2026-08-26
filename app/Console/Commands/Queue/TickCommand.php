<?php

namespace App\Console\Commands\Queue;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Process a small batch of jobs and exit. Built for cPanel-style
 * shared hosting where long-running `queue:work` daemons get killed
 * by hosting limits and crons are unreliable.
 *
 * The intended caller is an EXTERNAL cron — Cloudflare Workers
 * Cron Triggers (free tier) hit `POST /api/v1/internal/queue-tick`,
 * which calls this command. See infra/cloudflare-queue-worker.js.
 *
 * Hard-stops after `--max-time` seconds OR when the queue is empty,
 * whichever comes first. Returns counts so the cron caller can log
 * throughput.
 */
class TickCommand extends Command
{
    // `index` is the third queue actively used by the app (file uploads,
    // crawled pages, Notion/Google ingest all dispatch IndexDocumentJob
    // onto it). Pre-fix the default was `crawl,default` which silently
    // stranded every PDF / TXT / page index — the buyer's "129 jobs
    // pending, no failures" symptom. Keep the order so `crawl` work
    // (cheap fetch) runs ahead of `index` work (LLM embed + Vectorize
    // upsert) when both are backed up.
    //
    // Timeout bump (`--max-time` 25 → 55): CrawlPageJob's `$timeout` is
    // 90s and CF Browser Rendering routinely takes 30-60s per page.
    // The old 25s tick cap meant the per-job `--timeout` had to be
    // ≤23s, which SIGTERMed real crawls mid-flight → 5 retries → the
    // MaxAttemptsExceededException flood we saw in production. The
    // outer cron cadence (every 60s) still has 5s of slack.
    protected $signature = 'pitchbar:queue-tick
                            {--queues=crawl,index,default : Comma-separated queue names to process}
                            {--max-jobs=20 : Hard cap on jobs processed in this tick}
                            {--max-time=55 : Hard cap on wall-clock seconds}
                            {--job-timeout=120 : Per-job timeout passed to queue:work (capped to max-time - 5)}';

    protected $description = 'Process a bounded batch of queued jobs and exit. Designed for external-cron-driven processing on shared hosting.';

    public function handle(): int
    {
        $queues = (string) $this->option('queues');
        $maxJobs = max(1, (int) $this->option('max-jobs'));
        $maxTime = max(1, (int) $this->option('max-time'));
        $requestedJobTimeout = max(5, (int) $this->option('job-timeout'));
        // Worker `--timeout` must fit inside the tick budget with a
        // safety margin so the next loop iteration has time to start.
        $jobTimeout = min($requestedJobTimeout, max(5, $maxTime - 5));

        $startedAt = microtime(true);
        $deadline = $startedAt + $maxTime;
        $processed = 0;
        $failedInTick = 0;

        while ($processed < $maxJobs && microtime(true) < $deadline) {
            // queue:work --once --stop-when-empty processes a single
            // job and exits. Each call is independent — failed jobs go
            // to failed_jobs, successful jobs disappear from `jobs`.
            $code = $this->call('queue:work', [
                '--queue' => $queues,
                '--once' => true,
                '--stop-when-empty' => true,
                '--tries' => 2,
                '--timeout' => $jobTimeout,
                '--memory' => 256,
            ]);

            if ($code !== 0) {
                $failedInTick++;
            }
            $processed++;

            // Once the queue is drained, stop early. Saves the cron
            // caller from waiting the full max-time for nothing.
            if ($this->countPending($queues) === 0) {
                break;
            }
        }

        $elapsed = round(microtime(true) - $startedAt, 3);
        $remaining = $this->countPending($queues);
        $failedTotal = (int) DB::table('failed_jobs')->count();

        $this->line(json_encode([
            'processed' => $processed,
            'failed_in_tick' => $failedInTick,
            'remaining_pending' => $remaining,
            'failed_total' => $failedTotal,
            'elapsed_s' => $elapsed,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function countPending(string $queues): int
    {
        $names = array_filter(array_map('trim', explode(',', $queues)));
        if ($names === []) {
            return 0;
        }

        return (int) DB::table('jobs')
            ->whereIn('queue', $names)
            ->count();
    }
}
