<?php

namespace App\Http\Controllers\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PlanSubscriptionLedger;
use App\Services\Billing\WebhookIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayPal → us. Mirrors {@see WebhookController} (Stripe) for the PayPal
 * gateway. Listens for the four lifecycle events that matter for our
 * "workspace.plan_id reflects the active subscription's plan" model:
 *
 *   - BILLING.SUBSCRIPTION.CREATED   → no-op (charge not authorized yet)
 *   - BILLING.SUBSCRIPTION.ACTIVATED → grant the plan
 *   - BILLING.SUBSCRIPTION.UPDATED   → grant / re-grant the plan
 *   - BILLING.SUBSCRIPTION.CANCELLED → revert to free
 *   - BILLING.SUBSCRIPTION.SUSPENDED → revert to free
 *
 * We map subscriptions back to a workspace via the `custom_id` we set
 * at checkout time (workspace UUID), and back to a plan via PayPal's
 * `plan_id` ↔ `Plan.paypal_plan_id`.
 *
 * Signature verification is required when a `paypal_webhook_id` is
 * configured; otherwise we accept the payload as-is so dev/staging
 * installs without a registered webhook still flow through.
 */
class PayPalWebhookController
{
    private const PLAN_GRANTING_EVENTS = [
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.RE-ACTIVATED',
    ];

    private const PLAN_REVOKING_EVENTS = [
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        // Pre-fix: a failed recurring charge only revoked the plan
        // when PayPal eventually flipped the subscription to
        // SUSPENDED (5+ minutes after the charge attempt). During
        // that window the customer kept paid features despite the
        // charge failure. PAYMENT.FAILED fires immediately on the
        // declined charge — revoking here closes the grace period.
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
    ];

    /**
     * Informational events that don't change plan state but we want
     * a structured log record for. Charge receipts go here.
     */
    private const PLAN_INFORMATIONAL_EVENTS = [
        'BILLING.SUBSCRIPTION.PAYMENT.COMPLETED',
    ];

    public function __construct(
        private readonly PayPalClient $paypal,
        private readonly WebhookIdempotency $idempotency,
        private readonly PlanSubscriptionLedger $ledger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventType = (string) ($payload['event_type'] ?? '');

        if ($eventType === '') {
            return response()->json([], 400);
        }

        $webhookId = (string) config('services.paypal.webhook_id', '');

        if ($webhookId !== '') {
            $verified = $this->paypal->verifyWebhook(
                $request->headers->all() ? array_map(
                    static fn (array $values) => $values[0] ?? '',
                    $request->headers->all(),
                ) : [],
                $payload,
                $webhookId,
            );

            if (! $verified) {
                return response()->json([], 401);
            }
        }

        // Idempotency. PayPal retries a webhook up to 25 times if the
        // endpoint takes longer than 5s to respond. Without this guard
        // a slow transient blip causes the same activate/cancel to
        // double-apply, corrupting audit history + racing with
        // newer events in flight.
        $eventId = (string) ($payload['id'] ?? '');
        if (! $this->idempotency->recordOrSkip(WebhookIdempotency::PAYPAL, $eventId, $eventType)) {
            return response()->json(['ok' => true, 'replay' => true]);
        }

        /** @var array<string, mixed> $resource */
        $resource = $payload['resource'] ?? [];

        if (in_array($eventType, self::PLAN_GRANTING_EVENTS, true)) {
            $this->grantPlan($resource);
        } elseif (in_array($eventType, self::PLAN_REVOKING_EVENTS, true)) {
            $this->revertToFreePlan($resource);
        } elseif (in_array($eventType, self::PLAN_INFORMATIONAL_EVENTS, true)) {
            // Charge receipts — don't mutate state, just log for
            // audit + future analytics ("how many recurring charges
            // succeeded this month?"). The subscription itself
            // already reflects the active state.
            Log::info('paypal.payment.completed', [
                'subscription_id' => (string) ($resource['billing_agreement_id'] ?? $resource['id'] ?? ''),
                'amount' => (string) ($resource['amount']['total'] ?? $resource['amount']['value'] ?? ''),
                'currency' => (string) ($resource['amount']['currency'] ?? $resource['amount']['currency_code'] ?? ''),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function grantPlan(array $resource): void
    {
        $plan = $this->planFor($resource);
        $resolved = $this->workspaceFor($resource);

        if ($resolved === null || $plan === null) {
            return;
        }

        $subscriptionId = (string) ($resource['id'] ?? '');

        DB::transaction(function () use ($resolved, $plan, $subscriptionId): void {
            $workspace = Workspace::query()
                ->where('id', $resolved->id)
                ->lockForUpdate()
                ->first();

            if ($workspace === null) {
                return;
            }

            $update = ['payment_gateway' => PaymentGatewayRegistry::PAYPAL];
            if ($workspace->plan_id !== $plan->id) {
                $update['plan_id'] = $plan->id;
            }
            if ($subscriptionId !== '' && $workspace->paypal_subscription_id !== $subscriptionId) {
                $update['paypal_subscription_id'] = $subscriptionId;
            }
            $workspace->forceFill($update)->save();

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_PAYPAL,
                subscriptionId: $subscriptionId,
                status: 'active',
            );
        });
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function revertToFreePlan(array $resource): void
    {
        $resolved = $this->workspaceFor($resource);

        if ($resolved === null) {
            return;
        }

        $subscriptionId = (string) ($resource['billing_agreement_id'] ?? $resource['id'] ?? '');

        DB::transaction(function () use ($resolved, $subscriptionId): void {
            $workspace = Workspace::query()
                ->where('id', $resolved->id)
                ->lockForUpdate()
                ->first();

            if ($workspace === null) {
                return;
            }

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: null,
                gateway: PlanSubscription::GATEWAY_PAYPAL,
                subscriptionId: $subscriptionId,
                status: 'canceled',
            );

            $free = Plan::query()->where('slug', 'free')->first();
            if ($free === null) {
                return;
            }

            $workspace->forceFill([
                'plan_id' => $free->id,
                'payment_gateway' => null,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function workspaceFor(array $resource): ?Workspace
    {
        $customId = (string) ($resource['custom_id'] ?? '');
        $subscriptionId = (string) ($resource['id'] ?? '');

        if ($customId !== '') {
            $byCustom = Workspace::query()->where('id', $customId)->first();
            if ($byCustom !== null) {
                return $byCustom;
            }
        }

        if ($subscriptionId !== '') {
            return Workspace::query()
                ->where('paypal_subscription_id', $subscriptionId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function planFor(array $resource): ?Plan
    {
        $paypalPlanId = (string) ($resource['plan_id'] ?? '');

        if ($paypalPlanId === '') {
            return null;
        }

        return Plan::query()->where('paypal_plan_id', $paypalPlanId)->first();
    }
}
