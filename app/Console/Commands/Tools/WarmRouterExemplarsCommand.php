<?php

namespace App\Console\Commands\Tools;

use App\Services\Tools\ToolExemplarStore;
use App\Services\Tools\ToolRegistry;
use Illuminate\Console\Command;

/**
 * Warm every registered tool's routing centroid. Run on deploy (and
 * safe to run anytime — content-addressed cache keys make it
 * idempotent). Without warming, turns route on keywords only until
 * the queued auto-warm catches up.
 *
 *   php artisan router:warm
 */
class WarmRouterExemplarsCommand extends Command
{
    protected $signature = 'router:warm';

    protected $description = 'Embed + cache intent centroids for every registered tool (fast router)';

    public function handle(ToolRegistry $registry, ToolExemplarStore $store): int
    {
        foreach ($registry->all() as $tool) {
            $store->warm($tool);
            $this->line('warmed: '.$tool->name());
        }

        $this->components->info('Router exemplar centroids warmed.');

        return self::SUCCESS;
    }
}
