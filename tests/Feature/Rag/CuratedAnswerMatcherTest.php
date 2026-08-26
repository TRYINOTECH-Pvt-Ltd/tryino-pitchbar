<?php

use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\Workspace;
use App\Services\Rag\CuratedAnswerMatcher;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

/**
 * Buyer-reported (Lucian, 2026-05-15): an admin writes
 * `question_pattern = "pricing, web design"` in /agents/{id}/curated
 * expecting EITHER keyword to short-circuit the LLM. Pre-fix the
 * matcher treated the whole comma-string as a single substring
 * needle, so nothing ever matched. These tests pin the OR-token
 * semantics.
 */
function curatedAgent(): Agent
{
    $ws = Workspace::factory()->create();

    return Agent::factory()->create(['workspace_id' => $ws->id]);
}

test('comma-separated patterns match any single keyword in the visitor message', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing, web design',
        'answer' => 'Our web design pricing plans start from 725 euro.',
        'enabled' => true,
        'priority' => 100,
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    expect($matcher->match($agent->id, 'pricing'))
        ->toBe('Our web design pricing plans start from 725 euro.');
    expect($matcher->match($agent->id, 'web design'))
        ->toBe('Our web design pricing plans start from 725 euro.');
    expect($matcher->match($agent->id, 'What about your PRICING?'))
        ->toBe('Our web design pricing plans start from 725 euro.');
});

test('no match when none of the comma-separated tokens are substrings', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing, web design',
        'answer' => 'Pricing copy.',
        'enabled' => true,
        'priority' => 100,
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    expect($matcher->match($agent->id, 'tell me about cookies'))->toBeNull();
});

test('empty pattern tokens (extra commas / whitespace) are ignored', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => '  pricing ,, ,  refund  ',
        'answer' => 'OK',
        'enabled' => true,
        'priority' => 100,
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    expect($matcher->match($agent->id, 'i want a refund'))->toBe('OK');
    expect($matcher->match($agent->id, ''))->toBeNull();
});

test('disabled curated answers never fire', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing',
        'answer' => 'should not be seen',
        'enabled' => false,
        'priority' => 100,
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    expect($matcher->match($agent->id, 'pricing'))->toBeNull();
});

test('higher-priority matches win when multiple curated answers overlap', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing',
        'answer' => 'lower',
        'enabled' => true,
        'priority' => 1,
    ]);
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing, plans',
        'answer' => 'higher',
        'enabled' => true,
        'priority' => 100,
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    expect($matcher->match($agent->id, 'tell me about pricing'))->toBe('higher');
});

test('lang filter scopes matches when the answer is locale-tagged', function () {
    $agent = curatedAgent();
    CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'pricing',
        'answer' => 'fr-only',
        'enabled' => true,
        'priority' => 100,
        'lang' => 'fr',
    ]);

    $matcher = app(CuratedAnswerMatcher::class);

    // Visitor speaks English → French-locked answer does not fire.
    expect($matcher->match($agent->id, 'pricing', 'en'))->toBeNull();
    // Visitor speaks French → fires.
    expect($matcher->match($agent->id, 'pricing', 'fr'))->toBe('fr-only');
});
