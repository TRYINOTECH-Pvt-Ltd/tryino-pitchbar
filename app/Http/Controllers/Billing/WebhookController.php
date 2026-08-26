<?php

namespace App\Http\Controllers\Billing;

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Mail\PlanChangedMail;
use App\Models\InChatPayment;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use App\Services\Billing\PlanSubscriptionLedger;
use App\Services\Billing\WebhookIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe → us. Cashier handles the heavy lifting (subscription row CRUD,
 * customer/payment method sync, invoice payment-required emails). We
 * extend it to also flip the workspace's local `plan_id` whenever the
 * subscription's active price changes — that's what gates feature access
 * and conversation quota in the rest of the app.
 *
 * Mapping is keyed off the Plan.stripe_price_id we persisted at checkout
 * time (see StripeProductSync). When a subscription is canceled the
 * workspace falls back to the 'free' plan.
 */
class WebhookController extends CashierWebhookController
{
    public function __construct(
        private readonly WebhookIdempotency $idempotency,
        private readonly PlanSubscriptionLedger $ledger,
    ) {
        parent::__construct();
    }

    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        if ($this->isReplay($payload)) {
            return new Response('Webhook already processed.', 200);
        }
        $response = parent::handleCustomerSubscriptionCreated($payload);
        $this->syncWorkspacePlan($payload['data']['object'] ?? []);

        return $response;
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        if ($this->isReplay($payload)) {
            return new Response('Webhook already processed.', 200);
        }
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        // notifyOnChange: an existing subscription changing price is a plan
        // swap/upgrade/downgrade — email the customer. (Created is the
        // initial subscribe, which doesn't notify here.)
        $this->syncWorkspacePlan($payload['data']['object'] ?? [], notifyOnChange: true);

        return $response;
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        if ($this->isReplay($payload)) {
            return new Response('Webhook already processed.', 200);
        }
        $response = parent::handleCustomerSubscriptionDeleted($payload);
        $this->revertToFreePlan($payload['data']['object'] ?? []);

