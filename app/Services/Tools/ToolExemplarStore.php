<?php

namespace App\Services\Tools;

use App\Jobs\Tools\WarmToolExemplarsJob;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-tool intent centroids for the fast router's embedding gate.
 *
 * Each tool gets ONE vector: the mean of its exemplar utterance
 * embeddings, L2-normalised. At turn time the router does a single
 * dot product per enabled tool against the (already computed) query
 * embedding — microseconds, no network.
 *
 * Cache keys are content-addressed over (embed model, vector dim,
 * exemplar text), so invalidation is automatic: change the embed
 * model, the dimension, or a tool's exemplars and the old centroid
 * simply stops being read and ages out. No observers, no manual
 * busting.
 *
 * HOT-PATH RULE: centroidFor() is Cache::get ONLY. Embedding happens
 * exclusively in the queued WarmToolExemplarsJob (or the router:warm
 * deploy command). A cold cache degrades routing to keywords-only —
 * never latency.
 */
class ToolExemplarStore
{
    public const CACHE_PREFIX = 'router:exemplar:';

    private const CACHE_TTL_DAYS = 30;

    private const WARM_LOCK_SECONDS = 300;

    public function __construct(private readonly OpenAiClient $llm) {}

    /**
     * Cached, L2-normalised centroid for this tool — or null when not
     * warmed yet (caller should requestWarm() and skip the tool for
     * this turn).
     *
     * @return array<int, float>|null
     */
    public function centroidFor(Tool $tool): ?array
    {
        $cached = Cache::get($this->cacheKeyFor($tool));

        return is_array($cached) && $cached !== [] ? $cached : null;
    }

    /**
     * Embed the tool's exemplars, mean-pool, normalise, cache. Runs in
     * the queue worker / deploy command — never on the hot path.
     */
    public function warm(Tool $tool): void
    {
        $exemplars = $this->exemplarsFor($tool);
        if ($exemplars === []) {
            return;
        }

        $vectors = $this->llm->embed($exemplars);
        if ($vectors === [] || ! is_array($vectors[0] ?? null)) {
            Log::warning('router.exemplar.warm_failed', ['tool' => $tool->name()]);

            return;
        }

        $dims = count($vectors[0]);
        $centroid = array_fill(0, $dims, 0.0);
        $n = 0;
        foreach ($vectors as $vector) {
            if (! is_array($vector) || count($vector) !== $dims) {
                continue;
            }
            foreach ($vector as $i => $v) {
                $centroid[$i] += (float) $v;
            }
            $n++;
        }
        if ($n === 0) {
            return;
        }

        $norm = 0.0;
        foreach ($centroid as $i => $v) {
            $centroid[$i] = $v / $n;
            $norm += $centroid[$i] * $centroid[$i];
        }
        $norm = sqrt($norm);
        if ($norm > 0.0) {
            foreach ($centroid as $i => $v) {
                $centroid[$i] = $v / $norm;
            }
        }

        Cache::put($this->cacheKeyFor($tool), $centroid, now()->addDays(self::CACHE_TTL_DAYS));
    }

    /**
     * Dispatch a warm job for this tool at most once per lock window.
     * Called from the hot path when a centroid is missing — the lock
     * keeps N concurrent turns from queueing N identical jobs.
     */
    public function requestWarm(Tool $tool): void
    {
        $lock = Cache::lock('router:warm:'.$tool->name(), self::WARM_LOCK_SECONDS);
        if ($lock->get()) {
            // Lock intentionally NOT released — it doubles as the
            // dispatch-once-per-window marker.
            WarmToolExemplarsJob::dispatch($tool->name());
        }
    }

    /**
     * Content-addressed key: embed model + vector dim + exemplar text.
     */
    public function cacheKeyFor(Tool $tool): string
    {
        $provider = (string) config('services.llm.provider', '');
        $embedModel = match (true) {
            $provider === 'openai' => (string) config('services.openai.embed_model', 'text-embedding-3-small'),
            default => (string) config('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5'),
        };
        $dim = (int) config('services.vector_dim', 768);

        return self::CACHE_PREFIX
            .$tool->name().':'
            .hash('sha256', $embedModel.'|'.$dim.'|'.json_encode($this->exemplarsFor($tool)));
    }

    /**
     * @return list<string>
     */
    private function exemplarsFor(Tool $tool): array
    {
        if ($tool instanceof HasIntentSignals && $tool->intentExemplars() !== []) {
            return $tool->intentExemplars();
        }

        // Fallback for plain tools (incl. future MCP tools): the
        // description is written for the LLM to decide tool relevance —
        // the same signal works as a routing exemplar.
        $description = trim($tool->description());

        return $description !== '' ? [$description] : [];
    }
}
