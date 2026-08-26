<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Workflows\WorkflowSimulator;
use App\Support\AuditLogger;
use App\Support\CurrentWorkspace;
use App\Support\Pagination;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-side CRUD for workflows. Phase 1 ships a linear-step
 * editor — the admin picks a trigger keyword set and adds an ordered
 * list of message / question / escalate steps. Phase 2 added branch /
 * tag_lead / webhook step types and the React-Flow canvas editor.
 *
 * The runtime executor (App\Services\Workflows\WorkflowEngine) reads
 * `definition.steps` directly, so the JSON shape this controller
 * accepts == the shape the engine consumes.
 */
class WorkflowController
{
    private function gateManageWorkflows(User $user, Workspace $workspace): void
    {
        $role = Tenancy::roleFor($user, $workspace);
        if ($role === null || ! $role->canManageAgents()) {
            abort(403);
        }
    }

    public function index(Request $request, CurrentWorkspace $current): Response
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        // index() is read-only — Viewer is allowed. Use canViewAnalytics
        // semantics (all roles) by checking membership only.
        abort_unless(Tenancy::isMember($request->user(), $workspace), 403);

        $q = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', 'all');
        $sort = (string) $request->query('sort', 'updated_desc');

        if (! in_array($view, ['all', 'active', 'draft', 'disabled'], true)) {
            $view = 'all';
        }

        if (! in_array(
            $sort,
            ['updated_desc', 'updated_asc', 'name_asc', 'name_desc'],
            true,
        )) {
            $sort = 'updated_desc';
        }

