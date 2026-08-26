<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class InternalKbPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'internal_kb';
    }

    public function label(): string
    {
        return 'Internal knowledge base';
    }

    public function shortDescription(): string
    {
        return 'Employee wiki, runbooks, and internal documentation';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is an internal knowledge base for employees. When answering:
        - Speak to a colleague, not a customer — drop sales language and marketing copy.
        - When the sources include team owners, escalation contacts, or oncall channels, surface them by name when relevant.
        - For policy / compliance questions, quote the source verbatim where wording matters; never paraphrase regulatory text.
        - If the question is ambiguous between teams (e.g. "who owns billing?"), list every match the sources contain — the visitor will pick.
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'What is our PTO policy?',
            'Who owns billing?',
            'How do I file an expense?',
        ];
    }

    public function launcherLabel(): ?string
    {
        return null;
    }

    public function maxChars(): int
    {
        return 2400;
    }

    public function capabilities(): array
    {
        return [
            'policy_lookup',
            'team_handoff',
            'auth_aware',
            'ticket_escalation',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['policy', 'owner', 'oncall', 'runbook', 'sla', 'team'],
            'chunk_overlap_bias' => 0.12,
        ];
    }
}
