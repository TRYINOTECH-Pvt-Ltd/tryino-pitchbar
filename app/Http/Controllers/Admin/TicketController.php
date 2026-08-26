<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator-facing tickets list + detail. Companion to the chat-side
 * `open_ticket` tool — every ticket the LLM creates lands here for
 * a human operator to resolve.
 */
class TicketController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('viewAny', [Ticket::class, $workspace]) || abort(403);

        $status = (string) $request->query('status', 'open');
        if (! in_array($status, ['open', 'pending', 'resolved', 'closed', 'all'], true)) {
            $status = 'open';
        }

        $tickets = Ticket::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->latest()
            ->limit(100)
            ->get();

        $assignees = User::query()
            ->whereIn('id', $tickets->pluck('assigned_to_user_id')->filter())
            ->get(['id', 'name'])
            ->keyBy('id');

        return Inertia::render('app/tickets/index', [
            'tickets' => $tickets->map(fn (Ticket $t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'priority' => $t->priority,
                'assignee' => $t->assigned_to_user_id
                    ? ($assignees->get($t->assigned_to_user_id)?->name ?? '—')
                    : null,
                'conversation_id' => $t->conversation_id,
                'created_at' => $t->created_at?->toIso8601String(),
                'resolved_at' => $t->resolved_at?->toIso8601String(),
            ])->values(),
            'filter' => ['status' => $status],
            'counts' => [
                'open' => Ticket::query()->where('status', 'open')->count(),
                'pending' => Ticket::query()->where('status', 'pending')->count(),
                'resolved' => Ticket::query()->where('status', 'resolved')->count(),
            ],
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $request->user()->can('view', $ticket) || abort(403);

        return Inertia::render('app/tickets/show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'assigned_to_user_id' => $ticket->assigned_to_user_id,
                'conversation_id' => $ticket->conversation_id,
                'metadata' => $ticket->metadata ?? [],
                'created_at' => $ticket->created_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $request->user()->can('update', $ticket) || abort(403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([
                Ticket::STATUS_OPEN, Ticket::STATUS_PENDING,
                Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED,
            ])],
            'priority' => ['sometimes', Rule::in([
                Ticket::PRIORITY_LOW, Ticket::PRIORITY_NORMAL,
                Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT,
            ])],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $ticket->fill($data);
        if (($data['status'] ?? null) === Ticket::STATUS_RESOLVED
            && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }
        $ticket->save();

        return back()->with('success', 'Ticket updated.');
    }
}
