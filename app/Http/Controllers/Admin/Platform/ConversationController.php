<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Conversation;
use App\Models\TurnTrace;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController
{
    /**
     * Hard-delete a conversation (cascades to messages by FK on delete
     * cascade). Super_admin gated via route group.
     *
     * Card #60 (bulk-selection wiring rollout).
     */
    public function destroy(string $conversation): RedirectResponse
    {
        $model = Conversation::query()
            ->withoutGlobalScopes()
            ->findOrFail($conversation);

        $model->delete();

        return redirect()
            ->route('admin.conversations.index')
            ->with('success', 'Conversation deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $deleted = Conversation::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $data['ids'])
            ->delete();

        return redirect()
            ->route('admin.conversations.index')
            ->with('success', $deleted.' conversation(s) deleted.');
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = Conversation::query()->withoutGlobalScopes()
            ->with([
                'agent' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name', 'workspace_id', 'deleted_at'),
                'agent.workspace' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name'),
            ])
            ->withCount(['messages' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest('started_at');

        if ($q !== '') {
            // Match against the page URL directly + the related agent /
            // workspace names. EXISTS subqueries beat joining + DISTINCT
            // on a list this size.
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('page_url', 'like', $like)
                    ->orWhereHas('agent', fn ($a) => $a->where('name', 'like', $like))
                    ->orWhereHas('agent.workspace', fn ($ws) => $ws->where('name', 'like', $like));
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'agent' => $c->agent?->only('id', 'name'),
                'workspace' => $c->agent?->workspace?->only('id', 'name'),
                'page_url' => $c->page_url,
                'lang' => $c->lang,
                'is_lead' => $c->is_lead,
                'is_playground' => $c->is_playground,
                'message_count' => $c->messages_count,
                'started_at' => $c->started_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/conversations/index', [
            'conversations' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }

    /**
     * Read-only transcript view for platform admins. Reuses the same
     * data shape the customer side uses but is gated on super_admin
     * (the parent route group enforces this).
     */
    public function show(Conversation $conversation): Response
    {
        $conversation = Conversation::query()->withoutGlobalScopes()
            ->with([
                'agent' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name', 'workspace_id', 'deleted_at'),
                'agent.workspace' => fn ($q) => $q->withoutGlobalScopes()
                    ->select('id', 'name'),
                'messages' => fn ($q) => $q->withoutGlobalScopes()
                    ->orderBy('created_at'),
            ])
            ->findOrFail($conversation->id);

        // Behind-the-scenes turn traces for the debugger panel. Scope
        // bypass justified: this is the super_admin platform surface
        // (route group enforces the role) doing cross-workspace
        // forensics on a single conversation.
        $traces = TurnTrace::query()->withoutWorkspaceScope()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TurnTrace $tr) => [
                'id' => $tr->id,
                'message_id' => $tr->message_id,
                'kind' => $tr->kind,
                'payload' => $tr->payload,
                'created_at' => $tr->created_at?->toIso8601String(),
            ])
            ->values();

        $payload = [
            'id' => $conversation->id,
            'agent' => $conversation->agent?->only('id', 'name'),
            'workspace' => $conversation->agent?->workspace?->only('id', 'name'),
            'page_url' => $conversation->page_url,
            'lang' => $conversation->lang,
            'is_lead' => $conversation->is_lead,
            'is_playground' => $conversation->is_playground,
            'started_at' => $conversation->started_at?->toIso8601String(),
            'messages' => $conversation->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];

        return Inertia::render('admin/conversations/show', [
            'conversation' => $payload,
            'traces' => $traces,
        ]);
    }
}
