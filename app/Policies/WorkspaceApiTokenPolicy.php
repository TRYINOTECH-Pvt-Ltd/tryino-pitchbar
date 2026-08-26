<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Support\Tenancy;

class WorkspaceApiTokenPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        $role = Tenancy::roleFor($user, $workspace);

        return $role !== null && $role->isAtLeast(WorkspaceRole::Admin);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        $role = Tenancy::roleFor($user, $workspace);

        return $role !== null && $role->isAtLeast(WorkspaceRole::Admin);
    }

    public function revoke(User $user, WorkspaceApiToken $token): bool
    {
        $workspace = $token->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return $this->manage($user, $workspace);
    }
}
