<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class Tenancy
{
    public static function roleFor(User $user, Workspace $workspace): ?WorkspaceRole
    {
        $pivot = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()
            ?->pivot;

        if ($pivot === null) {
            return null;
        }

        return WorkspaceRole::tryFrom($pivot->role);
    }

    public static function isMember(User $user, Workspace $workspace): bool
    {
        return self::roleFor($user, $workspace) !== null;
    }
}
