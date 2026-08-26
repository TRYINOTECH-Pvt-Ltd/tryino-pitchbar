<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Support\AppBranding;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Owns the local Plan ↔ Stripe Product/Price mapping. Admins edit
 * plans in the admin UI and this service mirrors the changes to
 * Stripe so they never need to log in there to copy a price ID.
 *
 * Two flavors:
 *
 *   ensurePriceFor()  — first-touch lazy sync. Used by the checkout
 *                       path so a plan that's never been bought
 *                       still works on first attempt. Throws on
 *                       free / custom plans.
 *
 *   syncPlan()        — eager admin-driven sync. Used after the admin
 *                       creates / updates a plan. Idempotent. Free
 *                       and custom plans are skipped silently.
 *
 *   archivePlan()     — soft delete on Stripe (Stripe doesn't allow
 *                       hard deletes once anything has been billed
 *                       against a product). Sets the product + any
 *                       existing price to active=false.
 *
 * On price changes: Stripe Prices are immutable. We create a new
 * Price under the same Product and archive the old one. Existing
 * subscriptions on the old price keep that price (Stripe behavior);
 * new checkouts use the new price. That's the right grandfathering
 * default for SaaS.
 */
class StripeProductSync
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Ensure the given plan has a `stripe_price_id`. Returns the price ID.
     *
     * @throws \RuntimeException when the plan isn't suitable for purchase
     *                           (free, custom, or zero price).
     */
    public function ensurePriceFor(Plan $plan): string
    {
        if ($plan->price_cents <= 0) {
            throw new \RuntimeException("Plan '{$plan->slug}' is not purchasable (price is 0).");
        }

        if ($plan->stripe_price_id !== null && $plan->stripe_price_id !== '') {
            if (! $this->priceMissing($plan->stripe_price_id)) {
                return $plan->stripe_price_id;
            }

            // Test->live key switch: the saved price was minted under the
            // other mode's key. Drop the stale ids; syncPlan() below
            // re-mints Product + Price under the CURRENT key.
            \Log::warning('billing.stale_stripe_price_healed', [
                'plan_id' => $plan->id,
                'stale_price_id' => $plan->stripe_price_id,
            ]);
            $plan->forceFill([
                'stripe_price_id' => null,
                'stripe_product_id' => null,
            ])->save();
        }

        $this->syncPlan($plan);

        return (string) $plan->fresh()->stripe_price_id;
    }

    /**
     * C2: ensure a Stripe Price exists for the plan in the requested
     * ISO 4217 currency. Lazy-mints on first checkout in that currency.
     * Returns the price ID for the caller to pass into Cashier.
     *
     * `usd` falls back to the legacy `ensurePriceFor()` so existing
     * Stripe Prices keep being honored without re-minting. Every other
     * currency goes through `mintCurrencyPrice()` which writes the
     * returned price ID into `plans.stripe_price_ids[currency]`.
     *
     * @throws \RuntimeException when the plan has no price configured
     *                           for the requested currency.
     */
    public function ensurePriceForCurrency(Plan $plan, string $currency): string
    {
        $currency = strtolower(trim($currency));

        if ($currency === 'usd' || $currency === '') {
            return $this->ensurePriceFor($plan);
        }

        $existing = $plan->stripePriceIdFor($currency);
        if ($existing !== null) {
            if (! $this->priceMissing($existing)) {
                return $existing;
            }

            // Same test->live healing as ensurePriceFor, per-currency map.
            \Log::warning('billing.stale_stripe_price_healed', [
                'plan_id' => $plan->id,
                'currency' => $currency,
                'stale_price_id' => $existing,
            ]);
            $map = (array) ($plan->stripe_price_ids ?? []);
            unset($map[strtolower($currency)]);
            $plan->forceFill([
                'stripe_price_ids' => $map,
                'stripe_product_id' => null,
            ])->save();
        }

        $amount = $plan->priceFor($currency);
        if ($amount === null || $amount <= 0) {
            throw new \RuntimeException("Plan '{$plan->slug}' has no price set for currency '{$currency}'.");
        }

        $productId = $this->ensureProduct($plan);
        $price = $this->stripe->prices->create([
            'product' => $productId,
            'unit_amount' => $amount,
            'currency' => $currency,
            'recurring' => $plan->isLifetime() ? null : ['interval' => (string) ($plan->interval ?? 'month')],
            'metadata' => [
                'plan_id' => (string) $plan->id,
                'plan_slug' => (string) $plan->slug,
            ],
        ]);

        $plan->setStripePriceIdFor($currency, (string) $price->id);

        return (string) $price->id;
    }

    /**
     * Idempotent end-to-end sync. Creates Stripe Product + Price as
     * needed, updates the Product when name/metadata changed, rotates
     * the Price when price_cents or currency changed.
     */
    public function syncPlan(Plan $plan): void
    {
        if ($plan->price_cents <= 0) {
            // Free / custom plans never get a Stripe entity.
            return;
        }

        $productId = $this->ensureProduct($plan);
        $this->ensurePrice($plan, $productId);
    }

    /**
     * Archive the plan on Stripe. Used when an admin deletes a plan;
     * the local row stays (set is_active=false) so workspace links
     * don't break, but the Product + Price are flagged inactive on
     * Stripe so they can't be checked out against.
     */
    public function archivePlan(Plan $plan): void
    {
        if ($plan->stripe_price_id !== null && $plan->stripe_price_id !== '') {
            try {
                $this->stripe->prices->update($plan->stripe_price_id, ['active' => false]);
            } catch (\Throwable) {
                // Already archived / missing — nothing to do.
            }
        }

        if ($plan->stripe_product_id !== null && $plan->stripe_product_id !== '') {
            try {
                $this->stripe->products->update($plan->stripe_product_id, ['active' => false]);
            } catch (\Throwable) {
                // Already archived / missing — nothing to do.
            }
        }
    }

    /**
     * Walk every active paid plan and ensure each has a Stripe price.
     * Useful for a one-shot artisan command but not on the request path.
     */
    public function syncAll(): void
    {
        Plan::query()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->each(fn (Plan $p) => $this->syncPlan($p));
    }

    /**
     * Best-effort probe for the test->live key-switch footgun. True
     * ONLY when Stripe definitively reports the price does not exist
     * under the current key ("No such price"). Auth/network/any other
     * error returns false — the probe must never turn a working
     * checkout into a new failure; the real call downstream surfaces
     * genuine problems.
     */
    private function priceMissing(string $priceId): bool
    {
        try {
            $this->stripe->prices->retrieve($priceId);

            return false;
        } catch (InvalidRequestException $e) {
            return str_contains($e->getMessage(), 'No such price');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create the Stripe Product on first sync; update name + metadata
     * + active flag on subsequent syncs. Returns the product id.
     */
    private function ensureProduct(Plan $plan): string
    {
        $payload = [
            'name' => AppBranding::siteTitle().' '.$plan->name,
            'active' => (bool) $plan->is_active,
            'metadata' => [
                'plan_slug' => $plan->slug,
                'plan_id' => $plan->id,
            ],
        ];

        if ($plan->stripe_product_id !== null && $plan->stripe_product_id !== '') {
            try {
                $this->stripe->products->update($plan->stripe_product_id, $payload);

                return $plan->stripe_product_id;
            } catch (\Throwable) {
                // Stored id no longer exists on Stripe — fall through to create.
            }
        }

        $product = $this->stripe->products->create($payload);
        $plan->forceFill(['stripe_product_id' => $product->id])->save();

        return $product->id;
    }

    /**
     * Ensure the Plan's price_cents matches a live Stripe Price under
     * the given Product. Reuses the stored price when it still
     * matches; otherwise creates a new one + archives the prior.
     */
    private function ensurePrice(Plan $plan, string $productId): void
    {
        $currency = strtolower((string) config('cashier.currency', 'usd'));
        $stripeInterval = $plan->interval === 'year' ? 'year' : 'month';

        if ($plan->stripe_price_id !== null && $plan->stripe_price_id !== '') {
            try {
                $existing = $this->stripe->prices->retrieve($plan->stripe_price_id);

                $matches = (int) $existing->unit_amount === (int) $plan->price_cents
                    && strtolower((string) $existing->currency) === $currency
                    && (string) ($existing->recurring?->interval ?? 'month') === $stripeInterval;

                if ($matches && $existing->active) {
                    return;
                }

                if (! $matches) {
                    // Price changed — old one stays archived for any
                    // existing subs grandfathered onto it; create new below.
                    try {
                        $this->stripe->prices->update($plan->stripe_price_id, ['active' => false]);
                    } catch (\Throwable) {
                        // already archived
                    }
                }
            } catch (\Throwable) {
                // Stored price no longer exists on Stripe — create a new one.
            }
        }

        $price = $this->stripe->prices->create([
            'product' => $productId,
            'unit_amount' => $plan->price_cents,
            'currency' => $currency,
            'recurring' => ['interval' => $stripeInterval],
            'metadata' => [
                'plan_slug' => $plan->slug,
                'plan_id' => $plan->id,
                'plan_interval' => $stripeInterval,
            ],
        ]);

        $plan->forceFill(['stripe_price_id' => $price->id])->save();
    }
}
