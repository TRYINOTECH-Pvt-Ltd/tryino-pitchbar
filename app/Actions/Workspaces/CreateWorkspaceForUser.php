<?php

namespace App\Actions\Workspaces;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CreateWorkspaceForUser
{
    /**
     * Create a workspace, owner membership, and a plan subscription.
     * Caller is responsible for wrapping in a transaction.
     *
     * `inheritFrom` lets the caller seed the new workspace's plan
     * (and lifetime status) from another workspace the user already
     * owns. The buyer-facing "create another workspace" flow uses
     * this so a paid user's second workspace doesn't silently
     * downgrade to Free — they paid for the plan, the extra
     * workspace inherits it.
     *
     * When `inheritFrom` is null (registration / fresh installs) the
     * action falls back to the default Free plan lookup: an admin-
     * flagged `is_default_for_signup` plan, then a slug match, then
     * a minimal Free plan created on demand.
     */
    public function handle(
        User $user,
        ?string $name = null,
        ?Workspace $inheritFrom = null,
    ): Workspace {
        $workspaceName = $name ?? trim((string) $user->name).'\'s Workspace';
        if ($workspaceName === '\'s Workspace') {
            $workspaceName = 'My Workspace';
        }

        [$planId, $lifetimePlanId, $lifetimePurchasedAt] = $this->resolvePlanFor($inheritFrom);

        $attrs = [
            'name' => $workspaceName,
            'slug' => Str::slug($workspaceName).'-'.Str::random(6),
            'plan_id' => $planId,
            'lifetime_plan_id' => $lifetimePlanId,
            'lifetime_purchased_at' => $lifetimePurchasedAt,
            'owner_user_id' => $user->id,
            'settings' => [],
        ];

        // Only override the DB default ('usd') when there's a source
        // workspace AND its currency is set — passing null here would
        // violate the NOT NULL constraint on `preferred_currency`.
        if ($inheritFrom !== null && ! empty($inheritFrom->preferred_currency)) {
            $attrs['preferred_currency'] = $inheritFrom->preferred_currency;
        }

        $workspace = Workspace::create($attrs);

        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        [$subscriptionStatus, $currentPeriodEnd] = $this->initialSubscriptionWindow($inheritFrom, $planId);

        PlanSubscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $planId,
            'status' => $subscriptionStatus,
            'current_period_end' => $currentPeriodEnd,
            'cancel_at_period_end' => false,
        ]);

        $user->forceFill(['default_workspace_id' => $workspace->id])->save();

        return $workspace;
    }

    /**
     * Decide the initial PlanSubscription window. A fresh sign-up whose
     * default plan is flagged `is_trial` starts a no-card, time-limited
     * trial (status `trialing`, period end = now + trial_days). Inherited
     * workspaces (a paid user's 2nd workspace) NEVER restart a trial —
     * they keep the open-ended active window.
     *
     * @return array{0: string, 1: Carbon} [status, currentPeriodEnd]
     */
    private function initialSubscriptionWindow(?Workspace $inheritFrom, string $planId): array
    {
        $defaultPlan = $inheritFrom === null ? Plan::query()->find($planId) : null;

        if ($defaultPlan !== null && $defaultPlan->isTrial()) {
            return ['trialing', now()->addDays($defaultPlan->trialLengthInDays())];
        }

        return ['active', now()->addMonth()];
    }

    /**
     * Resolve the plan tuple the new workspace should ship with.
     * Returns `[plan_id, lifetime_plan_id, lifetime_purchased_at]`.
     *
     * Lifetime fields are only carried over when the source workspace
     * has them set; a paid-monthly source still inherits its `plan_id`
     * but leaves the lifetime columns null.
     *
     * @return array{0: string, 1: string|null, 2: Carbon|null}
     */
    private function resolvePlanFor(?Workspace $inheritFrom): array
    {
        if ($inheritFrom !== null && $inheritFrom->plan_id !== null) {
            return [
                $inheritFrom->plan_id,
                $inheritFrom->lifetime_plan_id,
                $inheritFrom->lifetime_purchased_at,
            ];
        }

        $plan = $this->resolveDefaultPlan();

        return [$plan->id, null, null];
    }

    /**
     * Operator-controlled default: if any plan is flagged as the
     * signup default, attach that. Otherwise fall back to a detected
     * free plan (legacy slug-based match). Last resort: create a
     * minimal Free plan so new installs work out of the box. Buyer
     * ask 2026-05-19 (paywall: each install can pick a different
     * default plan, or skip free entirely by flagging a paid plan
     * as the default).
     */
    private function resolveDefaultPlan(): Plan
    {
        $plan = Plan::query()
            ->where('is_active', true)
            ->where('is_default_for_signup', true)
            ->first();

        if ($plan === null) {
            $plan = Plan::query()
                ->where('slug', 'like', 'free-%')
                ->orWhere('slug', 'free')
                ->orWhere('name', 'Free')
                ->first();
        }

        return $plan ?? Plan::create([
            'name' => 'Free',
            'slug' => 'free',
            'monthly_conversations' => 100,
            'price_cents' => 0,
            'features' => [],
            'is_active' => true,
        ]);
    }
}
