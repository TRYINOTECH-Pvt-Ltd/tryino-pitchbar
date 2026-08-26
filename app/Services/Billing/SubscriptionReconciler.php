<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Synchronously pulls the live subscription state from a gateway and
 * flips workspace.plan_id to match. Mirrors the logic of the per-gateway
 * webhook controllers, but runs in-band on the success redirect so the
 * customer never sees a "still on Free" page after paying.
 *
 * Why this exists: the webhook is the source-of-truth for an
 * eventually-consistent state machine, but networking is not reliable.
 * Three scenarios that strand a paying customer on Free without it:
 *
 *   1. The Stripe webhook endpoint isn't registered in the customer's
 *      Stripe dashboard at all (very common after a fresh install).
 *   2. The webhook fires but our endpoint is briefly down / signature
 *      mismatched / rejected by an upstream WAF.
 *   3. The webhook eventually arrives but takes 30s+ to deliver,
 *      during which the customer reloads the billing page and panics.
 *
 * Calling this on the post-checkout redirect closes all three gaps.
 * Same business rules as the webhook handlers — idempotent, status
 * gated, ledger row written.
 */
final class SubscriptionReconciler
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly PayPalClient $paypal,
        private readonly RazorpayClient $razorpay,
        private readonly PlanSubscriptionLedger $ledger,
    ) {}

    /**
     * Reconcile against whichever gateway the workspace last checked out
     * with. Returns the active Plan after reconciliation, or null when
     * nothing applied (no payment_gateway set, gateway lookup failed,
     * subscription not yet funded).
     *
     * @param  string|null  $stripeSessionId  CHECKOUT_SESSION_ID from the
     *                                        success redirect, when Stripe.
     */
    public function reconcile(Workspace $workspace, ?string $stripeSessionId = null): ?Plan
    {
        $gateway = (string) ($workspace->payment_gateway ?? '');

        return match ($gateway) {
            PaymentGatewayRegistry::STRIPE => $this->reconcileStripe($workspace, $stripeSessionId),
            PaymentGatewayRegistry::PAYPAL => $this->reconcilePayPal($workspace),
            PaymentGatewayRegistry::RAZORPAY => $this->reconcileRazorpay($workspace),
            default => null,
        };
    }

    /**
     * Stripe path. With a session_id we can pull the subscription even
     * if Cashier's `subscriptions` row hasn't been written yet (webhook
     * race). Without one we fall back to `workspace.stripe_id` to walk
     * the Subscriptions API list — useful when the customer revisits
     * /app/billing well after checkout and the URL no longer carries
     * the session id.
     */
    private function reconcileStripe(Workspace $workspace, ?string $sessionId): ?Plan
    {
        try {
            $subscriptionId = null;

            if ($sessionId !== null && $sessionId !== '') {
                $session = $this->stripe->checkout->sessions->retrieve($sessionId, [
                    'expand' => ['subscription'],
                ]);

                $subscriptionId = is_string($session->subscription ?? null)
                    ? (string) $session->subscription
                    : (string) ($session->subscription->id ?? '');

                // Backfill workspace.stripe_id from the session if Cashier
                // hasn't done it yet — the link is needed for follow-up
                // webhook arrivals to find the workspace.
                $customerId = is_string($session->customer ?? null)
                    ? (string) $session->customer
                    : (string) ($session->customer->id ?? '');
                if ($customerId !== '' && $workspace->stripe_id !== $customerId) {
                    $workspace->forceFill(['stripe_id' => $customerId])->save();
                }
            }

            if (($subscriptionId === null || $subscriptionId === '')
                && (string) ($workspace->stripe_id ?? '') !== ''
            ) {
                $subscriptions = $this->stripe->subscriptions->all([
                    'customer' => (string) $workspace->stripe_id,
                    'status' => 'all',
                    'limit' => 5,
                ]);

                foreach ($subscriptions->data as $candidate) {
                    if (in_array($candidate->status, ['active', 'trialing', 'past_due'], true)) {
                        $subscriptionId = $candidate->id;
                        break;
                    }
                }
            }

            if ($subscriptionId === null || $subscriptionId === '') {
                return null;
            }

            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
            $status = (string) $subscription->status;
            $priceId = (string) ($subscription->items->data[0]->price->id ?? '');
            $customerId = (string) $subscription->customer;

            if ($priceId === '' || $customerId === '') {
                return null;
            }

            if (! in_array($status, ['active', 'trialing', 'past_due'], true)) {
                return null;
            }

            $plan = Plan::query()->where('stripe_price_id', $priceId)->first();
            if ($plan === null) {
                return null;
            }

            $update = [];
            if ($workspace->plan_id !== $plan->id) {
                $update['plan_id'] = $plan->id;
            }
            if ($workspace->stripe_id !== $customerId) {
                $update['stripe_id'] = $customerId;
            }
            if ($update !== []) {
                $workspace->forceFill($update)->save();
            }

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_STRIPE,
                subscriptionId: $subscriptionId,
                status: $status,
                currentPeriodEnd: isset($subscription->current_period_end)
                    ? new \DateTimeImmutable('@'.(int) $subscription->current_period_end)
                    : null,
                cancelAtPeriodEnd: (bool) ($subscription->cancel_at_period_end ?? false),
            );

            return $plan;
        } catch (\Throwable $e) {
            Log::warning('reconcile.stripe_failed', [
                'workspace_id' => (string) $workspace->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function reconcilePayPal(Workspace $workspace): ?Plan
    {
        $subscriptionId = (string) ($workspace->paypal_subscription_id ?? '');
        if ($subscriptionId === '') {
            return null;
        }

        try {
            $resource = $this->paypal->getSubscription($subscriptionId);
            $status = strtoupper((string) ($resource['status'] ?? ''));
            $paypalPlanId = (string) ($resource['plan_id'] ?? '');

            // PayPal subscription statuses: APPROVAL_PENDING, APPROVED,
            // ACTIVE, SUSPENDED, CANCELLED, EXPIRED. Only ACTIVE +
            // APPROVED (just approved, first billing imminent) grant.
            if (! in_array($status, ['ACTIVE', 'APPROVED'], true)) {
                return null;
            }

            $plan = Plan::query()->where('paypal_plan_id', $paypalPlanId)->first();
            if ($plan === null) {
                return null;
            }

            $update = ['payment_gateway' => PaymentGatewayRegistry::PAYPAL];
            if ($workspace->plan_id !== $plan->id) {
                $update['plan_id'] = $plan->id;
            }
            $workspace->forceFill($update)->save();

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_PAYPAL,
                subscriptionId: $subscriptionId,
                status: 'active',
            );

            return $plan;
        } catch (\Throwable $e) {
            Log::warning('reconcile.paypal_failed', [
                'workspace_id' => (string) $workspace->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function reconcileRazorpay(Workspace $workspace): ?Plan
    {
        $subscriptionId = (string) ($workspace->razorpay_subscription_id ?? '');
        if ($subscriptionId === '') {
            return null;
        }

        try {
            $entity = $this->razorpay->getSubscription($subscriptionId);
            $status = strtolower((string) ($entity['status'] ?? ''));
            $razorpayPlanId = (string) ($entity['plan_id'] ?? '');

            // Same funding-status gate as the webhook controller:
            // intermediate states (created, authenticated, pending)
            // fire before payment captures. Only `active` and `charged`
            // mean money has moved.
            if (! in_array($status, ['active', 'charged'], true)) {
                return null;
            }

            $plan = Plan::query()->where('razorpay_plan_id', $razorpayPlanId)->first();
            if ($plan === null) {
                return null;
            }

            $update = ['payment_gateway' => PaymentGatewayRegistry::RAZORPAY];
            if ($workspace->plan_id !== $plan->id) {
                $update['plan_id'] = $plan->id;
            }
            $workspace->forceFill($update)->save();

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_RAZORPAY,
                subscriptionId: $subscriptionId,
                status: $status,
            );

            return $plan;
        } catch (\Throwable $e) {
            Log::warning('reconcile.razorpay_failed', [
                'workspace_id' => (string) $workspace->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
