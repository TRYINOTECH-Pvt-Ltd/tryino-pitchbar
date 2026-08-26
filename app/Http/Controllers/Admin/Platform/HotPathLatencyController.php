<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Support\HotPathStats;
use App\Support\HotPathTimer;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin "where does the time go?" dashboard. Reads the ring
 * buffer HotPathTimer maintains (last 100 turns, cache-backed) and
 * renders per-stage timings + p50/p95 aggregates + a plain-English
 * verdict so a non-technical operator can answer "why is the bot
 * slow?" by looking at one table instead of grepping laravel.log.
 *
 * No DB schema, no hot-path cost: the buffer is written in the
 * post-`done` side-effect zone of the stream handler.
 */
class HotPathLatencyController
{
    public function __construct(private readonly HotPathStats $stats) {}

    public function index(): Response
    {
        $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);
        if (! is_array($recent)) {
            $recent = [];
        }

        $turns = array_values(array_map(fn (array $turn) => [
            'at' => $turn['at'] ?? null,
            'conversation_id' => $turn['conversation_id'] ?? null,
            'agent_id' => $turn['agent_id'] ?? null,
            'retrieve_ms' => (int) ($turn['stages']['retrieve_ms'] ?? 0),
            'tool_loop_ms' => (int) ($turn['stages']['tool_loop_ms'] ?? 0),
            'first_token_ms' => (int) ($turn['stages']['first_token_ms'] ?? 0),
            'llm_ms' => (int) ($turn['stages']['llm_ms'] ?? 0),
            'total_ms' => (int) ($turn['stages']['total_ms'] ?? 0),
            'embed_ms' => (int) ($turn['extra']['retrieve_timings']['embed_ms'] ?? 0),
            'ann_ms' => (int) ($turn['extra']['retrieve_timings']['ann_ms'] ?? 0),
            'hydrate_ms' => (int) ($turn['extra']['retrieve_timings']['hydrate_ms'] ?? 0),
            'rerank_ms' => (int) ($turn['extra']['retrieve_timings']['rerank_ms'] ?? 0),
            'cache_hit' => (bool) ($turn['extra']['retrieve_timings']['cache_hit'] ?? false),
            'rerank_skipped' => (bool) ($turn['extra']['retrieve_timings']['rerank_skipped'] ?? false),
            'route' => (string) ($turn['extra']['route'] ?? ''),
            'route_reason' => (string) ($turn['extra']['route_reason'] ?? ''),
            'sources' => (int) ($turn['extra']['sources'] ?? 0),
            'tokens_out' => (int) ($turn['extra']['tokens_out'] ?? 0),
            'is_playground' => (bool) ($turn['extra']['is_playground'] ?? false),
        ], $recent));

        return Inertia::render('admin/hotpath-latency', [
            'turns' => $turns,
            'aggregates' => $this->stats->aggregates($turns),
            'verdict' => $this->stats->verdict($turns),
        ]);
    }
}
