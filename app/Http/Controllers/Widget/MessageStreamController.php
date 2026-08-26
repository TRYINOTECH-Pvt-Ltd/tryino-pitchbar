<?php

namespace App\Http\Controllers\Widget;

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Jobs\Analytics\DetectGapJob;
use App\Jobs\Analytics\IncrementUsageJob;
use App\Jobs\Analytics\PersistUsageJob;
use App\Jobs\Rag\PersistTurnJob;
use App\Jobs\Rag\PersistTurnTraceJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Billing\MeteredBilling;
use App\Services\Experiments\ExperimentResolver;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Mcp\McpServerRegistry;
use App\Services\Mcp\McpToolExecutor;
use App\Services\Rag\CuratedAnswerMatcher;
use App\Services\Rag\PromptBuilder;
use App\Services\Rag\QueryRewriter;
use App\Services\Rag\Retriever;
use App\Services\Tools\Contracts\Tool;
use App\Services\Tools\ToolIntentRouter;
use App\Services\Tools\ToolRegistry;
use App\Services\Triggers\CtaSelector;
use App\Services\Triggers\HumanIntentDetector;
use App\Services\Triggers\LeadIntentDetector;
use App\Services\Widget\InlineBlockParser;
use App\Services\Widget\WidgetEventRecorder;
use App\Services\Widget\WidgetJwt;
use App\Services\Workflows\WorkflowEngine;
use App\Support\CanonicalUrl;
use App\Support\HotPathTimer;
use App\Support\TokenEstimator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Token-streaming variant of /v1/widget/messages.
 *
 * Returns text/event-stream. The widget reads tokens as they arrive,
 * giving the visitor a "first byte in <1s" experience. The synchronous
 * MessageController is still available for callers that don't want SSE.
 *
 * Hot-path rules from PLAN §7 are honored:
 *   - No DB writes during the stream (PersistTurnJob fires after).
 *   - No retries inside the stream.
 *   - Curated answer match short-circuits LLM.
 *   - Top-k=6 with similarity threshold from the agent.
 *   - All retrieved chunks wrapped in <source> tags.
 */
