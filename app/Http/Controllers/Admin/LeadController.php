<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Lead;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController
{
    public function agentIndex(Request $request, Agent $agent): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $q = trim((string) $request->query('q', ''));
        $hotOnly = $request->boolean('hot');

        $query = Lead::query()
            ->where('agent_id', $agent->id)
            ->with(['conversation:id,page_url,started_at,lead_score,lead_score_bucket,lead_score_reasons'])
            ->latest();

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($where) use ($like) {
                $where->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhereHas('conversation', fn ($conversationQuery) => $conversationQuery->where('page_url', 'like', $like));
            });
        }

        if ($hotOnly) {
            $query->whereHas('conversation', fn ($q) => $q->where('lead_score', '>=', 70));
        }

        $paginator = $query->paginate(25)->withQueryString();

        $leads = collect($paginator->items())
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'email' => $lead->email,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'status' => $lead->status,
                'page_url' => $lead->conversation?->page_url,
                'created_at' => $lead->created_at?->toIso8601String(),
                'lead_score' => (int) ($lead->conversation?->lead_score ?? 0),
                'lead_score_bucket' => (string) ($lead->conversation?->lead_score_bucket ?? 'low'),
                'lead_score_reasons' => array_values((array) ($lead->conversation?->lead_score_reasons ?? [])),
            ])
            ->values();

        return Inertia::render('app/agents/leads', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
            ],
            'totals' => [
                'leads' => (int) Lead::query()->where('agent_id', $agent->id)->count(),
                'qualified' => (int) Lead::query()
                    ->where('agent_id', $agent->id)
                    ->whereIn('status', ['qualified', 'contacted', 'won'])
                    ->count(),
                'with_email' => (int) Lead::query()
                    ->where('agent_id', $agent->id)
                    ->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->count(),
                'with_phone' => (int) Lead::query()
                    ->where('agent_id', $agent->id)
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->count(),
            ],
            'leads' => $leads,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q, 'hot' => $hotOnly],
        ]);
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', 'all');
        $sort = (string) $request->query('sort', 'created_desc');
        $phone = (string) $request->query('phone', 'all');
        $hotOnly = $request->boolean('hot');

        if (! in_array($view, ['all', 'new', 'qualified', 'contacted', 'won', 'lost'], true)) {
            $view = 'all';
        }

        if (! in_array($sort, ['created_desc', 'created_asc', 'name_asc', 'name_desc'], true)) {
            $sort = 'created_desc';
        }

        if (! in_array($phone, ['all', 'with_phone', 'without_phone'], true)) {
            $phone = 'all';
        }

        $query = Lead::query()
            ->with(['conversation:id,page_url,started_at,lead_score,lead_score_bucket,lead_score_reasons']);

        if ($hotOnly) {
            $query->whereHas('conversation', fn ($q) => $q->where('lead_score', '>=', 70));
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if ($view !== 'all') {
            $query->where('status', $view);
        }

        if ($phone === 'with_phone') {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        if ($phone === 'without_phone') {
            $query->where(function ($where) {
                $where->whereNull('phone')->orWhere('phone', '');
            });
        }

        match ($sort) {
            'created_asc' => $query->oldest(),
            'name_asc' => $query->orderByRaw('coalesce(name, email) asc'),
            'name_desc' => $query->orderByRaw('coalesce(name, email) desc'),
            default => $query->latest(),
        };

        $paginator = $query->paginate(25)->withQueryString();

        $leads = collect($paginator->items())
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'email' => $l->email,
                'name' => $l->name,
                'phone' => $l->phone,
                'status' => $l->status,
                'page_url' => $l->conversation?->page_url,
                'created_at' => $l->created_at?->toIso8601String(),
                'lead_score' => (int) ($l->conversation?->lead_score ?? 0),
                'lead_score_bucket' => (string) ($l->conversation?->lead_score_bucket ?? 'low'),
                'lead_score_reasons' => array_values((array) ($l->conversation?->lead_score_reasons ?? [])),
            ]);

        return Inertia::render('app/inbox/index', [
            'leads' => $leads,
            'pagination' => Pagination::meta($paginator),
            'filters' => [
                'q' => $q,
                'view' => $view,
                'sort' => $sort,
                'phone' => $phone,
                'hot' => $hotOnly,
            ],
        ]);
    }

    public function show(Request $request, Lead $lead): Response
    {
        $request->user()->can('view', $lead) || abort(403);

        $conversation = $lead->conversation()->withoutGlobalScopes()->first();

        $messages = $conversation
            ?->messages()
            ?->orderBy('created_at')
            ?->get(['id', 'role', 'content', 'citations', 'created_at']);

        $claimedBy = $conversation?->claimedBy()->withoutGlobalScopes()->first();

        return Inertia::render('app/inbox/show', [
            'lead' => $lead,
            'messages' => $messages,
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'claimed_by' => $claimedBy?->only('id', 'name'),
                'claimed_at' => $conversation->claimed_at?->toIso8601String(),
            ],
            'me' => ['id' => $request->user()->id, 'name' => $request->user()->name],
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $request->user()->can('update', $lead) || abort(403);

        $lead->update($request->validate([
            'status' => ['sometimes', 'in:new,qualified,contacted,won,lost'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
        ]));

        return back()->with('success', 'Lead updated.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $request->user()->can('delete', $lead) || abort(403);

        $lead->delete();

        return redirect()->route('inbox.index')->with('success', 'Lead deleted.');
    }

    /**
     * Bulk-delete leads from /app/inbox. Each id runs through the same
     * LeadPolicy@delete gate so cross-workspace ids silently drop out —
     * matching the agents bulk-destroy pattern (no info leak via 403 on
     * an id from a workspace the user can't see).
     *
     * Card #57 (bulk-selection wiring rollout).
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $user = $request->user();
        $deleted = 0;

        Lead::query()->whereIn('id', $data['ids'])->get()->each(function (Lead $lead) use ($user, &$deleted) {
            if ($user->can('delete', $lead)) {
                $lead->delete();
                $deleted++;
            }
        });

        return back()->with('success', $deleted.' lead(s) deleted.');
    }
}
