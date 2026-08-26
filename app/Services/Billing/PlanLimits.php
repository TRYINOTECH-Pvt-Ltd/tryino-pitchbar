<?php

namespace App\Services\Billing;

use App\Models\Agent;
use App\Models\IntegrationConnection;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Scopes\WorkspaceScope;

/**
 * Per-resource quota gate. Sits in front of every "create X" endpoint
 * (agent / source / workflow / integration / member / api token) so the
 * UI can render an "Upgrade your plan to add more" hint instead of
 * silently 500ing or letting one workspace run away with unlimited
 * resources on a $0 plan.
 *
 * Limits live on `plans.{agents,sources,workflows,integrations,members}_limit`
 * plus `plans.api_access`. NULL on any int column = unlimited (every
 * pre-existing plan defaults to NULL so grandfathered customers aren't
 * retroactively capped). 0 = hard block. Positive integer = absolute cap.
 *
 * `api_access` is a boolean — defaults to TRUE so any pre-existing
 * plan keeps the API tokens its workspaces already minted.
 */
class PlanLimits
{
    public const RESOURCE_AGENT = 'agent';

    public const RESOURCE_SOURCE = 'source';

    public const RESOURCE_WORKFLOW = 'workflow';

    public const RESOURCE_INTEGRATION = 'integration';

    public const RESOURCE_MEMBER = 'member';

    /**
     * Result shape: `{allowed, limit, current, remaining}`.
     *
     * `limit === null` means unlimited (so `remaining` is also null and
     * `allowed` is always true).
     *
     * @return array{allowed: bool, limit: int|null, current: int, remaining: int|null}
     */
    public function check(Workspace $workspace, string $resource): array
    {
        $limit = $this->limitFor($workspace, $resource);
        $current = $this->currentFor($workspace, $resource);

        if ($limit === null) {
            return [
                'allowed' => true,
                'limit' => null,
                'current' => $current,
                'remaining' => null,
            ];
        }

        $remaining = max(0, $limit - $current);

        return [
            'allowed' => $current < $limit,
            'limit' => $limit,
            'current' => $current,
            'remaining' => $remaining,
        ];
    }

    public function canCreateAgent(Workspace $workspace): bool
    {
        return $this->check($workspace, self::RESOURCE_AGENT)['allowed'];
    }

    public function canCreateSource(Workspace $workspace): bool
    {
        return $this->check($workspace, self::RESOURCE_SOURCE)['allowed'];
    }

    public function canCreateWorkflow(Workspace $workspace): bool
    {
        return $this->check($workspace, self::RESOURCE_WORKFLOW)['allowed'];
    }

    public function canCreateIntegration(Workspace $workspace): bool
    {
        return $this->check($workspace, self::RESOURCE_INTEGRATION)['allowed'];
    }

    public function canInviteMember(Workspace $workspace): bool
    {
        return $this->check($workspace, self::RESOURCE_MEMBER)['allowed'];
    }

    /**
     * Owner-scoped workspace creation gate. One user may own multiple
     * workspaces; `plans.workspaces_limit` caps how many a user can own
     * while on this plan. NULL = unlimited, 0 = blocked, positive = cap.
     *
     * The plan used for the check is whichever plan this user's PRIMARY
     * workspace currently sits on. If the user owns zero workspaces yet,
     * the first one is always permitted.
     *
     * @return array{allowed: bool, limit: int|null, current: int, remaining: int|null}
     */
    public function checkWorkspaceCreation(User $user): array
    {
        $current = (int) Workspace::query()
            ->withoutGlobalScopes()
            ->where('owner_user_id', $user->id)
            ->count();

        if ($current === 0) {
            return [
                'allowed' => true,
                'limit' => null,
                'current' => 0,
                'remaining' => null,
            ];
        }

        $primary = Workspace::query()
            ->withoutGlobalScopes()
            ->where('owner_user_id', $user->id)
            ->oldest()
            ->first();

        $plan = $primary?->effectivePlan();
        $limit = $plan === null
            ? null
            : $this->nullableInt($plan->workspaces_limit);

        if ($limit === null) {
            return [
                'allowed' => true,
                'limit' => null,
                'current' => $current,
                'remaining' => null,
            ];
        }

        return [
            'allowed' => $current < $limit,
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
        ];
    }

    public function canCreateWorkspace(User $user): bool
    {
        return $this->checkWorkspaceCreation($user)['allowed'];
    }

