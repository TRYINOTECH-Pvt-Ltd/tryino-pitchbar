<?php

namespace App\Http\Controllers\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PlanSubscriptionLedger;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\WebhookIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Razorpay → us. Same role as {@see WebhookController} for Stripe and
 * {@see PayPalWebhookController} for PayPal — flips
 * `workspace.plan_id` based on the active subscription's lifecycle.
 *
 * Signature verification is HMAC-SHA256 over the raw request body keyed
 * by the dashboard-configured webhook secret. We honor it strictly when
 * a secret is configured (no test-mode bypass on production traffic).
 *
 * Subscription → workspace mapping is via the `notes.workspace_id` we
 * stamped on the subscription at checkout time. Subscription → plan
 * mapping is via Razorpay's `plan_id` ↔ `Plan.razorpay_plan_id`.
 */
class RazorpayWebhookController
{
    private const PLAN_GRANTING_EVENTS = [
        'subscription.activated',
        'subscription.charged',
        'subscription.resumed',
        'subscription.updated',
    ];

    private const PLAN_REVOKING_EVENTS = [
        'subscription.cancelled',
        'subscription.completed',
        'subscription.halted',
        'subscription.paused',
    ];

    public function __construct(
        private readonly RazorpayClient $razorpay,
        private readonly WebhookIdempotency $idempotency,
        private readonly PlanSubscriptionLedger $ledger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $webhookSecret = (string) config('services.razorpay.webhook_secret', '');

        if ($webhookSecret !== '') {
            if (! $this->razorpay->verifyWebhookSignature($rawBody, $signature, $webhookSecret)) {
                return response()->json([], 401);
            }
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true) ?? [];
        $event = (string) ($payload['event'] ?? '');

        /** @var array<string, mixed> $entity */
        $entity = $payload['payload']['subscription']['entity'] ?? [];

        if ($entity === []) {
            return response()->json(['ok' => true]);
        }

        // Idempotency. Razorpay retries up to 5 times on non-2xx
        // responses. The (subscription id, event) pair is stable
        // across retries — we use the entity id + event so two DIFFERENT
        // events on the same subscription (e.g. activated → charged)
        // both run, but a duplicate of the same event short-circuits.
        $subscriptionId = (string) ($entity['id'] ?? '');
        $idempotencyKey = $subscriptionId.':'.$event;
        if (! $this->idempotency->recordOrSkip(WebhookIdempotency::RAZORPAY, $idempotencyKey, $event)) {
            return response()->json(['ok' => true, 'replay' => true]);
        }

        if (in_array($event, self::PLAN_GRANTING_EVENTS, true)) {
            $this->grantPlan($entity, $event);
        } elseif (in_array($event, self::PLAN_REVOKING_EVENTS, true)) {
            $this->revertToFreePlan($entity);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Razorpay subscription states that genuinely mean "payment is
     * captured and the subscription is funding the workspace". Other
     * intermediate states (`created`, `authenticated`, `pending`) fire
     * `subscription.updated` BEFORE the payment captures — granting the
     * plan there means the customer can close the checkout, never
     * fund the subscription, and still walk away with paid features.
     *
     * Source: Razorpay subscription lifecycle docs —
     * `active` is the only state where billing is guaranteed; `charged`
     * is a transient "we just took a payment" state that always
     * coexists with `active`.
     */
    private const FUNDING_STATUSES = ['active', 'charged'];

    /**
     * @param  array<string, mixed>  $entity
     */
    private function grantPlan(array $entity, string $event): void
    {
        // Status gate. `subscription.charged` always means money moved
        // — we trust those unconditionally. For other granting events
        // (`activated`, `updated`, `resumed`) we require the entity's
        // own status to be one of the FUNDING_STATUSES; otherwise the
        // webhook arrived between checkout-open and payment-capture
        // and we must NOT credit the workspace yet.
        $status = strtolower((string) ($entity['status'] ?? ''));
        if ($event !== 'subscription.charged' && ! in_array($status, self::FUNDING_STATUSES, true)) {
            return;
        }

        $resolved = $this->workspaceFor($entity);
        $plan = $this->planFor($entity);

        if ($resolved === null || $plan === null) {
            return;
        }

        $subscriptionId = (string) ($entity['id'] ?? '');
        $statusValue = $status !== '' ? $status : 'active';

        DB::transaction(function () use ($resolved, $plan, $subscriptionId, $statusValue): void {
            $workspace = Workspace::query()
                ->where('id', $resolved->id)
                ->lockForUpdate()
                ->first();

            if ($workspace === null) {
                return;
            }

            $update = ['payment_gateway' => PaymentGatewayRegistry::RAZORPAY];
            if ($workspace->plan_id !== $plan->id) {
                $update['plan_id'] = $plan->id;
            }
            if ($subscriptionId !== '' && $workspace->razorpay_subscription_id !== $subscriptionId) {
                $update['razorpay_subscription_id'] = $subscriptionId;
            }
            $workspace->forceFill($update)->save();

            $this->ledger->record(
                workspaceId: (string) $workspace->id,
                planId: (string) $plan->id,
                gateway: PlanSubscription::GATEWAY_RAZORPAY,
                subscriptionId: $subscriptionId,
                status: $statusValue,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function revertToFreePlan(array $entity): void
    {
        $resolved = $this->workspaceFor($entity);

        if ($resolved === null) {
            return;
        }

        $subscriptionId = (string) ($entity['id'] ?? '');

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
                gateway: PlanSubscription::GATEWAY_RAZORPAY,
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
     * @param  array<string, mixed>  $entity
     */
    private function workspaceFor(array $entity): ?Workspace
    {
        /** @var array<string, mixed> $notes */
        $notes = $entity['notes'] ?? [];
        $workspaceId = (string) ($notes['workspace_id'] ?? '');
        $subscriptionId = (string) ($entity['id'] ?? '');

        if ($workspaceId !== '') {
            $byNote = Workspace::query()->where('id', $workspaceId)->first();
            if ($byNote !== null) {
                return $byNote;
            }
        }

        if ($subscriptionId !== '') {
            return Workspace::query()
                ->where('razorpay_subscription_id', $subscriptionId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function planFor(array $entity): ?Plan
    {
        $razorpayPlanId = (string) ($entity['plan_id'] ?? '');

        if ($razorpayPlanId === '') {
            return null;
        }

        return Plan::query()->where('razorpay_plan_id', $razorpayPlanId)->first();
    }
}
