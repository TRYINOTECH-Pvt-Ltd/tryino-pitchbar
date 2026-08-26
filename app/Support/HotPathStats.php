<?php

namespace App\Support;

/**
 * Shared aggregation + plain-English diagnosis over hot-path turn
 * timings. Used by the super-admin latency dashboard
 * (HotPathLatencyController) and the `perf:hotpath` CLI so both
 * surfaces compute identical numbers and give identical advice.
 */
class HotPathStats
{
    public const STAGES = ['retrieve_ms', 'embed_ms', 'ann_ms', 'rerank_ms', 'tool_loop_ms', 'first_token_ms', 'llm_ms', 'total_ms'];

    /**
     * @param  array<int, array<string, mixed>>  $turns  rows holding int stage values
     * @return array<string, array{p50: int, p95: int, max: int}>
     */
    public function aggregates(array $turns): array
    {
        $out = [];
        foreach (self::STAGES as $stage) {
            $values = array_values(array_filter(
                array_map(fn ($t) => (int) ($t[$stage] ?? 0), $turns),
                fn ($v) => $v > 0,
            ));
            sort($values);
            $n = count($values);
            $out[$stage] = [
                'p50' => $n > 0 ? $values[(int) floor($n * 0.50)] ?? end($values) : 0,
                'p95' => $n > 0 ? $values[min($n - 1, (int) floor($n * 0.95))] : 0,
                'max' => $n > 0 ? max($values) : 0,
            ];
        }

        return $out;
    }

    /**
     * Plain-English diagnosis for non-technical operators. Looks at the
     * p95 of each stage and names the dominant cost with the matching
     * remedy.
     *
     * @param  array<int, array<string, mixed>>  $turns
     */
    public function verdict(array $turns): string
    {
        if (count($turns) === 0) {
            return 'No turns recorded yet. Send a message through the widget or playground, then reload.';
        }

        $agg = $this->aggregates($turns);
        $firstToken = $agg['first_token_ms']['p95'];
        $retrieve = $agg['retrieve_ms']['p95'];
        $rerank = $agg['rerank_ms']['p95'];
        $embed = $agg['embed_ms']['p95'];
        $ann = $agg['ann_ms']['p95'];
        $toolLoop = $agg['tool_loop_ms']['p95'];

        // Tool loop first: it sits INSIDE the first-token window, so a
        // dominant tool loop would otherwise be misdiagnosed as a slow
        // model. Each hop is a full non-streaming completion.
        if ($toolLoop >= 2000 && $firstToken > 0 && $toolLoop >= $firstToken / 2) {
            $routerHint = (bool) config('services.fast_router.enabled', false)
                ? ' The fast router is ON — these are turns it routed to the tool loop; check the Route column for keyword/embedding reasons.'
                : ' Enable the fast router (FAST_ROUTER_ENABLED=true + php artisan router:warm) so knowledge-only turns skip this cost, or disable unused tools/capabilities on the agent.';

            return 'Tool-call resolution dominates the first-token wait (tool loop p95 '.$toolLoop.'ms of '.$firstToken.'ms). Every tool-route turn runs a full non-streaming completion before streaming starts.'.$routerHint;
        }

        if ($firstToken >= 800 && $firstToken > $retrieve * 2) {
            return 'LLM first-token wait dominates (p95 '.$firstToken.'ms). Switch to a faster chat model in Settings → System → AI providers — Llama 3.3 70B fp8-fast or GPT-4o mini typically cut this 2-4×.';
        }

        if ($retrieve >= 500) {
            $parts = [];
            if ($embed >= 200) {
                $parts[] = "query embedding (p95 {$embed}ms)";
            }
            if ($ann >= 200) {
                $parts[] = "vector search (p95 {$ann}ms) — consider a local Qdrant (VECTOR_PROVIDER=qdrant) on this server";
            }
            if ($rerank >= 200) {
                $parts[] = "reranker (p95 {$rerank}ms)";
            }
            $detail = $parts === [] ? 'spread across stages' : implode(', ', $parts);

            return "Retrieval dominates (p95 {$retrieve}ms): {$detail}.";
        }

        if ($firstToken > 0 && $firstToken < 400 && $retrieve < 300) {
            return 'Pipeline is healthy (first token p95 '.$firstToken.'ms, retrieval p95 '.$retrieve.'ms). If visitors still report slowness, look at the network between their browser and this server (proxy buffering, TLS, region).';
        }

        return 'Mixed profile — first token p95 '.$firstToken.'ms, retrieval p95 '.$retrieve.'ms. Compare the stage columns to see which round-trip grows on slow turns.';
    }
}
