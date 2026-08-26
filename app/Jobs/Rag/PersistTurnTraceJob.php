<?php

namespace App\Jobs\Rag;

use App\Models\TurnTrace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Persists the behind-the-scenes trace of a single widget turn for the
 * super-admin conversation debugger. Dispatched AFTER emit('done') /
 * emit('error') — the visitor's stream never waits on this write.
 *
 * Self-healing: when the DB write fails (table not migrated yet, DB
 * hiccup), the trace is stashed in a capped cache buffer instead of
 * being lost; the next successful write drains the buffer back into
 * the table with the original timestamps. Proven failure mode: a
 * deploy that pulls code but runs `php artisan migrate` later.
 */
class PersistTurnTraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FALLBACK_KEY = 'turn_traces:fallback';

    private const FALLBACK_MAX = 200;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $agentId,
        public string $conversationId,
        public ?string $messageId,
        public string $kind,
        public array $payload,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $row = [
            'agent_id' => $this->agentId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'kind' => $this->kind,
            'payload' => $this->payload,
            'created_at' => now(),
        ];

        try {
            TurnTrace::create($row);
        } catch (\Throwable $e) {
            $this->stashInFallback($row, $e);

            return;
        }

        $this->drainFallback();
    }

    /**
     * Keep the trace in cache when the DB write fails so it can be
     * recovered later. Capped + TTL'd so a long outage can't grow the
     * buffer without bound; the newest traces win when the cap bites.
     *
     * @param  array<string, mixed>  $row
     */
    private function stashInFallback(array $row, \Throwable $e): void
    {
        Log::warning('turn_trace.persist_failed', [
            'conversation_id' => $this->conversationId,
            'error' => $e->getMessage(),
        ]);

        try {
            // Serialize the timestamp so the row survives any cache driver.
            $row['created_at'] = $row['created_at']->toIso8601String();

            $backlog = Cache::get(self::FALLBACK_KEY, []);
            $backlog = is_array($backlog) ? $backlog : [];
            $backlog[] = $row;
            $backlog = array_slice($backlog, -self::FALLBACK_MAX);

            Cache::put(self::FALLBACK_KEY, $backlog, now()->addDays(7));
        } catch (\Throwable) {
            // Cache also down — the log line above is the last resort.
        }
    }

    /**
     * Flush previously-stashed traces into the table. Runs after every
     * successful write, under a lock so concurrent jobs don't double-
     * insert the same backlog. Rows that still fail are re-stashed.
     */
    private function drainFallback(): void
    {
        try {
            $lock = Cache::lock('turn_traces:fallback:drain', 10);
            if (! $lock->get()) {
                return;
            }

            try {
                $backlog = Cache::pull(self::FALLBACK_KEY);
                if (! is_array($backlog) || $backlog === []) {
                    return;
                }

                $failed = [];
                foreach ($backlog as $row) {
                    try {
                        if (is_string($row['created_at'] ?? null)) {
                            $row['created_at'] = Date::parse($row['created_at']);
                        }
                        TurnTrace::create($row);
                    } catch (\Throwable) {
                        $failed[] = $row;
                    }
                }

                if ($failed !== []) {
                    Cache::put(self::FALLBACK_KEY, array_slice($failed, -self::FALLBACK_MAX), now()->addDays(7));
                }
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('turn_trace.drain_failed', ['error' => $e->getMessage()]);
        }
    }
}
