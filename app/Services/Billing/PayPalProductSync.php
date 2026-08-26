<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Support\AppBranding;

/**
 * Mirrors {@see StripeProductSync} for PayPal. Owns the local Plan ↔
 * PayPal Product/Plan mapping. Admins edit plans in the admin UI and
 * this service mirrors the changes to PayPal so they never need to log
 * in there to copy a plan id.
 *
 * PayPal Plans are immutable on price / billing_cycles after activation;
 * if the admin edits the price, we deactivate the old PayPal Plan and
 * create a new one under the same Product. Existing subscriptions on
 * the old plan stay billed at the old price (PayPal grandfathering),
 * which matches how StripeProductSync rotates Stripe Prices.
 *
 * Free / custom plans (price_cents <= 0) skip PayPal entirely.
 */
class PayPalProductSync
{
    public function __construct(private readonly PayPalClient $paypal) {}

    /**
     * Ensure the given plan has a `paypal_plan_id`. Returns the plan id.
     *
     * @throws \RuntimeException when the plan isn't suitable for purchase.
     */
    public function ensurePlanFor(Plan $plan): string
    {
        if ($plan->price_cents <= 0) {
            throw new \RuntimeException("Plan '{$plan->slug}' is not purchasable (price is 0).");
        }

        if ($plan->paypal_plan_id !== null && $plan->paypal_plan_id !== '') {
            return $plan->paypal_plan_id;
        }

        $this->syncPlan($plan);

        return (string) $plan->fresh()->paypal_plan_id;
    }

    /**
     * Idempotent product + plan sync.
     */
    public function syncPlan(Plan $plan): void
    {
        if ($plan->price_cents <= 0) {
            return;
        }

        if (! $this->paypal->isConfigured()) {
            throw new \RuntimeException('PayPal is not configured.');
        }

        $productId = $this->ensureProduct($plan);
        $this->ensurePlan($plan, $productId);
    }

    /**
     * Soft-archive the plan on PayPal. Existing subscriptions stay live
     * (PayPal blocks delete on Plans with active subscribers).
     */
    public function archivePlan(Plan $plan): void
    {
        if ($plan->paypal_plan_id !== null && $plan->paypal_plan_id !== '') {
            try {
                $this->paypal->deactivatePlan($plan->paypal_plan_id);
            } catch (\Throwable) {
                // Already archived / missing — nothing to do.
            }
        }
    }

    private function ensureProduct(Plan $plan): string
    {
        if ($plan->paypal_product_id !== null && $plan->paypal_product_id !== '') {
            return $plan->paypal_product_id;
        }

        $brand = AppBranding::siteTitle();
        $product = $this->paypal->createProduct(
            $brand.' '.$plan->name,
            $brand.' subscription plan: '.$plan->name,
        );

        $productId = (string) ($product['id'] ?? '');

        if ($productId === '') {
            throw new \RuntimeException('PayPal did not return a product id.');
        }

        $plan->forceFill(['paypal_product_id' => $productId])->save();

        return $productId;
    }

    private function ensurePlan(Plan $plan, string $productId): void
    {
        $currency = strtoupper((string) config('cashier.currency', 'usd'));
        $value = number_format($plan->price_cents / 100, 2, '.', '');
        $interval = $plan->interval === 'year' ? 'YEAR' : 'MONTH';
        $intervalLabel = $plan->interval === 'year' ? 'Yearly' : 'Monthly';

        $payload = [
            'product_id' => $productId,
            'name' => AppBranding::siteTitle().' '.$plan->name,
            'description' => $intervalLabel.' billing for the '.$plan->name.' plan.',
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => $interval, 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => [
                    'fixed_price' => ['value' => $value, 'currency_code' => $currency],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => ['value' => '0', 'currency_code' => $currency],
                'setup_fee_failure_action' => 'CONTINUE',
                'payment_failure_threshold' => 3,
            ],
        ];

        // PayPal plans don't support price edits in place. If the stored
        // plan id is for a now-mismatched price, deactivate it and let
        // the new one take over for fresh checkouts.
        if ($plan->paypal_plan_id !== null && $plan->paypal_plan_id !== '') {
            try {
                $this->paypal->deactivatePlan($plan->paypal_plan_id);
            } catch (\Throwable) {
                // already deactivated
            }
        }

        $created = $this->paypal->createPlan($payload);
        $newId = (string) ($created['id'] ?? '');

        if ($newId === '') {
            throw new \RuntimeException('PayPal did not return a plan id.');
        }

        $plan->forceFill(['paypal_plan_id' => $newId])->save();
    }
}
