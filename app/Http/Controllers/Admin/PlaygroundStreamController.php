<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Services\I18n\LocaleResolver;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Rag\CuratedAnswerMatcher;
use App\Services\Rag\PromptBuilder;
use App\Services\Rag\Retriever;
use App\Services\Tools\Contracts\Tool;
use App\Services\Tools\ToolRegistry;
use App\Services\Triggers\CtaSelector;
use App\Services\Triggers\LeadIntentDetector;
use App\Services\Vertical\VerticalPresets;
use App\Services\Widget\InlineBlockParser;
use App\Services\Workflows\WorkflowEngine;
use App\Support\CanonicalUrl;
use App\Support\LlmErrorPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-side streaming playground. Mirrors the visitor hot path
 * (MessageStreamController) but:
 *
 * - Auth via session + AgentPolicy::update — no widget JWT.
 * - Accepts override flags in the body (`site_type_override`,
 *   `language_override`, `page_context`) so admins can simulate any
 *   vertical or visitor page without mutating the agent's persisted
 *   columns.
 * - Emits two extra debug events alongside the standard stream:
 *   `retrieval` (sources + scores) and `prompt` (system message,
 *   resolved vertical, history depth) so the right-pane diagnostics
 *   tab can show "why did the agent answer this way?".
 *
 * NOT on the visitor hot path — the 1s p95 TTFT contract from PLAN §7
 * doesn't bind here. We stream as fast as we can but trade a bit of
 * latency for the richer diagnostic surface.
 */
class PlaygroundStreamController
{
    public function __construct(
        private Retriever $retriever,
        private PromptBuilder $prompt,
        private CuratedAnswerMatcher $curated,
        private OpenAiClient $llm,
        private ToolRegistry $tools,
        private InlineBlockParser $blockParser,
        private CtaSelector $ctaSelector,
        private LeadIntentDetector $leadIntent,
        private WorkflowEngine $workflows,
    ) {}

    public function __invoke(Request $request, Agent $agent): StreamedResponse
    {
        $request->user()?->can('update', $agent) || abort(403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string'],
            'site_type_override' => ['nullable', 'string', Rule::in(VerticalPresets::SLUGS)],
            // Auto-discovered locales (LocaleResolver::supported() scans
            // lang/*.json). Hardcoding the legacy 8-locale list here
            // 422'd every Playground request whenever the agent's
            // language_default was set to any other locale — the
            // symptom was "Server closed the stream without a done
            // event" on the admin side because the SSE socket closed
            // before streaming started.
            'language_override' => ['nullable', 'string', Rule::in(app(LocaleResolver::class)->supported())],
            'page_context' => ['nullable', 'array'],
            // Optional sandbox-only shopper attestation. Lets the admin
            // rehearse a logged-in-WooCommerce-customer turn without
            // standing up a real WP install. Mirrors what InitController
            // writes when the WP plugin's signed `data-shopper-token`
            // verifies. Never an auth path for production traffic.
            'shopper_simulation' => ['nullable', 'array'],
            'shopper_simulation.wp_user_id' => ['nullable', 'integer', 'min:1'],
            'shopper_simulation.email' => ['nullable', 'email', 'max:255'],
        ]);

        $conversation = $this->resolveConversation($agent, $data['conversation_id'] ?? null);
        $userMessage = $data['message'];
        $pageContext = $this->sanitizePageContext($data['page_context'] ?? null);
        $siteTypeOverride = $data['site_type_override'] ?? null;
        $languageOverride = $data['language_override'] ?? null;

        $this->applyShopperSimulation($conversation, $data['shopper_simulation'] ?? null);

