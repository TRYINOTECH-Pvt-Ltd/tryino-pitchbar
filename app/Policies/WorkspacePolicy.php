<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return Tenancy::isMember($user, $workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        $role = Tenancy::roleFor($user, $workspace);

        return $role !== null && $role->isAtLeast(WorkspaceRole::Admin);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace) === WorkspaceRole::Owner;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace)?->canManageMembers() === true;
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace)?->canManageBilling() === true;
    }

    /**
     * Permission to bulk-delete conversations across the workspace. Lives
     * here (not on ConversationPolicy) because Laravel's Gate dispatches
     * by the second argument's class — `can('bulkDelete', $workspace)`
     * routes to this policy. Admins+Owners only — destructive, PII-laden.
     */
    public function bulkDeleteConversations(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace)?->canManageMembers() === true;
    }
}
