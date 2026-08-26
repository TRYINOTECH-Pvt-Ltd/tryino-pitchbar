<?php

namespace App\Models;

use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;
    use HasUuidV7;

    public const BILLING_SUBSCRIPTION = 'subscription';

    public const BILLING_LIFETIME = 'lifetime';

    public const BILLING_ONE_TIME = 'one_time';

    protected $fillable = [
        'name', 'slug', 'monthly_conversations', 'monthly_messages',
        'max_tokens_per_response', 'price_cents', 'yearly_price_cents', 'interval',
        'billing_model',
        'stripe_price_id', 'stripe_product_id',
        'paypal_product_id', 'paypal_plan_id', 'razorpay_plan_id',
        'features', 'is_active', 'show_on_pricing_page', 'is_default_for_signup',
        'is_trial', 'trial_days',
        'prices', 'stripe_price_ids',
        'agents_limit', 'sources_limit', 'workflows_limit',
        'integrations_limit', 'members_limit', 'workspaces_limit',
        'api_access',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'show_on_pricing_page' => 'boolean',
        'is_default_for_signup' => 'boolean',
        'is_trial' => 'boolean',
        'trial_days' => 'integer',
        'monthly_conversations' => 'integer',
        'monthly_messages' => 'integer',
        'max_tokens_per_response' => 'integer',
        'price_cents' => 'integer',
        'yearly_price_cents' => 'integer',
        'prices' => 'array',
        'stripe_price_ids' => 'array',
        'agents_limit' => 'integer',
        'sources_limit' => 'integer',
        'workflows_limit' => 'integer',
        'integrations_limit' => 'integer',
        'members_limit' => 'integer',
        'workspaces_limit' => 'integer',
        'api_access' => 'boolean',
    ];

    /**
     * Minor-unit price (cents-ish) for a given ISO 4217 currency. Falls
     * back to `price_cents` (USD-equivalent) when the requested currency
     * has no row in `prices`. Returns null only when the plan has zero
     * pricing data at all (typically the Free / Custom plans).
     */
    public function priceFor(string $currency): ?int
    {
        $currency = strtolower(trim($currency));
        $prices = (array) ($this->prices ?? []);

        if (isset($prices[$currency])) {
            return (int) $prices[$currency];
        }

        // USD is the canonical fallback — every paid plan has a
        // `price_cents` value from the pre-multi-currency era.
        if ($currency !== 'usd' && isset($prices['usd'])) {
            return (int) $prices['usd'];
        }

        return $this->price_cents > 0 ? (int) $this->price_cents : null;
    }

    /**
     * @return array<int, string> Currencies this plan ships pricing for.
     */
    public function availableCurrencies(): array
    {
        $prices = (array) ($this->prices ?? []);
        $codes = array_map('strtolower', array_keys($prices));

        if ($codes === [] && $this->price_cents > 0) {
            return ['usd'];
        }

        return $codes;
    }

    public function stripePriceIdFor(string $currency): ?string
    {
        $currency = strtolower(trim($currency));
        $map = (array) ($this->stripe_price_ids ?? []);

        if (isset($map[$currency]) && is_string($map[$currency]) && $map[$currency] !== '') {
            return $map[$currency];
        }

        // Legacy single-Price installs continue to work with USD only.
        if ($currency === 'usd' && is_string($this->stripe_price_id) && $this->stripe_price_id !== '') {
            return $this->stripe_price_id;
        }

        return null;
    }

    public function setStripePriceIdFor(string $currency, string $priceId): void
    {
        $currency = strtolower(trim($currency));
        $map = (array) ($this->stripe_price_ids ?? []);
        $map[$currency] = $priceId;

        $this->stripe_price_ids = $map;
        // Mirror the USD price into the legacy column so reads that
        // still hit stripe_price_id (Cashier internals, older
        // dashboards) keep resolving.
        if ($currency === 'usd') {
            $this->stripe_price_id = $priceId;
        }
        $this->save();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * Whether this plan hides the "Powered by Pitchbar" footer in the
     * visitor widget. Driven off `features.remove_branding` so adding
     * new feature flags later doesn't require a schema change.
     *
     * C6 wiring: when a workspace has a lifetime plan, callers that
     * check `$workspace->plan->removesBranding()` would miss the LTD
     * unlock. The Workspace model now exposes `effectivePlanFeatures()`
     * which prefers the lifetime plan; existing code paths can keep
     * calling this method on the resolved plan instance.
     */
    public function removesBranding(): bool
    {
        return (bool) (($this->features ?? [])['remove_branding'] ?? false);
    }

    public const DEFAULT_TRIAL_DAYS = 14;

    /**
     * Whether signing up on this plan should start a time-limited,
     * no-card trial rather than an open-ended plan.
     */
    public function isTrial(): bool
    {
        return (bool) $this->is_trial;
    }

    /**
     * Trial length in days, falling back to a sane default when a plan
     * is flagged as a trial but left without an explicit length.
     */
    public function trialLengthInDays(): int
    {
        $days = (int) ($this->trial_days ?? 0);

        return $days > 0 ? $days : self::DEFAULT_TRIAL_DAYS;
    }

    public function isLifetime(): bool
    {
        return $this->billing_model === self::BILLING_LIFETIME;
    }

    public function isSubscription(): bool
    {
        return ($this->billing_model ?? self::BILLING_SUBSCRIPTION)
            === self::BILLING_SUBSCRIPTION;
    }

    public function isOneTimePurchase(): bool
    {
        return in_array(
            $this->billing_model,
            [self::BILLING_LIFETIME, self::BILLING_ONE_TIME],
            true,
        );
    }
}
