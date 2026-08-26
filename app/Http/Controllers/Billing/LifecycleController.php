<?php

namespace App\Http\Controllers\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PlanSubscriptionLedger;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\StripeProductSync;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer-driven subscription-lifecycle actions: cancel, resume, swap.
 *
 * Each verb lands on the gateway that owns the active subscription
 * (workspace.payment_gateway). The three gateways have different
 * capabilities, so the per-gateway behaviour is:
 *
 *   - Stripe (Cashier):  full set. cancel (at period end), cancelNow,
 *                        resume (before period end), swap (immediate
 *                        plan change with proration).
 *   - PayPal:            cancel. Resume isn't possible on a CANCELLED
 *                        subscription — PayPal makes that terminal.
 *                        Swap = cancel + new checkout (CheckoutController
 *                        already orphan-cancels the prior sub before
 *                        starting a new one).
 *   - Razorpay:          cancel (at cycle end). No resume path exposed
 *                        on cancelled subs. Swap = cancel + new
 *                        checkout, same as PayPal.
 *
 * Everything that mutates state goes through the {@see PlanSubscriptionLedger}
 * so the audit trail stays gateway-agnostic. The workspace's local
 * plan_id is left untouched here — the webhook (or the post-cancel
 * follow-up state retrieval) flips it. That keeps a single source of
 * truth and avoids two-write races between the click and the webhook.
 */
class LifecycleController
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly PayPalClient $paypal,
        private readonly RazorpayClient $razorpay,
        private readonly StripeProductSync $stripeSync,
        private readonly PlanSubscriptionLedger $ledger,
    ) {}

    public function cancel(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $gateway = (string) ($workspace->payment_gateway ?? '');

        try {
            switch ($gateway) {
                case PaymentGatewayRegistry::STRIPE:
                    if (! $workspace->subscribed('default')) {
                        return back()->with('error', 'No active Stripe subscription to cancel.');
                    }
                    $workspace->subscription('default')->cancel();
                    break;

                case PaymentGatewayRegistry::PAYPAL:
                    $subId = (string) ($workspace->paypal_subscription_id ?? '');
                    if ($subId === '') {
                        return back()->with('error', 'No active PayPal subscription to cancel.');
                    }
                    $this->paypal->cancelSubscription($subId, 'Customer cancelled via Pitchbar billing page.');
                    $this->ledger->record(
                        workspaceId: (string) $workspace->id,
                        planId: null,
                        gateway: PlanSubscription::GATEWAY_PAYPAL,
                        subscriptionId: $subId,
                        status: 'canceled',
                    );
                    break;

                case PaymentGatewayRegistry::RAZORPAY:
                    $subId = (string) ($workspace->razorpay_subscription_id ?? '');
                    if ($subId === '') {
                        return back()->with('error', 'No active Razorpay subscription to cancel.');
                    }
                    $this->razorpay->cancelSubscription($subId, atCycleEnd: true);
                    $this->ledger->record(
                        workspaceId: (string) $workspace->id,
                        planId: null,
                        gateway: PlanSubscription::GATEWAY_RAZORPAY,
                        subscriptionId: $subId,
                        status: 'canceled',
                    );
                    break;

                default:
                    return back()->with('error', 'No active subscription on file.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not cancel: '.$e->getMessage());
        }

        return back()->with(
            'success',
            $gateway === PaymentGatewayRegistry::STRIPE
                ? 'Subscription cancellation scheduled. You keep access until the end of the current billing period.'
                : 'Subscription cancelled.',
        );
    }

    /**
     * Resume a Stripe subscription that was cancelled-at-period-end but
     * hasn't yet expired. PayPal CANCELLED is terminal at PayPal's side
     * — a "resume" there would require a fresh checkout. Razorpay's
     * cancelled state is similarly final for our flow. So both fall
     * through to the same "start a new subscription" guidance.
     */
    public function resume(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $gateway = (string) ($workspace->payment_gateway ?? '');

        if ($gateway !== PaymentGatewayRegistry::STRIPE) {
            return back()->with(
                'error',
                'Resuming a cancelled '.ucfirst($gateway).' subscription is not supported. Start a new subscription from the plans grid instead.',
            );
        }

        if (! $workspace->subscribed('default')) {
            return back()->with('error', 'No Stripe subscription to resume.');
        }

        try {
            $workspace->subscription('default')->resume();
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not resume: '.$e->getMessage());
        }

        return back()->with('success', 'Subscription resumed. Billing will continue on the next cycle.');
    }

    /**
     * In-place plan swap. Stripe supports immediate swap with proration
     * — that's what Cashier::swap does, and it's the natural upgrade /
     * downgrade UX. PayPal + Razorpay don't have a clean swap primitive
     * (PayPal has revise but it's a separate approval round-trip;
     * Razorpay's update has plan-level restrictions), so we instead
     * surface a clear redirect to the new-plan checkout path. The
     * existing CheckoutController orphan-cancels the prior gateway
     * subscription before starting a new one, so the customer doesn't
     * end up paying both.
     */
    public function swap(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $data = $request->validate([
            'plan_slug' => ['required', 'string'],
        ]);

        $plan = Plan::query()->where('slug', $data['plan_slug'])->firstOrFail();
        if ($plan->price_cents <= 0) {
            return back()->with('error', 'You can only swap to a paid plan.');
        }

        $gateway = (string) ($workspace->payment_gateway ?? '');

        if ($gateway !== PaymentGatewayRegistry::STRIPE) {
            // Non-Stripe doesn't have a clean in-place swap. The supported
            // path is: pick the new plan from the grid; CheckoutController
            // cancels the old sub (orphan-cleanup branch) before creating
            // the new one. The UI surfaces this in the action button copy.
            return back()->with(
                'error',
                'In-place plan swaps are only supported on Stripe. To change plans, click the new plan in the grid — your existing '.ucfirst($gateway).' subscription will be cancelled automatically.',
            );
        }

        if (! $workspace->subscribed('default')) {
            return back()->with(
                'error',
                'No active Stripe subscription to swap. Start a new subscription from the plans grid.',
            );
        }

        try {
            $priceId = $this->stripeSync->ensurePriceFor($plan);
            $workspace->subscription('default')->swap($priceId);
            // The subsequent customer.subscription.updated webhook will
            // flip workspace.plan_id AND email the customer a plan-changed
            // confirmation (WebhookController::syncWorkspacePlan). If it
            // doesn't arrive, the reconciler on /app/billing catches the
            // plan flip up on the next render.
        } catch (\Throwable $e) {
            return back()->with('error', 'Plan swap failed: '.$e->getMessage());
        }

        return back()->with('success', "Swapped to {$plan->name}. The change is effective immediately.");
    }
}
