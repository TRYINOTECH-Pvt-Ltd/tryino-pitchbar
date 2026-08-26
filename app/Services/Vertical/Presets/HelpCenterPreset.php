<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class HelpCenterPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'help_center';
    }

    public function label(): string
    {
        return 'Help center';
    }

    public function shortDescription(): string
    {
        return 'Support articles, FAQs, and ticket triage';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is a help center / knowledge base. When answering:
        - Treat every visitor question as someone potentially blocked. Lead with the resolution; explain context only after.
        - When the sources contain numbered steps, preserve the numbering.
        - If the visitor's question matches an existing FAQ entry, cite it directly with [n].
        - If the issue could be a bug, an outage, or something the bot cannot resolve, offer to escalate to a human (don't loop the visitor through irrelevant articles).
        - Keep apologies brief — one acknowledgement, then move to the fix.
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'I need help with my account',
            'How do I reset my password?',
            'I want to talk to a human',
        ];
    }

    public function launcherLabel(): ?string
    {
        return 'Get help';
    }

    public function maxChars(): int
    {
        return 2400;
    }

    public function capabilities(): array
    {
        return [
            'ticket_escalation',
            'kb_article_card',
            'sentiment_routing',
            // C3: open_ticket tool. Auto-on for help_center; other
            // verticals can opt in via vertical_overrides.capabilities.
            'ticketing',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['fix', 'error', 'troubleshoot', 'reset', 'cancel', 'refund'],
            'chunk_overlap_bias' => 0.12,
        ];
    }
}
