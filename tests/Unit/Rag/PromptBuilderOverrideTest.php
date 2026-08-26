<?php

use App\Models\Agent;
use App\Services\Rag\PromptBuilder;

/**
 * Playground passes a site-type override into PromptBuilder so admins can
 * try "what would my agent say if treated as ecommerce vs documentation?"
 * without mutating agent.site_type. The override wins; null falls back to
 * the persisted column. Widget hot path always passes null — existing
 * behaviour stays byte-identical.
 */
test('siteTypeOverride wins over agent.site_type', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = 'documentation';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'do you ship to canada?',
        sources: [],
        siteTypeOverride: 'ecommerce',
    );

    expect($messages[0]['content'])
        ->toContain('Vertical context (site type: ecommerce)')
        ->toContain('e-commerce store');
    expect($messages[0]['content'])->not->toContain('site type: documentation');
});

test('null override falls back to agent.site_type', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = 'saas';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'show me your pricing',
        sources: [],
        siteTypeOverride: null,
    );

    expect($messages[0]['content'])->toContain('Vertical context (site type: saas)');
});

test('siteTypeOverride applies even when agent.site_type is null', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = null;

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
        siteTypeOverride: 'help_center',
    );

    expect($messages[0]['content'])->toContain('Vertical context (site type: help_center)');
});

test('generic override still skips the fragment block', function () {
    // Generic preset returns an empty system fragment, so the
    // "Vertical context" block should be omitted entirely — same shape
    // as a NULL-site-type baseline. Important: the playground UI lets
    // admins explicitly pick "Generic" to compare against vertical
    // overrides, and we don't want a noisy empty header.
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = 'ecommerce';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
        siteTypeOverride: 'generic',
    );

    expect($messages[0]['content'])->not->toContain('Vertical context');
});
