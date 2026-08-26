<?php

namespace App\Services\Vector;

use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps a Vector client (Vectorize or Qdrant) with a per-instance
 * circuit breaker.
 *
 * Hot-path behaviour during a Vectorize regional outage:
 *
 *   - `search()` returns `[]` so the visitor turn keeps streaming
 *     (the LLM falls back to general knowledge + conversation
 *     history without `<source>` chunks).
 *   - `upsertPoints()` / `deleteByFilter()` / `ensureCollection()` /
 *     `dropCollection()` throw `CircuitOpenException` so the
 *     queued worker re-queues instead of silently dropping.
 *
 * Failure counter is stored in Cache with a 60s window. Once it
 * trips, the open flag carries its own TTL (cooldown). On the next
 * call after the cooldown, a single "probe" goes through; if it
 * succeeds, the counter resets; if it fails again, the circuit
 * stays open for another cooldown window.
 */
class CircuitBreakingVectorClient implements QdrantClient
{
    public function __construct(
        private readonly QdrantClient $delegate,
        private readonly string $clientLabel,
        private readonly int $failureThreshold = 5,
        private readonly int $windowSeconds = 60,
        private readonly int $cooldownSeconds = 60,
    ) {}

    public function search(string $collection, array $vector, array $filter, int $limit): array
    {
        if ($this->isOpen()) {
            return [];
        }

        return $this->call(fn () => $this->delegate->search($collection, $vector, $filter, $limit));
    }

    public function upsertPoints(string $collection, array $points): void
    {
        $this->refuseIfOpen();
        $this->call(fn () => $this->delegate->upsertPoints($collection, $points));
    }

    public function deleteByFilter(string $collection, array $filter): void
    {
        $this->refuseIfOpen();
        $this->call(fn () => $this->delegate->deleteByFilter($collection, $filter));
    }

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void
    {
        $this->refuseIfOpen();
        $this->call(fn () => $this->delegate->ensureCollection($name, $dim, $distance));
    }

    public function dropCollection(string $name): void
    {
        $this->refuseIfOpen();
        $this->call(fn () => $this->delegate->dropCollection($name));
    }

    private function call(callable $fn): mixed
    {
        try {
            $result = $fn();
            $this->onSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->onFailure($e);
            throw $e;
        }
    }

    private function refuseIfOpen(): void
    {
        if ($this->isOpen()) {
            throw new CircuitOpenException($this->clientLabel);
        }
    }

    public function isOpen(): bool
    {
        return Cache::get($this->openKey()) === true;
    }

    private function onSuccess(): void
    {
        Cache::forget($this->openKey());
        Cache::forget($this->counterKey());
    }

    private function onFailure(Throwable $e): void
    {
        $key = $this->counterKey();
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $this->windowSeconds);

        if ($count >= $this->failureThreshold) {
            Cache::put($this->openKey(), true, $this->cooldownSeconds);
            Log::warning('vector.circuit_open', [
                'client' => $this->clientLabel,
                'count' => $count,
                'cooldown' => $this->cooldownSeconds,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    private function openKey(): string
    {
        return "vector_circuit:{$this->clientLabel}:open";
    }

    private function counterKey(): string
    {
        return "vector_circuit:{$this->clientLabel}:fails";
    }
}
