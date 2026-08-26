<?php

use App\Models\Agent;
use App\Models\Workspace;
use App\Services\Rag\PromptBuilder;

/**
 * PromptBuilder receives an optional `personaOverride` (the variant
 * config) and shallow-merges it over agent.persona. Used by A/B
 * experiments of kind=persona so the LLM speaks under the variant's
 * name/tone for the assigned conversation only.
 */
test('persona override replaces the agent default name + tone in the system message', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'persona' => ['name' => 'Aria', 'tone' => 'friendly'],
    ]);

    $builder = app(PromptBuilder::class);

    $defaultMessages = $builder->build(
        agent: $agent,
        userMessage: 'hi',
        sources: [],
    );
    expect((string) $defaultMessages[0]['content'])->toContain('You are Aria');
    expect((string) $defaultMessages[0]['content'])->toContain('Tone: friendly');

    $overrideMessages = $builder->build(
        agent: $agent,
        userMessage: 'hi',
        sources: [],
        personaOverride: ['name' => 'Max', 'tone' => 'punchy'],
    );
    expect((string) $overrideMessages[0]['content'])->toContain('You are Max');
    expect((string) $overrideMessages[0]['content'])->toContain('Tone: punchy');
    expect((string) $overrideMessages[0]['content'])->not->toContain('You are Aria');
});

test('null persona override leaves the agent default in place', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'persona' => ['name' => 'Aria', 'tone' => 'friendly'],
    ]);
    $builder = app(PromptBuilder::class);

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'hi',
        sources: [],
        personaOverride: null,
    );

    expect((string) $messages[0]['content'])->toContain('You are Aria');
});
