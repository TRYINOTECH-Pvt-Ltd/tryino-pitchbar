<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Rag\Contracts\Reranker;
use App\Services\Vector\Contracts\QdrantClient;
use App\Support\CanonicalUrl;
use Illuminate\Support\Facades\Cache;

class Retriever
{
    /**
     * Score lift given to chunks from a source flagged "answer on every
     * page" (is_global). Mirrors the current-page +0.15 boost so a global
     * fact is reachable from ANY page, exactly as if the visitor were
     * standing on the page that fact was crawled from.
     */
    private const GLOBAL_SOURCE_BOOST = 0.15;

    /**
     * Cap on how many global chunks may be force-passed through the
     * threshold per turn, so a large global source can't crowd out the
     * real answer. Beyond the cap, global chunks compete on merit.
     */
    private const GLOBAL_MAX_FORCED = 2;

    public function __construct(
        private readonly OpenAiClient $llm,
        private readonly QdrantClient $vector,
        private readonly Reranker $reranker,
    ) {}

    /**
     * Two-stage retrieval:
     *   1. ANN over Vectorize → take top (topK * fanOut) candidates by cosine similarity (recall-oriented).
     *   2. Cross-encoder reranker → reorder candidates by query relevance (precision-oriented), keep top-K.
     *
     * Threshold is applied AFTER reranking so a candidate that bge-base
     * scored borderline (0.51) but the reranker scores high (0.92) survives.
     *
     * The returned `timings` key breaks the retrieval wall-time into its
     * component round-trips (embed → ANN → chunk hydrate → rerank) so the
     * hot-path latency dashboard can show WHICH stage is slow instead of
     * one lumped retrieve_ms. Timings are per-request observations — they
     * are intentionally NOT cached; a cache hit reports `cache_hit: 1`.
     *
     * @return array{chunks: array<int, array{text: string, url: ?string, score: float, chunk_id: string, rerank_score?: float}>, low_confidence: bool, timings: array<string, int>, query_embedding?: array<int, float>|null}
     */
    public function retrieve(
        string $agentId,
        string $query,
        float $threshold = 0.5,
        int $topK = 6,
        ?string $currentPageUrl = null,
    ): array {
        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
        $canonicalCurrent = $currentPageUrl !== null ? CanonicalUrl::for($currentPageUrl) : null;
        // Bake the current-page hint into the cache key so a question
        // asked from /products/red-pen doesn't reuse the cached top-K
        // for the same question asked from /about — those should rank
        // different chunks higher.
        // Fold the global-source version into the hash ONLY when the agent
        // has global sources, so toggling "answer on every page" instantly
        // invalidates stale cached answers (e.g. a cached "I can't confirm"
        // for the homepage address). Agents that never use the feature keep
        // the exact legacy hash — pre-feature cache entries stay valid.
        $hasGlobal = Source::hasGlobalFor($agentId);
        $globalKeyPart = $hasGlobal ? '|g:'.Source::globalVersionFor($agentId) : '';
        $queryHash = hash('sha256', mb_strtolower(trim($query)).'|'.($canonicalCurrent ?? '').$globalKeyPart);
        $cacheKey = 'rag:retrieve:'.$agentId.':'.$queryHash;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['timings'] = ['cache_hit' => 1];
            // Sidecar embedding survives the retrieve cache so the fast
            // router's embedding gate works on cache-hit turns too.
            // Tolerates null (legacy entries cached before the sidecar
            // existed) — the router degrades to keywords-only then.
            $sidecar = Cache::get('rag:qembed:'.$queryHash);
            $cached['query_embedding'] = is_array($sidecar) ? $sidecar : null;

            return $cached;
        }

        $timings = [];
        $clock = static fn (): float => microtime(true);

        // Embed query (slow on cache miss).
        $t0 = $clock();
        $embeddings = $this->llm->embed([$query]);
        $vector = $embeddings[0] ?? [];
        $timings['embed_ms'] = (int) round(($clock() - $t0) * 1000);

        // Sidecar: keep the raw query embedding for the fast router.
        // Stored OUTSIDE the retrieve payload (keeps that entry small +
        // backward compatible); same TTL so the pair expires together.
        if ($vector !== []) {
            Cache::put('rag:qembed:'.$queryHash, $vector, now()->addMinutes(30));
        }

        // Stage 1 — recall-oriented ANN. Pull more than we need so the
        // reranker has options to choose from.
        $fanOut = (int) config('services.rag.rerank_fan_out', 3);
        $candidatesK = max($topK, $topK * $fanOut);
        $t0 = $clock();
        $results = $this->vector->search($collection, $vector, ['agent_id' => $agentId], $candidatesK);
        $timings['ann_ms'] = (int) round(($clock() - $t0) * 1000);

