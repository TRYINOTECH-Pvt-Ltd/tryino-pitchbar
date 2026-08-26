<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Support\Tenancy;

class SourcePolicy
{
    public function manage(User $user, Source $source): bool
    {
        $agent = $source->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return false;
        }
        $workspace = $agent->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }

    public function view(User $user, Source $source): bool
    {
        $agent = $source->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return false;
        }
        $workspace = $agent->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace) !== null;
    }

    public function update(User $user, Source $source): bool
    {
        return $this->manage($user, $source);
    }

    public function delete(User $user, Source $source): bool
    {
        return $this->manage($user, $source);
    }
}
