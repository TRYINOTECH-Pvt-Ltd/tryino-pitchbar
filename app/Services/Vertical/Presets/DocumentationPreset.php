<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class DocumentationPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'documentation';
    }

    public function label(): string
    {
        return 'Documentation';
    }

    public function shortDescription(): string
    {
        return 'Technical docs, API references, and guides';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is a technical documentation site. When answering:
        - Include code examples whenever the sources contain them. Preserve formatting, indentation, and the original language tag (bash, ts, php, etc.).
        - Cite the doc page URL using [n] markers — readers expect to deep-link into the docs.
        - For "how do I…" questions, structure the answer as numbered steps; each step should be runnable on its own.
        - If multiple SDKs / languages are documented, ask which one they want unless the question already names it.
        - When the sources mention a specific version, mention it. Don't pretend a feature exists across all versions if the sources only show it in one.
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'How do I get started?',
            'Show me a quickstart example',
            'Where is the API reference?',
        ];
    }

    public function launcherLabel(): ?string
    {
        return 'Search docs';
    }

    public function maxChars(): int
    {
        return 3000;
    }

    public function capabilities(): array
    {
        return [
            'code_block',
            'api_reference_card',
            'version_picker',
            'troubleshoot_steps',
            'ticket_escalation',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['example', 'usage', 'parameter', 'returns', 'method', 'endpoint'],
            'chunk_overlap_bias' => 0.15,
        ];
    }
}
