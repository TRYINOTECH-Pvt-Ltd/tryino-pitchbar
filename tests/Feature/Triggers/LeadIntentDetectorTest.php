<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Triggers\LeadIntentDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function makeConv(): Conversation
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::create([
        'agent_id' => $agent->id,
        'anonymous_id' => 'anon_'.Str::random(8),
        'ip_hash' => 'x',
        'ua' => 'x',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'visit_count' => 1,
    ]);

    return Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now(),
        'lang' => 'en',
    ]);
}

beforeEach(fn () => Cache::flush());

test('high-intent keyword in visitor message triggers a prompt', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    expect($detector->shouldPrompt($conv, 'How much does this cost?'))->toBeTrue();
});

test('benign first message does not trigger', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    expect($detector->shouldPrompt($conv, 'hi'))->toBeFalse();
});

test('engagement fallback triggers on the third visitor turn even without keywords', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    // Persist two prior visitor turns. The third (current) is what we
    // pass to shouldPrompt. visitorTurns becomes 3 → ENGAGEMENT_THRESHOLD.
    foreach (['hi', 'who are you'] as $i => $content) {
        Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $content,
            'citations' => [],
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'created_at' => now()->subMinutes(2 - $i),
        ]);
    }

    expect($detector->shouldPrompt($conv, 'tell me more'))->toBeTrue();
});

test('does not re-prompt within the cooldown window', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    // First prompt fires on a high-intent message.
    expect($detector->shouldPrompt($conv, 'I want pricing'))->toBeTrue();

    // Same conversation, even another high-intent visitor message, should
    // not re-fire while cooldown is in effect.
    expect($detector->shouldPrompt($conv, 'What about a demo?'))->toBeFalse();
});

test('skips entirely when a lead has already been captured for the conversation', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    Lead::create([
        'id' => (string) Str::uuid7(),
        'agent_id' => $conv->agent_id,
        'conversation_id' => $conv->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
    ]);

    expect($detector->shouldPrompt($conv, 'I want pricing'))->toBeFalse();
});

test('cooldown window expires after the configured number of visitor turns', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();

    // Fire the first prompt at visitor turn 1.
    expect($detector->shouldPrompt($conv, 'pricing please'))->toBeTrue();

    // Persist 5 more visitor turns to push past REPROMPT_COOLDOWN (5).
    foreach (range(1, 5) as $i) {
        Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => "msg {$i}",
            'citations' => [],
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'created_at' => now()->subSeconds(60 - $i),
        ]);
    }

    // Visitor turns is now 6 (5 persisted + current). Last prompted was at 1.
    // Diff = 5 → cooldown elapsed, allow prompt.
    expect($detector->shouldPrompt($conv, 'can I talk to sales?'))->toBeTrue();
});

test('strategy=first_turn prompts on the very first benign message', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();
    $conv->agent->forceFill(['lead_prompt_strategy' => 'first_turn'])->save();
    $conv->setRelation('agent', $conv->agent->fresh());

    expect($detector->shouldPrompt($conv, 'hi there'))->toBeTrue();
});

test('strategy=keyword_only skips the 3-turn engagement fallback', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();
    $conv->agent->forceFill(['lead_prompt_strategy' => 'keyword_only'])->save();
    $conv->setRelation('agent', $conv->agent->fresh());

    foreach (range(1, 3) as $i) {
        Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => "msg {$i}",
            'citations' => [],
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'created_at' => now()->subSeconds(60 - $i),
        ]);
    }

    // Benign message on turn 4 — engagement path would fire, keyword-only must not.
    expect($detector->shouldPrompt($conv, 'tell me more'))->toBeFalse();
    // Keyword still works.
    expect($detector->shouldPrompt($conv, 'how much does pricing cost?'))->toBeTrue();
});

test('strategy=never suppresses every path', function () {
    $detector = app(LeadIntentDetector::class);
    $conv = makeConv();
    $conv->agent->forceFill(['lead_prompt_strategy' => 'never'])->save();
    $conv->setRelation('agent', $conv->agent->fresh());

    expect($detector->shouldPrompt($conv, 'how much does pricing cost?'))->toBeFalse();
    expect($detector->shouldPrompt($conv, 'demo please'))->toBeFalse();
});
