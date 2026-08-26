<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

class IntegrationConnectionPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return Tenancy::isMember($user, $workspace);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        $role = Tenancy::roleFor($user, $workspace);

        return $role !== null && $role->isAtLeast(WorkspaceRole::Admin);
    }

    public function delete(User $user, IntegrationConnection $connection): bool
    {
        $workspace = $connection->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return $this->manage($user, $workspace);
    }
}
