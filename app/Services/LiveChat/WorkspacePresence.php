<?php

namespace App\Services\LiveChat;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Computes which operators in a workspace are currently "available"
 * for live chat:
 *
 *  1. They've opted in via Profile → Notifications (`live_chat_available = true`)
 *  2. Their admin tab heartbeat fired within the last `STALE_AFTER_MINUTES`
 *     (default 2 — matches the 60s heartbeat cadence with one missed
 *     ping of grace).
 *  3. They're an active member of the given workspace (accepted invite).
 *
 * Cheap: one indexed query on `users.last_active_at` joined against
 * `workspace_users`. Used at request-time inside RequestHumanController
 * to decide between queueing the visitor (operators present) and the
 * "we're offline, drop your email" fallback.
 */
class WorkspacePresence
{
    public const STALE_AFTER_MINUTES = 2;

    /**
     * @return Collection<int, User>
     */
    public function activeOperators(Workspace $workspace): Collection
    {
        return User::query()
            ->where('users.live_chat_available', true)
            ->where('users.last_active_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->whereHas(
                'workspaces',
                fn ($q) => $q->where('workspaces.id', $workspace->id)
                    ->whereNotNull('workspace_users.accepted_at'),
            )
            ->get();
    }

    public function activeOperatorCount(Workspace $workspace): int
    {
        return $this->activeOperators($workspace)->count();
    }
}