        $query = Workflow::query();

        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }

        if (in_array($view, ['active', 'draft', 'disabled'], true)) {
            $query->where('status', $view);
        }

        match ($sort) {
            'updated_asc' => $query->oldest('updated_at'),
            'name_asc' => $query->orderBy('name'),
            'name_desc' => $query->orderByDesc('name'),
            default => $query->latest('updated_at'),
        };

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (Workflow $w) => $this->serialize($w))
            ->values();

        return Inertia::render('app/workflows/index', [
            'workflows' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => [
                'q' => $q,
                'view' => $view,
                'sort' => $sort,
            ],
        ]);
    }

    public function create(Request $request, CurrentWorkspace $current): Response
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageWorkflows($request->user(), $workspace);

        // Canvas-first creation: ?canvas=1 opens the visual builder on
        // an unsaved draft (workflow: null) — Save POSTs store() and
        // lands on the persisted workflow's canvas. The classic form
        // stays the default.
        if ($request->boolean('canvas')) {
            return Inertia::render('app/workflows/canvas', [
                'workflow' => null,
                'agents' => $this->agentOptions(),
            ]);
        }

        return Inertia::render('app/workflows/create', [
            'agents' => $this->agentOptions(),
        ]);
    }

    /**
     * Stateless dry-run for the canvas "Test run" panel. Receives the
     * DRAFT definition straight from the editor (so unsaved changes and
     * brand-new flows are testable) plus the visitor messages, and
     * traces the exact engine semantics with zero side effects — see
     * WorkflowSimulator. Nothing is persisted; tenancy needs no row
     * scoping because no tenant data is read or written.
     */
    public function simulate(Request $request, CurrentWorkspace $current, WorkflowSimulator $simulator): JsonResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageWorkflows($request->user(), $workspace);

        $data = $request->validate([
            'match_mode' => ['sometimes', Rule::in(['any', 'all', 'exact'])],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:120'],
            'messages' => ['required', 'array', 'min:1', 'max:12'],
            'messages.*' => ['string', 'max:2000'],
            ...$this->stepRules(),
        ]);

        return response()->json([
            'data' => $simulator->simulate(
                array_values(array_filter((array) ($data['keywords'] ?? []), fn ($k) => is_string($k) && trim($k) !== '')),
                (string) ($data['match_mode'] ?? 'any'),
                array_values((array) $data['steps']),
                array_values((array) $data['messages']),
            ),
        ]);
    }

    public function store(Request $request, CurrentWorkspace $current, PlanLimits $limits): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageWorkflows($request->user(), $workspace);

        $check = $limits->check($workspace, PlanLimits::RESOURCE_WORKFLOW);
        if (! $check['allowed']) {
            return redirect()
                ->route('workflows.index')
                ->with('error', $limits->reasonFor(PlanLimits::RESOURCE_WORKFLOW, (int) $check['limit']));
        }

        $data = $this->validatedFor($request);

        $workflow = Workflow::create([
            'agent_id' => $data['agent_id'] ?? null,
            'name' => $data['name'],
            'status' => $data['status'] ?? 'draft',
            'trigger_kind' => $data['trigger_kind'],
            'trigger_config' => [
                'keywords' => $data['keywords'] ?? [],
                'match_mode' => $data['match_mode'] ?? 'any',
            ],
            'definition' => array_filter([
                'steps' => $data['steps'] ?? [],
                'canvas' => $data['definition_canvas'] ?? null,
            ], fn ($v) => $v !== null),
            'created_by_user_id' => $request->user()?->id,
        ]);

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'workflow.created',
            entityType: 'workflow',
            entityId: $workflow->id,
            after: ['name' => $workflow->name, 'status' => $workflow->status, 'trigger_kind' => $workflow->trigger_kind],
            request: $request,
        );

        // Drop the admin straight onto the canvas — that's the default
        // editor for Phase 2+ flows. The linear form is still reachable
        // from the canvas page's "Linear edit" button for simple flows.
        return redirect()->route('workflows.canvas', ['workflow' => $workflow->id])
            ->with('success', "Workflow '{$workflow->name}' created.");
    }

    public function edit(Request $request, Workflow $workflow): Response
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($workflow->workspace_id);
        $this->gateManageWorkflows($request->user(), $workspace ?? abort(404));

        return Inertia::render('app/workflows/edit', [
            'workflow' => $this->serialize($workflow),
            'agents' => $this->agentOptions(),
        ]);
    }

    /**
     * Visual editor at /app/workflows/{workflow}/canvas. The page is
     * the React-Flow canvas; the linear edit page stays available as
     * a fallback for simple flows. Both write through the same PATCH
     * route — the canvas just adds a `definition_canvas` block alongside
     * the linear `steps[]` so positions persist.
     */
    public function canvas(Request $request, Workflow $workflow): Response
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($workflow->workspace_id);
        $this->gateManageWorkflows($request->user(), $workspace ?? abort(404));

        return Inertia::render('app/workflows/canvas', [
            'workflow' => $this->serialize($workflow),
            'agents' => $this->agentOptions(),
        ]);
    }

    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($workflow->workspace_id);
        $this->gateManageWorkflows($request->user(), $workspace ?? abort(404));

        $data = $this->validatedFor($request);
        $before = $workflow->only(['name', 'status', 'trigger_kind']);

        $workflow->update([
            'agent_id' => $data['agent_id'] ?? null,
            'name' => $data['name'],
            'status' => $data['status'] ?? $workflow->status,
            'trigger_kind' => $data['trigger_kind'],
            'trigger_config' => [
                'keywords' => $data['keywords'] ?? [],
                'match_mode' => $data['match_mode'] ?? 'any',
            ],
            'definition' => array_filter([
                'steps' => $data['steps'] ?? [],
                'canvas' => $data['definition_canvas'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'workflow.updated',
            entityType: 'workflow',
            entityId: $workflow->id,
            before: $before,
            after: $workflow->only(['name', 'status', 'trigger_kind']),
            request: $request,
        );

        // Saving from the canvas keeps the admin ON the canvas — being
        // bounced to the index after every save made iterating on a
        // flow miserable. The linear form keeps its index redirect.
        if ($request->input('_editor') === 'canvas') {
            return redirect()->route('workflows.canvas', ['workflow' => $workflow->id])
                ->with('success', "Workflow '{$workflow->name}' updated.");
        }

        return redirect()->route('workflows.index')
            ->with('success', "Workflow '{$workflow->name}' updated.");
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($workflow->workspace_id);
        $this->gateManageWorkflows($request->user(), $workspace ?? abort(404));

        $snapshot = ['name' => $workflow->name, 'status' => $workflow->status];
        $workflow->delete();

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'workflow.deleted',
            entityType: 'workflow',
            entityId: $workflow->id,
            before: $snapshot,
            request: $request,
        );

        return redirect()->route('workflows.index')
            ->with('success', 'Workflow deleted.');
    }

    /**
     * Bulk delete workflows from the index page. Tenancy is enforced by
     * the global Workspace scope on `Workflow` — `whereIn('id', ...)`
     * already silently drops cross-workspace ids.
     */
    public function bulkDestroy(Request $request, CurrentWorkspace $current): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageWorkflows($request->user(), $workspace);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $targets = Workflow::query()
            ->whereIn('id', $data['ids'])
            ->get(['id', 'name'])
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])
            ->all();

        $deleted = Workflow::query()->whereIn('id', $data['ids'])->delete();

        foreach ($targets as $t) {
            AuditLogger::log(
                workspaceId: $workspace->id,
                action: 'workflow.bulk_deleted',
                entityType: 'workflow',
                entityId: $t['id'],
                before: ['name' => $t['name']],
                request: $request,
            );
        }

        return back()->with('success', "{$deleted} workflow(s) deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Workflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'agent_id' => $workflow->agent_id,
            'trigger_kind' => $workflow->trigger_kind,
            'keywords' => $workflow->keywords(),
            'match_mode' => (string) ($workflow->trigger_config['match_mode'] ?? 'any'),
            'steps' => $workflow->steps(),
            'definition' => $workflow->definition,
            'created_at' => $workflow->created_at?->toIso8601String(),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function agentOptions(): array
    {
        return Agent::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request): array
    {
        // Activation guard: an active workflow with no keywords can
        // never fire (keyword matching returns false on an empty set),
        // so flipping status to active demands at least one. Drafts
        // stay saveable in any half-finished state.
        $activating = $request->input('status') === 'active';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'disabled'])],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
            'agent_id' => ['nullable', 'string', 'exists:agents,id'],
            'trigger_kind' => ['required', Rule::in(['on_keyword'])],
            'match_mode' => ['sometimes', Rule::in(['any', 'all', 'exact'])],
            'keywords' => [$activating ? 'required' : 'nullable', 'array', $activating ? 'min:1' : 'max:20', 'max:20'],
            'keywords.*' => ['string', 'max:120'],

            ...$this->stepRules(),

            // Canvas state — opaque blob of x/y positions + edges so
            // the visual editor can re-open a workflow with the same
            // layout. Runtime ignores it; only the editor reads it.
            'definition_canvas' => ['nullable', 'array'],
            'definition_canvas.nodes' => ['nullable', 'array', 'max:128'],
            'definition_canvas.nodes.*.id' => ['required_with:definition_canvas.nodes.*', 'string', 'max:64'],
            'definition_canvas.edges' => ['nullable', 'array', 'max:256'],
            'definition_canvas.edges.*.source' => ['required_with:definition_canvas.edges.*', 'string', 'max:64'],
            'definition_canvas.edges.*.target' => ['required_with:definition_canvas.edges.*', 'string', 'max:64'],
        ]);

        $this->rejectCanvasOrphans($data);

        return $data;
    }

    /**
     * Step-array validation shared by store/update and the simulate
     * endpoint, so a definition that saves is exactly a definition
     * that can be test-run.
     *
     * @return array<string, array<int, mixed>>
     */
    private function stepRules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:64'],

            // Per-step base fields. `text` is required only for the
            // step types that emit a chat bubble (message / question /
            // escalate). Side-effect steps (branch / tag_lead /
            // webhook) leave it nullable.
            'steps.*.type' => ['required', Rule::in([
                'message', 'question', 'escalate',
                'branch', 'tag_lead', 'webhook',
            ])],
            'steps.*.text' => ['nullable', 'string', 'max:1000'],
            'steps.*.var_name' => ['nullable', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],

            // branch — case-by-case routing on a captured var.
            'steps.*.var' => ['nullable', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'steps.*.cases' => ['nullable', 'array', 'max:16'],
            'steps.*.cases.*.match' => [Rule::in([
                'equals', 'contains', 'starts_with',
                'is_empty', 'not_empty', 'default',
            ])],
            'steps.*.cases.*.value' => ['nullable', 'string', 'max:200'],
            'steps.*.cases.*.go_to' => ['integer', 'min:0', 'max:64'],

            // tag_lead — append tag strings to the lead's fields.tags array.
            'steps.*.tags' => ['nullable', 'array', 'max:8'],
            'steps.*.tags.*' => ['string', 'max:32'],

            // webhook — outbound HTTP from the workflow run.
            'steps.*.url' => ['nullable', 'required_if:steps.*.type,webhook', 'url', 'max:2000'],
            'steps.*.method' => ['nullable', Rule::in(['POST', 'GET'])],
            'steps.*.extra_payload' => ['nullable', 'array'],
        ];
    }

    /**
     * Edges may only reference node ids that exist in the same canvas.
     * Visual editor surfaces transient orphans during drag; persisted
     * state must be consistent.
     *
     * @param  array<string, mixed>  $data
     */
    private function rejectCanvasOrphans(array $data): void
    {
        $canvas = $data['definition_canvas'] ?? null;
        if (! is_array($canvas)) {
            return;
        }
        $nodes = (array) ($canvas['nodes'] ?? []);
        $edges = (array) ($canvas['edges'] ?? []);
        if ($edges === []) {
            return;
        }
        // React-Flow reserves virtual ids for the implicit start / end
        // (they live outside the user-defined node list).
        $reserved = ['trigger', 'start', 'end'];
        $userNodeIds = array_filter(array_map(
            static fn ($n) => is_array($n) ? ($n['id'] ?? null) : null,
            $nodes,
        ));
        $nodeIds = array_flip(array_merge($reserved, $userNodeIds));
        foreach ($edges as $idx => $edge) {
            if (! is_array($edge)) {
                continue;
            }
            foreach (['source', 'target'] as $end) {
                $id = $edge[$end] ?? null;
                if (is_string($id) && ! isset($nodeIds[$id])) {
                    throw ValidationException::withMessages([
                        "definition_canvas.edges.{$idx}.{$end}" => "Edge {$end} references a node that does not exist in this canvas.",
                    ]);
                }
            }
        }
    }
}
