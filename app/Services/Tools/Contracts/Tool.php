<?php

namespace App\Services\Tools\Contracts;

use App\Models\Agent;

/**
 * Server-side function the LLM can invoke during a turn.
 *
 * Tools sit at the intersection of vertical capabilities (declared in
 * Phase 1) and the LLM's tool-calling protocol. The registry resolves
 * which tools are available for an agent by intersecting:
 *   1. The agent's `site_type` preset capabilities.
 *   2. Per-tool `capability()` — the capability slug a tool requires.
 *   3. The admin's `vertical_overrides.enabled_tools` allow-list (if set).
 */
interface Tool
{
    /**
     * Stable identifier the LLM sees in the tools array.
     */
    public function name(): string;

    /**
     * One-line description shown to the LLM. The LLM uses this to
     * decide whether to call this tool — write it to be unambiguous
     * against neighbouring tools.
     */
    public function description(): string;

    /**
     * The capability slug this tool requires. The registry only exposes
     * the tool to agents whose vertical preset declares this capability
     * (or whose vertical_overrides.capabilities listed it).
     */
    public function capability(): string;

    /**
     * JSON Schema for the tool's parameters. Returned as the
     * `function.parameters` field in the OpenAI tools array.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Execute the tool with arguments the LLM passed. Returns a payload
     * that becomes the `tool` role message content fed back to the LLM
     * for its next turn, plus an optional `block` for the widget to
     * render inline.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context  Optional per-turn context — today carries `conversation` (the Conversation model) for tools that need shopper identity or other turn-specific data.
     * @return array{result: array<string, mixed>, block?: array{type: string, payload: array<string, mixed>}}
     */
    public function execute(array $args, Agent $agent, array $context = []): array;
}
