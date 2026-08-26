<?php

use App\Models\Agent;
use App\Services\Rag\PromptBuilder;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Vertical\VerticalPresets;

/**
 * Locks the system-prompt contract for every shipped preset. For each
 * non-generic vertical we assert the prompt contains the preset's
 * sentinel phrase plus our "Vertical context" header. Catches drift
 * if a preset's wording changes inadvertently.
 */
dataset('non_generic_presets', function () {
    return [
        'ecommerce' => ['ecommerce', 'e-commerce store'],
        'documentation' => ['documentation', 'documentation site'],
        'saas' => ['saas', 'SaaS product website'],
        'help_center' => ['help_center', 'knowledge base'],
        'marketing' => ['marketing', 'lead-generation site'],
        'internal_kb' => ['internal_kb', 'employees'],
    ];
});

test('every non-generic preset injects its fragment into the system prompt', function (string $slug, string $sentinel) {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar', 'tone' => 'helpful'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = $slug;

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'tell me about this',
        sources: [],
    );
    $system = $messages[0]['content'];

    expect($system)->toContain("Vertical context (site type: {$slug})");
    expect($system)->toContain($sentinel);
})->with('non_generic_presets');

test('preset fragment positioning: after sources, before admin custom prompt', function () {
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = 'Always end replies with a smile.';
    $agent->site_type = 'ecommerce';

    $messages = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [['text' => 'A widget costs $49', 'url' => 'https://shop.example/products/widget', 'score' => 0.9]],
    );
    $system = $messages[0]['content'];

    $sourcesBlock = strpos($system, '<source id="1"');
    $verticalHeader = strpos($system, 'Vertical context');
    $customHeader = strpos($system, 'Additional instructions from the workspace owner');
    $adminPrompt = strpos($system, 'Always end replies with a smile.');

    expect($sourcesBlock)->not->toBeFalse();
    expect($verticalHeader)->not->toBeFalse();
    expect($customHeader)->not->toBeFalse();
    expect($adminPrompt)->not->toBeFalse();

    // Ordering contract: sources → vertical → admin custom prompt.
    expect($sourcesBlock)->toBeLessThan($verticalHeader);
    expect($verticalHeader)->toBeLessThan($customHeader);
    expect($customHeader)->toBeLessThan($adminPrompt);
});

test('every preset slug declared on the registry produces a fragment-ready prompt', function () {
    $registry = new VerticalPresetRegistry;
    $builder = new PromptBuilder($registry);

    foreach (VerticalPresets::SLUGS as $slug) {
        $agent = new Agent;
        $agent->persona = ['name' => 'Pitchbar'];
        $agent->guardrails = [];
        $agent->site_type = $slug;
        $agent->system_prompt = null;

        $messages = $builder->build(
            agent: $agent,
            userMessage: 'q',
            sources: [],
        );

        // generic must not add a fragment; everything else must.
        $hasFragment = str_contains($messages[0]['content'], "Vertical context (site type: {$slug})");
        if ($slug === 'generic') {
            expect($hasFragment)->toBeFalse();
        } else {
            expect($hasFragment)->toBeTrue();
        }
    }
});

test('phase-3 presets carry an example marker AND a directive to emit it', function (string $slug, string $blockTag) {
    // Contract: ecommerce / saas / marketing presets must include both
    // a concrete example of their block marker AND an instruction to
    // emit it. If wording drifts to "may emit", the widget's rich
    // blocks silently disappear.
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = [];
    $agent->system_prompt = null;
    $agent->site_type = $slug;

    $system = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
    )[0]['content'];

    // Example marker is verbatim — the LLM mirrors it.
    expect($system)->toContain("<{$blockTag} ");

    // Some form of "you must emit it" directive — accept either the
    // strict ALWAYS phrasing (ecommerce / saas) or the softer
    // "emit a … card on its own line" phrasing (marketing case-study).
    $hasDirective = str_contains($system, 'ALWAYS emit')
        || str_contains($system, 'emit a case study card')
        || str_contains($system, 'emit a product card')
        || str_contains($system, 'emit a pricing card');
    expect($hasDirective)->toBeTrue();
})->with([
    'ecommerce' => ['ecommerce', 'product'],
    'saas' => ['saas', 'pricing'],
    'marketing' => ['marketing', 'case-study'],
]);

test('persona max_chars from preset surfaces in length-guidance', function () {
    // The preset apply endpoint copies max_chars into agent.guardrails;
    // here we simulate that and assert the prompt's hard cap reflects it.
    $builder = app(PromptBuilder::class);
    $agent = new Agent;
    $agent->persona = ['name' => 'Pitchbar'];
    $agent->guardrails = ['max_chars' => 1800]; // ecommerce default
    $agent->system_prompt = null;
    $agent->site_type = 'ecommerce';

    $system = $builder->build(
        agent: $agent,
        userMessage: 'q',
        sources: [],
    )[0]['content'];

    expect($system)->toContain('Hard upper bound: 1800 characters');
});
