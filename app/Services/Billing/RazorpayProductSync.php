<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Support\AppBranding;

/**
 * Mirrors {@see StripeProductSync} for Razorpay. Razorpay treats Plans
 * as a single resource (no separate Product), so this is the simplest
 * of the three syncs.
 *
 * Razorpay Plans are immutable on amount / period after creation. If
 * the admin edits the price, we orphan the old plan id (existing
 * subscriptions stay billed at the old rate — Razorpay grandfathering)
 * and create a new one. New checkouts use the new plan id.
 */
class RazorpayProductSync
{
    public function __construct(private readonly RazorpayClient $razorpay) {}

    /**
     * Ensure the given plan has a `razorpay_plan_id`. Returns the plan id.
     *
     * @throws \RuntimeException when the plan isn't suitable for purchase.
     */
    public function ensurePlanFor(Plan $plan): string
    {
        if ($plan->price_cents <= 0) {
            throw new \RuntimeException("Plan '{$plan->slug}' is not purchasable (price is 0).");
        }

        if ($plan->razorpay_plan_id !== null && $plan->razorpay_plan_id !== '') {
            return $plan->razorpay_plan_id;
        }

        $this->syncPlan($plan);

        return (string) $plan->fresh()->razorpay_plan_id;
    }

    public function syncPlan(Plan $plan): void
    {
        if ($plan->price_cents <= 0) {
            return;
        }

        if (! $this->razorpay->isConfigured()) {
            throw new \RuntimeException('Razorpay is not configured.');
        }

        if ($plan->razorpay_plan_id !== null && $plan->razorpay_plan_id !== '') {
            // Razorpay Plans are immutable — if a stored id exists, we trust
            // it and only churn when the admin nukes it manually. Plans are
            // 1-cent-precise on Razorpay, so there's nothing useful to "sync"
            // unless the price changed (handled in ensurePlanFor on demand).
            return;
        }

        $currency = strtoupper((string) config('cashier.currency', 'usd'));
        $period = $plan->interval === 'year' ? 'yearly' : 'monthly';
        $periodLabel = $plan->interval === 'year' ? 'Yearly' : 'Monthly';

        $payload = [
            'period' => $period,
            'interval' => 1,
            'item' => [
                'name' => AppBranding::siteTitle().' '.$plan->name,
                'amount' => (int) $plan->price_cents,
                'currency' => $currency,
                'description' => $periodLabel.' subscription to the '.$plan->name.' plan.',
            ],
            'notes' => [
                'plan_slug' => $plan->slug,
                'plan_id' => (string) $plan->id,
                'plan_interval' => $plan->interval ?? 'month',
            ],
        ];

        $created = $this->razorpay->createPlan($payload);
        $newId = (string) ($created['id'] ?? '');

        if ($newId === '') {
            throw new \RuntimeException('Razorpay did not return a plan id.');
        }

        $plan->forceFill(['razorpay_plan_id' => $newId])->save();
    }

    /**
     * Razorpay doesn't expose a plan-archive endpoint. We just clear the
     * local id so future checkouts re-create it under the new pricing.
     */
    public function archivePlan(Plan $plan): void
    {
        if ($plan->razorpay_plan_id !== null && $plan->razorpay_plan_id !== '') {
            $plan->forceFill(['razorpay_plan_id' => null])->save();
        }
    }
}
