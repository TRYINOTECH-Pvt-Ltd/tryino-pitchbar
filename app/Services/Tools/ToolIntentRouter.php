<?php

namespace App\Services\Tools;

use App\Models\Agent;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;

/**
 * The fast router's pre-stream gate. Decides per turn whether the
 * expensive tool-check completion (runToolLoop — 1-3 FULL non-streaming
 * LLM calls, 5-15s each on Workers AI 70B) is worth running, or whether
 * this is a plain knowledge question that should go straight to
 * RAG + streamChat.
 *
 * Measured motivation: production first-token p50 was 13.9s with the
 * fastest available model because EVERY turn paid the tool check, while
 * ~90% of visitor questions never needed a tool.
 *
 * Decision ladder (budget <2ms, zero network):
 *   1. Kill switches (global config / per-agent override) → tool_loop
 *      with reason 'disabled' — byte-identical legacy behavior.
 *   2. Keyword gate: per-tool lowercase substring scan.
 *   3. Embedding gate: cosine(query embedding, tool centroid) against
 *      per-tool thresholds. The query embedding is REUSED from the RAG
 *      retrieval — the router never embeds anything itself.
 *   4. No signal → knowledge route.
 *
 * Safety nets that live OUTSIDE this class (and run before it):
 * curated-answer short-circuit, trigger scripts, and the
 * HumanIntentDetector keyword shortcut — an explicit "talk to a human"
 * never reaches the router at all.
 */
class ToolIntentRouter
{
    public function __construct(private readonly ToolExemplarStore $exemplars) {}

    /**
     * @param  array<int, Tool>  $enabledTools
     * @param  array<int, float>|null  $queryEmbedding  reused from Retriever; null on legacy cache hits
     */
    public function route(string $message, ?array $queryEmbedding, array $enabledTools, Agent $agent): RouteDecision
    {
        if ($enabledTools === []) {
            return new RouteDecision(RouteDecision::ROUTE_KNOWLEDGE, [], 'no_tools');
        }

        $overrides = (array) ($agent->vertical_overrides ?? []);
        $perAgentEnabled = $overrides['fast_router'] ?? null;
        $globallyEnabled = (bool) config('services.fast_router.enabled', false);

        // Per-agent override wins in both directions; global default
        // otherwise. Disabled → legacy behavior (always run the loop).
        $enabled = is_bool($perAgentEnabled) ? $perAgentEnabled : $globallyEnabled;
        if (! $enabled) {
            return new RouteDecision(
                RouteDecision::ROUTE_TOOL_LOOP,
                array_map(fn (Tool $t) => $t->name(), $enabledTools),
                'disabled',
            );
        }

        // ── Keyword gate ──
        $haystack = mb_strtolower($message);
        $keywordHits = [];
        foreach ($enabledTools as $tool) {
            if (! $tool instanceof HasIntentSignals) {
                continue;
            }
            foreach ($tool->intentKeywords() as $needle) {
                if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                    $keywordHits[] = $tool->name();
                    break;
                }
            }
        }
        if ($keywordHits !== []) {
            return new RouteDecision(RouteDecision::ROUTE_TOOL_LOOP, $keywordHits, 'keyword');
        }

        // ── Embedding gate ──
        if ($queryEmbedding === null || $queryEmbedding === []) {
            // Legacy retrieve-cache hit without a sidecar embedding.
            // Keywords already had their chance; explicit human asks
            // were caught upstream. Worst case: one turn where the
            // model can't call a tool — the visitor's rephrase misses
            // the cache and routes through the full gate.
            return new RouteDecision(RouteDecision::ROUTE_KNOWLEDGE, [], 'no_embedding');
        }

        $defaultThreshold = (float) config('services.fast_router.threshold', 0.74);
        $perTool = (array) config('services.fast_router.thresholds', []);
        $queryDim = count($queryEmbedding);

        $best = null;
        $bestScore = -2.0;
        foreach ($enabledTools as $tool) {
            $centroid = $this->exemplars->centroidFor($tool);
            if ($centroid === null) {
                // Cold cache: queue a warm (deduped by lock) and skip
                // this tool for this turn. Never embed inline.
                $this->exemplars->requestWarm($tool);

                continue;
            }
            if (count($centroid) !== $queryDim) {
                // Embed model / dim changed since this centroid was
                // warmed; content-addressed keys mean a fresh one will
                // replace it. Skip rather than compare garbage.
                continue;
            }

            $score = $this->cosine($queryEmbedding, $centroid);
            $threshold = (float) ($perTool[$tool->name()] ?? $defaultThreshold);
            if ($score >= $threshold && $score > $bestScore) {
                $best = $tool->name();
                $bestScore = $score;
            }
        }

        if ($best !== null) {
            return new RouteDecision(RouteDecision::ROUTE_TOOL_LOOP, [$best], 'embedding', round($bestScore, 4));
        }

        return new RouteDecision(RouteDecision::ROUTE_KNOWLEDGE, [], 'no_signal');
    }

    /**
     * Cosine similarity. Centroids are pre-normalised; query embeddings
     * from the providers are near-unit but normalised defensively here.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $v) {
            $w = (float) ($b[$i] ?? 0.0);
            $dot += $v * $w;
            $normA += $v * $v;
            $normB += $w * $w;
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
