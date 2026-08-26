<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Plan;
use App\Services\Widget\WidgetDefaultsResolver;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run onboarding wizard. Walks the workspace owner through:
 *   1. Confirm/edit the website URL their agent will learn from.
 *   2. Auto-discover crawlable pages (sitemap + common paths).
 *   3. Pick which to ingest, watch them flip to "indexed".
 *   4. Copy-paste the embed snippet onto their site.
 *
 * The wizard pulls the same /discover and /bulk endpoints used by the
 * regular Sources page — it's UI on top of the existing pipeline, not a
 * parallel one.
 */
class OnboardingController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->oldest()
            ->first();

        // Track whether the wizard is configuring a brand-new agent
        // (created right now) vs an existing one. The frontend uses
        // this to label the "Subject card" — "Creating your first
        // agent" vs "Configuring agent" — so admins are never confused
        // about what's happening.
        $isNewAgent = $agent === null;

        if ($agent === null) {
            // Inherit theme / persona / guardrails / starter prompts from
            // the workspace's widget defaults (with platform → ship
            // fallback). Workspaces that haven't customised their
            // defaults still produce a working agent because the
            // resolver always returns at least the hardcoded floor.
            $defaults = app(WidgetDefaultsResolver::class)->for($workspace);

            $agent = Agent::create([
                'workspace_id' => $workspace->id,
                'name' => $workspace->name.' agent',
                'language_default' => 'en',
                'persona' => $defaults['persona'],
                'theme' => $defaults['theme'],
                'guardrails' => $defaults['guardrails'],
                'starter_prompts' => $defaults['starter_prompts'] === [] ? null : $defaults['starter_prompts'],
                'allowed_origins' => [],
                'confidence_threshold' => (float) config('services.rag.confidence_threshold', 0.5),
            ]);
        }

        $startUrl = $agent->allowed_origins[0] ?? null;

        $widgetSrc = $this->buildWidgetSrc();

        // Persist the start URL into allowed_origins so the wizard can pre-fill on reload.
        if ($startUrl === null && ! empty($request->query('domain'))) {
            $startUrl = (string) $request->query('domain');
        }

        $currentPlan = $workspace->plan_id !== null
            ? Plan::query()->withoutGlobalScopes()->find($workspace->plan_id)
            : null;

        $availablePlans = Plan::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get();

        return Inertia::render('onboarding/index', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'is_published' => $agent->is_published,
                'sources_count' => $agent->sources()->withoutGlobalScopes()->count(),
                'sources_indexed' => $agent->sources()->withoutGlobalScopes()->where('status', 'indexed')->count(),
            ],
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ],
            'is_new_agent' => $isNewAgent,
            'start_url' => $startUrl,
            'widget' => [
                'src' => $widgetSrc,
                'agent_id' => $agent->id,
            ],
            'current_plan' => $currentPlan === null ? null : [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'price_cents' => (int) $currentPlan->price_cents,
            ],
            'available_plans' => $availablePlans
                ->map(fn (Plan $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price_cents' => (int) $p->price_cents,
                    'interval' => $p->interval ?? 'month',
                    'monthly_conversations' => (int) ($p->monthly_conversations ?? 0),
                ])
                ->values(),
        ]);
    }

    /**
     * Lightweight JSON poll for the wizard's "indexed of N" line. Cheap so we
     * can hit it every ~3s without backing onto the queue or DB hot path.
     */
    public function status(Request $request, Agent $agent): JsonResponse
    {
        $request->user()->can('view', $agent) || abort(403);

        return response()->json([
            'data' => [
                'sources_count' => $agent->sources()->withoutGlobalScopes()->count(),
                'sources_indexed' => $agent->sources()->withoutGlobalScopes()->where('status', 'indexed')->count(),
                'is_published' => (bool) $agent->is_published,
            ],
        ]);
    }

    /**
     * Build the widget script URL for the onboarding install step.
     *
     * Always returns the STABLE path `/widget/widget.js?v=<hash>`.
     * Pre-fix this returned `/widget/widget.<hash>.js` directly from
     * the build manifest, which meant every deploy rotated the URL and
     * the customer's pasted `<script src="...">` 404'd. Buyer
     * report 2026-05-29. See `AgentController::widgetUrl()` for the
     * full reasoning.
     */
    private function buildWidgetSrc(): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $manifestPath = public_path('widget/manifest.json');
        $version = 'dev';

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ! empty($manifest['hash'])) {
                $version = substr((string) $manifest['hash'], 0, 12);
            }
        }

        if ($version === 'dev') {
            $path = public_path('widget/widget.js');
            if (is_file($path)) {
                $version = substr((string) md5_file($path), 0, 8);
            }
        }

        return $appUrl.'/widget/widget.js?v='.$version;
    }
}
