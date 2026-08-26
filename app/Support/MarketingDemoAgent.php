<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the agent id used by the live demo widget on marketing
 * pages. Single source of truth so the Inertia home page, the Blade
 * marketing pages, and any test all agree on which agent loads.
 *
 * Resolution order:
 *   1. Explicit MARKETING_DEMO_AGENT_ID env (operator override).
 *   2. The first published agent in the "pitchbar-demo" workspace —
 *      automatic on a fresh install once DemoSeeder has run.
 */
final class MarketingDemoAgent
{
    public static function id(): ?string
    {
        $explicit = config('services.marketing.demo_agent_id');
        if (is_string($explicit) && $explicit !== '') {
            // Validate the explicit env-pinned id still resolves to a
            // published agent. Operators commonly re-seed the demo
            // agent (`pitchbar:seed-demo-agent`) which mints a fresh
            // uuid; the stale env value then makes /widget/init return
            // 404 agent_not_found and the marketing widget sits silent.
            // Falling through to auto-discovery when the explicit id
            // is invalid means the widget heals itself after a re-seed
            // without an operator restart. Buyer reported 2026-05-21.
            $valid = Cache::remember(
                'marketing.demo_agent_id.explicit_valid.'.$explicit,
                now()->addMinutes(2),
                fn () => Agent::query()->withoutGlobalScopes()
                    ->where('id', $explicit)
                    ->where('is_published', true)
                    ->exists(),
            );
            if ($valid) {
                return $explicit;
            }
        }

        return Cache::remember('marketing.demo_agent_id', now()->addMinutes(5), function () {
            $workspace = Workspace::query()->withoutGlobalScopes()
                ->where('slug', 'pitchbar-demo')
                ->first();

            if ($workspace === null) {
                return null;
            }

            return Agent::query()->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_published', true)
                ->orderBy('created_at')
                ->value('id');
        });
    }
}
