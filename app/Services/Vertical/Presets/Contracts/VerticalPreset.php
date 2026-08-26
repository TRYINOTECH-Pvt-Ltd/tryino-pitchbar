<?php

namespace App\Services\Vertical\Presets\Contracts;

use App\Services\Vertical\VerticalPresets;

/**
 * Contract every vertical preset implements. Presets are PHP classes (not
 * data) so a preset can ship executable defaults — system-prompt fragments,
 * starter prompt copy, retrieval boost keywords — and stay testable as a
 * pure function.
 */
interface VerticalPreset
{
    /**
     * Slug stored on agents.site_type. Must be one of
     * {@see VerticalPresets::SLUGS}.
     */
    public function slug(): string;

    /**
     * Human-readable label shown in the admin UI radio cards.
     */
    public function label(): string;

    /**
     * One-line description used under the label in radio cards.
     */
    public function shortDescription(): string;

    /**
     * Vertical-specific instructions appended to the LLM system prompt
     * AFTER sources but BEFORE the admin's custom system_prompt — admin
     * always wins. Empty string for the generic preset.
     */
    public function systemPromptFragment(): string;

    /**
     * Default starter prompts (chips shown in the widget when no messages
     * yet). Capped at 6 items per agent; each <= 80 chars.
     *
     * @return array<int, string>
     */
    public function starterPrompts(): array;

    /**
     * Default theme.launcher_label. NULL means "don't override an
     * existing default" — the widget then keeps "Ask anything".
     */
    public function launcherLabel(): ?string;

    /**
     * Default guardrails.max_chars for replies in this vertical. Tuned
     * per vertical (docs allow longer answers; commerce stays tight).
     */
    public function maxChars(): int;

    /**
     * Capability flags exposed to the widget so it knows which rich-UI
     * affordances Phase 3 may render. Phase 1 just exposes the list;
     * the widget's canRender() returns false until renderers ship.
     *
     * @return array<int, string>
     */
    public function capabilities(): array;

    /**
     * Vertical retrieval tuning hints. Phase 1 doesn't act on these; the
     * shape is fixed now so Phase 2 can wire them into the Retriever
     * without changing every preset.
     *
     * @return array{boost_keywords: array<int, string>, chunk_overlap_bias: float}
     */
    public function retrievalTuning(): array;
}
