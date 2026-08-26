<?php

namespace App\Services\Rag;

use App\Events\TokenStreamed;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\Analytics\DetectGapJob;
use App\Jobs\Analytics\IncrementUsageJob;
use App\Jobs\Rag\PersistTurnJob;
use App\Models\Conversation;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiException;
use App\Services\Triggers\HumanIntentDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Hot-path RAG service. Per PLAN §7:
 *   - No DB writes mid-stream
 *   - No retries inside the stream
 *   - Curated answer match short-circuits LLM
 *   - Top-k=6, similarity threshold 0.78
 *   - All retrieved chunks wrapped in <source> tags
 */
class RagPipeline
{
    public function __construct(
        private readonly Retriever $retriever,
        private readonly PromptBuilder $prompt,
        private readonly CuratedAnswerMatcher $curated,
        private readonly OpenAiClient $llm,
        private readonly HumanIntentDetector $humanIntent,
        private readonly QueryRewriter $queryRewriter,
    ) {}

    /**
     * @param  array<string, mixed>|null  $pageContext  Sanitized DOM snapshot from the widget.
     * @return array{message_id: string, text: string, citations: array, low_confidence: bool, latency_ms: int}
     */
    public function handle(string $conversationId, string $userMessage, bool $isPlayground = false, ?array $pageContext = null): array
    {
        $started = (int) (microtime(true) * 1000);

        $conversation = Conversation::query()->withoutWorkspaceScope()->findOrFail($conversationId);
        $agent = $conversation->agent()->withoutWorkspaceScope()->firstOrFail();

        $userMessageId = (string) Str::uuid7();
        $assistantMessageId = (string) Str::uuid7();

        // 1a. Human-intent short-circuit. Mirror of MessageStreamController's
        // 1c block: when the visitor explicitly asks for a human, return
        // the escalation_button block + a short confirmation and skip
        // retrieval + LLM. Same payload shape so the widget renders the
        // button identically across the streaming and JSON paths.
        if ($this->humanIntent->matches($userMessage)) {
            $reply = 'Connecting you with a human. Tap the button below to request a takeover — an agent will join the chat as soon as one is available.';
            foreach ($this->tokenize($reply) as $tok) {
                event(new TokenStreamed($conversationId, $assistantMessageId, $tok));
            }
            event(new TurnCompleted($conversationId, $assistantMessageId, $reply));
            $this->afterTurn($conversation, $userMessageId, $userMessage, $assistantMessageId, $reply, [], 1.0, $started, 'human-intent', $isPlayground, lowConfidence: false);

            return [
                'message_id' => $assistantMessageId,
                'text' => $reply,
                'citations' => [],
                'low_confidence' => false,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
                'blocks' => [[
                    'type' => 'escalation_button',
                    'payload' => [
                        'label' => 'Connect me with a human',
                        'reason' => 'Visitor explicitly requested a human.',
                    ],
                ]],
            ];
        }

        // 1. Curated answer short-circuit
        $curatedAnswer = $this->curated->match($agent->id, $userMessage, $conversation->lang);
        if ($curatedAnswer !== null) {
            // Stream the curated answer "as if" from the LLM so the widget renders it identically
            foreach ($this->tokenize($curatedAnswer) as $tok) {
                event(new TokenStreamed($conversationId, $assistantMessageId, $tok));
            }
            event(new TurnCompleted($conversationId, $assistantMessageId, $curatedAnswer));

            $this->afterTurn($conversation, $userMessageId, $userMessage, $assistantMessageId, $curatedAnswer, [], 1.0, $started, 'curated', $isPlayground, lowConfidence: false);

            return [
                'message_id' => $assistantMessageId,
                'text' => $curatedAnswer,
                'citations' => [],
                'low_confidence' => false,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ];
        }

        // 2. Retrieval (cached) — pass current page so chunks from
        // that page get a small relevance boost.
        $threshold = (float) ($agent->confidence_threshold ?? config('services.rag.confidence_threshold', 0.5));
        $currentPageUrl = is_array($pageContext) && isset($pageContext['url']) && is_string($pageContext['url'])
            ? $pageContext['url']
            : $conversation->page_url;
        // Standalone-query rewrite (LLM when enabled, heuristic otherwise) —
        // twin of MessageStreamController.
        $priorHistory = Cache::get("conv:{$conversationId}:history", []);
        $retrievalQueryText = $this->queryRewriter->rewrite($userMessage, is_array($priorHistory) ? $priorHistory : [], $conversationId);
        $retrieval = $this->retriever->retrieve($agent->id, $retrievalQueryText, $threshold, 6, $currentPageUrl);
        $sources = $retrieval['chunks'];
        // Confidence: pick the strongest grounding signal (retrieved
        // similarity OR page-context baseline 0.85). Old hardcoded
        // 0.4/0.9 ignored page-context entirely → every page-context-only
        // reply showed 40% in the dashboard.
        $confidence = $this->computeConfidence($sources, $pageContext);
        $lowConfidence = $confidence < $threshold;

        // 3. History — last 6 turns from cache (Redis hot path), not DB
        $historyKey = "conv:{$conversationId}:history";
        $history = Cache::get($historyKey, []);

        // 4. Prompt assembly
        $messages = $this->prompt->build(
            agent: $agent,
            userMessage: $userMessage,
            sources: array_map(fn ($s) => ['text' => $s['text'], 'url' => $s['url'], 'score' => $s['score']], $sources),
            history: $history,
            detectedLang: $conversation->lang,
            pageUrl: $conversation->page_url,
            pageContext: $pageContext,
        );

        // 5. Stream
        $buffer = '';
        try {
            foreach ($this->llm->streamChat($messages, ['max_tokens' => 800]) as $token) {
                $buffer .= $token;
                event(new TokenStreamed($conversationId, $assistantMessageId, $token));
            }
        } catch (OpenAiException $e) {
            event(new TurnFailed($conversationId, $e->getMessage()));
            throw $e;
        }

        // 6. Citations — mirror PromptBuilder's source[0] = page_context
        // so [1] in the LLM reply maps to the page URL the visitor was on.
        $sourcesForCitations = $sources;
        if ($pageContext !== null) {
            array_unshift($sourcesForCitations, [
                'text' => '',
                'url' => is_string($pageContext['url'] ?? null) ? $pageContext['url'] : null,
                'score' => 1.0,
            ]);
        }
        $citations = $this->extractCitations($buffer, $sourcesForCitations);

        // 7. Stream complete
        event(new TurnCompleted(
            conversationId: $conversationId,
            messageId: $assistantMessageId,
            fullText: $buffer,
            citations: $citations,
            lowConfidence: $lowConfidence,
        ));

        // 8. After-turn (async DB writes + analytics)
        $this->afterTurn(
            conversation: $conversation,
            userMessageId: $userMessageId,
            userMessage: $userMessage,
            assistantMessageId: $assistantMessageId,
            assistantText: $buffer,
            citations: $citations,
            confidence: $confidence,
            startedMs: $started,
            model: 'gpt-4o-mini',
            isPlayground: $isPlayground,
            lowConfidence: $lowConfidence,
        );

        // Update Redis history (capped at 6 turns)
        $history[] = ['role' => 'user', 'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $buffer];
        if (count($history) > 12) {
            $history = array_slice($history, -12);
        }
        Cache::put($historyKey, $history, now()->addHours(2));

        return [
            'message_id' => $assistantMessageId,
            'text' => $buffer,
            'citations' => $citations,
            'low_confidence' => $lowConfidence,
            'latency_ms' => (int) (microtime(true) * 1000) - $started,
        ];
    }

    /**
     * Strongest grounding signal — retrieval similarity OR page-context
     * baseline (0.85), whichever is higher. Returns 0.3 when neither is
     * present (LLM has nothing to cite).
     *
     * @param  array<int, array{score?: float, rerank_score?: float}>  $retrievedSources
     * @param  array<string, mixed>|null  $pageContext
     */
    private function computeConfidence(array $retrievedSources, ?array $pageContext): float
    {
        $best = 0.0;
        foreach ($retrievedSources as $s) {
            $score = (float) ($s['rerank_score'] ?? $s['score'] ?? 0);
            if ($score > $best) {
                $best = $score;
            }
        }

        if ($pageContext !== null) {
            $best = max($best, 0.85);
        }

        if ($best === 0.0) {
            return 0.3;
        }

        return min(1.0, $best);
    }

    /**
     * @param  array<int, array{text: string, url: ?string, score: float}>  $sources
     * @return array<int, array{id: int, url: ?string}>
     */
    private function extractCitations(string $text, array $sources): array
    {
        if (! preg_match_all('/\[(\d+)\]/', $text, $m)) {
            return [];
        }
        $ids = array_unique(array_map('intval', $m[1]));
        $citations = [];
        foreach ($ids as $id) {
            $idx = $id - 1;
            if (isset($sources[$idx])) {
                $citations[] = ['id' => $id, 'url' => $sources[$idx]['url'] ?? null];
            }
        }

        return $citations;
    }

    private function tokenize(string $text): iterable
    {
        $tokens = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($tokens as $t) {
            yield $t;
        }
    }

    private function afterTurn(
        Conversation $conversation,
        string $userMessageId,
        string $userMessage,
        string $assistantMessageId,
        string $assistantText,
        array $citations,
        float $confidence,
        int $startedMs,
        string $model,
        bool $isPlayground,
        bool $lowConfidence,
    ): void {
        $latency = (int) (microtime(true) * 1000) - $startedMs;

        // dispatchSync: message persistence is critical (conversation
        // history depends on it). Avoids silent data loss when the
        // queue worker is misconfigured on production.
        PersistTurnJob::dispatchSync(
            $conversation->id,
            $userMessageId,
            $userMessage,
            $assistantMessageId,
            $assistantText,
            $citations,
            $confidence,
            $latency,
            $model,
        );

        if ($lowConfidence || $this->looksLikeFailure($assistantText)) {
            // Inline, like PersistTurnJob above: Content Gaps must not be
            // lost when the `analytics` queue is unworked. The ShouldQueue
            // notification inside DetectGapJob stays async.
            try {
                DetectGapJob::dispatchSync($conversation->agent_id, $userMessage);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $isPlayground) {
            IncrementUsageJob::dispatch($conversation->id);
        }
    }

    private function looksLikeFailure(string $text): bool
    {
        $needles = ["don't know", 'not sure', "couldn't find", 'unable to find', 'no information'];
        $haystack = mb_strtolower($text);
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }
}
