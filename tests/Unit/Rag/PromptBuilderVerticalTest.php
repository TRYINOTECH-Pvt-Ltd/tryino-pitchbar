<?php

use App\Models\Agent;
use App\Services\Rag\PromptBuilder;

test('vertical fragment is injected for ecommerce agents', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = 'ecommerce';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'do you ship to canada?',
        sources: [],
    );

    expect($messages[0]['content'])->toContain('Vertical context (site type: ecommerce)');
    expect($messages[0]['content'])->toContain('e-commerce store');
});

test('NULL site_type produces no vertical block', function () {
    $builder = new PromptBuilder;
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = null;

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
    );

    expect($messages[0]['content'])->not->toContain('Vertical context');
});

test('generic site_type adds no fragment (empty preset)', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = 'generic';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
    );

    expect($messages[0]['content'])->not->toContain('Vertical context');
});

test('vertical fragment appears BEFORE admin custom system_prompt', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = 'Use only formal tone. Never mention competitors.';
    $agent->site_type = 'documentation';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
    );

    $system = $messages[0]['content'];
    $verticalPos = strpos($system, 'Vertical context (site type: documentation)');
    $customPos = strpos($system, 'Additional instructions from the workspace owner');

    expect($verticalPos)->not->toBeFalse();
    expect($customPos)->not->toBeFalse();
    expect($verticalPos)->toBeLessThan($customPos);
});
