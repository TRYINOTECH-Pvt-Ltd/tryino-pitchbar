<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\Tenancy;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        $workspace = $lead->agent()->withoutGlobalScopes()->first()?->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::isMember($user, $workspace);
    }

    public function update(User $user, Lead $lead): bool
    {
        $workspace = $lead->agent()->withoutGlobalScopes()->first()?->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }

    /**
     * Same gate as `update`: anyone who can manage agents in the lead's
     * workspace can also delete leads from the Inbox. Used by the
     * single-row `inbox.destroy` endpoint and by the `inbox.bulkDestroy`
     * gate-per-id loop (card #57, bulk-selection wiring rollout).
     */
    public function delete(User $user, Lead $lead): bool
    {
        $workspace = $lead->agent()->withoutGlobalScopes()->first()?->workspace()->withoutGlobalScopes()->first();
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace)?->canManageAgents() === true;
    }
}
