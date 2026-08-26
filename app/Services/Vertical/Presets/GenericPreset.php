<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

/**
 * No-op preset. Used when the admin explicitly chooses "I'll configure
 * this myself" or when an unknown slug is requested. Behaves identically
 * to NULL site_type — preserving existing behaviour for agents that
 * never opt in to a vertical.
 */
class GenericPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'generic';
    }

    public function label(): string
    {
        return 'Generic';
    }

    public function shortDescription(): string
    {
        return "I'll configure this myself";
    }

    public function systemPromptFragment(): string
    {
        return '';
    }

    public function starterPrompts(): array
    {
        return [];
    }

    public function launcherLabel(): ?string
    {
        return null;
    }

    public function maxChars(): int
    {
        return 2500;
    }

    public function capabilities(): array
    {
        return [
            'ticket_escalation',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => [],
            'chunk_overlap_bias' => 0.0,
        ];
    }
}