    public function apiAccessEnabled(Workspace $workspace): bool
    {
        $plan = $workspace->effectivePlan();

        // Back-compat: workspaces without a plan keep their API access
        // (otherwise local dev / free trial accounts would lose tokens
        // immediately on upgrade). Set api_access=false on a plan to
        // gate this behind paid tiers.
        if ($plan === null) {
            return true;
        }

        return (bool) ($plan->api_access ?? true);
    }

    /**
     * Operator-facing message for the flash banner. Lives here so every
     * controller's "limit reached" copy stays in sync.
     */
    public function reasonFor(string $resource, int $limit): string
    {
        $label = match ($resource) {
            self::RESOURCE_AGENT => 'agents',
            self::RESOURCE_SOURCE => 'knowledge sources',
            self::RESOURCE_WORKFLOW => 'workflows',
            self::RESOURCE_INTEGRATION => 'integrations',
            self::RESOURCE_MEMBER => 'members',
            default => 'resources',
        };

        if ($limit === 0) {
            return ucfirst($label).' are not included on your current plan. Upgrade to enable this feature.';
        }

        return "You've reached your plan's limit of {$limit} {$label}. Upgrade to add more.";
    }

    private function limitFor(Workspace $workspace, string $resource): ?int
    {
        $plan = $workspace->effectivePlan();

        if ($plan === null) {
            return null;
        }

        return match ($resource) {
            self::RESOURCE_AGENT => $this->nullableInt($plan->agents_limit),
            self::RESOURCE_SOURCE => $this->nullableInt($plan->sources_limit),
            self::RESOURCE_WORKFLOW => $this->nullableInt($plan->workflows_limit),
            self::RESOURCE_INTEGRATION => $this->nullableInt($plan->integrations_limit),
            self::RESOURCE_MEMBER => $this->nullableInt($plan->members_limit),
            default => null,
        };
    }

    private function currentFor(Workspace $workspace, string $resource): int
    {
        return match ($resource) {
            self::RESOURCE_AGENT => $this->countAgents($workspace),
            self::RESOURCE_SOURCE => $this->countSources($workspace),
            self::RESOURCE_WORKFLOW => $this->countWorkflows($workspace),
            self::RESOURCE_INTEGRATION => $this->countIntegrations($workspace),
            self::RESOURCE_MEMBER => $this->countMembers($workspace),
            default => 0,
        };
    }

    private function countAgents(Workspace $workspace): int
    {
        // Drop only the WorkspaceScope. Keeping the SoftDeletingScope
        // means trashed agents stop counting toward the plan's agent
        // quota. Client report 2026-05-22: banner showed "2 of 1 used"
        // on a Free plan workspace that visually only had one live
        // agent — the second row was soft-deleted but still tallied
        // because `withoutGlobalScopes()` stripped every scope.
        return (int) Agent::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->id)
            ->count();
    }

    private function countSources(Workspace $workspace): int
    {
        // Source rows belong to agents (BelongsToAgent), not directly to
        // workspaces. Count via a sub-query on agent ids so the SQL stays
        // index-friendly and skips the global scopes that would otherwise
        // require a CurrentWorkspace context.
        return (int) Source::query()
            ->withoutGlobalScopes()
            ->whereIn('agent_id', Agent::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select('id'))
            ->count();
    }

    private function countWorkflows(Workspace $workspace): int
    {
        return (int) Workflow::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();
    }

    /**
     * Integrations bundle Slack/etc connections AND outbound webhook
     * subscriptions because both count as "external systems we talk to"
     * from the buyer's perspective. Tickets, leads, and BYOK keys are
     * a separate concept — workspaces always get those.
     */
    private function countIntegrations(Workspace $workspace): int
    {
        $connections = (int) IntegrationConnection::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();

        $webhooks = (int) WebhookSubscription::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();

        return $connections + $webhooks;
    }

    /**
     * Member count includes:
     * - accepted seats (`workspace_users` rows)
     * - pending invitations not yet accepted (`invitations.accepted_at IS NULL`)
     *
     * The owner always counts as a seat. We count pending invites so
     * a workspace can't queue 100 invitations and then accept them all
     * after lowering the plan.
     */
    private function countMembers(Workspace $workspace): int
    {
        $seats = (int) WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->count();

        $pending = (int) Invitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        return $seats + $pending;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
