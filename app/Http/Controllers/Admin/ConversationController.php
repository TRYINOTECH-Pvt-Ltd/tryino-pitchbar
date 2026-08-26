<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\CannedReply;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\VisitorPageView;
use App\Support\CurrentWorkspace;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing conversation log — every visitor session for an
 * agent, with per-conversation full-thread drilldown. This is "what
 * are people actually asking?" — distinct from the Inbox (captured
 * leads) and Analytics (aggregates).
 */
class ConversationController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function workspaceIndex(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        // Filter pill: '' (all) | 'needs_human' | 'live_now'.
        // - needs_human → visitor clicked "Connect me with a human" but
        //   no operator has claimed yet.
        // - live_now    → an operator is currently in the conversation.
        $filter = (string) $request->query('filter', '');
        $tagFilter = trim((string) $request->query('tag', ''));
        // Engaged-only toggle. Visitors that load the widget but never
        // type get a Conversation row from /api/v1/widget/init — without
        // this filter the buyer's list fills with empty rows. Default ON;
        // pass `?show=all` to opt back into seeing every session.
        $show = (string) $request->query('show', 'engaged');
        $engagedOnly = $show !== 'all';

        $conversationsQuery = Conversation::query()
            ->where('is_playground', false)
            ->with([
                'agent:id,name',
                'visitor:id,anonymous_id,first_seen_at',
                'tags:id,label,color',
            ])
            ->orderByDesc('started_at');

        if ($engagedOnly) {
            $conversationsQuery->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.role', 'user');
            });
        }

        if ($filter === 'needs_human') {
            $conversationsQuery
                ->whereNotNull('human_requested_at')
                ->whereNull('claimed_by_user_id');
        } elseif ($filter === 'live_now') {
            $conversationsQuery->whereNotNull('claimed_by_user_id');
        }

        if ($tagFilter !== '') {
            $conversationsQuery->whereHas(
                'tags',
                fn ($q) => $q->where('conversation_tags.id', $tagFilter),
            );
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $conversationsQuery->where(function ($query) use ($like) {
                $query->where('page_url', 'like', $like)
                    ->orWhereIn('id', function ($sub) use ($like) {
                        $sub->select('conversation_id')
                            ->from('messages')
                            ->where('content', 'like', $like);
                    })
                    ->orWhereIn('visitor_id', function ($sub) use ($like) {
                        $sub->select('id')
                            ->from('visitors')
                            ->where('anonymous_id', 'like', $like);
                    })
                    ->orWhereHas('agent', fn ($agentQuery) => $agentQuery->where('name', 'like', $like));
            });
        }

        $paginator = $conversationsQuery->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(function (Conversation $conversation) {
                $firstUserMsg = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('role', 'user')
                    ->orderBy('created_at')
                    ->value('content');

                $lastActivityAt = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereIn('role', ['user', 'assistant', 'human-agent'])
                    ->latest('created_at')
                    ->first(['created_at'])?->created_at;

                $messageCount = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereIn('role', ['user', 'assistant', 'human-agent'])
                    ->count();

                return [
                    'id' => $conversation->id,
                    'started_at' => $conversation->started_at?->toIso8601String(),
                    'last_activity_at' => $lastActivityAt?->toIso8601String(),
                    'page_url' => $conversation->page_url,
                    'lang' => $conversation->lang,
                    'visitor_anon' => $conversation->visitor?->anonymous_id,
                    'is_returning' => $conversation->visitor?->first_seen_at?->lt(now()->subDay()) ?? false,
                    'message_count' => $messageCount,
                    'preview' => $firstUserMsg
                        ? mb_substr((string) $firstUserMsg, 0, 140)
                        : '(no messages yet)',
                    'agent' => $conversation->agent === null ? null : [
                        'id' => $conversation->agent->id,
                        'name' => $conversation->agent->name,
                    ],
                    // Live-handoff signals so the list can render the
                    // "Needs human" / "Live" pills next to each row.
                    'human_requested_at' => $conversation->human_requested_at?->toIso8601String(),
                    'claimed_by_user_id' => $conversation->claimed_by_user_id,
                    'satisfaction' => $conversation->satisfaction,
                    'lead_score' => (int) ($conversation->lead_score ?? 0),
                    'lead_score_bucket' => (string) ($conversation->lead_score_bucket ?? 'low'),
                    'lead_score_reasons' => array_values((array) ($conversation->lead_score_reasons ?? [])),
                    'tags' => $conversation->tags->map(fn ($t) => [
                        'id' => $t->id,
                        'label' => $t->label,
                        'color' => $t->color,
                    ])->values(),
                ];
            })
            ->values();

        $totalConversations = (int) Conversation::query()
            ->where('is_playground', false)
            ->count();
        // Counts that drive the filter pills + the sidebar nav badge.
        $needsHumanCount = (int) Conversation::query()
            ->where('is_playground', false)
            ->whereNotNull('human_requested_at')
            ->whereNull('claimed_by_user_id')
            ->count();
        $liveNowCount = (int) Conversation::query()
            ->where('is_playground', false)
            ->whereNotNull('claimed_by_user_id')
            ->count();
        $totalMessages = (int) Message::query()
            ->whereIn('role', ['user', 'assistant', 'human-agent'])
            ->whereHas('conversation', fn ($query) => $query->where('is_playground', false))
            ->count();
        $conversationsLast24h = (int) Conversation::query()
            ->where('is_playground', false)
            ->where('started_at', '>=', now()->subDay())
            ->count();
        $activeAgents = (int) Conversation::query()
            ->where('is_playground', false)
            ->distinct()
            ->count('agent_id');

        // Drives the "Delete N empty conversations" affordance. An "empty"
        // conversation is one where no user role message was ever stored
        // (the visitor loaded the widget and bounced).
        $emptyCount = (int) Conversation::query()
            ->where('is_playground', false)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.role', 'user');
            })
            ->count();

        $availableTags = ConversationTag::query()
            ->orderBy('label')
            ->get(['id', 'label', 'color'])
            ->map(fn (ConversationTag $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'color' => $t->color,
            ])
            ->values();

        return Inertia::render('app/conversations/index', [
            'totals' => [
                'conversations' => $totalConversations,
                'messages' => $totalMessages,
                'last_24h' => $conversationsLast24h,
                'active_agents' => $activeAgents,
                'leads' => (int) Lead::query()->count(),
                'needs_human' => $needsHumanCount,
                'live_now' => $liveNowCount,
                'empty' => $emptyCount,
            ],
            'conversations' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => [
                'q' => $q,
                'filter' => $filter,
                'tag' => $tagFilter,
                'show' => $engagedOnly ? 'engaged' : 'all',
            ],
            'available_tags' => $availableTags,
            'can_delete' => $this->canBulkDelete($request),
        ]);
    }

    public function index(Request $request, Agent $agent): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $q = trim((string) $request->query('q', ''));
        $show = (string) $request->query('show', 'engaged');
        $engagedOnly = $show !== 'all';

        // Per-conversation aggregates: message count, last message
        // preview, last activity. Cheap — single GROUP BY query.
        $aggregates = DB::table('messages')
            ->select(
                'conversation_id',
                DB::raw('COUNT(*) as msg_count'),
                DB::raw('MAX(created_at) as last_at'),
            )
            ->whereIn('role', ['user', 'assistant', 'human-agent'])
            ->whereIn('conversation_id', function ($sub) use ($agent) {
                $sub->select('id')
                    ->from('conversations')
                    ->where('agent_id', $agent->id);
            })
            ->groupBy('conversation_id')
            ->pluck('msg_count', 'conversation_id');

        $lastSeenAtByConv = DB::table('messages')
            ->select('conversation_id', DB::raw('MAX(created_at) as last_at'))
            ->whereIn('role', ['user', 'assistant', 'human-agent'])
            ->whereIn('conversation_id', function ($sub) use ($agent) {
                $sub->select('id')
                    ->from('conversations')
                    ->where('agent_id', $agent->id);
            })
            ->groupBy('conversation_id')
            ->pluck('last_at', 'conversation_id');

        $conversationsQuery = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->with('visitor:id,anonymous_id,first_seen_at')
            ->orderByDesc('started_at');

        if ($engagedOnly) {
            $conversationsQuery->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.role', 'user');
            });
        }

        if ($q !== '') {
            // Match on visitor anon_id, page_url, or message body.
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $conversationsQuery->where(function ($qq) use ($like) {
                $qq->where('page_url', 'like', $like)
                    ->orWhereIn('id', function ($sub) use ($like) {
                        $sub->select('conversation_id')
                            ->from('messages')
                            ->where('content', 'like', $like);
                    })
                    ->orWhereIn('visitor_id', function ($sub) use ($like) {
                        $sub->select('id')
                            ->from('visitors')
                            ->where('anonymous_id', 'like', $like);
                    });
            });
        }

        $paginator = $conversationsQuery->paginate(25)->withQueryString();

        $conversations = collect($paginator->items())
            ->map(function (Conversation $c) use ($aggregates, $lastSeenAtByConv) {
                // First user message gives the most useful one-line preview.
                $firstUserMsg = Message::query()
                    ->where('conversation_id', $c->id)
                    ->where('role', 'user')
                    ->orderBy('created_at')
                    ->value('content');

                return [
                    'id' => $c->id,
                    'started_at' => $c->started_at?->toIso8601String(),
                    'last_activity_at' => $lastSeenAtByConv[$c->id] ?? $c->started_at?->toIso8601String(),
                    'page_url' => $c->page_url,
                    'lang' => $c->lang,
                    'visitor_anon' => $c->visitor?->anonymous_id,
                    'is_returning' => $c->visitor?->first_seen_at?->lt(now()->subDay()) ?? false,
                    'message_count' => (int) ($aggregates[$c->id] ?? 0),
                    'preview' => $firstUserMsg
                        ? mb_substr((string) $firstUserMsg, 0, 140)
                        : '(no messages yet)',
                    'lead_score' => (int) ($c->lead_score ?? 0),
                    'lead_score_bucket' => (string) ($c->lead_score_bucket ?? 'low'),
                    'lead_score_reasons' => array_values((array) ($c->lead_score_reasons ?? [])),
                ];
            })->values();

        // Top-line stats for the page header.
        $totalConversations = (int) Conversation::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->count();
        $totalMessages = (int) DB::table('messages')
            ->whereIn('conversation_id', function ($sub) use ($agent) {
                $sub->select('id')
                    ->from('conversations')
                    ->where('agent_id', $agent->id);
            })
            ->count();
        $convsLast24h = (int) Conversation::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('started_at', '>=', now()->subDay())
            ->count();

        $emptyCount = (int) Conversation::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.role', 'user');
            })
            ->count();

        return Inertia::render('app/agents/conversations', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'totals' => [
                'conversations' => $totalConversations,
                'messages' => $totalMessages,
                'last_24h' => $convsLast24h,
                'empty' => $emptyCount,
            ],
            'conversations' => $conversations,
            'pagination' => Pagination::meta($paginator),
            'filters' => [
                'q' => $q,
                'show' => $engagedOnly ? 'engaged' : 'all',
            ],
            'can_delete' => $this->canBulkDelete($request),
        ]);
    }

    /**
     * Full thread view + live operator console. Workspace members with
     * `update` permission on the agent can claim the conversation, post
     * replies that broadcast over Reverb to the visitor's widget, and
     * release when done. Members with only `view` permission see a
     * read-only thread.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $agent = $conversation->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('view', $agent) || abort(403);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            // Phase 3: include internal-note rows in the operator's
            // thread view. The visitor-side poll filters on
            // `model LIKE 'human:%'` so notes never leak there.
            ->whereIn('role', ['user', 'assistant', 'human-agent', 'internal-note'])
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'citations', 'confidence', 'created_at', 'model'])
            ->map(fn (Message $m) => [
                'id' => (string) $m->id,
                'role' => $m->role,
                'content' => (string) $m->content,
                'citations' => $m->citations ?? [],
                'confidence' => $m->confidence !== null ? (float) $m->confidence : null,
                'created_at' => $m->created_at?->toIso8601String(),
                // human:<id> on `model` flags an operator-authored
                // message so the UI can render it with a "Human" badge.
                'is_human' => is_string($m->model) && str_starts_with($m->model, 'human:'),
                'is_note' => $m->role === 'internal-note',
                'is_system' => is_string($m->model) && str_starts_with($m->model, 'system:'),
            ])
            ->values();

        $claimedBy = $conversation->claimedBy()->withoutGlobalScopes()->first();

        // The lead row (if captured) carries the visitor's email +
        // form submission — surfacing it lets the operator follow up
        // out-of-band if the visitor disconnects mid-chat.
        $lead = $conversation->lead()->withoutGlobalScopes()->first();

        // Phase 3: surface live operator presence + canned replies +
        // visitor-typing flag so the rebuilt thread page can wire
        // transfer + canned picker + indicator without separate roundtrips.
        $teammates = User::query()
            ->whereHas(
                'workspaces',
                fn ($q) => $q->where('workspaces.id', $agent->workspace_id)
                    ->whereNotNull('workspace_users.accepted_at'),
            )
            ->where('users.id', '!=', $request->user()->id)
            ->get(['id', 'name', 'live_chat_available', 'last_active_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'is_online' => $u->live_chat_available
                    && $u->last_active_at !== null
                    && $u->last_active_at->greaterThanOrEqualTo(now()->subMinutes(2)),
            ])
            ->values();

        $cannedReplies = CannedReply::query()
            ->orderBy('position')
            ->orderBy('label')
            ->get(['id', 'label', 'content']);

        $appliedTags = $conversation->tags()
            ->get(['conversation_tags.id', 'conversation_tags.label', 'conversation_tags.color'])
            ->map(fn (ConversationTag $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'color' => $t->color,
            ])
            ->values();

        $availableTags = ConversationTag::query()
            ->orderBy('label')
            ->get(['id', 'label', 'color'])
            ->map(fn (ConversationTag $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'color' => $t->color,
            ])
            ->values();

        return Inertia::render('app/conversations/show', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'conversation' => [
                'id' => $conversation->id,
                'started_at' => $conversation->started_at?->toIso8601String(),
                'page_url' => $conversation->page_url,
                'lang' => $conversation->lang,
                'visitor_anon' => $conversation->visitor()->withoutGlobalScopes()->value('anonymous_id'),
                'human_requested_at' => $conversation->human_requested_at?->toIso8601String(),
                'claimed_at' => $conversation->claimed_at?->toIso8601String(),
                'claimed_by' => $claimedBy === null ? null : [
                    'id' => $claimedBy->id,
                    'name' => $claimedBy->name,
                ],
                'lead' => $lead === null ? null : [
                    'id' => $lead->id,
                    'email' => $lead->email,
                    'name' => $lead->name,
                    'phone' => $lead->phone,
                ],
                'visitor_typing' => $conversation->visitor_typing_until !== null
                    && $conversation->visitor_typing_until->isFuture(),
                'satisfaction' => $conversation->satisfaction,
                'satisfaction_at' => $conversation->satisfaction_at?->toIso8601String(),
                'satisfaction_comment' => $conversation->satisfaction_comment,
                'is_returning' => $conversation->visitor()
                    ->withoutGlobalScopes()
                    ->value('first_seen_at')
                    ?->lt(now()->subDay()) ?? false,
                'lead_score' => (int) ($conversation->lead_score ?? 0),
                'lead_score_bucket' => (string) ($conversation->lead_score_bucket ?? 'low'),
                'lead_score_reasons' => array_values((array) ($conversation->lead_score_reasons ?? [])),
                'lead_score_updated_at' => $conversation->lead_score_updated_at?->toIso8601String(),
            ],
            'messages' => $messages,
            'trajectory' => VisitorPageView::query()->withoutWorkspaceScope()
                ->where('visitor_id', $conversation->visitor_id)
                ->orderByDesc('viewed_at')
                ->limit(50)
                ->get(['url', 'title', 'referrer', 'viewed_at', 'conversation_id'])
                ->map(fn (VisitorPageView $v) => [
                    'url' => $v->url,
                    'title' => $v->title,
                    'referrer' => $v->referrer,
                    'viewed_at' => $v->viewed_at?->toIso8601String(),
                    'in_this_conversation' => $v->conversation_id === $conversation->id,
                ])
                ->values(),
            'teammates' => $teammates,
            'canned_replies' => $cannedReplies,
            'applied_tags' => $appliedTags,
            'available_tags' => $availableTags,
            'auth_user_id' => $request->user()->id,
            'can_takeover' => $request->user()->can('update', $agent),
        ]);
    }

    /**
     * Delete a single conversation. Cascades to messages, leads, and
     * tag pivots via the FK constraints. Events rows are preserved
     * (FK nullOnDelete) so aggregate analytics stay intact.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $request->user()->can('delete', $conversation) || abort(403);

        $conversation->delete();

        return back()->with('success', 'Conversation deleted.');
    }

    /**
     * Bulk delete in one of two modes:
     *   - mode=empty → wipe every conversation in the workspace that
     *     never received a user-role message. The "phantom rows" case
     *     from passive widget loads.
     *   - mode=ids → caller supplies a list of conversation_ids to drop.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('bulkDeleteConversations', $workspace) || abort(403);

        $data = $request->validate([
            'mode' => ['required', 'string', 'in:empty,ids'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['string'],
        ]);

        if ($data['mode'] === 'empty') {
            $deleted = Conversation::query()
                ->where('is_playground', false)
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('messages')
                        ->whereColumn('messages.conversation_id', 'conversations.id')
                        ->where('messages.role', 'user');
                })
                ->delete();

            return back()->with('success', "Deleted {$deleted} empty conversations.");
        }

        $ids = array_values(array_filter((array) ($data['ids'] ?? []), 'is_string'));
        if ($ids === []) {
            return back()->with('error', 'No conversations selected.');
        }

        // Workspace global scope on Conversation keeps this from
        // reaching other tenants — we never trust the raw ID list.
        $deleted = Conversation::query()
            ->whereIn('id', $ids)
            ->delete();

        return back()->with('success', "Deleted {$deleted} conversations.");
    }

    private function canBulkDelete(Request $request): bool
    {
        $workspace = $this->current->get();
        if ($workspace === null) {
            return false;
        }

        return $request->user()?->can('bulkDeleteConversations', $workspace) === true;
    }
}
