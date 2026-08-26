<?php

namespace App\Listeners\Queue;

use App\Models\JobRun;
use App\Support\AgentIdExtractor;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Log;

/**
 * Single-class subscriber that logs every queued job's lifecycle into
 * the `job_runs` table. The platform-admin "Queue health" widget tails
 * this table so a buyer can see RUNNING / DONE / FAILED rows live.
 *
 * Events handled:
 *   - JobProcessing — insert a row with status=running, started_at=now.
 *   - JobProcessed  — update to status=done, finished_at, duration_ms.
 *   - JobFailed     — update to status=failed + first line of exception.
 *
 * Every event arrives with $event->job which exposes:
 *   - uuid()        — Laravel's per-job uuid (stable across retries).
 *   - resolveName() — fully-qualified job class.
 *   - getQueue()    — queue name (`default`, `crawl`, etc.).
 *   - attempts()    — retry count (1-indexed; first attempt = 1).
 *
 * If the insert fails (DB unreachable, schema migration not yet run)
 * we swallow the error — queue processing must never break because
 * observability is unavailable.
 */
class RecordJobRun
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class => 'onProcessing',
            JobProcessed::class => 'onProcessed',
            JobFailed::class => 'onFailed',
        ];
    }

    public function onProcessing(JobProcessing $event): void
    {
        try {
            $uuid = (string) $event->job->uuid();

            // updateOrCreate handles the retry case: a job that fails
            // and is released back to the queue will fire JobProcessing
            // again with the same uuid. We bump `attempt`, reset
            // `status` to running, and clear stale finished_at/duration.
            JobRun::query()->updateOrCreate(
                ['uuid' => $uuid],
                [
                    'job_class' => (string) $event->job->resolveName(),
                    'queue' => (string) $event->job->getQueue(),
                    'agent_id' => self::extractAgentId($event->job),
                    'status' => 'running',
                    'started_at' => now(),
                    'finished_at' => null,
                    'duration_ms' => null,
                    'attempt' => (int) $event->job->attempts(),
                ],
            );
        } catch (\Throwable $e) {
            Log::debug('job_run.record_processing_failed', ['error' => $e->getMessage()]);
        }
    }

    public function onProcessed(JobProcessed $event): void
    {
        try {
            $uuid = (string) $event->job->uuid();
            $row = JobRun::query()->where('uuid', $uuid)->first();

            if ($row === null) {
                // Processing event was missed (queue restarted mid-job,
                // for example). Stub a row so the widget still shows it.
                JobRun::query()->create([
                    'uuid' => $uuid,
                    'job_class' => (string) $event->job->resolveName(),
                    'queue' => (string) $event->job->getQueue(),
                    'agent_id' => self::extractAgentId($event->job),
                    'status' => 'done',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'duration_ms' => 0,
                    'attempt' => (int) $event->job->attempts(),
                ]);

                return;
            }

            $finishedAt = now();
            $duration = $row->started_at
                ? (int) max(0, $row->started_at->diffInMilliseconds($finishedAt))
                : null;

            $row->forceFill([
                'status' => 'done',
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
            ])->save();
        } catch (\Throwable $e) {
            Log::debug('job_run.record_processed_failed', ['error' => $e->getMessage()]);
        }
    }

    public function onFailed(JobFailed $event): void
    {
        try {
            $uuid = (string) $event->job->uuid();
            $row = JobRun::query()->where('uuid', $uuid)->first();

            $firstLine = self::firstExceptionLine((string) $event->exception);
            $finishedAt = now();

            if ($row === null) {
                JobRun::query()->create([
                    'uuid' => $uuid,
                    'job_class' => (string) $event->job->resolveName(),
                    'queue' => (string) $event->job->getQueue(),
                    'agent_id' => self::extractAgentId($event->job),
                    'status' => 'failed',
                    'started_at' => $finishedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => 0,
                    'exception_first_line' => $firstLine,
                    'attempt' => (int) $event->job->attempts(),
                ]);

                return;
            }

            $duration = $row->started_at
                ? (int) max(0, $row->started_at->diffInMilliseconds($finishedAt))
                : null;

            $row->forceFill([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
                'exception_first_line' => $firstLine,
            ])->save();
        } catch (\Throwable $e) {
            Log::debug('job_run.record_failed_failed', ['error' => $e->getMessage()]);
        }
    }

    private static function firstExceptionLine(string $exception): string
    {
        $line = strtok($exception, "\n") ?: '';

        return mb_substr(trim($line), 0, 240);
    }

    /**
     * Pulls the agent id out of the job's serialized payload. Best-effort:
     * returns null when the job isn't agent-scoped (cron heartbeat,
     * workspace-level bookkeeping) or when the id doesn't resolve to a
     * known model. Never throws — this runs on every queue event and
     * must not break job processing.
     */
    private static function extractAgentId(Job $job): ?string
    {
        try {
            $payload = $job->payload();
            $command = (string) ($payload['data']['command'] ?? '');

            return AgentIdExtractor::fromCommand($command);
        } catch (\Throwable) {
            return null;
        }
    }
}
