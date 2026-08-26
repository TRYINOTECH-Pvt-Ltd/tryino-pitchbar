<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

class AgentPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return Tenancy::isMember($user, $workspace);
    }

    public function view(User $user, Agent $agent): bool
    {
        return $this->viaWorkspace($user, $agent);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }

    public function update(User $user, Agent $agent): bool
    {
        $workspace = $agent->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $this->update($user, $agent);
    }

    private function viaWorkspace(User $user, Agent $agent): bool
    {
        $workspace = $agent->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::isMember($user, $workspace);
    }
}
