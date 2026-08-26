<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-scoped audit trail. Admins+ only — the entries can contain
 * PII (lead emails, IPs) so Viewer/Editor are excluded by policy.
 */
class AuditController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', 'string', 'max:60'],
            'user_email' => ['nullable', 'string', 'email', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = AuditLog::query()
            ->where('workspace_id', $workspace->id);

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.$filters['action'].'%');
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (! empty($filters['user_email'])) {
            $userId = User::query()
                ->where('email', $filters['user_email'])
                ->value('id');

            $query->where('user_id', $userId ?? 0);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $userIds = collect($page->items())
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->get(['id', 'name', 'email'])
                ->keyBy('id');

        $rows = collect($page->items())->map(function (AuditLog $log) use ($users) {
            $u = $log->user_id ? $users->get($log->user_id) : null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'user' => $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email] : null,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'ua' => $log->ua,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        });

        $entityTypes = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('entity_type')
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type');

        return Inertia::render('app/audit/index', [
            'rows' => $rows,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'action' => $filters['action'] ?? '',
                'entity_type' => $filters['entity_type'] ?? '',
                'user_email' => $filters['user_email'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'entityTypes' => $entityTypes,
        ]);
    }
}
