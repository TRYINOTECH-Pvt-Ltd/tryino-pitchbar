<?php

use App\Support\HotPathTimer;
use Illuminate\Support\Facades\Log;

test('snapshotStages turns name.start/.end pairs into _ms ints', function () {
    $t = new HotPathTimer('c-1', 'a-1');

    $t->mark('retrieve.start');
    usleep(2_000); // 2ms
    $t->mark('retrieve.end');

    $t->mark('llm.start');
    usleep(5_000); // 5ms
    $t->mark('llm.end');

    $stages = $t->snapshotStages();

    expect($stages)->toHaveKey('retrieve_ms');
    expect($stages)->toHaveKey('llm_ms');
    expect($stages)->toHaveKey('total_ms');
    expect($stages['retrieve_ms'])->toBeGreaterThanOrEqual(0);
    expect($stages['llm_ms'])->toBeGreaterThanOrEqual($stages['retrieve_ms']);
    expect($stages['total_ms'])->toBeGreaterThanOrEqual($stages['llm_ms']);
});

test('snapshotStages ignores unmatched marks', function () {
    $t = new HotPathTimer('c', 'a');
    $t->mark('orphan.start');
    // no .end pair → must be ignored

    $stages = $t->snapshotStages();
    expect($stages)->not->toHaveKey('orphan_ms');
});

test('emit logs structured rag.turn line', function () {
    Log::shouldReceive('channel->info')
        ->once()
        ->withArgs(function (string $event, array $context): bool {
            return $event === 'rag.turn'
                && $context['conversation_id'] === 'c-x'
                && $context['agent_id'] === 'a-x'
                && isset($context['stages']['total_ms'])
                && ($context['extra']['low_confidence'] ?? null) === true;
        });

    $t = new HotPathTimer('c-x', 'a-x');
    $t->mark('retrieve.start');
    $t->mark('retrieve.end');
    $t->emit(['low_confidence' => true]);
});