class MessageStreamController
{
    public function __construct(
        private WidgetJwt $jwt,
        private Retriever $retriever,
        private PromptBuilder $prompt,
        private CuratedAnswerMatcher $curated,
        private OpenAiClient $llm,
        private CtaSelector $ctaSelector,
        private LeadIntentDetector $leadIntent,
        private HumanIntentDetector $humanIntent,
        private ToolRegistry $tools,
        private ToolIntentRouter $router,
        private InlineBlockParser $blockParser,
        private MeteredBilling $billing,
        private WorkflowEngine $workflows,
        private ExperimentResolver $experiments,
        private McpServerRegistry $mcpRegistry,
        private McpToolExecutor $mcpExecutor,
        private WidgetEventRecorder $widgetEvents,
        private QueryRewriter $queryRewriter,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return $this->errorStream('missing_token', 401);
        }
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable $e) {
            return $this->errorStream('invalid_token', 401);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            // Visitor's current page metadata — captured client-side from
            // the DOM (title, og:*, JSON-LD, visible text). Optional;
            // legacy widget builds won't send it.
            'page_context' => ['nullable', 'array'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $userMessage = $data['message'];
        $pageContext = $this->sanitizePageContext($data['page_context'] ?? null);

        return new StreamedResponse(function () use ($conversationId, $userMessage, $pageContext) {
            // Under PHP-FPM / mod_php / LiteSpeed the default
            // max_execution_time (often 30s) counts against this closure,
            // and a slow LLM turn — tool loops routinely pass 25s — gets
            // killed mid-stream, truncating the answer. Octane/CLI run
            // with no limit, which is why this never surfaced there. The
            // @ tolerates hosts that put set_time_limit in
            // disable_functions; those keep their configured ceiling.
            @set_time_limit(0);

            // Top-level try/catch so any unhandled exception inside
            // the streaming closure surfaces as a real `error` SSE
            // event instead of dying silently (PHP swallows
            // exceptions in flush callbacks). The visitor then sees
            // the actual server message via the widget's terminal-
            // error UX path; without this they get a stuck "thinking"
            // bubble and three pointless retries.
            try {
                $this->streamTurn($conversationId, $userMessage, $pageContext);
            } catch (\Throwable $e) {
                \Log::error('widget.stream_unhandled', [
                    'conversation_id' => $conversationId,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
                // Surface the failure on the Widget Monitor so an operator
                // sees it before the client screenshots it. Off the hot path
                // by definition — the turn has already died here.
                $this->widgetEvents->record(
                    type: WidgetEventRecorder::TYPE_STREAM_FAILED,
                    message: $e->getMessage(),
                    context: ['exception' => get_class($e)],
                    conversationId: $conversationId,
                );
                $this->emit('error', [
                    'code' => 'stream_failed',
                    'message' => 'The chat had to stop unexpectedly. Please refresh the page and try again.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $pageContext  Sanitized DOM snapshot from the widget.
     */
    private function streamTurn(string $conversationId, string $userMessage, ?array $pageContext = null): void
    {
        // Defang every layer of output buffering before we start writing
        // SSE bytes. cPanel + LSAPI + Octane each layer their own buffer;
        // without this an entire stream can sit invisible for 30s+ and
        // get killed by an idle-timeout proxy (Cloudflare, nginx).
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        // Send an immediate heartbeat so headers + the first byte hit
        // the client right away — proxies that buffer "until first byte"
        // release the response now, not after retrieval finishes.
        $this->emitHeartbeat();

        $started = (int) (microtime(true) * 1000);

        $conversation = Conversation::query()->withoutWorkspaceScope()->find($conversationId);
        if ($conversation === null) {
            \Log::warning('widget.conversation_missing', [
                'conversation_id' => $conversationId,
            ]);
            $this->emit('error', [
                'code' => 'conversation_not_found',
                'message' => 'Your chat session has expired. Please refresh the page to start a new chat.',
            ]);

            return;
        }
        $agent = $conversation->agent()->withoutWorkspaceScope()->first();
        if ($agent === null) {
            \Log::warning('widget.agent_missing', [
                'conversation_id' => $conversationId,
                'agent_id' => $conversation->agent_id,
            ]);
            $this->emit('error', [
                'code' => 'agent_not_found',
                'message' => 'This chat agent is no longer available. Please contact the site owner.',
            ]);

            return;
        }

        // Resolve the workspace's plan once for this turn and cache the
        // per-response token cap. We deliberately do this BEFORE the
        // first-token clock starts so the lookup never lands inside the
        // p95 TTFT window.
        $workspace = Workspace::query()->find($agent->workspace_id);
        $maxTokens = $workspace !== null
            ? ($this->billing->maxTokensFor($workspace) ?? 800)
            : 800;

        // Per-message quota gate. If the workspace's plan caps
        // monthly_messages and we're over, fail the request before
        // burning a single LLM token. The widget treats this as a soft
        // failure ("upgrade to keep chatting"); analytics-wise we still
        // record the visitor's question via DetectGapJob below so the
        // operator sees the demand they're losing to the cap.
        if ($workspace !== null && ! $this->billing->canSendMessage($workspace)) {
            \Log::warning('widget.message_quota_exceeded', [
                'workspace_id' => $workspace->id,
                'agent_id' => $agent->id,
            ]);
            $this->emit('error', [
                'code' => 'message_quota_exceeded',
                'message' => 'Monthly message limit reached for this workspace.',
            ]);

            return;
        }

        $userMessageId = (string) Str::uuid7();
        $assistantMessageId = (string) Str::uuid7();

        $this->emit('start', [
            'conversation_id' => $conversationId,
            'message_id' => $assistantMessageId,
        ]);

        // 0. Live human takeover: if a workspace member has claimed this
        // conversation, the bot stops auto-responding. The visitor's
        // message is still persisted (so the operator sees it via the
        // broadcast), but no LLM call is made.
        //
        // PLAN §7 ordering: emit('done') BEFORE the DB insert + broadcast.
        // The visitor's stream is then unblocked as soon as the SSE flush
        // reaches them; persistence + broadcast for the operator inbox
        // ride after the client has the close event.
        if ($conversation->claimed_by_user_id !== null) {
            $this->emit('done', [
                'text' => '',
                'citations' => [],
                'low_confidence' => false,
                'human_takeover' => true,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);

            $this->persistShortCircuitVisitorTurn($conversationId, $userMessageId, $userMessage);
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'takeover', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'note' => 'Operator owns the conversation; bot stayed silent by design.',
            ]);

            return;
        }

        // 0b. Human-requested-but-unclaimed: visitor clicked the
        // "Connect me with a human" pill but no operator has claimed yet.
        // Persist + broadcast the visitor message so operators see the
        // queue building, but DO NOT run the LLM — bot staying silent
        // here is the contract. Stream a single holding bubble so the
        // visitor's chat keeps progressing instead of looking frozen.
        if ($conversation->human_requested_at !== null) {
            $holding = "An operator is joining you in a moment. I'll keep this conversation here for them.";
            foreach ($this->tokenize($holding) as $tok) {
                $this->emit('token', ['t' => $tok]);
            }
            $this->emit('done', [
                'text' => $holding,
                'citations' => [],
                'low_confidence' => false,
                'human_pending' => true,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);

            // PLAN §7: visitor-message persist + operator broadcast move
            // below emit('done') so the SSE close reaches the visitor
            // without waiting on the write.
            $this->persistShortCircuitVisitorTurn($conversationId, $userMessageId, $userMessage);
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'human_pending', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'note' => 'Visitor is queued for a human; bot streamed the holding line only.',
            ]);

            return;
        }

        // 1. Curated answer short-circuit
        $curated = $this->curated->match($agent->id, $userMessage, $conversation->lang);
        if ($curated !== null) {
            foreach ($this->tokenize($curated) as $tok) {
                $this->emit('token', ['t' => $tok]);
            }
            $this->emit('done', [
                'text' => $curated,
                'citations' => [],
                'low_confidence' => false,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);
            $this->afterTurn($conversation, $userMessageId, $userMessage, $assistantMessageId, $curated, [], 1.0, $started, 'curated', (bool) $conversation->is_playground);
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'curated', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'answer' => mb_substr($curated, 0, 500),
                'note' => 'Admin-pinned curated answer matched; retrieval and LLM skipped.',
            ]);

            return;
        }

        // 1b. Workflow short-circuit: if a workflow run is paused on a
        // question step (or a fresh visitor message matches a keyword
        // trigger), the engine emits scripted bubbles + skips the LLM.
        // Falls through silently when nothing matches.
        $flowOutput = '';
        $handledByFlow = $this->workflows->handleTurn(
            $conversation,
            $userMessage,
            function (string $text) use (&$flowOutput): void {
                // Two newlines so consecutive bubbles read as separate
                // paragraphs in the visitor's chat — the widget renders
                // the assembled text as one assistant turn.
                $flowOutput .= ($flowOutput === '' ? '' : "\n\n").$text;
                foreach ($this->tokenize($text."\n") as $tok) {
                    $this->emit('token', ['t' => $tok]);
                }
            },
        );
        if ($handledByFlow) {
            $this->emit('done', [
                'text' => trim($flowOutput),
                'citations' => [],
                'low_confidence' => false,
                'flow' => true,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);
            $this->afterTurn(
                $conversation,
                $userMessageId,
                $userMessage,
                $assistantMessageId,
                trim($flowOutput),
                [],
                1.0,
                $started,
                'workflow',
                (bool) $conversation->is_playground,
            );
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'workflow', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'answer' => mb_substr(trim($flowOutput), 0, 500),
                'note' => 'Scripted workflow handled the turn; retrieval and LLM skipped.',
            ]);

            return;
        }

        // 1c. Human-intent short-circuit. When the visitor literally
        // asks for a human ("connect me to a human", "talk to a real
        // person", etc.) we skip retrieval + the LLM entirely and emit
        // the escalation_button block alongside a short confirmation.
        // This bypasses the unreliability of small Workers AI models
        // choosing to call the `escalate_to_human` tool — buyers
        // reported visitors typing "please connect me to a human" and
        // still getting an LLM answer because the model didn't pick up
        // the cue. The button itself reuses the existing
        // /api/v1/widget/request-human flow.
        if ($this->humanIntent->matches($userMessage)) {
            $reply = 'Connecting you with a human. Tap the button below to request a takeover — an agent will join the chat as soon as one is available.';
            foreach ($this->tokenize($reply) as $tok) {
                $this->emit('token', ['t' => $tok]);
            }
            $this->emit('block', [
                'type' => 'escalation_button',
                'payload' => [
                    'label' => 'Connect me with a human',
                    'reason' => 'Visitor explicitly requested a human.',
                ],
            ]);
            $this->emit('done', [
                'text' => $reply,
                'citations' => [],
                'low_confidence' => false,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);

            // PLAN §7: attribution stamp moves below emit('done'). Pre-fix
            // the visitor's stream was blocked by a `forceFill->save()` on
            // the conversation row even though nothing in the response
            // depends on the write succeeding. The next turn's tool loop
            // reads `escalation_offered_at` from the model attribute that
            // afterTurn() will refresh anyway.
            $attribution = (array) ($conversation->attribution ?? []);
            $attribution['escalation_offered_at'] = time();
            $conversation->forceFill(['attribution' => $attribution])->save();
            $this->afterTurn(
                $conversation,
                $userMessageId,
                $userMessage,
                $assistantMessageId,
                $reply,
                [],
                1.0,
                $started,
                'human-intent',
                (bool) $conversation->is_playground,
            );
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'human_shortcut', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'note' => 'Explicit human-request phrase matched; escalation button emitted, LLM skipped.',
            ]);

            return;
        }

        $timer = new HotPathTimer($conversationId, $agent->id);

        // 2. Retrieval — pass the current page URL so chunks crawled
        // from that exact page get a small score boost (visitor on
        // /products/red-pen asking "what's the price?" should prefer
        // red-pen chunks over generic FAQ chunks that mention price).
        //
        // Stage hint: surface "searching" to the widget so the typing
        // pill can say what we're actually doing instead of a generic
        // "thinking…". The retrieve phase typically lands at 200-600ms,
        // long enough that an unbranded indicator feels stale.
        $this->emit('stage', ['s' => 'searching']);
        $timer->mark('retrieve.start');
        $threshold = (float) ($agent->confidence_threshold ?? config('services.rag.confidence_threshold', 0.5));
        $currentPageUrl = is_array($pageContext) && isset($pageContext['url']) && is_string($pageContext['url'])
            ? $pageContext['url']
            : $conversation->page_url;
        // Standalone-query rewrite: a context-dependent message ("and in
        // which year?", "what is your address" mid-conversation) is condensed
        // into ONE self-contained search query — by a small LLM when enabled,
        // else by the deterministic heuristic. Self-contained questions skip
        // it (the gate), so most turns pay nothing. The prompt still receives
        // the raw message + full history; only the retrieval query changes.
        $priorHistory = Cache::get("conv:{$conversationId}:history", []);
        $timer->mark('rewrite.start');
        $retrievalQueryText = $this->queryRewriter->rewrite($userMessage, is_array($priorHistory) ? $priorHistory : [], $conversationId);
        $timer->mark('rewrite.end');
        $retrieval = $this->retriever->retrieve($agent->id, $retrievalQueryText, $threshold, 6, $currentPageUrl);
        $sources = $retrieval['chunks'];
        // Confidence: take the strongest signal we have. Retrieved chunks
        // give us a real similarity score; page_context is a verified
        // DOM snapshot we trust at ~0.85. Either one alone is enough
        // to ground the reply, so we pick the max — not the average,
        // not the chunk-count heuristic the old code used.
        $confidence = $this->computeConfidence($sources, $pageContext);
        $lowConfidence = $confidence < $threshold;
        $timer->mark('retrieve.end');

        // 3. History
        $historyKey = "conv:{$conversationId}:history";
        $history = Cache::get($historyKey, []);

        // 3.5 A/B resolver — sticky-assigns the visitor to a running
        // experiment's variant on first turn, persists
        // `conversation.variant_id`, and exposes any variant.config
        // overrides for the prompt builder. Buyer-reported (Lucian,
        // 2026-05-15): A/B UI existed but no runtime integration. For
        // `kind = persona` the variant.config['persona'] shallow-merges
        // over `agent->persona` so the LLM speaks under the variant's
        // name/tone. `kind = cta` and `kind = trigger` are recorded for
        // measurement but don't yet alter runtime behavior.
        $variant = $this->experiments->resolveForConversation($conversation);
        $personaOverride = null;
        if ($variant !== null && $variant->experiment?->kind === 'persona') {
            $personaOverride = (array) ($variant->config['persona'] ?? $variant->config);
        }

        // 4. Prompt
        $messages = $this->prompt->build(
            agent: $agent,
            userMessage: $userMessage,
            sources: array_map(fn ($s) => ['text' => $s['text'], 'url' => $s['url'], 'score' => $s['score']], $sources),
            history: $history,
            detectedLang: $conversation->lang,
            pageUrl: $conversation->page_url,
            pageContext: $pageContext,
            personaOverride: $personaOverride,
        );

        // 5. Stream — with optional tool-call pre-pass.
        //
        // If the agent has tools enabled, we run up to 3 hops of the
        // tool-resolution loop FIRST. Each hop is non-streaming. As soon
        // as the model returns content (no tool_calls), we exit the loop
        // and stream the final answer normally. The 99% no-tools case
        // hits the streaming path immediately with no extra latency.
        //
        // Stage hint: switch the typing pill to "Thinking…" while the
        // LLM composes the reply. First token arriving makes the
        // indicator moot — Bar swaps to the streaming bubble.
        $this->emit('stage', ['s' => 'thinking']);
        $buffer = '';
        $timer->mark('llm.start');
        $timer->mark('first_token.start');
        $sawFirstToken = false;
        $blocks = [];
        $routeDecision = null;
        $toolTrace = null;
        try {
            $enabledTools = $this->tools->forAgent($conversation->agent);

            // Fast router: decide whether this turn even needs the tool
            // check. ~90% of visitor questions are pure knowledge — for
            // those, runToolLoop's 1-3 full non-streaming completions
            // (5-15s each on Workers AI 70B) are pure wasted first-token
            // time. The router reuses the RAG query embedding, so its
            // own cost is <2ms and zero network. Kill switches: config
            // services.fast_router.enabled + per-agent
            // vertical_overrides['fast_router'].
            $timer->mark('route.start');
            $routeDecision = $this->router->route(
                $userMessage,
                $retrieval['query_embedding'] ?? null,
                $enabledTools,
                $conversation->agent,
            );
            $timer->mark('route.end');

            if ($enabledTools !== [] && ! $routeDecision->skipsToolLoop()) {
                // Each hop below is a FULL non-streaming completion —
                // on Workers AI 70B that's seconds per hop, all spent
                // before the visitor sees a single character. Timed
                // separately so the latency dashboard can prove when
                // this (not the streaming TTFT) is the real wait.
                $timer->mark('tool_loop.start');
                $loopOut = $this->runToolLoop(
                    messages: $messages,
                    agent: $conversation->agent,
                    enabledTools: $enabledTools,
                    maxHops: 3,
                    maxTokens: $maxTokens,
                    conversation: $conversation,
                );
                $timer->mark('tool_loop.end');
                $messages = $loopOut['messages'];
                $blocks = $loopOut['blocks'];
                $toolTrace = $loopOut['trace'] ?? [];
                // After tool resolution, we still stream the final answer
                // for TTFT. The model has all tool results in $messages now.
            }

            // Keep-alive immediately before the streaming call: the first
            // token can be 5-15s out on a cold provider, and after a tool
            // loop the last SSE event may already be 10s+ old. This resets
            // the widget's stale-stream timer right before that wait.
            $this->emitHeartbeat();

            foreach ($this->llm->streamChat($messages, ['max_tokens' => $maxTokens]) as $token) {
                if (! $sawFirstToken) {
                    $timer->mark('first_token.end');
                    $sawFirstToken = true;
                }
                $buffer .= $token;
                $this->emit('token', ['t' => $token]);
            }
        } catch (\Throwable $e) {
            // Visitor-facing path: never echo the raw provider envelope
            // (Workers AI 401 body, OpenAI quota body, etc.) into the
            // public widget. The operator sees the real error in the
            // application log / Sentry; the visitor sees a generic
            // "try again" line so they can retry without an instruction
            // manual on what CLOUDFLARE_API_TOKEN means.
            \Log::warning('widget.llm_failed', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversationId,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $this->emit('error', [
                'code' => 'llm_failed',
                'message' => 'We\'re having trouble responding right now. Please try again in a moment.',
            ]);
            $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'error', [
                'user_message' => mb_substr($userMessage, 0, 500),
                'error' => ['code' => 'llm_failed', 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 500)],
                'route' => $routeDecision === null ? null : ['route' => $routeDecision->route, 'reason' => $routeDecision->reason, 'score' => $routeDecision->topScore, 'matched_tools' => $routeDecision->matchedTools],
                'history_count' => count($history),
                'retrieval' => $this->traceRetrieval($retrieval, $sources),
                'tool_loop' => $toolTrace,
                'partial_text' => mb_substr($buffer, 0, 500),
            ]);

            return;
        }
        $timer->mark('llm.end');

        // Parse server-emitted block markers from the assembled text.
        // Phase 3 ecommerce/saas/marketing presets instruct the LLM to
        // emit <product/>, <pricing/>, <case-study/> XML markers when
        // recommending an item — we extract those here, emit them as
        // block events for the widget renderer, and strip the markers
        // from the visible text so visitors only see the rendered card.
        $parsed = $this->blockParser->extract($buffer);
        $buffer = $parsed['text'];
        foreach ($parsed['blocks'] as $b) {
            $blocks[] = $b;
        }

        // Emit any blocks the tool loop or inline parser produced so the
        // widget can render them inline with the final assistant text.
        foreach ($blocks as $block) {
            $this->emit('block', $block);
        }

        // Defer the `escalation_offered_at` stamp until after emit('done')
        // so the visitor's stream closes without waiting on a DB write.
        // Computed here so we can stash the flag before falling through
        // into the response section; the actual save happens at the very
        // bottom of the handler. PLAN §7 ordering.
        $hasEscalation = false;
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'escalation_button') {
                $hasEscalation = true;

                break;
            }
        }

        // 6. Citations + CTA + lead-form heuristic
        // Mirror PromptBuilder's source[0] = page_context so [1] in the
        // LLM reply maps back to the page URL the visitor was on. Without
        // this, replies that cite page-context-only data showed [N] as
        // dead text in the widget (no clickable link, no Sources footer).
        $sourcesForCitations = $sources;
        if ($pageContext !== null) {
            array_unshift($sourcesForCitations, [
                'text' => '',
                'url' => is_string($pageContext['url'] ?? null) ? $pageContext['url'] : null,
                'score' => 1.0,
            ]);
        }
        $citations = $this->extractCitations($buffer, $sourcesForCitations);
        // Multi-CTA emission. Pre-fix we returned a single CTA from
        // `select()` and the widget rendered one card — customers who
        // configured 3 CTAs only ever saw the highest-priority one.
        // Now we return every matching rule (capped at MAX_CTAS) in
        // priority order so the widget can stack them. Keep `cta`
        // (singular) populated with the first match so legacy widget
        // bundles + JSON-fallback clients still render at least one.
        $ctas = $this->ctaSelector->selectAll($conversation, $buffer);
        $cta = $ctas[0] ?? null;
        // Smart lead capture — keyword + engagement on the VISITOR'S
        // message rather than scanning the assistant's reply. See
        // LeadIntentDetector for the heuristics.
        $leadPrompt = $this->leadIntent->shouldPrompt($conversation, $userMessage);

        $this->emit('done', [
            'text' => $buffer,
            'citations' => $citations,
            'cta' => $cta,
            'ctas' => $ctas,
            'lead_prompt' => $leadPrompt,
            'low_confidence' => $lowConfidence,
            'latency_ms' => (int) (microtime(true) * 1000) - $started,
        ]);

        // 7. After-turn jobs
        $this->afterTurn(
            conversation: $conversation,
            userMessageId: $userMessageId,
            userMessage: $userMessage,
            assistantMessageId: $assistantMessageId,
            assistantText: $buffer,
            citations: $citations,
            confidence: $confidence,
            startedMs: $started,
            model: 'workers-ai',
            isPlayground: (bool) $conversation->is_playground,
            lowConfidence: $lowConfidence,
        );

        // C9: persist token-level usage for this turn. Bookkeeping only —
        // never gates the stream. Wrapped in a try/catch so a logging
        // mishap can never break the visitor-visible chat.
        try {
            $latencyMs = (int) (microtime(true) * 1000) - $started;
            // Approximate via the BPE heuristic in TokenEstimator. The
            // upstream providers don't currently surface response.usage
            // through our streaming clients; this estimator brings us
            // within ~15% of real tokenizer counts and is calibrated
            // against the rates in TokenPricing.
            PersistUsageJob::dispatch(
                $conversation->id,
                $conversation->agent_id,
                $assistantMessageId,
                [[
                    'provider' => $this->llmProviderName(),
                    'model' => (string) config('services.cloudflare.chat_model', 'workers-ai'),
                    'purpose' => 'chat',
                    'tokens_in' => TokenEstimator::estimate($userMessage),
                    'tokens_out' => TokenEstimator::estimate($buffer),
                    'latency_ms' => $latencyMs,
                ]],
            );
        } catch (\Throwable $e) {
            \Log::warning('usage.persist.dispatch_failed', ['error' => $e->getMessage()]);
        }

        // 8. Update history
        $history[] = ['role' => 'user', 'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $buffer];
        if (count($history) > 12) {
            $history = array_slice($history, -12);
        }
        Cache::put($historyKey, $history, now()->addHours(2));

        // Deferred escalation stamp. Moved here from before emit('done')
        // so the visitor's stream closes without waiting on a DB write.
        // Next turn's tool loop reads this back when the conversation
        // is rehydrated from DB on the next request.
        if ($hasEscalation) {
            $attribution = (array) ($conversation->attribution ?? []);
            $attribution['escalation_offered_at'] = time();
            $conversation->forceFill(['attribution' => $attribution])->save();
        }

        // 9. Emit hot-path timing (one structured log line per turn).
        // retrieve_timings breaks retrieve_ms into embed / ann / hydrate
        // / rerank (or cache_hit) so the latency dashboard can point at
        // the exact slow round-trip.
        $timer->emit([
            'sources' => count($sources),
            'low_confidence' => $lowConfidence,
            'tokens_out' => TokenEstimator::estimate($buffer),
            'is_playground' => (bool) $conversation->is_playground,
            'retrieve_timings' => $retrieval['timings'] ?? [],
            'route' => $routeDecision?->route,
            'route_reason' => $routeDecision?->reason,
            'route_score' => $routeDecision?->topScore,
        ]);

        // 10. Behind-the-scenes trace for the conversation debugger.
        // Dispatched dead last — every visitor-facing byte is already out.
        $this->persistTrace($agent->id, $conversationId, $assistantMessageId, 'llm', [
            'user_message' => mb_substr($userMessage, 0, 500),
            'answer' => mb_substr($buffer, 0, 500),
            'route' => $routeDecision === null ? null : ['route' => $routeDecision->route, 'reason' => $routeDecision->reason, 'score' => $routeDecision->topScore, 'matched_tools' => $routeDecision->matchedTools],
            'history_count' => count($history) - 2, // pre-turn context size; this turn's pair was just appended
            'retrieval' => $this->traceRetrieval($retrieval, $sources),
            'low_confidence' => $lowConfidence,
            'confidence' => round($confidence, 4),
            'tool_loop' => $toolTrace,
            'blocks' => array_values(array_map(static fn ($b) => (string) ($b['type'] ?? ''), $blocks)),
            'ctas' => count($ctas),
            'lead_prompt' => $leadPrompt,
            'latency_ms' => (int) (microtime(true) * 1000) - $started,
            'tokens_out' => TokenEstimator::estimate($buffer),
            'model' => $this->llmProviderName().':'.(string) config('services.cloudflare.chat_model', 'workers-ai'),
        ]);
    }

    /**
     * Persist the visitor's message + broadcast for the operator UI in
     * a short-circuit path (human takeover / human pending). Runs after
     * the SSE stream has closed so the response is unblocked.
     */
    private function persistShortCircuitVisitorTurn(
        string $conversationId,
        string $userMessageId,
        string $userMessage,
    ): void {
        try {
            Message::create([
                'id' => $userMessageId,
                'conversation_id' => $conversationId,
                'role' => 'user',
                'content' => $userMessage,
                'citations' => [],
                'confidence' => null,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
                'model' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Visitor message persist failed in short-circuit path', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            AgentReplyPostedEvent::dispatch(
                $conversationId,
                $userMessageId,
                $userMessage,
                'visitor',
            );
        } catch (\Throwable $e) {
            Log::warning('Broadcast dispatch failed in short-circuit path', [
                'event' => AgentReplyPostedEvent::class,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compact retrieval summary for the trace payload: per-chunk URL +
     * scores + an 80-char snippet. Truncated hard — traces are forensic
     * breadcrumbs, not a copy of the knowledge base.
     *
     * @param  array<string, mixed>  $retrieval
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function traceRetrieval(array $retrieval, array $sources): array
    {
        $timings = (array) ($retrieval['timings'] ?? []);

        return [
            'cache_hit' => (bool) ($timings['cache_hit'] ?? false),
            'rerank_skipped' => (bool) ($timings['rerank_skipped'] ?? false),
            'chunks' => array_map(static fn (array $c): array => [
                'url' => $c['url'] ?? null,
                'score' => round((float) ($c['score'] ?? 0), 4),
                'rerank_score' => isset($c['rerank_score']) ? round((float) $c['rerank_score'], 4) : null,
                'snippet' => mb_substr((string) ($c['text'] ?? ''), 0, 80),
            ], array_slice($sources, 0, 6)),
        ];
    }

    /**
     * Best-effort dispatch of the turn's behind-the-scenes trace for the
     * super-admin conversation debugger. Always called AFTER the SSE
     * stream has its terminal event — never adds hot-path latency. A
     * trace failure must never break the visitor's turn.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistTrace(
        string $agentId,
        string $conversationId,
        ?string $messageId,
        string $kind,
        array $payload,
    ): void {
        if (! (bool) config('services.turn_traces.enabled', true)) {
            return;
        }

        try {
            PersistTurnTraceJob::dispatch($agentId, $conversationId, $messageId, $kind, $payload);
        } catch (\Throwable $e) {
            \Log::warning('turn_trace.dispatch_failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Runs the tool-resolution loop. Each hop calls the LLM
     * non-streaming with the tools array; if the model wants to invoke
     * tools, we execute each one, append `tool` role messages with the
     * results, and loop. Stops as soon as the model returns content
     * (no tool_calls) or when the hop limit is hit.
     *
     * Emits a `tool_call` SSE event for every tool invocation so the
     * widget can show a "running …" pill while the visitor waits.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, Tool>  $enabledTools
     * @return array{messages: array<int, array<string, mixed>>, blocks: array<int, array{type: string, payload: array}>}
     */
    private function runToolLoop(array $messages, Agent $agent, array $enabledTools, int $maxHops, int $maxTokens = 800, ?Conversation $conversation = null): array
    {
        $toolPayload = $this->tools->openAiToolsFor($agent);

        // MCP tools the admin has enabled for this agent. The registry
        // caches the result for 60s so this is a cheap call. Append to
        // the OpenAI tools[] payload using the namespaced name (e.g.
        // "linear.search_issues") as the function name. Routing on the
        // call-back side uses the dot prefix to distinguish from
        // built-in tools (which never contain a dot).
        $mcpTools = config('features.mcp_enabled', true)
            ? $this->mcpRegistry->grantedToolsForAgent($agent)
            : collect();
        $mcpToolsByName = [];
        foreach ($mcpTools as $mcpTool) {
            $mcpToolsByName[$mcpTool->namespaced_name] = $mcpTool;
            $toolPayload[] = [
                'type' => 'function',
                'function' => [
                    'name' => $mcpTool->namespaced_name,
                    'description' => $mcpTool->is_destructive
                        ? '[external integration · may modify external system] '.((string) $mcpTool->description)
                        : '[external integration] '.((string) $mcpTool->description),
                    'parameters' => $mcpTool->input_schema ?: ['type' => 'object'],
                ],
            ];
        }

        $blocks = [];
        $hopTrace = [];
        // Track tool invocations this turn so smaller / open-source models
        // (Llama 3.3 on Workers AI is the primary culprit) cannot
        // infinite-loop the same tool with the same args. Once we see a
        // duplicate, we stop the loop and let the streaming path produce
        // the final answer.
        $invokedKeys = [];
        $emittedBlockKeys = [];

        for ($hop = 0; $hop < $maxHops; $hop++) {
            // Each hop is a NON-streaming completion (5-15s on Workers AI
            // 70B). Without a keep-alive the SSE connection goes silent for
            // the whole call, and the widget's stale-stream guard aborts the
            // fetch ("BodyStreamBuffer was aborted") on slow / multi-hop
            // tool turns. A heartbeat before each hop keeps the gap under one
            // completion. Hot-path-safe: a ':' comment + flush, no DB/HTTP.
            $this->emitHeartbeat();
            $response = $this->llm->chatWithTools($messages, $toolPayload, ['max_tokens' => $maxTokens]);
            $toolCalls = $response['tool_calls'] ?? null;
            if (! is_array($toolCalls) || $toolCalls === []) {
                // Model wants to give a final answer — break out and let
                // the streaming path produce it for TTFT.
                $hopTrace[] = ['hop' => $hop + 1, 'outcome' => 'final_answer', 'calls' => []];

                break;
            }

            // Drop any tool the model has already invoked this turn. We
            // dedupe by NAME (not name+args) because every Phase 2 tool
            // is stateless / one-shot per turn — escalating twice with
            // a different "reason" string is still one escalation.
            // Llama 3.3 reliably loops calling the same tool with
            // slightly different args; this guard is what stops that.
            $freshCalls = [];
            $droppedDuplicates = [];
            foreach ($toolCalls as $tc) {
                $name = (string) ($tc['name'] ?? '');
                if (isset($invokedKeys[$name])) {
                    $droppedDuplicates[] = $name;

                    continue;
                }
                $invokedKeys[$name] = true;
                $freshCalls[] = $tc;
            }
            if ($freshCalls === []) {
                $hopTrace[] = ['hop' => $hop + 1, 'outcome' => 'all_calls_were_duplicates', 'calls' => [], 'dropped_duplicates' => $droppedDuplicates];

                break;
            }
            $toolCalls = $freshCalls;
            $hopCalls = [];

            // Append the assistant turn that requested the tool calls.
            // OpenAI's protocol requires this turn to be present before
            // the corresponding `tool` role messages. Cloudflare Workers
            // AI's strict schema rejects `content: null` here — it wants a
            // string, even when tool_calls is present. Send an empty
            // string for cross-provider compatibility (OpenAI accepts it
            // too).
            $messages[] = [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => array_map(static fn ($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => $tc['arguments'],
                    ],
                ], $toolCalls),
            ];

            foreach ($toolCalls as $tc) {
                $name = (string) ($tc['name'] ?? '');
                $args = json_decode((string) ($tc['arguments'] ?? '{}'), true);
                if (! is_array($args)) {
                    $args = [];
                }

                // MCP tools are namespaced "{server_label}.{tool_name}".
                // Built-in tools (escalate_to_human etc.) have no dot.
                if (isset($mcpToolsByName[$name])) {
                    $this->emit('tool_call', ['name' => $name, 'args' => $args, 'origin' => 'mcp']);
                    $execution = $this->mcpExecutor->execute(
                        agent: $agent,
                        conversation: $conversation,
                        namespacedName: $name,
                        rawArgs: $args,
                    );
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'name' => $name,
                        'content' => $execution['wrapped'],
                    ];

                    continue;
                }

                $tool = $this->tools->get($name);
                if ($tool === null) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'name' => $name,
                        'content' => json_encode(['error' => "Unknown tool: {$name}"]) ?: '{}',
                    ];
                    $hopCalls[] = ['name' => $name, 'args' => $args, 'error' => 'unknown_tool'];

                    continue;
                }

                // Inform the widget the tool is running.
                $this->emit('tool_call', ['name' => $name, 'args' => $args]);

                try {
                    $out = $tool->execute($args, $agent, ['conversation' => $conversation ?? null]);
                } catch (\Throwable $e) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'name' => $name,
                        'content' => json_encode(['error' => $e->getMessage()]) ?: '{}',
                    ];
                    $hopCalls[] = ['name' => $name, 'args' => $args, 'error' => mb_substr($e->getMessage(), 0, 300)];

                    continue;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($tc['id'] ?? ''),
                    'name' => $name,
                    'content' => json_encode($out['result'] ?? []) ?: '{}',
                ];
                $hopCalls[] = [
                    'name' => $name,
                    'args' => $args,
                    'result' => mb_substr((string) (json_encode($out['result'] ?? []) ?: '{}'), 0, 500),
                    'block' => isset($out['block']['type']) ? (string) $out['block']['type'] : null,
                ];

                if (isset($out['block']) && is_array($out['block'])) {
                    // Conversation-level dedupe for escalation_button.
                    // Small Workers AI models call `escalate_to_human`
                    // on nearly every turn even after the button has
                    // already been offered, so the widget rendered the
                    // same "Connect me with a human" button on every
                    // reply. Suppress when:
                    //   - the conversation already offered one in the
                    //     last 30 minutes, AND
                    //   - the visitor has not clicked it yet
                    //     (`human_requested_at` still null).
                    $blockType = (string) ($out['block']['type'] ?? '');
                    if ($blockType === 'escalation_button' && $conversation !== null) {
                        $attribution = (array) ($conversation->attribution ?? []);
                        $offered = $attribution['escalation_offered_at'] ?? null;
                        if ($offered !== null && $conversation->human_requested_at === null) {
                            $offeredAt = is_numeric($offered)
                                ? (int) $offered
                                : (int) (strtotime((string) $offered) ?: 0);
                            if ($offeredAt > 0 && (time() - $offeredAt) < 1800) {
                                continue;
                            }
                        }
                    }

                    // Dedupe identical blocks across hops within the
                    // same turn (e.g. an escalation_button emitted
                    // twice with the same payload). The widget would
                    // otherwise render N copies of the same button.
                    $blockKey = ($out['block']['type'] ?? '').':'.md5((string) json_encode($out['block']['payload'] ?? []));
                    if (! isset($emittedBlockKeys[$blockKey])) {
                        $emittedBlockKeys[$blockKey] = true;
                        $blocks[] = $out['block'];
                    }
                }
            }

            $hopTrace[] = [
                'hop' => $hop + 1,
                'outcome' => 'executed',
                'calls' => $hopCalls,
                'dropped_duplicates' => $droppedDuplicates,
            ];
        }

        return ['messages' => $messages, 'blocks' => $blocks, 'trace' => $hopTrace];
    }

    /**
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
        bool $lowConfidence = false,
    ): void {
        $latency = (int) (microtime(true) * 1000) - $startedMs;

        // dispatchSync: message persistence is the foundation of the
        // conversation log, history resume, and citation surfaces. A
        // misconfigured queue worker on production was silently losing
        // every visitor turn → "0 msgs" everywhere. Cheap DB inserts;
        // runs after emit('done') so the visitor's stream is unaffected.
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
            // Record the gap INLINE, not on the analytics queue. Content
            // Gaps are the visible surface of the self-improvement loop;
            // a production worker started without the `analytics` queue in
            // its list would strand every DetectGapJob unrun, so deflected
            // questions never reach the board. Same reasoning as
            // PersistTurnJob above — after-stream persistence too important
            // to silently depend on queue config. Runs after emit('done')
            // so the visitor's stream is unaffected; the SMTP notification
            // inside DetectGapJob stays ShouldQueue.
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

    /**
     * Best-effort provider name for usage logging. Resolves from env
     * with the same priority order as AppServiceProvider's LLM binder.
     */
    private function llmProviderName(): string
    {
        $forced = (string) config('services.llm.provider', '');
        if ($forced !== '') {
            return $forced;
        }
        if ((string) config('services.cloudflare.account_id', '') !== ''
            && (string) config('services.cloudflare.api_token', '') !== ''
        ) {
            return 'cloudflare';
        }
        if ((string) config('services.openai.key', '') !== '') {
            return 'openai';
        }
        if ((string) config('services.openrouter.key', '') !== '') {
            return 'openrouter';
        }

        return 'fake';
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

    private function tokenize(string $text): iterable
    {
        $tokens = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($tokens as $t) {
            yield $t;
        }
    }

    private function emit(string $event, array $payload): void
    {
        $line = "event: {$event}\n".'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
        $this->writeRaw($line);
    }

    /**
     * SSE comment ("heartbeat") line. Browsers ignore it but it forces
     * a flush through proxies (Cloudflare, nginx, cPanel FastCGI) that
     * otherwise buffer responses with no body for ~30s and silently
     * drop the connection. Sent at the start of every turn so the
     * client's stale-stream detector clock starts ticking from a
     * known-good baseline.
     */
    private function emitHeartbeat(): void
    {
        $this->writeRaw(': heartbeat '.microtime(true)."\n\n");
    }

    private function writeRaw(string $bytes): void
    {
        echo $bytes;
        // Skip aggressive ob drain during tests — Pest's runner manages
        // its own output buffer and drains during teardown. In real HTTP
        // requests on cPanel / LSAPI / Octane there are typically 2–3
        // buffers stacked which a single ob_flush() doesn't pierce.
        if (function_exists('ob_get_level') && ! app()->runningUnitTests()) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        } elseif (function_exists('ob_get_level') && ob_get_level() > 0) {
            // Inside tests: gentle flush only, no buffer destruction.
            @ob_flush();
        }
        flush();
    }

    /**
     * Defang the page context payload before it goes into the prompt.
     * Caps total payload size and string lengths so a malicious page
     * can't blow our token budget or smuggle 50KB of "instructions".
     *
     * @return array<string, mixed>|null
     */
    private function sanitizePageContext(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        // Hard cap on serialized size — nothing legitimately useful needs
        // more than this, and it bounds the worst-case prompt cost.
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > 8192) {
            return null;
        }

        $allowedKeys = ['url', 'title', 'description', 'og', 'twitter', 'json_ld', 'h1', 'h2', 'visible_text'];
        $clean = [];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $clean[$key] = $raw[$key];
        }

        // Canonicalize the URL so /page, /page/, /page#x, /page?utm=...
        // all share one citation identity (matches what AutoIndexPageVisit
        // stored as Document.url, so citations align with Knowledge entries).
        if (isset($clean['url']) && is_string($clean['url'])) {
            $canonical = CanonicalUrl::for($clean['url']);
            if ($canonical !== null) {
                $clean['url'] = $canonical;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * How sure are we this answer is grounded?
     *
     * Picks the strongest grounding signal available:
     *  - Retrieved chunks → max similarity score (0..1)
     *  - Page context (verified DOM snapshot) → baseline 0.85
     *  - Neither → 0.3 (LLM is on its own)
     *
     * @param  array<int, array{score?: float}>  $retrievedSources
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
            // No retrieval, no page context — the LLM has nothing to cite.
            return 0.3;
        }

        return min(1.0, $best);
    }

    private function errorStream(string $code, int $status): StreamedResponse
    {
        $origin = request()->headers->get('Origin') ?? '*';

        return new StreamedResponse(function () use ($code) {
            echo "event: error\n".'data: '.json_encode(['code' => $code])."\n\n";
            flush();
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'false',
        ]);
    }
}
