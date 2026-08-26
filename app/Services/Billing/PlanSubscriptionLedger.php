<?php

namespace App\Services\Billing;

use App\Models\PlanSubscription;

/**
 * Upserts a `plan_subscriptions` row whenever a webhook reports a
 * state change. The Workspace model already carries the live state
 * (`workspace.plan_id`, `workspace.payment_gateway`,
 * `workspace.*_subscription_id`); this ledger gives us a per-event
 * audit trail across all three gateways with a single shape.
 *
 * Keyed on (gateway, gateway_subscription_id) so a duplicate webhook
 * (already filtered upstream by WebhookIdempotency, but doubly-safe
 * here) updates the existing row instead of creating a fork.
 */
final class PlanSubscriptionLedger
{
    /**
     * @param  string  $gateway  PlanSubscription::GATEWAY_*
     * @param  string  $subscriptionId  the gateway's own subscription id
     * @param  string  $status  "active" | "past_due" | "canceled" | "trialing" | "incomplete"
     */
    public function record(
        string $workspaceId,
        ?string $planId,
        string $gateway,
        string $subscriptionId,
        string $status,
        ?\DateTimeInterface $currentPeriodEnd = null,
        bool $cancelAtPeriodEnd = false,
    ): PlanSubscription {
        if ($subscriptionId === '') {
            // No subscription id to key on — we cannot dedupe across
            // retries. Skip rather than fork a row per delivery.
            return new PlanSubscription;
        }

        /** @var PlanSubscription $row */
        $row = PlanSubscription::query()->withoutGlobalScopes()
            ->updateOrCreate(
                ['gateway' => $gateway, 'gateway_subscription_id' => $subscriptionId],
                [
                    'workspace_id' => $workspaceId,
                    'plan_id' => $planId,
                    'status' => $status,
                    'current_period_end' => $currentPeriodEnd,
                    'cancel_at_period_end' => $cancelAtPeriodEnd,
                    // Mirror into the legacy stripe-only column for
                    // back-compat with code that hasn't been migrated
                    // off the old column yet.
                    'stripe_subscription_id' => $gateway === PlanSubscription::GATEWAY_STRIPE
                        ? $subscriptionId
                        : null,
                ],
            );

        return $row;
    }
}
