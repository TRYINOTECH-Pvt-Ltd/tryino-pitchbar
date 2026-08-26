<?php

namespace App\Services\Telemetry;

class NoopTracer
{
    public function spanBuilder(string $name): self
    {
        return $this;
    }

    public function startSpan(): NoopSpan
    {
        return new NoopSpan;
    }
}

class NoopSpan
{
    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    public function recordException(\Throwable $e): self
    {
        return $this;
    }

    public function setStatus(string $status, ?string $description = null): self
    {
        return $this;
    }

    public function end(): void {}
}
