<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

class DsrPolicy
{
    public function manage(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace)?->canManageMembers() === true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return Tenancy::isMember($user, $workspace);
    }
}
