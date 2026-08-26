<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Crawl\SourceRetrier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing sweep for the indexing pipeline: automatically retries
 * sources that failed for TRANSIENT reasons (rate limits, timeouts,
 * upstream 5xx, an unreachable queue) and rescues sources stranded
 * mid-flight in `crawling`/`pending` after a dead worker — so the owner
 * never has to click Reindex "again and again" to nurse a source back
 * (client ask, 2026-07-04).
 *
 * Deliberately conservative:
 *  - Permanent errors (robots.txt blocks, 404s, login walls, revoked
 *    Google connections, quota, re-upload cases) are NEVER auto-retried;
 *    they wait for a human, so the sweep can't burn crawl/API quota on
 *    hopeless work.
 *  - Max 3 automatic retries per failure episode, tracked in the
 *    source's config (`auto_retry: {count, at}`). The counter decays
 *    after 24 quiet hours so a NEW failure next week gets fresh retries.
 *  - A failed source must be ≥10 minutes untouched before the sweep
 *    picks it up — never races a human actively working the page.
 *
 * Scheduled every 15 minutes via routes/console.php.
 */
class RetrySourcesCommand extends Command
{
    /** Automatic retries per failure episode before a human must act. */
    private const MAX_AUTO_RETRIES = 3;

    /** Quiet hours after which the retry counter resets. */
    private const COUNTER_DECAY_HOURS = 24;

    /** A failed source must be this many minutes untouched first. */
    private const FAILED_COOLDOWN_MINUTES = 10;

    /** crawling/pending older than this = the job died mid-flight. */
    private const STRANDED_AFTER_MINUTES = 45;

    protected $signature = 'pitchbar:retry-sources
        {--limit=100 : Maximum sources to examine per run}
        {--dry-run : Print what would be retried, don\'t dispatch}';

    protected $description = 'Auto-retry transiently-failed sources and rescue ones stranded mid-crawl';

    public function handle(SourceRetrier $retrier): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Scope bypass justified: system maintenance sweep across every
        // workspace, same as the sibling pitchbar:refresh-stale-sources.
        $candidates = Source::query()->withoutGlobalScopes()
            ->where(function ($q) {
                $q->where(function ($failed) {
                    $failed->where('status', 'failed')
                        ->where('updated_at', '<', now()->subMinutes(self::FAILED_COOLDOWN_MINUTES));
                })->orWhere(function ($stranded) {
                    $stranded->whereIn('status', ['crawling', 'pending'])
                        ->where('updated_at', '<', now()->subMinutes(self::STRANDED_AFTER_MINUTES));
                });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $retried = 0;
        $skipped = 0;

        foreach ($candidates as $source) {
            // Failed sources: only transient errors qualify. Stranded
            // crawling/pending rows qualify unconditionally — the job
            // died without writing a verdict, so the error column says
            // nothing about whether a retry can work.
            if ($source->status === 'failed' && ! SourceRetrier::isTransient($source->error)) {
                $skipped++;

                continue;
            }

            $attempt = $this->attemptNumber($source);
            if ($attempt > self::MAX_AUTO_RETRIES) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("would retry [{$source->type}] {$source->id} (attempt {$attempt}): ".(string) $source->error);
                $retried++;

                continue;
            }

            // Stamp the attempt BEFORE dispatching so a crash mid-loop
            // can't produce uncounted retries.
            $config = (array) ($source->config ?? []);
            $config['auto_retry'] = ['count' => $attempt, 'at' => now()->toIso8601String()];
            $source->forceFill(['config' => $config])->save();

            try {
                $result = $retrier->retry($source);
            } catch (\Throwable $e) {
                // Queue backend unreachable — leave the source as-is;
                // the next sweep run will try again (and count it).
                Log::warning('sources.retry_sweep.dispatch_failed', [
                    'source_id' => $source->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            Log::info('sources.retry_sweep.retried', [
                'source_id' => $source->id,
                'type' => $source->type,
                'attempt' => $attempt,
                'queued' => $result['queued'],
                'reason' => $result['reason'],
            ]);
            $retried++;
        }

        $this->info("Retried {$retried} source(s), skipped {$skipped} (permanent error or retry cap).");

        return self::SUCCESS;
    }

    /**
     * 1-based number of the auto-retry we are about to perform, honouring
     * the 24h decay: a counter last bumped more than a day ago belongs to
     * an old failure episode and restarts at 1.
     */
    private function attemptNumber(Source $source): int
    {
        $state = (array) (($source->config ?? [])['auto_retry'] ?? []);
        $count = (int) ($state['count'] ?? 0);
        $at = $state['at'] ?? null;

        if ($count === 0 || ! is_string($at)) {
            return 1;
        }

        $last = Carbon::parse($at);
        if ($last->lt(now()->subHours(self::COUNTER_DECAY_HOURS))) {
            return 1;
        }

        return $count + 1;
    }
}
