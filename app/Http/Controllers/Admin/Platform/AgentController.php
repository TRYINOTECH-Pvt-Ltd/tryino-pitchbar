<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Jobs\Agents\PurgeAgentVectorsJob;
use App\Models\Agent;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController
{
    /**
     * Resolve any agent across the platform, bypassing the
     * BelongsToWorkspace global scope. Used by every method on this
     * platform-admin controller — implicit route-model binding would
     * apply WorkspaceScope and 404 whenever the admin's
     * CurrentWorkspace doesn't match the agent's workspace, which is
     * the common case when cleaning up orphans for a customer.
     */
    private function findAgentOrFail(string $id): Agent
    {
        /** @var Agent $agent */
        $agent = Agent::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);

        return $agent;
    }

    /**
     * Inline rename + publish toggle from the /admin/agents row, without
     * having to impersonate the workspace owner. Doesn't expose the full
     * customer-side agent editor — that lives at
     * /app/agents/{id}/settings and the admin can still reach it by
     * impersonating.
     */
    public function update(Request $request, string $agent): RedirectResponse
    {
        $model = $this->findAgentOrFail($agent);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $model->forceFill($data)->save();

        return redirect()
            ->route('admin.agents.index')
            ->with('success', "Agent '{$model->name}' updated.");
    }

    /**
     * Permanently delete an agent from the platform admin. Client report
     * 2026-05-23: a customer's workspace had a phantom agent visible in
     * /admin/agents but hidden in the customer's own /app/agents because
     * its workspace_id pointed at a workspace the customer no longer had
     * as their current context. The customer-side `destroy` 404s for the
     * same reason. This bypasses WorkspaceScope so super_admin can clean
     * up the orphan from the platform view.
     */
    public function destroy(string $agent): RedirectResponse
    {
        $model = $this->findAgentOrFail($agent);
        $name = $model->name;

        // Match customer-side semantics: hard-delete so cascade reaches
        // every child row + queue PurgeAgentVectorsJob so the vector
        // store isn't left with orphans (audit 2026-05-30).
        PurgeAgentVectorsJob::dispatch($model->id);
        $model->forceDelete();

        return redirect()
            ->route('admin.agents.index')
            ->with('success', "Agent '{$name}' deleted.");
    }

    /**
     * Bulk-delete agents from the platform admin index. Super_admin
     * middleware on the route group already gates the action; no per-
     * row Policy needed (platform admin sees every workspace by design).
     * Bypasses WorkspaceScope so orphan agents across tenants delete.
     *
     * Card #58 (bulk-selection wiring rollout).
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        // Match customer-side: hard-delete + vector purge so platform
        // bulk delete doesn't leave soft-deleted rows the customer can't
        // see + orphan vectors in Vectorize.
        $deleted = Agent::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $data['ids'])
            ->get()
            ->each(function (Agent $a) {
                PurgeAgentVectorsJob::dispatch($a->id);
                $a->forceDelete();
            })
            ->count();

        return redirect()
            ->route('admin.agents.index')
            ->with('success', $deleted.' agent(s) deleted.');
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = Agent::query()->withoutGlobalScopes()
            ->with(['workspace:id,name,slug'])
            // Counts MUST drop the BelongsToAgent global scope on the
            // related models — otherwise the subquery filters by
            // CurrentWorkspace (which is the admin's own workspace,
            // not the agent's), zeroing out every row on the platform
            // /admin/agents page.
            ->withCount([
                'sources as sources_count' => fn ($q) => $q->withoutGlobalScopes(),
                'conversations as conversations_count' => fn ($q) => $q->withoutGlobalScopes()->where('is_playground', false),
            ])
            ->latest('updated_at');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('name', 'like', $like)
                    ->orWhereHas('workspace', fn ($ws) => $ws->where('name', 'like', $like));
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'is_published' => $a->is_published,
                'language_default' => $a->language_default,
                'workspace' => $a->workspace?->only('id', 'name'),
                'sources_count' => $a->sources_count,
                'conversations_count' => $a->conversations_count,
                'updated_at' => $a->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/agents/index', [
            'agents' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }
}
