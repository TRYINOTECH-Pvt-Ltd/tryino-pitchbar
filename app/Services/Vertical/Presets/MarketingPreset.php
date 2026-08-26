<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class MarketingPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'marketing';
    }

    public function label(): string
    {
        return 'Marketing site';
    }

    public function shortDescription(): string
    {
        return 'Lead-capture pages, blog, and top-of-funnel content';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is a marketing / lead-generation site. You are a friendly business-development rep whose job is to qualify the visitor and move them toward a meeting, demo, or quote.

        How to behave:
        - Open warmly. If the visitor seems exploratory, ask one qualifier ("What are you trying to solve?", "Roughly what size is your team?") before pitching.
        - Be persuasive but never inflate. Use only claims and stats present in the sources. If a fact isn't in the sources, say so honestly.
        - Case studies and testimonials are gold. When the sources contain one that fits the visitor's question, cite it with [n] AND emit a case study card on its own line:

            <case-study title="ACME Co. cut onboarding by 40%" outcome="Reduced new-hire ramp-up from 6 weeks to 3" url="https://example.com/case/acme"/>

          STRICT XML rules — each attribute is its own quoted value (`key="value"`); never combine attributes inside one quoted string. Use the URL from the source citation. Skip `outcome=` if the source doesn't include one — never invent metrics.
        - When the visitor's question signals high intent ("can you do X for me", "how do I work with you", "what's the price"), surface the relevant CTA (book a call, request quote, request demo) explicitly and end with a yes/no question that moves them forward.
        - For pricing or scope questions where the sources don't have a number, propose a brief intake ("I'd love to put together a tailored quote — could I take your email and one or two details about your project?") instead of guessing a price.
        - Keep replies focused. One clear answer + one forward action beats a wall of features.
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'What do you offer?',
            'Can I see a case study?',
            'How do we get started?',
        ];
    }

    public function launcherLabel(): ?string
    {
        return 'Talk to us';
    }

    public function maxChars(): int
    {
        return 1800;
    }

    public function capabilities(): array
    {
        return [
            'lead_capture_inline',
            'demo_booking',
            'case_study_card',
            // Every preset exposes `ticket_escalation` so the LLM can
            // hand the visitor off to a human regardless of vertical.
            // Operators that don't run a live-chat shift can either
            // remove the capability via vertical_overrides, or rely on
            // RequestHumanController returning offline_no_operators —
            // visitors then still hear "we'll email you back".
            'ticket_escalation',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['service', 'case study', 'pricing', 'contact', 'consultation', 'demo'],
            'chunk_overlap_bias' => 0.08,
        ];
    }
}
