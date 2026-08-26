<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\Workspace;
use App\Services\Scoring\LeadScoringEngine;

function makeScoringFixtures(): array
{
    $agent = Agent::factory()->create();
    $workspace = Workspace::query()->find($agent->workspace_id);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::query()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now(),
        'message_count' => 0,
    ]);

    return compact('workspace', 'agent', 'visitor', 'conversation');
}

function recordView(array $f, string $url, ?int $minutesAgo = null): VisitorPageView
{
    return VisitorPageView::create([
        'workspace_id' => $f['workspace']->id,
        'agent_id' => $f['agent']->id,
        'visitor_id' => $f['visitor']->id,
        'conversation_id' => $f['conversation']->id,
        'url' => $url,
        'viewed_at' => now()->subMinutes($minutesAgo ?? 0),
    ]);
}

test('empty conversation scores zero and bucket low', function () {
    $f = makeScoringFixtures();
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBe(0)
        ->and($result['bucket'])->toBe(LeadScoringEngine::BUCKET_LOW);
});

test('unique pages contribute 5 each capped at 25', function () {
    $f = makeScoringFixtures();
    foreach (['/a', '/b', '/c', '/d', '/e', '/f', '/g'] as $url) {
        recordView($f, 'https://example.com'.$url);
    }
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBe(25);
});

test('duplicate page visits do not stack on unique-pages weight', function () {
    $f = makeScoringFixtures();
    recordView($f, 'https://example.com/home');
    recordView($f, 'https://example.com/home');
    recordView($f, 'https://example.com/home');
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBe(5);
});

test('intent keywords in url contribute 15 each capped at 30', function () {
    $f = makeScoringFixtures();
    recordView($f, 'https://example.com/pricing');
    recordView($f, 'https://example.com/demo');
    recordView($f, 'https://example.com/contact');
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    // 3 unique + 3 intent matches → 5*3 + 15*2 (capped) = 15 + 30 = 45
    expect($result['score'])->toBe(45)
        ->and($result['bucket'])->toBe(LeadScoringEngine::BUCKET_MEDIUM);
});

test('engagement weight scales with message_count capped at 30', function () {
    $f = makeScoringFixtures();
    $f['conversation']->forceFill(['message_count' => 20])->save();
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBe(30);
});

test('lead captured adds 15 when email present', function () {
    $f = makeScoringFixtures();
    Lead::create([
        'conversation_id' => $f['conversation']->id,
        'agent_id' => $f['agent']->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
    ]);
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBe(15);
});

test('high bucket triggers when total reaches 70+', function () {
    $f = makeScoringFixtures();
    // 5 unique pages × 5 = 25
    foreach (['/a', '/b', '/c', '/d', '/e'] as $url) {
        recordView($f, 'https://example.com'.$url);
    }
    // 2 intent pages × 15 = 30
    recordView($f, 'https://example.com/pricing');
    recordView($f, 'https://example.com/demo');
    $f['conversation']->forceFill(['message_count' => 10])->save(); // 2 × 10 = 20 messages weight
    Lead::create([
        'conversation_id' => $f['conversation']->id,
        'agent_id' => $f['agent']->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
    ]);
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBeGreaterThanOrEqual(70)
        ->and($result['bucket'])->toBe(LeadScoringEngine::BUCKET_HIGH);
});

test('score clamps to 100 max', function () {
    $f = makeScoringFixtures();
    foreach (range(0, 20) as $i) {
        recordView($f, "https://example.com/pricing-{$i}");
    }
    $f['conversation']->forceFill(['message_count' => 200])->save();
    Lead::create([
        'conversation_id' => $f['conversation']->id,
        'agent_id' => $f['agent']->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
    ]);
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['score'])->toBeLessThanOrEqual(100);
});

test('reasons array reflects which signals fired', function () {
    $f = makeScoringFixtures();
    recordView($f, 'https://example.com/pricing');
    $f['conversation']->forceFill(['message_count' => 3])->save();
    $engine = app(LeadScoringEngine::class);

    $result = $engine->compute($f['conversation']);

    expect($result['reasons'])->toBeArray();
    expect(count($result['reasons']))->toBeGreaterThan(0);
    expect(implode(' ', $result['reasons']))->toContain('pricing');
});
