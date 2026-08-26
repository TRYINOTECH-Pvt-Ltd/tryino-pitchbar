<?php

namespace App\Services\Tools;

/**
 * Outcome of the fast router's pre-stream gate. Immutable value object
 * carried from ToolIntentRouter into MessageStreamController and the
 * hot-path telemetry (ring buffer → latency dashboard → perf:hotpath).
 *
 * Routes:
 *   knowledge  — skip runToolLoop entirely; RAG + streamChat only.
 *   tool_loop  — legacy behavior; the model sees the full tool set and
 *                may call any of them (matchedTools records what gated).
 *
 * Phase 2 adds `tool_direct` (execute the matched tool without an LLM
 * decision hop).
 */
final class RouteDecision
{
    public const ROUTE_KNOWLEDGE = 'knowledge';

    public const ROUTE_TOOL_LOOP = 'tool_loop';

    /**
     * @param  list<string>  $matchedTools  tool names whose gate fired
     * @param  string  $reason  disabled | keyword | embedding | no_signal | no_embedding | no_tools
     */
    public function __construct(
        public readonly string $route,
        public readonly array $matchedTools,
        public readonly string $reason,
        public readonly ?float $topScore = null,
    ) {}

    public function skipsToolLoop(): bool
    {
        return $this->route === self::ROUTE_KNOWLEDGE;
    }
}
