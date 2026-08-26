<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\Variant;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Experiments\ExperimentResolver;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function experimentFixture(string $status = 'running', string $kind = 'persona'): array
{
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $exp = Experiment::create([
        'agent_id' => $agent->id,
        'name' => 'Test',
        'kind' => $kind,
        'status' => $status,
        'started_at' => $status === 'running' ? now() : null,
    ]);
    $variantA = Variant::create([
        'experiment_id' => $exp->id,
        'name' => 'control',
        'weight' => 50,
        'config' => ['persona' => ['name' => 'Aria', 'tone' => 'friendly']],
    ]);
    $variantB = Variant::create([
        'experiment_id' => $exp->id,
        'name' => 'treatment',
        'weight' => 50,
        'config' => ['persona' => ['name' => 'Max', 'tone' => 'punchy']],
    ]);

    return compact('agent', 'visitor', 'conv', 'exp', 'variantA', 'variantB');
}

test('hot path resolve returns a variant WITHOUT writing variant_id (PLAN §7 safety)', function () {
    // Refactor 2026-05-16: resolveForConversation is now pure to keep
    // the visitor SSE first-token p95 ≤ 1s. The conversation.variant_id
    // persist + ExperimentAssignment row insert moved into PersistTurnJob.
    ['conv' => $conv] = experimentFixture();
    $resolver = app(ExperimentResolver::class);

    $variant = $resolver->resolveForConversation($conv);

    expect($variant)->not->toBeNull();
    expect($conv->fresh()->variant_id)->toBeNull(); // NOT persisted on hot path
    expect(ExperimentAssignment::query()->count())->toBe(0);
});

test('post-stream persistAssignment writes variant_id and the assignment row', function () {
    ['conv' => $conv] = experimentFixture();
    $resolver = app(ExperimentResolver::class);

    $picked = $resolver->resolveForConversation($conv);
    expect($picked)->not->toBeNull();

    $resolver->persistAssignment($conv);

    expect($conv->fresh()->variant_id)->toBe($picked->id);
    expect(ExperimentAssignment::query()->count())->toBe(1);
});

test('repeat visitor stays in the same variant — pick() is deterministic across resolve + persist', function () {
    ['conv' => $conv] = experimentFixture();
    $resolver = app(ExperimentResolver::class);

    $hotPath = $resolver->resolveForConversation($conv);
    $resolver->persistAssignment($conv);
    // Next turn re-reads via the persisted variant_id branch.
    $nextTurn = $resolver->resolveForConversation($conv->fresh());

    expect($nextTurn->id)->toBe($hotPath->id);
});

test('persistAssignment is idempotent — re-running on the same conversation is a no-op', function () {
    ['conv' => $conv] = experimentFixture();
    $resolver = app(ExperimentResolver::class);

    $resolver->resolveForConversation($conv);
    $resolver->persistAssignment($conv);
    $variantId = $conv->fresh()->variant_id;

    $resolver->persistAssignment($conv->fresh());
    $resolver->persistAssignment($conv->fresh());

    expect($conv->fresh()->variant_id)->toBe($variantId);
    expect(ExperimentAssignment::query()->count())->toBe(1);
});

test('returns null when no experiment is running for the agent', function () {
    ['conv' => $conv] = experimentFixture('draft');
    $resolver = app(ExperimentResolver::class);

    expect($resolver->resolveForConversation($conv))->toBeNull();
    expect($conv->fresh()->variant_id)->toBeNull();
});

test('returns null when conversation has no visitor', function () {
    ['conv' => $conv] = experimentFixture();
    $conv->forceFill(['visitor_id' => null])->save();
    $resolver = app(ExperimentResolver::class);

    expect($resolver->resolveForConversation($conv->fresh()))->toBeNull();
});

test('forget() removes the running-experiment cache key for an agent', function () {
    ['agent' => $agent, 'conv' => $conv] = experimentFixture();
    $resolver = app(ExperimentResolver::class);

    // Prime cache via a resolve.
    $resolver->resolveForConversation($conv);
    expect(Cache::has("experiments:running:{$agent->id}"))->toBeTrue();

    ExperimentResolver::forget($agent->id);
    expect(Cache::has("experiments:running:{$agent->id}"))->toBeFalse();
});

test('eager-loads the experiment relation on the returned variant for hot-path callers', function () {
    ['conv' => $conv] = experimentFixture(kind: 'persona');
    $resolver = app(ExperimentResolver::class);

    $variant = $resolver->resolveForConversation($conv);

    expect($variant->relationLoaded('experiment'))->toBeTrue();
    expect($variant->experiment->kind)->toBe('persona');
});