        return new StreamedResponse(function () use ($conversation, $agent, $userMessage, $pageContext, $siteTypeOverride, $languageOverride) {
            // Under PHP-FPM / mod_php / LiteSpeed the default
            // max_execution_time counts against this closure and kills a
            // slow LLM turn mid-stream; Octane/CLI have no limit. The @
            // tolerates hosts that disable set_time_limit.
            @set_time_limit(0);

            $this->streamTurn($conversation, $agent, $userMessage, $pageContext, $siteTypeOverride, $languageOverride);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $pageContext
     */
    private function streamTurn(
        Conversation $conversation,
        Agent $agent,
        string $userMessage,
        ?array $pageContext,
        ?string $siteTypeOverride,
        ?string $languageOverride,
    ): void {
        try {
            $this->runStream($conversation, $agent, $userMessage, $pageContext, $siteTypeOverride, $languageOverride);
        } catch (\Throwable $e) {
            // Anything that escapes the inner pipeline (retriever, prompt
            // builder, workflow engine, citation/CTA helpers — none of
            // which were originally wrapped) lands here. Without this
            // safety net the StreamedResponse callback would unwind on
            // the throw and the socket would close before any `done` or
            // `error` event was emitted — the client then renders
            // "Server closed the stream without a done event."
            \Log::error('playground.stream.failed', [
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // Best-effort emit; if the socket is gone, the echo is a
            // no-op. The doneless close still happens but at least the
            // server log now points at the real failure.
            $this->emit('error', [
                'code' => 'pipeline_failed',
                // Route through LlmErrorPresenter so a raw Workers AI
                // 401 envelope ("{success:false, error:[{code:2009...}]}")
                // becomes an actionable "rotate CLOUDFLARE_API_TOKEN"
                // line instead of leaking into the playground chat
                // bubble. Mirrors what the /settings/system probes do.
                'message' => LlmErrorPresenter::present($e->getMessage()) ?? $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $pageContext
     */
    private function runStream(
        Conversation $conversation,
        Agent $agent,
        string $userMessage,
        ?array $pageContext,
        ?string $siteTypeOverride,
        ?string $languageOverride,
    ): void {
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        $this->writeRaw(': heartbeat '.microtime(true)."\n\n");

        $started = (int) (microtime(true) * 1000);
        $assistantMessageId = (string) Str::uuid7();

        $this->emit('start', [
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessageId,
        ]);

        // 1. Curated answer short-circuit. The override flags don't apply
        // here — curated answers are the operator's pinned reply, not
        // model output.
        $detectedLang = $languageOverride ?? $conversation->lang;
        $curated = $this->curated->match($agent->id, $userMessage, $detectedLang);
        if ($curated !== null) {
            foreach ($this->tokenize($curated) as $tok) {
                $this->emit('token', ['t' => $tok]);
            }
            $this->emit('done', [
                'text' => $curated,
                'citations' => [],
                'low_confidence' => false,
                'curated' => true,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
            ]);

            return;
        }

        // 1b. Workflow short-circuit — same shape as the widget hot
        // path. If the agent has a workflow whose trigger matches this
        // visitor message (or a paused run on a question step), the
        // engine emits scripted bubbles and skips the LLM. Without this,
        // the playground couldn't test workflow flows, which would be a
        // false experience: a real visitor would hit the workflow,
        // playground admin wouldn't.
        $flowOutput = '';
        $handledByFlow = $this->workflows->handleTurn(
            $conversation,
            $userMessage,
            function (string $text) use (&$flowOutput): void {
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

            return;
        }

        // 2. Retrieval — same call shape as the widget. We surface the
        // raw chunks (with both ANN and rerank scores) on the SSE stream
        // so the diagnostics pane can show the operator exactly what
        // the LLM was given.
        $threshold = (float) ($agent->confidence_threshold ?? 0.5);
        $currentPageUrl = is_array($pageContext) && isset($pageContext['url']) && is_string($pageContext['url'])
            ? $pageContext['url']
            : $conversation->page_url;
        $retrieval = $this->retriever->retrieve($agent->id, $userMessage, $threshold, 6, $currentPageUrl);
        $sources = $retrieval['chunks'];
        $confidence = $this->computeConfidence($sources, $pageContext);
        $lowConfidence = $confidence < $threshold;

        $this->emit('retrieval', [
            'sources' => array_map(static fn (array $s) => [
                'url' => $s['url'] ?? null,
                'score' => round((float) ($s['score'] ?? 0), 4),
                'rerank_score' => isset($s['rerank_score']) ? round((float) $s['rerank_score'], 4) : null,
                'snippet' => mb_substr((string) ($s['text'] ?? ''), 0, 240),
            ], $sources),
            'confidence' => round($confidence, 4),
            'threshold' => $threshold,
            'page_url_used' => $currentPageUrl,
        ]);

        // 3. History — same Redis key the widget uses, so playground
        // conversations resume cleanly across page reloads.
        $historyKey = "conv:{$conversation->id}:history";
        $history = Cache::get($historyKey, []);

        // 4. Prompt assembly with the override applied.
        $messages = $this->prompt->build(
            agent: $agent,
            userMessage: $userMessage,
            sources: array_map(static fn ($s) => ['text' => $s['text'], 'url' => $s['url'], 'score' => $s['score']], $sources),
            history: $history,
            detectedLang: $detectedLang,
            pageUrl: $conversation->page_url,
            pageContext: $pageContext,
            siteTypeOverride: $siteTypeOverride,
        );

        $effectiveSiteType = $siteTypeOverride ?? $agent->site_type;
        $this->emit('prompt', [
            'system' => (string) ($messages[0]['content'] ?? ''),
            'history_count' => count($history),
            'vertical' => $effectiveSiteType,
            'language' => $detectedLang,
        ]);

        // 5. Tool loop (if the agent has any tools enabled). We resolve
        // tools from the EFFECTIVE site type — when an admin overrides
        // to ecommerce, escalate_to_human shouldn't show up unless
        // they're testing help_center. Build a transient agent shadow
        // with the override applied just for the registry call.
        $blocks = [];
        try {
            $effectiveAgent = $this->shadowAgent($agent, $effectiveSiteType);
            $enabledTools = $this->tools->forAgent($effectiveAgent);
            if ($enabledTools !== []) {
                $loopOut = $this->runToolLoop(
                    messages: $messages,
                    agent: $effectiveAgent,
                    conversation: $conversation,
                    enabledTools: $enabledTools,
                    maxHops: 3,
                );
                $messages = $loopOut['messages'];
                $blocks = $loopOut['blocks'];
            }

            $buffer = '';
            foreach ($this->llm->streamChat($messages, ['max_tokens' => 800]) as $token) {
                $buffer .= $token;
                $this->emit('token', ['t' => $token]);
            }
        } catch (\Throwable $e) {
            $this->emit('error', [
                'code' => 'llm_failed',
                'message' => LlmErrorPresenter::present($e->getMessage()) ?? $e->getMessage(),
            ]);

            return;
        }

        // 6. Inline block parsing (strips <product/> etc. from text).
        $parsed = $this->blockParser->extract($buffer);
        $buffer = $parsed['text'];
        foreach ($parsed['blocks'] as $b) {
            $blocks[] = $b;
        }
        foreach ($blocks as $block) {
            $this->emit('block', $block);
        }

        // 7. Citations with page-context aware indexing (mirror the
        // widget controller's behaviour so [1] in the reply lines up).
        $sourcesForCitations = $sources;
        if ($pageContext !== null) {
            array_unshift($sourcesForCitations, [
                'text' => '',
                'url' => is_string($pageContext['url'] ?? null) ? $pageContext['url'] : null,
                'score' => 1.0,
            ]);
        }
        $citations = $this->extractCitations($buffer, $sourcesForCitations);

        // CTA + lead-prompt — same heuristics the widget uses, surfaced
        // here so the admin sees exactly what a visitor would see after
        // a turn. Without these, the playground silently drops two
        // visitor-facing UI affordances and the admin can't QA them.
        $cta = $this->ctaSelector->select($conversation, $buffer);
        $leadPrompt = $this->leadIntent->shouldPrompt($conversation, $userMessage);

        $this->emit('done', [
            'text' => $buffer,
            'citations' => $citations,
            'cta' => $cta,
            'lead_prompt' => $leadPrompt,
            'low_confidence' => $lowConfidence,
            'confidence' => round($confidence, 4),
            'latency_ms' => (int) (microtime(true) * 1000) - $started,
        ]);

        // 8. Update Redis history (capped at 6 turns) so a follow-up
        // playground question keeps context. Playground turns are
        // intentionally NOT persisted to the messages table — admins
        // shouldn't see test runs in their inbox or analytics.
        $history[] = ['role' => 'user', 'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $buffer];
        if (count($history) > 12) {
            $history = array_slice($history, -12);
        }
        Cache::put($historyKey, $history, now()->addHours(2));
    }

    /**
     * Find or create the playground conversation. Conversations created
     * here are flagged `is_playground=true` so analytics + billing skip
     * them.
     */
    private function resolveConversation(Agent $agent, ?string $conversationId): Conversation
    {
        if ($conversationId !== null) {
            $existing = Conversation::query()
                ->where('id', $conversationId)
                ->where('agent_id', $agent->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $visitor = Visitor::create([
            'agent_id' => $agent->id,
            'anonymous_id' => 'pg_'.Str::random(16),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return Conversation::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'page_url' => '/playground',
            'started_at' => now(),
            'is_playground' => true,
        ]);
    }

    /**
     * Build a transient in-memory agent with the site_type override
     * applied. Used purely for tool-registry resolution; never saved.
     * Avoids a `clone` because we want the SAME persistent properties
     * (workspace, persona, vertical_overrides) — only site_type changes.
     */
    private function shadowAgent(Agent $agent, ?string $effectiveSiteType): Agent
    {
        if ($effectiveSiteType === $agent->site_type) {
            return $agent;
        }

        $shadow = clone $agent;
        $shadow->site_type = $effectiveSiteType;

        return $shadow;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, Tool>  $enabledTools
     * @return array{messages: array<int, array<string, mixed>>, blocks: array<int, array{type: string, payload: array}>}
     */
    private function runToolLoop(array $messages, Agent $agent, Conversation $conversation, array $enabledTools, int $maxHops): array
    {
        $toolPayload = $this->tools->openAiToolsFor($agent);
        $blocks = [];
        $invokedKeys = [];
        $emittedBlockKeys = [];

        for ($hop = 0; $hop < $maxHops; $hop++) {
            $response = $this->llm->chatWithTools($messages, $toolPayload, ['max_tokens' => 800]);
            $toolCalls = $response['tool_calls'] ?? null;
            if (! is_array($toolCalls) || $toolCalls === []) {
                break;
            }

            $freshCalls = [];
            foreach ($toolCalls as $tc) {
                $name = (string) ($tc['name'] ?? '');
                if (isset($invokedKeys[$name])) {
                    continue;
                }
                $invokedKeys[$name] = true;
                $freshCalls[] = $tc;
            }
            if ($freshCalls === []) {
                break;
            }
            $toolCalls = $freshCalls;

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
                $tool = $this->tools->get($name);
                if ($tool === null) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'name' => $name,
                        'content' => json_encode(['error' => "Unknown tool: {$name}"]) ?: '{}',
                    ];

                    continue;
                }

                $this->emit('tool_call', ['name' => $name, 'args' => $args]);

                try {
                    $out = $tool->execute($args, $agent, ['conversation' => $conversation]);
                } catch (\Throwable $e) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'name' => $name,
                        'content' => json_encode(['error' => $e->getMessage()]) ?: '{}',
                    ];

                    continue;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($tc['id'] ?? ''),
                    'name' => $name,
                    'content' => json_encode($out['result'] ?? []) ?: '{}',
                ];

                if (isset($out['block']) && is_array($out['block'])) {
                    $blockKey = ($out['block']['type'] ?? '').':'.md5((string) json_encode($out['block']['payload'] ?? []));
                    if (! isset($emittedBlockKeys[$blockKey])) {
                        $emittedBlockKeys[$blockKey] = true;
                        $blocks[] = $out['block'];
                    }
                }
            }
        }

        return ['messages' => $messages, 'blocks' => $blocks];
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

    /**
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
     * Mirrors the widget controller's sanitizer so the playground feeds
     * exactly the shape the prompt builder expects.
     *
     * @return array<string, mixed>|null
     */
    private function sanitizePageContext(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > 8192) {
            return null;
        }

        $allowedKeys = [
            // Generic page-context (heuristic-DOM path).
            'url', 'title', 'description', 'og', 'twitter', 'json_ld', 'h1', 'h2', 'visible_text',
            // WordPress companion plugin CMS extensions. Keeps the
            // playground's payload shape identical to what the plugin
            // emits in `PageContext::collect()` on a real site so the
            // prompt builder and retriever see the same input both
            // places. `source: "wordpress"` is the marker the widget
            // and retriever read to skip DOM scraping.
            'source', 'site_url', 'page_url', 'page_title',
            'post_id', 'post_type', 'permalink', 'categories', 'tags', 'woo',
        ];
        $clean = [];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $clean[$key] = $raw[$key];
        }

        // Light type discipline on WP fields so a malformed playground
        // payload can't pollute the prompt with the wrong shape.
        if (isset($clean['source']) && $clean['source'] !== 'wordpress') {
            unset($clean['source']);
        }
        if (isset($clean['post_id']) && ! is_int($clean['post_id'])) {
            $clean['post_id'] = (int) $clean['post_id'];
        }
        if (isset($clean['categories']) && ! is_array($clean['categories'])) {
            unset($clean['categories']);
        }
        if (isset($clean['tags']) && ! is_array($clean['tags'])) {
            unset($clean['tags']);
        }
        if (isset($clean['woo']) && ! is_array($clean['woo'])) {
            unset($clean['woo']);
        }

        if (isset($clean['url']) && is_string($clean['url'])) {
            $canonical = CanonicalUrl::for($clean['url']);
            if ($canonical !== null) {
                $clean['url'] = $canonical;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Sandbox-only: thread a fake WordPress shopper identity onto the
     * conversation's `attribution.shopper` exactly as InitController
     * does for real visitors holding a verified `shopper_token`. This
     * is what unlocks the `lookup_order` tool path on a playground
     * conversation — without it the tool returns `not_signed_in` and
     * the LLM can't rehearse the "where's my order?" flow.
     *
     * Never an auth path. The admin is already authorized to play with
     * this agent (the route is `can('update', $agent)`); the simulated
     * claims live on the playground conversation only.
     *
     * @param  array<string, mixed>|null  $simulation
     */
    private function applyShopperSimulation(Conversation $conversation, ?array $simulation): void
    {
        if (! is_array($simulation)) {
            return;
        }
        $wpUserId = isset($simulation['wp_user_id']) ? (int) $simulation['wp_user_id'] : 0;
        $email = isset($simulation['email']) && is_string($simulation['email'])
            ? strtolower(trim($simulation['email']))
            : '';

        if ($wpUserId <= 0 || $email === '') {
            // Either field absent → admin toggled simulation off. Clear
            // any prior playground claim so the next turn rehearses the
            // anonymous path.
            $attribution = (array) ($conversation->attribution ?? []);
            if (isset($attribution['shopper'])) {
                unset($attribution['shopper']);
                $conversation->forceFill(['attribution' => $attribution])->save();
            }

            return;
        }

        $attribution = (array) ($conversation->attribution ?? []);
        $attribution['shopper'] = [
            'wp_user_id' => (string) $wpUserId,
            'email_hash' => hash('sha256', $email),
            'source' => 'wordpress',
            'simulated' => true,
        ];
        $conversation->forceFill(['attribution' => $attribution])->save();
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

    private function writeRaw(string $bytes): void
    {
        echo $bytes;
        if (function_exists('ob_get_level') && ! app()->runningUnitTests()) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        } elseif (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
