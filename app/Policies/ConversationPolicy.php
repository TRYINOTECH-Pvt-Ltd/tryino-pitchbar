<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        $workspace = $this->workspaceFor($conversation);

        return $workspace !== null && Tenancy::isMember($user, $workspace);
    }

    /**
     * Destroy a conversation row + its cascaded children (messages, leads,
     * tag pivots). Restricted to admins/owners — destructive and PII-laden.
     * Editors can manage agents but not wipe customer-facing history.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        $workspace = $this->workspaceFor($conversation);
        if ($workspace === null) {
            return false;
        }

        return Tenancy::roleFor($user, $workspace)?->canManageMembers() === true;
    }

    private function workspaceFor(Conversation $conversation): ?Workspace
    {
        return $conversation->agent()
            ->withoutGlobalScopes()
            ->first()
            ?->workspace()
            ->withoutGlobalScopes()
            ->first();
    }
}
