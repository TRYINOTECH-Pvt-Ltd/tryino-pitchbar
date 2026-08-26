<?php

use App\Services\Vector\CircuitBreakingVectorClient;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;

class FlakyClient implements QdrantClient
{
    public int $calls = 0;

    public bool $shouldFail = false;

    public function search(string $collection, array $vector, array $filter, int $limit): array
    {
        $this->calls++;
        if ($this->shouldFail) {
            throw new RuntimeException('boom');
        }

        return [['id' => 'r1', 'score' => 0.9, 'payload' => []]];
    }

    public function upsertPoints(string $collection, array $points): void
    {
        $this->calls++;
        if ($this->shouldFail) {
            throw new RuntimeException('boom');
        }
    }

    public function deleteByFilter(string $collection, array $filter): void
    {
        $this->calls++;
        if ($this->shouldFail) {
            throw new RuntimeException('boom');
        }
    }

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void
    {
        $this->calls++;
        if ($this->shouldFail) {
            throw new RuntimeException('boom');
        }
    }

    public function dropCollection(string $name): void
    {
        $this->calls++;
        if ($this->shouldFail) {
            throw new RuntimeException('boom');
        }
    }
}

beforeEach(function () {
    Cache::flush();
});

test('search returns empty array when circuit is open', function () {
    $flaky = new FlakyClient;
    $breaker = new CircuitBreakingVectorClient($flaky, 'flaky', 2, 60, 60);

    $flaky->shouldFail = true;
    try {
        $breaker->search('c', [0.1], [], 5);
    } catch (Throwable) {
    }
    try {
        $breaker->search('c', [0.1], [], 5);
    } catch (Throwable) {
    }

    expect($breaker->isOpen())->toBeTrue();

    $result = $breaker->search('c', [0.1], [], 5);
    expect($result)->toBe([]);
});

test('upsert throws CircuitOpenException when circuit is open', function () {
    $flaky = new FlakyClient;
    $breaker = new CircuitBreakingVectorClient($flaky, 'flaky', 1, 60, 60);

    $flaky->shouldFail = true;
    try {
        $breaker->upsertPoints('c', []);
    } catch (Throwable) {
    }

    expect($breaker->isOpen())->toBeTrue();

    expect(fn () => $breaker->upsertPoints('c', []))->toThrow(CircuitOpenException::class);
});

test('successful call clears failure counter', function () {
    $flaky = new FlakyClient;
    $breaker = new CircuitBreakingVectorClient($flaky, 'flaky', 5, 60, 60);

    $flaky->shouldFail = true;
    try {
        $breaker->search('c', [0.1], [], 5);
    } catch (Throwable) {
    }
    try {
        $breaker->search('c', [0.1], [], 5);
    } catch (Throwable) {
    }

    expect((int) Cache::get('vector_circuit:flaky:fails', 0))->toBe(2);

    $flaky->shouldFail = false;
    $breaker->search('c', [0.1], [], 5);

    expect(Cache::get('vector_circuit:flaky:fails'))->toBeNull();
});

test('circuit opens after threshold and recovers after cooldown', function () {
    $flaky = new FlakyClient;
    $breaker = new CircuitBreakingVectorClient($flaky, 'flaky', 3, 60, 1);

    $flaky->shouldFail = true;
    for ($i = 0; $i < 3; $i++) {
        try {
            $breaker->search('c', [0.1], [], 5);
        } catch (Throwable) {
        }
    }
    expect($breaker->isOpen())->toBeTrue();

    Cache::forget('vector_circuit:flaky:open');
    $flaky->shouldFail = false;
    $result = $breaker->search('c', [0.1], [], 5);

    expect($result)->toHaveCount(1);
    expect($breaker->isOpen())->toBeFalse();
});

test('healthy client passes through with no side effects', function () {
    $flaky = new FlakyClient;
    $breaker = new CircuitBreakingVectorClient($flaky, 'flaky');

    $result = $breaker->search('c', [0.1], [], 5);
    expect($result)->toHaveCount(1);
    expect($flaky->calls)->toBe(1);
    expect($breaker->isOpen())->toBeFalse();
});
