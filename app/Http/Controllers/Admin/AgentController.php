<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Agents\PublishAgent;
use App\Actions\Agents\RollbackAgent;
use App\Http\Requests\Agent\StoreAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Http\Resources\AgentResource;
use App\Jobs\Agents\PurgeAgentVectorsJob;
use App\Jobs\Analytics\DetectAgentSiteTypeJob;
use App\Models\Agent;
use App\Models\AgentVersion;
use App\Models\Conversation;
use App\Services\Billing\PlanLimits;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Widget\WidgetDefaultsResolver;
use App\Support\AuditLogger;
use App\Support\CurrentWorkspace;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController
{
    public function __construct(private CurrentWorkspace $current) {}

    public function index(Request $request, PlanLimits $limits): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('viewAny', [Agent::class, $workspace]) || abort(403);

        $q = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', 'all');
        $sort = (string) $request->query('sort', 'updated_desc');
        $language = trim((string) $request->query('language', 'all'));

        if (! in_array($view, ['all', 'published', 'draft'], true)) {
            $view = 'all';
        }

        if (! in_array($sort, ['updated_desc', 'updated_asc', 'name_asc', 'name_desc'], true)) {
            $sort = 'updated_desc';
        }

        if ($language === '') {
            $language = 'all';
        }

        $agentsQuery = Agent::query();

        if ($q !== '') {
            $agentsQuery->where('name', 'like', "%{$q}%");
        }

        if ($view === 'published') {
            $agentsQuery->where('is_published', true);
        }

        if ($view === 'draft') {
            $agentsQuery->where('is_published', false);
        }

        if ($language !== 'all') {
            $agentsQuery->where('language_default', $language);
        }

        match ($sort) {
            'updated_asc' => $agentsQuery->oldest('updated_at'),
            'name_asc' => $agentsQuery->orderBy('name'),
            'name_desc' => $agentsQuery->orderByDesc('name'),
            default => $agentsQuery->latest('updated_at'),
        };

        $paginator = $agentsQuery->paginate(25)->withQueryString();
        $agents = collect($paginator->items());
        $languages = Agent::query()
            ->select('language_default')
            ->distinct()
            ->orderBy('language_default')
            ->pluck('language_default')
            ->filter()
            ->values()
            ->all();

        // One batched query for per-agent counts so the index doesn't fan out N+1.
        $agentIds = $agents->pluck('id');
        $sourceCounts = \DB::table('sources')
            ->whereIn('agent_id', $agentIds)
            ->selectRaw('agent_id, count(*) as total, sum(case when status = \'indexed\' then 1 else 0 end) as indexed')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $convoCounts = \DB::table('conversations')
            ->whereIn('agent_id', $agentIds)
            ->where('is_playground', false)
            ->where('started_at', '>=', now()->subDays(7))
            ->selectRaw('agent_id, count(*) as c')
            ->groupBy('agent_id')
            ->pluck('c', 'agent_id');

        $leadCounts = \DB::table('leads')
            ->whereIn('agent_id', $agentIds)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('agent_id, count(*) as c')
            ->groupBy('agent_id')
            ->pluck('c', 'agent_id');

        // Workspace-wide source quota for the Knowledge column.
        // Client report 2026-05-23: when the active plan caps knowledge
        // sources at N, the column should read `used/N` rather than the
        // per-agent `indexed/total`. The Knowledge column now shows the
        // agent's source count against the workspace plan limit so the
        // operator sees how close they are to the cap without leaving
        // the index.
        $sourceQuota = $limits->check($workspace, PlanLimits::RESOURCE_SOURCE);

        return Inertia::render('app/agents/index', [
            'agents' => $agents->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'language_default' => $a->language_default,
                'is_published' => $a->is_published,
                'updated_at' => $a->updated_at->toIso8601String(),
                'sources' => [
                    'total' => (int) ($sourceCounts->get($a->id)?->total ?? 0),
                    'indexed' => (int) ($sourceCounts->get($a->id)?->indexed ?? 0),
                ],
                'conversations_7d' => (int) ($convoCounts[$a->id] ?? 0),
                'leads_7d' => (int) ($leadCounts[$a->id] ?? 0),
            ])->values(),
            'pagination' => Pagination::meta($paginator),
            'filters' => [
                'q' => $q,
                'view' => $view,
                'sort' => $sort,
                'language' => $language,
            ],
            'filterOptions' => [
                'languages' => $languages,
            ],
            'sourceQuota' => [
                'limit' => $sourceQuota['limit'],
                'current' => $sourceQuota['current'],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('create', [Agent::class, $workspace]) || abort(403);

        return Inertia::render('app/agents/create');
    }

    public function store(StoreAgentRequest $request, PlanLimits $limits): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('create', [Agent::class, $workspace]) || abort(403);

        $check = $limits->check($workspace, PlanLimits::RESOURCE_AGENT);
        if (! $check['allowed']) {
            return redirect()
                ->route('agents.index')
                ->with('error', $limits->reasonFor(PlanLimits::RESOURCE_AGENT, (int) $check['limit']));
        }

        // Pre-fill the new agent with the workspace's widget defaults
        // (theme, persona, guardrails, starter prompts). Submitted form
        // values win where present, so an admin who configured a
        // bespoke palette in the create form keeps their choices.
        $defaults = app(WidgetDefaultsResolver::class)->for($workspace);
        $payload = array_replace([
            'theme' => $defaults['theme'],
            'persona' => $defaults['persona'],
            'guardrails' => $defaults['guardrails'],
            'starter_prompts' => $defaults['starter_prompts'] === [] ? null : $defaults['starter_prompts'],
        ], $request->validated());

        // confidence_threshold column default (0.78) was tuned for
        // OpenAI's text-embedding-3-small. The app defaults to
        // Cloudflare's bge-base-en-v1.5 whose ANN cosine for relevant
        // matches lands at 0.50-0.65 — so 0.78 silently filters every
        // retrieval and the agent says "I don't have enough info" even
        // with sources indexed. Resolve from config at create time so
        // new agents inherit the env-appropriate threshold.
        if (! array_key_exists('confidence_threshold', $payload)) {
            $payload['confidence_threshold'] = (float) config('services.rag.confidence_threshold', 0.5);
        }

        $agent = Agent::create([
            'workspace_id' => $workspace->id,
            ...$payload,
        ]);

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'agent.created',
            entityType: 'agent',
            entityId: $agent->id,
            after: ['name' => $agent->name, 'site_type' => $agent->site_type],
            request: $request,
        );

        // Best-effort vertical auto-detection. Skips when admin already
        // picked a site_type in the form OR allowed_origins has nothing
        // usable. Runs in the queue so the create response is fast.
        if ($agent->site_type === null) {
            DetectAgentSiteTypeJob::dispatch($agent->id);
        }

        return redirect()->route('agents.show', $agent->id);
    }

    public function show(Request $request, Agent $agent): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $sourcesIndexed = $agent->sources()->withoutGlobalScopes()->where('status', 'indexed')->count();
        $sourcesTotal = $agent->sources()->withoutGlobalScopes()->count();

        // "Validate in playground" Setup-progress marker.
        //
        // Pre-fix this was `whereHas('messages')` against playground
        // conversations — but PlaygroundStreamController intentionally
        // does NOT persist messages to the database (see the comment
        // on line 347 of that controller: "admins shouldn't see test
        // runs in their inbox or analytics"). The marker therefore
        // never flipped, leaving the buyer's Setup progress stuck at
        // 2/3 forever even after dozens of playground turns.
        //
        // Use the playground conversation existence instead — the
        // conversation row IS created on the first turn and persists
        // independently of message persistence.
        $hasMessages = Conversation::query()->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('is_playground', true)
            ->exists();

        $widgetUrl = $this->widgetUrl();

        return Inertia::render('app/agents/show', [
            'agent' => (new AgentResource($agent))->resolve($request),
            'setup' => [
                'sources_indexed' => $sourcesIndexed,
                'sources_total' => $sourcesTotal,
                'has_messages' => $hasMessages,
            ],
            'embed' => [
                'widget_url' => $widgetUrl,
                'snippet' => "<script src=\"{$widgetUrl}\" data-agent-id=\"{$agent->id}\" async></script>",
            ],
        ]);
    }

    public function edit(Request $request, Agent $agent): Response
    {
        $request->user()->can('update', $agent) || abort(403);

        $widgetUrl = $this->widgetUrl();

        return Inertia::render('app/agents/settings', [
            'agent' => (new AgentResource($agent))->resolve($request),
            'embed' => [
                'widget_url' => $widgetUrl,
                'snippet' => "<script src=\"{$widgetUrl}\" data-agent-id=\"{$agent->id}\" async></script>",
            ],
        ]);
    }

    /**
     * Persona / theme / starter-prompts customisation page. Moved out of
     * routes/web.php closure into a controller method (audit 2026-05-16)
     * so `php artisan route:cache` can compile the route map on deploy.
     */
    public function customize(Request $request, Agent $agent): Response
    {
        $request->user()->can('update', $agent) || abort(403);

        return Inertia::render('app/agents/customize', [
            'agent' => (new AgentResource($agent))->resolve($request),
        ]);
    }

    /**
     * Vertical-preset preview page. Same closure→controller migration as
     * customize() above.
     */
    public function vertical(Request $request, Agent $agent, VerticalPresetRegistry $registry): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $presetPreview = [];
        foreach ($registry->all() as $slug => $preset) {
            $presetPreview[$slug] = [
                'slug' => $preset->slug(),
                'label' => $preset->label(),
                'short_description' => $preset->shortDescription(),
                'starter_prompts' => $preset->starterPrompts(),
                'capabilities' => $preset->capabilities(),
                'system_prompt_fragment' => $preset->systemPromptFragment(),
                'launcher_label' => $preset->launcherLabel(),
                'max_chars' => $preset->maxChars(),
            ];
        }

        return Inertia::render('app/agents/vertical', [
            'agent' => (new AgentResource($agent))->resolve($request),
            'preset_preview' => $presetPreview,
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $before = $agent->only(['name', 'site_type', 'is_published']);
        $agent->update($request->validated());

        AuditLogger::log(
            workspaceId: $agent->workspace_id,
            action: 'agent.updated',
            entityType: 'agent',
            entityId: $agent->id,
            before: $before,
            after: $agent->only(['name', 'site_type', 'is_published']),
            request: $request,
        );

        return back()->with('success', 'Agent updated.');
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('delete', $agent) || abort(403);

        $snapshot = ['id' => $agent->id, 'name' => $agent->name];

        // Hard-delete so every FK-cascade child row (conversations,
        // messages, leads, sources, documents, chunks, experiments,
        // visitor_page_views, etc.) actually goes with the agent.
        // Soft-delete left orphan playground conversations + DB rows
        // visible after the customer "deleted" their agent — buyer
        // report 2026-05-19. Cascade is enforced at the DB layer; this
        // call triggers it.
        //
        // Vector store lives outside the DB so DB cascade can't reach
        // it. Queue PurgeAgentVectorsJob BEFORE the destroy so the
        // payload (agent_id) is captured while the row still exists,
        // and so a failed dispatch doesn't strand the agent — destroy
        // only runs if dispatch succeeded.
        $agentId = $agent->id;
        $workspaceId = $agent->workspace_id;
        PurgeAgentVectorsJob::dispatch($agentId);
        $agent->forceDelete();

        AuditLogger::log(
            workspaceId: $workspaceId,
            action: 'agent.deleted',
            entityType: 'agent',
            entityId: $snapshot['id'],
            before: $snapshot,
            request: $request,
        );

        return redirect()->route('agents.index')->with('success', 'Agent deleted.');
    }

    /**
     * Bulk soft-delete agents from the index page. Each id runs through
     * the same Policy@delete gate, so cross-workspace ids silently drop
     * out of the result set. No 403 thrown for individual mismatched
     * ids — that would leak agent existence across tenants; instead,
     * unauthorized ids are skipped and the user sees "N agents deleted"
     * based on what they actually owned.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $user = $request->user();
        $deletedIds = [];

        Agent::query()->whereIn('id', $data['ids'])->get()->each(function (Agent $agent) use ($user, &$deletedIds) {
            if ($user->can('delete', $agent)) {
                $deletedIds[] = ['id' => $agent->id, 'workspace_id' => $agent->workspace_id, 'name' => $agent->name];
                PurgeAgentVectorsJob::dispatch($agent->id);
                $agent->forceDelete();
            }
        });

        foreach ($deletedIds as $entry) {
            AuditLogger::log(
                workspaceId: $entry['workspace_id'],
                action: 'agent.bulk_deleted',
                entityType: 'agent',
                entityId: $entry['id'],
                before: ['name' => $entry['name']],
                request: $request,
            );
        }

        return back()->with('success', count($deletedIds).' agent(s) deleted.');
    }

    public function publish(Request $request, Agent $agent, PublishAgent $action): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $wasPublished = (bool) $agent->is_published;
        $action->handle($agent, $request->user());

        AuditLogger::log(
            workspaceId: $agent->workspace_id,
            action: $wasPublished ? 'agent.republished' : 'agent.published',
            entityType: 'agent',
            entityId: $agent->id,
            before: ['is_published' => $wasPublished],
            after: ['is_published' => true],
            request: $request,
        );

        return back()->with('success', 'Agent published.');
    }

    public function rollback(Request $request, Agent $agent, RollbackAgent $action): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $request->validate(['version_id' => ['required', 'string']]);

        $version = AgentVersion::query()->whereKey($request->input('version_id'))->firstOrFail();
        $action->handle($agent, $version);

        AuditLogger::log(
            workspaceId: $agent->workspace_id,
            action: 'agent.rolled_back',
            entityType: 'agent',
            entityId: $agent->id,
            after: ['version_id' => $version->id],
            request: $request,
        );

        return back()->with('success', 'Agent rolled back.');
    }

    /**
     * Build the widget script URL.
     *
     * IMPORTANT: returns the STABLE path `/widget/widget.js` with a
     * `?v=<hash>` query, NEVER the hashed filename directly. The buyer
     * pastes this snippet once on their site; we then ship deploys
     * indefinitely without breaking that paste.
     *
     * Pre-fix this returned `/widget/widget.<hash>.js` from the build
     * manifest — every `npm run build:widget` rotated the hash, the
     * embed snippet text changed, and the customer's pasted `<script>`
     * pointed at a hash that no longer exists on the server
     * (`widget-postbuild.cjs` deletes the unhashed file and only the
     * current-hash file lives on disk). Buyer report 2026-05-29.
     *
     * The stable URL is served by `WidgetBundleController` which
     * resolves the manifest internally and streams the latest hashed
     * bundle with `Cache-Control: no-cache, must-revalidate`. The
     * `?v=` query is just a cache-bust hint for new pastes; existing
     * pasted snippets keep working because the path component is
     * what matters for routing.
     */
    private function widgetUrl(): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        return $appUrl.'/widget/widget.js?v='.$this->widgetVersion();
    }

    private function widgetVersion(): string
    {
        $manifestPath = public_path('widget/manifest.json');
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ! empty($manifest['hash'])) {
                return substr((string) $manifest['hash'], 0, 12);
            }
        }

        $path = public_path('widget/widget.js');

        return is_file($path) ? substr((string) md5_file($path), 0, 8) : 'dev';
    }
}