        return $response;
    }

    /**
     * Lifetime Deal completion (Stripe Checkout in `mode=payment`).
     * Cashier doesn't ship a handler for this event because it lives
     * outside the subscription lifecycle. We watch for sessions whose
     * metadata carries `billing_model=lifetime` and stamp
     * `workspaces.lifetime_plan_id` so plan-feature gates honor the
     * unlock permanently.
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        if ($this->isReplay($payload)) {
            return new Response('Webhook already processed.', 200);
        }

        $session = $payload['data']['object'] ?? [];
        $metadata = (array) ($session['metadata'] ?? []);

        // Branch on the checkout's intent. `pitchbar_kind=in_chat_checkout`
        // identifies the in-chat <checkout/> block flow; legacy LTD
        // checkouts carry `billing_model=lifetime` instead. Anything
        // else (workspace subscriptions, future product lines) flows
        // through the Cashier defaults.
        if (($metadata['pitchbar_kind'] ?? null) === 'in_chat_checkout') {
            return $this->handleInChatCheckoutCompleted($session);
        }

        if (($metadata['billing_model'] ?? null) !== Plan::BILLING_LIFETIME) {
            return new Response('Not an LTD checkout — ignored.', 200);
        }

        $workspaceId = (string) ($metadata['workspace_id'] ?? '');
        $planId = (string) ($metadata['plan_id'] ?? '');
        if ($workspaceId === '' || $planId === '') {
            return new Response('Missing LTD metadata — ignored.', 200);
        }

        $workspace = Workspace::query()->find($workspaceId);
        $plan = Plan::query()->find($planId);
        if ($workspace === null || $plan === null || ! $plan->isLifetime()) {
            return new Response('LTD workspace or plan not found.', 200);
        }

        $workspace->forceFill([
            'lifetime_plan_id' => $plan->id,
            'lifetime_purchased_at' => now(),
            'plan_id' => $plan->id,
        ])->save();

        return new Response('Lifetime access unlocked.', 200);
    }

    /**
     * Flip an InChatPayment row to `paid` and broadcast to the
     * conversation so the chat surface shows "Payment received".
     *
     * @param  array<string, mixed>  $session
     */
    private function handleInChatCheckoutCompleted(array $session): Response
    {
        $sessionId = (string) ($session['id'] ?? '');
        $paymentIntent = (string) ($session['payment_intent'] ?? '');
        if ($sessionId === '') {
            return new Response('Missing session id — ignored.', 200);
        }

        $payment = InChatPayment::query()
            ->withoutGlobalScopes()
            ->where('stripe_session_id', $sessionId)
            ->first();
        if ($payment === null) {
            \Log::warning('billing.in_chat_payment_missing', ['session_id' => $sessionId]);

            return new Response('In-chat payment row not found — ignored.', 200);
        }

        if ($payment->status === InChatPayment::STATUS_PAID) {
            return new Response('Already paid.', 200);
        }

        $payment->forceFill([
            'status' => InChatPayment::STATUS_PAID,
            'stripe_payment_intent' => $paymentIntent ?: $payment->stripe_payment_intent,
            'paid_at' => now(),
        ])->save();

        // Broadcast a "Payment received" message into the conversation
        // so the visitor's chat surface picks it up without polling.
        AgentReplyPostedEvent::dispatch(
            $payment->conversation_id,
            (string) Str::uuid7(),
            'Payment received — thanks!',
            'system',
        );

        return new Response('In-chat payment marked paid.', 200);
    }

    /**
     * Stripe's `event.id` is stable across retries. Recording it on
     * first sight + checking the unique constraint on subsequent
     * deliveries lets us 200-OK a duplicate without re-running the
     * (un)subscribe logic — preventing audit-log doubling, race
     * conditions between two simultaneous workers, and accidental
     * mid-flight state regressions when a stale retry arrives after
     * a newer event has already been applied.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isReplay(array $payload): bool
    {
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');

        return ! $this->idempotency->recordOrSkip(WebhookIdempotency::STRIPE, $eventId, $eventType);
    }

    /**
     * Mirror the Stripe subscription's active price → workspace.plan_id.
     * Statuses considered "owns the plan": active, trialing, past_due
     * (grace period). Anything else (incomplete, canceled) doesn't grant
     * the plan yet.
     */
    private function syncWorkspacePlan(array $obj, bool $notifyOnChange = false): void
    {
        $stripeCustomerId = (string) ($obj['customer'] ?? '');
        $status = (string) ($obj['status'] ?? '');
        $priceId = (string) ($obj['items']['data'][0]['price']['id'] ?? '');

        if ($stripeCustomerId === '' || $priceId === '') {
            return;
        }

        // Defensive: Stripe multi-item subscriptions are uncommon for us
        // but possible. Warn so operators see it in laravel.log and the
        // implicit choice of items[0] doesn't silently drop a price.
        if (count($obj['items']['data'] ?? []) > 1) {
            \Log::warning('billing.stripe_multi_item_subscription_detected', [
                'subscription_id' => $obj['id'] ?? null,
                'item_count' => count($obj['items']['data']),
            ]);
        }

        if (! in_array($status, ['active', 'trialing', 'past_due'], true)) {
            return;
        }

        $plan = Plan::query()->where('stripe_price_id', $priceId)->first();
        if ($plan === null) {
            return;
        }

        $subId = (string) ($obj['id'] ?? '');

        // Set inside the transaction when the plan actually changes, so the
        // confirmation email below can fire AFTER commit (never inside it).
        $previousPlan = null;
        $changedWorkspace = null;

        DB::transaction(function () use ($stripeCustomerId, $plan, $status, $subId, $obj, &$previousPlan, &$changedWorkspace): void {
            $workspace = Workspace::query()
                ->where('stripe_id', $stripeCustomerId)
                ->lockForUpdate()
                ->first();

            if ($workspace === null) {
                return;
            }

            if ($workspace->plan_id !== $plan->id) {
                // Capture the prior plan BEFORE the flip, for the email.
                $previousPlan = $workspace->plan;
                $changedWorkspace = $workspace;
                $workspace->forceFill(['plan_id' => $plan->id])->save();
            }

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_STRIPE,
                subscriptionId: $subId,
                status: $status,
                currentPeriodEnd: isset($obj['current_period_end'])
                    ? (new \DateTimeImmutable('@'.(int) $obj['current_period_end']))
                    : null,
                cancelAtPeriodEnd: (bool) ($obj['cancel_at_period_end'] ?? false),
            );
        });

        // "Your plan was changed" confirmation — best-effort, AFTER commit.
        // Stripe sends no email on a plain swap (a downgrade is a proration
        // credit, not a payment), so this is the customer's only notice.
        // Only on a real plan-to-plan change from an `updated` event (not the
        // initial subscribe) and only when the prior plan is known. A
        // mail/queue failure must never fail the webhook.
        if ($notifyOnChange && $previousPlan !== null && $changedWorkspace !== null) {
            $recipient = $changedWorkspace->owner?->email;
            if ($recipient !== null && $recipient !== '') {
                try {
                    Mail::to($recipient)->send(
                        new PlanChangedMail($changedWorkspace, (string) $previousPlan->name, (string) $plan->name),
                    );
                } catch (\Throwable $e) {
                    \Log::warning('billing.plan_changed_mail_failed', [
                        'workspace_id' => (string) $changedWorkspace->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function revertToFreePlan(array $obj): void
    {
        $stripeCustomerId = (string) ($obj['customer'] ?? '');
        if ($stripeCustomerId === '') {
            return;
        }

        $subId = (string) ($obj['id'] ?? '');

        DB::transaction(function () use ($stripeCustomerId, $subId): void {
            $workspace = Workspace::query()
                ->where('stripe_id', $stripeCustomerId)
                ->lockForUpdate()
                ->first();

            if ($workspace === null) {
                return;
            }

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: null,
                gateway: PlanSubscription::GATEWAY_STRIPE,
                subscriptionId: $subId,
                status: 'canceled',
            );

            $free = Plan::query()->where('slug', 'free')->first();
            if ($free === null) {
                return;
            }

            $workspace->forceFill(['plan_id' => $free->id])->save();
        });
    }
}