        if ($results === []) {
            $payload = ['chunks' => [], 'low_confidence' => true];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));

            return $payload + ['timings' => $timings, 'query_embedding' => $vector !== [] ? $vector : null];
        }

        // Scope bypass safe: ANN results are agent-id filtered, agents
        // are workspace-scoped — chunk ids are provably in-workspace.
        $t0 = $clock();
        $chunkIds = array_filter(array_map(fn ($r) => $r['payload']['chunk_id'] ?? null, $results));
        $byId = Chunk::query()->withoutWorkspaceScope()
            ->whereIn('id', $chunkIds)
            ->get()
            ->keyBy('id');

        // Global-source membership map (chunk → document → source.is_global),
        // gated on the cached "has global sources" flag so agents without
        // any global source pay nothing on the hot path.
        $globalByDocument = null;
        if ($hasGlobal) {
            $documentIds = $byId->pluck('document_id')->filter()->unique()->values()->all();
            // Scope bypass safe: document ids come from the agent-filtered
            // ANN hit set hydrated above.
            $globalByDocument = Document::query()->withoutWorkspaceScope()
                ->leftJoin('sources', 'sources.id', '=', 'documents.source_id')
                ->whereIn('documents.id', $documentIds)
                ->get(['documents.id as document_id', 'sources.is_global as is_global'])
                ->pluck('is_global', 'document_id')
                ->map(fn ($v) => (bool) $v);
        }

        $candidates = [];
        foreach ($results as $r) {
            $chunkId = $r['payload']['chunk_id'] ?? null;
            $chunk = $chunkId !== null ? ($byId[$chunkId] ?? null) : null;
            if ($chunk === null) {
                continue;
            }
            $candidate = [
                'text' => $chunk->text,
                'url' => $r['payload']['url'] ?? null,
                'score' => (float) $r['score'],
                'chunk_id' => $chunk->id,
            ];
            if ($globalByDocument !== null) {
                $candidate['source_is_global'] = (bool) ($globalByDocument[$chunk->document_id] ?? false);
            }
            $candidates[] = $candidate;
        }
        $timings['hydrate_ms'] = (int) round(($clock() - $t0) * 1000);

        // Stage 2 — precision-oriented rerank. Skipped adaptively when ANN
        // was already decisive: every chunk we'd keep goes into the prompt
        // regardless of order, so the cross-encoder's only real value is
        // choosing WHICH topK of the fan-out candidates survive. When the
        // top topK candidates all clear rerank_skip_score, that choice is
        // already made — the 500-1,200ms round-trip buys nothing.
        // When the agent has global sources we must rank the FULL candidate
        // list (a global fact can sit outside the naive top-K yet still
        // deserve to surface), so the top-K slice is deferred to after the
        // boosts below. Non-global agents keep the exact top-K behavior.
        if ($this->shouldSkipRerank($candidates, $topK)) {
            $reranked = $hasGlobal ? $candidates : array_slice($candidates, 0, $topK);
            $timings['rerank_ms'] = 0;
            $timings['rerank_skipped'] = 1;
        } else {
            $t0 = $clock();
            $reranked = $this->reranker->rerank($query, $candidates, $hasGlobal ? count($candidates) : $topK);
            $timings['rerank_ms'] = (int) round(($clock() - $t0) * 1000);
        }

        // Stage 2.5 — current-page boost. When the visitor is on a
        // specific page, chunks crawled from THAT exact page are more
        // likely to be the right answer than chunks from elsewhere on
        // the site. Bump their rerank score by 0.15 (cap at 1.0) and
        // re-sort. The threshold check below uses the boosted score so
        // a borderline same-page chunk survives where it otherwise
        // would have been filtered out.
        if ($canonicalCurrent !== null) {
            foreach ($reranked as $i => $r) {
                $candUrl = is_string($r['url'] ?? null) ? CanonicalUrl::for($r['url']) : null;
                if ($candUrl !== null && $candUrl === $canonicalCurrent) {
                    $base = (float) ($r['rerank_score'] ?? $r['score'] ?? 0);
                    // Don't cap — only relative order matters for ranking,
                    // and capping at 1.0 would tie with already-1.0 chunks
                    // and let stable-sort preserve the wrong order.
                    $reranked[$i]['rerank_score'] = $base + 0.15;
                    $reranked[$i]['boosted_for_current_page'] = true;
                }
            }
        }

        // Establish the rank order (so the global gate can read which chunks
        // the reranker judged most relevant for THIS query) before lifting
        // global facts.
        $byScore = fn ($a, $b) => ($b['rerank_score'] ?? $b['score'] ?? 0) <=> ($a['rerank_score'] ?? $a['score'] ?? 0);
        if ($canonicalCurrent !== null || $hasGlobal) {
            usort($reranked, $byScore);
        }

        // Stage 2.6 — global-source boost. "Answer on every page" sources
        // get the current-page-equivalent lift + always-pass, but only when
        // the reranker already ranked them near the top for this query — so
        // "what is your address" surfaces the contact fact from any page,
        // while "what are your prices" does not (see applyGlobalBoost).
        if ($hasGlobal) {
            $reranked = $this->applyGlobalBoost($reranked);
            usort($reranked, $byScore);
        }

        $reranked = array_slice($reranked, 0, $topK);

        // Threshold passes when EITHER the ANN cosine score OR the
        // rerank score clears the bar — whichever is more meaningful for
        // this provider:
        //
        //  - Real bge-reranker-base (Cloudflare): cross-encoder logits run
        //    0.001–0.5 even on perfect matches. ANN cosine (0.3–0.8) is
        //    the meaningful signal and rerank gives ordering only.
        //  - FakeReranker in tests: synthetic vectors can produce negative
        //    cosine scores; rerank_score is the deterministic 0–1 signal
        //    that survives.
        //
        // Taking max() of the two means production filters on ANN
        // (correct), tests filter on rerank (correct), and we never
        // throw away a high-confidence match because one of the two
        // signals happens to be small.
        //
        // Boosted same-page chunks always pass — current-page lift is
        // already baked into rerank_score above.
        $passing = array_values(array_filter(
            $reranked,
            function ($r) use ($threshold) {
                if (($r['boosted_for_current_page'] ?? false) === true
                    || ($r['boosted_for_global'] ?? false) === true) {
                    return true;
                }
                $best = max(
                    (float) ($r['score'] ?? 0),
                    (float) ($r['rerank_score'] ?? 0),
                );

                return $best >= $threshold;
            }
        ));

        $payload = [
            'chunks' => $passing,
            'low_confidence' => count($passing) < 2,
        ];

        // Cache WITHOUT timings — they describe this request's network
        // weather, not the retrieval result. Cache hits report their own
        // marker so the dashboard can distinguish hit vs miss turns.
        Cache::put($cacheKey, $payload, now()->addMinutes(30));

        return $payload + ['timings' => $timings, 'query_embedding' => $vector !== [] ? $vector : null];
    }

    /**
     * "Answer on every page": give chunks from a source flagged is_global
     * the same treatment current-page chunks get — a +GLOBAL_SOURCE_BOOST
     * score lift plus an always-pass through the confidence threshold (via
     * the `boosted_for_global` flag the threshold filter honors) — so
     * global/company facts (address, opening hours, contact) are reachable
     * from any page, not walled to the one page they were crawled from.
     *
     * Relevance is filtered by ANN candidacy, NOT by reranker position: a
     * chunk only reaches this method if the bi-encoder (bge-base) retrieved
     * it into the top candidates for the query, so an off-topic query never
     * surfaces the fact. We deliberately do NOT gate on the cross-encoder
     * reranker's ordering — it mis-ranks cross-lingual matches (a Dutch
     * postal string ranks low for the English word "address"), which is
     * exactly the case this feature exists for. Flooding is bounded instead
     * by GLOBAL_MAX_FORCED: only the strongest few global candidates (by
     * score) are lifted, so even a large global source can't dominate an
     * answer. This mirrors the current-page boost, which also always-passes
     * without a reranker gate (verified live 2026-07-05: the position gate
     * blocked the English "what is your address" it was meant to fix).
     *
     * @param  array<int, array<string, mixed>>  $ranked
     * @return array<int, array<string, mixed>>
     */
    private function applyGlobalBoost(array $ranked): array
    {
        $globalScores = [];
        foreach ($ranked as $i => $r) {
            if (($r['source_is_global'] ?? false) === true) {
                $globalScores[$i] = (float) ($r['rerank_score'] ?? $r['score'] ?? 0);
            }
        }
        if ($globalScores === []) {
            return $ranked;
        }

        arsort($globalScores);
        $lift = array_slice(array_keys($globalScores), 0, self::GLOBAL_MAX_FORCED);
        foreach ($lift as $i) {
            $base = (float) ($ranked[$i]['rerank_score'] ?? $ranked[$i]['score'] ?? 0);
            $ranked[$i]['rerank_score'] = $base + self::GLOBAL_SOURCE_BOOST;
            $ranked[$i]['boosted_for_global'] = true;
        }

        return $ranked;
    }

    /**
     * ANN-decisive check for the adaptive rerank skip. True only when the
     * flag is on AND there are at least topK candidates AND every one of
     * the first topK (ANN already returns them score-descending) clears
     * the skip score. Sparse or weak candidate sets always rerank —
     * precision matters most exactly when recall was shaky.
     *
     * @param  array<int, array{text: string, url: ?string, score: float, chunk_id: string}>  $candidates
     */
    private function shouldSkipRerank(array $candidates, int $topK): bool
    {
        if (! (bool) config('services.rag.rerank_skip', false)) {
            return false;
        }
        if (count($candidates) < $topK) {
            return false;
        }

        $skipScore = (float) config('services.rag.rerank_skip_score', 0.68);
        foreach (array_slice($candidates, 0, $topK) as $candidate) {
            if ((float) $candidate['score'] < $skipScore) {
                return false;
            }
        }

        return true;
    }
}
