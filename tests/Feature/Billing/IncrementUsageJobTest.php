<?php

use App\Jobs\Analytics\IncrementUsageJob;
use App\Models\Conversation;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    CarbonImmutable::setTestNow(null);
});

test('first turn writes one conversation event + one message event', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $conversation = Conversation::factory()->create(['agent_id' => $agent->id]);

    (new IncrementUsageJob($conversation->id))->handle();

    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'conversation')->count())->toBe(1);
    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'message')->count())->toBe(1);
});

test('subsequent turns in the same conversation + same month write only message events', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $conversation = Conversation::factory()->create(['agent_id' => $agent->id]);

    (new IncrementUsageJob($conversation->id))->handle();
    (new IncrementUsageJob($conversation->id))->handle();
    (new IncrementUsageJob($conversation->id))->handle();

    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'conversation')->count())->toBe(1);
    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'message')->count())->toBe(3);
});

test('a returning visitor next month counts as a new conversation', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $conversation = Conversation::factory()->create(['agent_id' => $agent->id]);

    // First month — first turn writes the conversation event.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 10:00:00'));
    (new IncrementUsageJob($conversation->id))->handle();

    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'conversation')
        ->where('occurred_at', '>=', CarbonImmutable::parse('2026-01-01'))
        ->where('occurred_at', '<', CarbonImmutable::parse('2026-02-01'))
        ->count())->toBe(1);

    // Next month — same conversation cookie. Must count again so the
    // billing surface for the new month doesn't show 0 for returning
    // visitors. Pre-fix behavior left the 30-day cache key alive,
    // which suppressed the count even after the month rolled over.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 09:00:00'));
    (new IncrementUsageJob($conversation->id))->handle();

    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'conversation')
        ->where('occurred_at', '>=', CarbonImmutable::parse('2026-02-01'))
        ->where('occurred_at', '<', CarbonImmutable::parse('2026-03-01'))
        ->count())->toBe(1);

    expect(UsageEvent::where('workspace_id', $workspace->id)->where('kind', 'conversation')->count())->toBe(2);
});

test('job returns silently when conversation_id is unknown', function () {
    (new IncrementUsageJob('019e5fff-ffff-7fff-8fff-ffffffffffff'))->handle();

    expect(UsageEvent::query()->count())->toBe(0);
});
