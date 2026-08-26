<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

/**
 * Ticket gating mirrors the conversation policy: read access to any
 * workspace member, write/assign/resolve to Editor + Admin + Owner.
 * Viewer stays read-only — matches how we treat all customer-facing
 * surfaces.
 */
class TicketPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return Tenancy::isMember($user, $workspace);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        $workspace = $this->workspaceFor($ticket);

        return $workspace !== null && Tenancy::isMember($user, $workspace);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        $workspace = $this->workspaceFor($ticket);

        return $workspace !== null
            && Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        $workspace = $this->workspaceFor($ticket);

        return $workspace !== null
            && Tenancy::roleFor($user, $workspace)?->canManageMembers() === true;
    }

    private function workspaceFor(Ticket $ticket): ?Workspace
    {
        return Workspace::query()->withoutGlobalScopes()->find($ticket->workspace_id);
    }
}
