<?php

namespace App\Services\Rag;

use App\Services\Llm\Contracts\OpenAiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the visitor's latest message into the string that gets embedded for
 * retrieval — the "condensed standalone question" step of conversational RAG.
 *
 * Why this exists: retrieval quality was death-by-a-thousand-heuristics. A
 * short question ("wat is uw adres") asked mid-conversation was silently
 * fused with the previous topic and the true answer dropped below threshold;
 * cross-lingual and re-phrasings failed unpredictably. Instead of guessing
 * with string rules, a small fast LLM reads the recent conversation and
 * rewrites a context-dependent message into ONE self-contained search query.
 *
 * Three layers keep the hot-path p95 ≤ 1s contract intact:
 *   1. Gate — a self-contained question (or the first turn) is already a
 *      clean query, so it is returned verbatim with NO LLM call. This is the
 *      majority of turns; they pay nothing.
 *   2. Bounded — the rewrite uses a small model, temperature 0, a tiny token
 *      budget, and a hard timeout. It is cached per conversation+message.
 *   3. Fallback — disabled feature, timeout, error, or empty/oversized output
 *      all fall back to the deterministic heuristic (RetrievalQueryBuilder).
 *      The stream NEVER blocks on the rewrite.
 *
 * OFF by default (config services.rag.query_rewrite.enabled) — when off this
 * is behaviourally identical to calling RetrievalQueryBuilder directly, so
 * tests and unconfigured installs are unchanged.
 */
class QueryRewriter
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You rewrite the latest user message from a website chat into a SINGLE, self-contained search query for a knowledge base.

        Rules:
        - Resolve pronouns and ellipsis using the conversation (e.g. after "Where was Jan Roel born?", the message "and the year?" becomes "Jan Roel birth year").
        - Keep the user's language. Preserve names, numbers, addresses and specific terms exactly.
        - If the message is already a complete, self-contained question, output it unchanged.
        - Output ONLY the query, as one short line. No quotes, no explanation, no labels.
        PROMPT;

    public function __construct(
        private readonly OpenAiClient $llm,
        private readonly RetrievalQueryBuilder $fallback,
    ) {}

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history  Chronological turns from the conversation cache.
     * @param  ?string  $cacheKey  Conversation id (or any stable id) to scope the rewrite cache; null disables caching.
     */
    public function rewrite(string $message, array $history, ?string $cacheKey = null): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        // Gate: self-contained questions and first turns need no rewrite.
        if (! $this->fallback->isContextDependent($trimmed, $history)) {
            return $trimmed;
        }

        // Feature off → deterministic heuristic (unchanged legacy behavior).
        if (! (bool) config('services.rag.query_rewrite.enabled', false)) {
            return $this->fallback->build($trimmed, $history);
        }

        $priorUser = $this->mostRecentUserContent($history);
        $key = $cacheKey !== null
            ? 'rag:rewrite:'.hash('sha256', $cacheKey.'|'.$trimmed.'|'.($priorUser ?? ''))
            : null;
        if ($key !== null) {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $stitched = $this->fallback->build($trimmed, $history);

        try {
            $rewritten = $this->callLlm($trimmed, $history);
        } catch (Throwable) {
            $rewritten = null;
        }

        // Card #485 — a rewrite must never come out WORSE than the
        // deterministic stitch. Proven on blengi 2026-08-06: with the
        // feature on, control passed to the LLM the moment #481 widened the
        // gate, and turns that the stitch answered correctly went back to
        // deferring. A small model asked to "make this self-contained"
        // drops the product name and the query looks perfectly reasonable
        // while retrieving the wrong thing — so any critical term it lost
        // is appended rather than the whole rewrite being thrown away.
        $result = ($rewritten !== null && $rewritten !== '')
            ? $this->restoreLostTerms($rewritten, $trimmed, $history)
            : $stitched;

        Log::info('rag.query_rewrite', [
            'used' => ($rewritten !== null && $rewritten !== '') ? 'llm' : 'stitch',
            'llm' => $rewritten,
            'stitch' => $stitched,
            'final' => $result,
        ]);

        if ($key !== null) {
            Cache::put($key, $result, now()->addMinutes(30));
        }

        return $result;
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function callLlm(string $message, array $history): ?string
    {
        $model = (string) config('services.rag.query_rewrite.model', '');
        $timeoutMs = (int) config('services.rag.query_rewrite.timeout_ms', 2500);
        $maxTurns = (int) config('services.rag.query_rewrite.max_history_turns', 6);

        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($this->recentTurns($history, $maxTurns) as $turn) {
            $messages[] = $turn;
        }
        $messages[] = [
            'role' => 'user',
            'content' => "Rewrite this into one self-contained search query:\n".$message,
        ];

        $opts = [
            'max_tokens' => 64,
            'temperature' => 0.0,
            // Seconds; a slow rewrite falls back rather than stalling the stream.
            'timeout' => max(1, (int) ceil($timeoutMs / 1000)),
        ];
        if ($model !== '') {
            $opts['model'] = $model;
        }

        $res = $this->llm->chatWithTools($messages, [], $opts);

        return $this->sanitize((string) ($res['content'] ?? ''));
    }

    /**
     * Keep the last N chronological turns as clean {role, content} pairs,
     * each capped so a long earlier answer can't blow the rewrite budget.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function recentTurns(array $history, int $maxTurns): array
    {
        $turns = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? null) === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $turns[] = ['role' => $role, 'content' => mb_substr($content, 0, 500)];
        }

        return array_slice($turns, -$maxTurns);
    }

    /**
     * Append any critical term the rewrite dropped. Retrieval queries are
     * embedded, not parsed, so appending a bare product name is safe and
     * cheap — far safer than trusting the model to have kept it.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function restoreLostTerms(string $rewritten, string $message, array $history): string
    {
        $missing = [];
        foreach ($this->fallback->criticalTerms($message, $history) as $term) {
            if (mb_stripos($rewritten, $term) === false) {
                $missing[] = $term;
            }
        }

        return $missing === [] ? $rewritten : $rewritten.' '.implode(' ', $missing);
    }

    /**
     * Normalize the model's output into a single safe query line. Returns
     * null when the output is unusable so the caller falls back.
     */
    private function sanitize(string $out): ?string
    {
        $out = trim($out);
        if ($out === '') {
            return null;
        }
        // Some models prepend a label / preamble — take the first real line.
        $lines = preg_split('/\r\n|\r|\n/', $out) ?: [$out];
        foreach ($lines as $line) {
            $line = trim(trim((string) $line), " \t\"'`");
            $line = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line));
            if ($line !== '') {
                return mb_substr($line, 0, 400);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function mostRecentUserContent(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? null) === 'user') {
                $content = trim((string) ($history[$i]['content'] ?? ''));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }
}
