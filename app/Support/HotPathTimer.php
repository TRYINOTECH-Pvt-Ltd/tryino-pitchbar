<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records per-stage timings on the RAG hot path and emits one structured
 * log line per turn. Cheap (just microtime + array writes), safe to enable
 * in production.
 *
 * Usage:
 *   $t = new HotPathTimer($conversationId, $agentId);
 *   $t->mark('embed.start');
 *   ... embed query ...
 *   $t->mark('embed.end');
 *   $t->mark('search.start'); ... $t->mark('search.end');
 *   $t->emit(['low_confidence' => true]);
 *
 * Output (single Log::info call, channel "hotpath"):
 *   {
 *     "event": "rag.turn",
 *     "conversation_id": "...",
 *     "agent_id": "...",
 *     "stages": { "embed_ms": 41, "search_ms": 87, "rerank_ms": 120,
 *                 "llm_first_token_ms": 480, "total_ms": 2540 },
 *     "extra": {...}
 *   }
 *
 * Stages are derived from {name}.start / {name}.end mark pairs. Marks
 * without a matching counterpart are silently ignored.
 */
class HotPathTimer
{
    /**
     * Cache key holding the most recent turn timings as a ring buffer.
     * Read by the super-admin "Hot path latency" dashboard so operators
     * can see which stage is slow without grepping laravel.log.
     */
    public const RECENT_CACHE_KEY = 'hotpath:recent';

    public const RECENT_MAX = 100;

    private const RECENT_TTL_DAYS = 7;

    /** @var array<string, float> microtime_float for each mark */
    private array $marks = [];

    private float $startedAt;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $agentId,
    ) {
        $this->startedAt = microtime(true);
    }

    public function mark(string $name): void
    {
        $this->marks[$name] = microtime(true);
    }

    /**
     * Compute durations and write the log line. Pass any extra context as
     * the $extra array (e.g. source counts, low_confidence flag).
     *
     * @param  array<string, mixed>  $extra
     */
    public function emit(array $extra = []): void
    {
        $stages = [];
        foreach ($this->marks as $name => $at) {
            if (! str_ends_with($name, '.start')) {
                continue;
            }
            $base = substr($name, 0, -6);
            $endKey = $base.'.end';
            if (isset($this->marks[$endKey])) {
                $stages[$base.'_ms'] = (int) round(($this->marks[$endKey] - $at) * 1000);
            }
        }
        $stages['total_ms'] = (int) round((microtime(true) - $this->startedAt) * 1000);

        Log::channel(config('logging.hotpath_channel', 'stack'))->info('rag.turn', [
            'conversation_id' => $this->conversationId,
            'agent_id' => $this->agentId,
            'stages' => $stages,
            'extra' => $extra,
        ]);

        $this->pushRecent($stages, $extra);
    }

    /**
     * Append this turn to the dashboard ring buffer. Runs in the
     * post-`done` side-effect zone (emit() is step 9 of the stream
     * handler), so the visitor's time-to-first-token is unaffected.
     * Failures are swallowed — observability must never break a turn.
     *
     * @param  array<string, int>  $stages
     * @param  array<string, mixed>  $extra
     */
    private function pushRecent(array $stages, array $extra): void
    {
        try {
            $recent = Cache::get(self::RECENT_CACHE_KEY, []);
            if (! is_array($recent)) {
                $recent = [];
            }

            array_unshift($recent, [
                'at' => now()->toIso8601String(),
                'conversation_id' => $this->conversationId,
                'agent_id' => $this->agentId,
                'stages' => $stages,
                'extra' => $extra,
            ]);

            Cache::put(
                self::RECENT_CACHE_KEY,
                array_slice($recent, 0, self::RECENT_MAX),
                now()->addDays(self::RECENT_TTL_DAYS),
            );
        } catch (Throwable) {
            // Cache outage must not surface to the visitor.
        }
    }

    /**
     * @return array<string, int>
     */
    public function snapshotStages(): array
    {
        $stages = [];
        foreach ($this->marks as $name => $at) {
            if (! str_ends_with($name, '.start')) {
                continue;
            }
            $base = substr($name, 0, -6);
            $endKey = $base.'.end';
            if (isset($this->marks[$endKey])) {
                $stages[$base.'_ms'] = (int) round(($this->marks[$endKey] - $at) * 1000);
            }
        }
        $stages['total_ms'] = (int) round((microtime(true) - $this->startedAt) * 1000);

        return $stages;
    }
}
