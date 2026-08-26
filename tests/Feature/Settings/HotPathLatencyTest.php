<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Support\HotPathStats;
use App\Support\HotPathTimer;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('records emitted turns into the ring buffer', function () {
    $timer = new HotPathTimer('conv-1', 'agent-1');
    $timer->mark('retrieve.start');
    $timer->mark('retrieve.end');
    $timer->mark('first_token.start');
    $timer->mark('first_token.end');
    $timer->emit(['sources' => 3, 'retrieve_timings' => ['embed_ms' => 42, 'ann_ms' => 17]]);

    $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY);
    expect($recent)->toBeArray()->toHaveCount(1)
        ->and($recent[0]['conversation_id'])->toBe('conv-1')
        ->and($recent[0]['stages'])->toHaveKeys(['retrieve_ms', 'first_token_ms', 'total_ms'])
        ->and($recent[0]['extra']['retrieve_timings']['embed_ms'])->toBe(42);
});

it('caps the ring buffer at the configured max', function () {
    for ($i = 0; $i < HotPathTimer::RECENT_MAX + 10; $i++) {
        (new HotPathTimer("conv-{$i}", 'agent-1'))->emit([]);
    }

    $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY);
    expect($recent)->toHaveCount(HotPathTimer::RECENT_MAX)
        // Newest first.
        ->and($recent[0]['conversation_id'])->toBe('conv-'.(HotPathTimer::RECENT_MAX + 9));
});

it('renders the dashboard with aggregates and verdict for super admins', function () {
    $timer = new HotPathTimer('conv-x', 'agent-x');
    $timer->mark('retrieve.start');
    $timer->mark('retrieve.end');
    $timer->emit(['sources' => 2, 'tokens_out' => 50, 'retrieve_timings' => ['embed_ms' => 30, 'ann_ms' => 20, 'hydrate_ms' => 2, 'rerank_ms' => 60]]);

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $res = $this->actingAs($admin)->get('/settings/system/hotpath-latency');
    $res->assertOk();

    $props = $res->viewData('page')['props'];
    expect($props['turns'])->toHaveCount(1)
        ->and($props['turns'][0]['embed_ms'])->toBe(30)
        ->and($props['turns'][0]['rerank_ms'])->toBe(60)
        ->and($props['aggregates'])->toHaveKeys(['retrieve_ms', 'first_token_ms', 'llm_ms', 'total_ms'])
        ->and($props['verdict'])->toBeString()->not->toBe('');
});

it('shows an instructional verdict when no turns are recorded', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $res = $this->actingAs($admin)->get('/settings/system/hotpath-latency');
    $res->assertOk();
    expect($res->viewData('page')['props']['verdict'])->toContain('No turns recorded yet');
});

it('hides the dashboard from non-super-admins', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);

    $res = $this->actingAs($user)->get('/settings/system/hotpath-latency');
    expect($res->status())->toBeIn([403, 404]);
});

it('computes identical aggregates and verdict via HotPathStats', function () {
    $stats = new HotPathStats;

    $turns = [
        ['retrieve_ms' => 100, 'first_token_ms' => 900, 'llm_ms' => 2000, 'total_ms' => 2500, 'embed_ms' => 40, 'ann_ms' => 30, 'rerank_ms' => 30],
        ['retrieve_ms' => 120, 'first_token_ms' => 1100, 'llm_ms' => 2400, 'total_ms' => 3000, 'embed_ms' => 50, 'ann_ms' => 40, 'rerank_ms' => 30],
    ];

    $agg = $stats->aggregates($turns);
    expect($agg['first_token_ms']['max'])->toBe(1100)
        ->and($agg['retrieve_ms']['p50'])->toBeGreaterThan(0);

    // first_token p95 >= 800 and > 2x retrieve → LLM verdict.
    expect($stats->verdict($turns))->toContain('LLM first-token wait dominates');
});

it('blames the tool loop when it dominates the first-token wait', function () {
    $stats = new HotPathStats;

    // Real-world shape from a production install, 2026-06-10: first token ~14s, of
    // which the tool-check completion ate most of the window.
    $turns = [
        ['retrieve_ms' => 905, 'tool_loop_ms' => 11000, 'first_token_ms' => 13878, 'llm_ms' => 17233, 'total_ms' => 18468, 'embed_ms' => 155, 'ann_ms' => 284, 'rerank_ms' => 457],
    ];

    $verdict = $stats->verdict($turns);
    expect($verdict)->toContain('Tool-call resolution dominates')
        ->and($verdict)->toContain('Enable the fast router');
});

it('still blames the model when the tool loop is small', function () {
    $stats = new HotPathStats;

    $turns = [
        ['retrieve_ms' => 200, 'tool_loop_ms' => 300, 'first_token_ms' => 5000, 'llm_ms' => 7000, 'total_ms' => 7500, 'embed_ms' => 50, 'ann_ms' => 80, 'rerank_ms' => 60],
    ];

    expect($stats->verdict($turns))->toContain('LLM first-token wait dominates');
});

it('flags slow retrieval in the verdict', function () {
    $stats = new HotPathStats;

    $turns = [
        ['retrieve_ms' => 700, 'first_token_ms' => 300, 'llm_ms' => 900, 'total_ms' => 1400, 'embed_ms' => 100, 'ann_ms' => 350, 'rerank_ms' => 250],
    ];

    $verdict = $stats->verdict($turns);
    expect($verdict)->toContain('Retrieval dominates')
        ->and($verdict)->toContain('vector search')
        ->and($verdict)->toContain('reranker');
});

it('survives a corrupt ring buffer value', function () {
    Cache::put(HotPathTimer::RECENT_CACHE_KEY, 'not-an-array');

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $this->actingAs($admin)->get('/settings/system/hotpath-latency')->assertOk();

    // Emit also recovers: replaces the corrupt value with a fresh buffer.
    (new HotPathTimer('conv-r', 'agent-r'))->emit([]);
    expect(Cache::get(HotPathTimer::RECENT_CACHE_KEY))->toBeArray()->toHaveCount(1);
});
