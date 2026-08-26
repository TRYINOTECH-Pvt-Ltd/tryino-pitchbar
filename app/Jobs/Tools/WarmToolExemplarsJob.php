<?php

namespace App\Jobs\Tools;

use App\Services\Tools\ToolExemplarStore;
use App\Services\Tools\ToolRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Embeds a tool's intent exemplars into a routing centroid, off the
 * hot path. Dispatched by ToolExemplarStore::requestWarm() (behind a
 * 5-minute lock) when a turn finds the centroid cold, and by the
 * `router:warm` deploy command for every registered tool.
 */
class WarmToolExemplarsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly string $toolName) {}

    public function handle(ToolRegistry $registry, ToolExemplarStore $store): void
    {
        $tool = $registry->get($this->toolName);
        if ($tool === null) {
            return;
        }

        $store->warm($tool);
    }
}
