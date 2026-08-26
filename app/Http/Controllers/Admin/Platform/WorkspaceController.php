<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Workspace;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController
{
    /**
     * Soft-delete a workspace from the platform admin. Workspace model
     * uses SoftDeletes, so the row + all its agents/conversations/leads
     * remain in the DB for audit; they're filtered out of normal queries
     * by the global scope on `deleted_at`. Restoration is a manual DB
     * operation — there's no UI yet (card #61 only covers delete).
     *
     * Safety: refuse to delete the admin's own current workspace —
     * doing so would log them out of the very surface they used to
     * trigger the action.
     */
    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        if ($workspace->id === $request->user()?->default_workspace_id) {
            return back()->with('error', "Refusing to delete the workspace you're currently signed into. Switch workspaces first.");
        }

        $workspace->delete();

        return redirect()
            ->route('admin.workspaces.index')
            ->with('success', "Workspace '{$workspace->name}' deleted.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $currentWorkspaceId = $request->user()?->default_workspace_id;
        $deleted = 0;

        Workspace::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $data['ids'])
            ->get()
            ->each(function (Workspace $w) use ($currentWorkspaceId, &$deleted) {
                if ($w->id === $currentWorkspaceId) {
                    return;
                }
                $w->delete();
                $deleted++;
            });

        return redirect()
            ->route('admin.workspaces.index')
            ->with('success', $deleted.' workspace(s) deleted.');
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        // No bare withoutGlobalScopes() here: Workspace carries no tenancy
        // global scope (it IS the tenant root), so the only scope a bare
        // call would strip is Laravel's SoftDeletingScope — which made
        // soft-deleted workspaces keep showing in this list after a
        // super-admin deleted them (buyer-reported). A plain query already
        // returns every workspace AND honours the soft-delete filter.
        $query = Workspace::query()
            ->with(['plan:id,name,slug', 'owner:id,name,email'])
            ->withCount([
                // Agent::query() carries BelongsToWorkspace global scope
                // that pins rows to app(CurrentWorkspace)->id() — for an
                // admin browsing /admin/workspaces that means every OTHER
                // workspace's agent count drops to 0. Bypass the scope so
                // the Footprint column is honest.
                'agents' => fn ($q) => $q->withoutGlobalScopes(),
                'workspaceUsers as members_count',
            ])
            ->latest();

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhereHas('owner', function ($o) use ($like) {
                        $o->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'plan' => $w->plan?->only('name', 'slug'),
                'owner' => $w->owner?->only('id', 'name', 'email'),
                'agents_count' => $w->agents_count,
                'members_count' => $w->members_count,
                'created_at' => $w->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/workspaces/index', [
            'workspaces' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }

    public function show(Request $request, Workspace $workspace): Response
    {
        // Workspace model has no global scope, but agents/leads/conversations under it do.
        $agents = Agent::query()->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'name', 'is_published', 'language_default', 'created_at']);

        // Cheap two-step instead of `whereHas('agent', ...)`. The
        // `agent` relation goes through Agent::class which has the
        // WorkspaceScope global scope — joining through it from the
        // platform-admin context (different CurrentWorkspace) silently
        // returned 0 rows. The whereIn against pre-resolved agent ids
        // is also faster than the nested subquery in MySQL.
        $agentIds = $agents->pluck('id');

        $conversationCount = (int) Conversation::query()->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds)
            ->where('is_playground', false)
            ->count();

        $leadCount = (int) Lead::query()->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds)
            ->count();

        return Inertia::render('admin/workspaces/show', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'plan' => $workspace->plan?->only('name', 'slug', 'monthly_conversations', 'price_cents'),
                'owner' => $workspace->owner?->only('id', 'name', 'email'),
                'created_at' => $workspace->created_at?->toIso8601String(),
            ],
            'agents' => $agents,
            'metrics' => [
                'conversations' => $conversationCount,
                'leads' => $leadCount,
            ],
        ]);
    }
}
