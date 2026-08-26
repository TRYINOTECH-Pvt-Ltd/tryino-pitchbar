<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Lead;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController
{
    /**
     * Hard-delete a lead from the platform admin. Lead model has no
     * SoftDeletes — row is removed outright. Super_admin middleware on
     * the route group is the only gate (no per-row Policy because the
     * platform admin sees every tenant by design).
     *
     * Card #59 (bulk-selection wiring rollout).
     */
    public function destroy(string $lead): RedirectResponse
    {
        $model = Lead::query()
            ->withoutGlobalScopes()
            ->findOrFail($lead);

        $model->delete();

        return redirect()
            ->route('admin.leads.index')
            ->with('success', 'Lead deleted.');
    }

    /**
     * Bulk-delete leads from /admin/leads. See destroy() above for the
     * gating rationale.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $deleted = Lead::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $data['ids'])
            ->delete();

        return redirect()
            ->route('admin.leads.index')
            ->with('success', $deleted.' lead(s) deleted.');
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = Lead::query()->withoutGlobalScopes()
            // Closure form for eager loads so we can drop the Agent
            // WorkspaceScope on the join. The string-attribute form
            // (`agent:id,name,...`) inherits global scopes and silently
            // returns `null` for the agent relation when admin's
            // CurrentWorkspace !== the lead's agent's workspace_id —
            // which made the Workspace + Agent columns render as
            // en-dashes for every row in /admin/leads.
            ->with([
                'agent' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name', 'workspace_id', 'deleted_at'),
                'agent.workspace' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name'),
            ])
            ->latest();

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'email' => $l->email,
                'name' => $l->name,
                'status' => $l->status,
                'agent' => $l->agent?->only('id', 'name'),
                'workspace' => $l->agent?->workspace?->only('id', 'name'),
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/leads/index', [
            'leads' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }
}
