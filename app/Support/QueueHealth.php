<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\CronTickLog;
use App\Models\JobRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-source-of-truth queue + cron-tick aggregator. The same
 * payload feeds the platform-admin dashboard's "Queue health"
 * widget AND the deeper Cron Worker page in Settings → System,
 * so a buyer can see "is the Cloudflare Worker firing AND
 * actually processing jobs?" without hopping between screens.
 *
 * Cheap: two indexed counts (`jobs`, `failed_jobs`) and a handful
 * of aggregate queries over the `cron_tick_logs` table. Safe to
 * include in the admin shell payload and to poll every few seconds
 * from the dashboard.
 */
final class QueueHealth
{
    /**
     * @return array{
     *   last_tick_at: ?string,
     *   seconds_since_last_tick: ?int,
     *   liveness: 'live'|'stale'|'dead'|'unknown',
     *   ticks_last_hour: int,
     *   ticks_last_24h: int,
     *   jobs_processed_last_hour: int,
     *   jobs_processed_last_24h: int,
     *   pending_jobs: int,
     *   failed_jobs: int,
     *   recent_ticks: array<int, array{at: ?string, processed: int, failed: int, remaining: int, ms: int}>,
     *   recent_failures: array<int, array{uuid: string, job: string, queue: string, agent_id: ?string, agent_name: ?string, failed_at: ?string, exception_first_line: string}>,
     *   recent_runs: array<int, array{uuid: string, job: string, queue: string, agent_id: ?string, agent_name: ?string, status: string, started_at: ?string, finished_at: ?string, duration_ms: ?int, exception_first_line: ?string}>,
     * }
     */
    public static function summary(int $recentTicks = 20, int $recentFailures = 5, int $recentRuns = 25): array
    {
        $latest = CronTickLog::query()->orderByDesc('id')->first();
        $now = now();

        $secondsSince = $latest ? (int) $latest->received_at->diffInSeconds($now) : null;
        // > 90s = stale (cron is supposed to fire every 60s); > 5min = dead.
        $liveness = match (true) {
            $latest === null => 'unknown',
            $secondsSince !== null && $secondsSince <= 90 => 'live',
            $secondsSince !== null && $secondsSince <= 300 => 'stale',
            default => 'dead',
        };

        $hourAgo = $now->copy()->subHour();
        $dayAgo = $now->copy()->subDay();

        $ticksLastHour = (int) CronTickLog::query()->where('received_at', '>=', $hourAgo)->count();
        $ticksLast24h = (int) CronTickLog::query()->where('received_at', '>=', $dayAgo)->count();
        $jobsLastHour = (int) CronTickLog::query()->where('received_at', '>=', $hourAgo)->sum('processed');
        $jobsLast24h = (int) CronTickLog::query()->where('received_at', '>=', $dayAgo)->sum('processed');

        $pendingJobs = (int) DB::table('jobs')->count();
        $failedJobs = (int) DB::table('failed_jobs')->count();

        $recent = CronTickLog::query()
            ->orderByDesc('id')
            ->limit(max(1, $recentTicks))
            ->get(['received_at', 'processed', 'failed_in_tick', 'remaining_pending', 'elapsed_ms'])
            ->map(fn ($r) => [
                'at' => $r->received_at?->toIso8601String(),
                'processed' => (int) $r->processed,
                'failed' => (int) $r->failed_in_tick,
                'remaining' => (int) $r->remaining_pending,
                'ms' => (int) $r->elapsed_ms,
            ])
            ->values()
            ->all();

        // Most-recent failed-job rows with the first line of their
        // exception. The dashboard widget renders these inline so the
        // platform-admin can paste the line straight to the dev team
        // without hopping into the detailed failed-queue page. The
        // first line is enough to triage 90% of failures (it carries
        // the exception class + message); for the full stack trace the
        // widget links into /admin/jobs/failed where the existing
        // expand-on-click UI fetches the full payload.
        // Left-join to job_runs by uuid so we can surface the agent
        // attribution our listener stamped at JobProcessing time. The
        // failed_jobs row is Laravel-core and can't carry the agent_id
        // directly, but the same uuid links to our job_runs entry.
        $recentFails = DB::table('failed_jobs')
            ->leftJoin('job_runs', 'job_runs.uuid', '=', 'failed_jobs.uuid')
            ->orderByDesc('failed_jobs.failed_at')
            ->limit(max(1, $recentFailures))
            ->get([
                'failed_jobs.uuid as uuid',
                'failed_jobs.queue as queue',
                'failed_jobs.payload as payload',
                'failed_jobs.exception as exception',
                'failed_jobs.failed_at as failed_at',
                'job_runs.agent_id as agent_id',
            ])
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'uuid' => (string) $row->uuid,
                    'job' => is_array($payload) ? (string) ($payload['displayName'] ?? '?') : '?',
                    'queue' => (string) $row->queue,
                    'agent_id' => isset($row->agent_id) && $row->agent_id !== '' ? (string) $row->agent_id : null,
                    'agent_name' => null,
                    'failed_at' => $row->failed_at ? (string) $row->failed_at : null,
                    'exception_first_line' => self::firstExceptionLine((string) $row->exception),
                ];
            })
            ->values()
            ->all();

        // Per-job lifecycle log — RUNNING / DONE / FAILED rows the
        // dashboard widget tails like a terminal. Empty array when the
        // job_runs migration hasn't been applied yet (fresh install,
        // pre-migrate state) so the widget keeps rendering rather
        // than 500ing.
        $recentRunRows = [];
        if (Schema::hasTable('job_runs')) {
            $recentRunRows = JobRun::query()
                ->orderByDesc('started_at')
                ->limit(max(1, $recentRuns))
                ->get([
                    'uuid',
                    'job_class',
                    'queue',
                    'agent_id',
                    'status',
                    'started_at',
                    'finished_at',
                    'duration_ms',
                    'exception_first_line',
                ])
                ->map(fn ($r) => [
                    'uuid' => (string) $r->uuid,
                    'job' => self::shortJobName((string) $r->job_class),
                    'queue' => (string) $r->queue,
                    'agent_id' => is_string($r->agent_id) && $r->agent_id !== '' ? $r->agent_id : null,
                    'agent_name' => null,
                    'status' => (string) $r->status,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'finished_at' => $r->finished_at?->toIso8601String(),
                    'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                    'exception_first_line' => $r->exception_first_line,
                ])
                ->values()
                ->all();
        }

        // Single bulk Agent::name lookup. Both recent_failures and
        // recent_runs share the same id pool — one IN query covers both.
        $agentIds = array_values(array_unique(array_filter(array_merge(
            array_column($recentFails, 'agent_id'),
            array_column($recentRunRows, 'agent_id'),
        ))));

        if ($agentIds !== []) {
            $names = Agent::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $agentIds)
                ->pluck('name', 'id')
                ->all();

            $hydrate = static function (array &$row) use ($names): void {
                $aid = $row['agent_id'] ?? null;
                if ($aid !== null && isset($names[$aid])) {
                    $row['agent_name'] = (string) $names[$aid];
                }
            };

            foreach ($recentFails as &$row) {
                $hydrate($row);
            }
            unset($row);
            foreach ($recentRunRows as &$row) {
                $hydrate($row);
            }
            unset($row);
        }

        return [
            'last_tick_at' => $latest?->received_at?->toIso8601String(),
            'seconds_since_last_tick' => $secondsSince,
            'liveness' => $liveness,
            'ticks_last_hour' => $ticksLastHour,
            'ticks_last_24h' => $ticksLast24h,
            'jobs_processed_last_hour' => $jobsLastHour,
            'jobs_processed_last_24h' => $jobsLast24h,
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'recent_ticks' => $recent,
            'recent_failures' => $recentFails,
            'recent_runs' => $recentRunRows,
        ];
    }

    /**
     * Trim `App\Jobs\Crawl\CrawlPageJob` → `Crawl\CrawlPageJob` so the
     * widget table doesn't waste horizontal space on the namespace
     * prefix. Returns the original string when it doesn't follow the
     * App\Jobs\ convention.
     */
    private static function shortJobName(string $fqn): string
    {
        $prefix = 'App\\Jobs\\';

        if (str_starts_with($fqn, $prefix)) {
            return substr($fqn, strlen($prefix));
        }

        return $fqn;
    }

    private static function firstExceptionLine(string $exception): string
    {
        $line = strtok($exception, "\n") ?: '';

        return mb_substr(trim($line), 0, 240);
    }
}
