<?php

namespace App\Models;

use App\Concerns\HasUuidV7;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable;

class Workspace extends Model
{
    use Billable;
    use HasFactory;
    use HasUuidV7;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'plan_id', 'owner_user_id',
        // Cashier's Billable trait writes Stripe customer/subscription
        // IDs into its own `stripe_id` + `subscriptions` table. The
        // pre-Cashier `stripe_customer_id` / `stripe_subscription_id`
        // columns are dead — keep them in DB for back-compat readers
        // but never mass-assign to them (silently no-op + would
        // confuse future maintainers into thinking they're live).
        'payment_gateway', 'paypal_subscription_id', 'razorpay_subscription_id',
        'settings', 'widget_defaults', 'live_chat_personalize',
        'slack_webhook_url', 'teams_webhook_url', 'business_hours',
        'cta_context_secret',
        'lifetime_plan_id', 'lifetime_purchased_at',
        'preferred_currency',
        'byok_keys',
    ];

    protected $casts = [
        'settings' => 'array',
        'widget_defaults' => 'array',
        'live_chat_personalize' => 'boolean',
        'business_hours' => 'array',
        'cta_context_secret' => 'encrypted',
        'lifetime_purchased_at' => 'datetime',
        // C1: BYOK keys are encrypted at rest via APP_KEY. Same
        // pattern as cta_context_secret.
        'byok_keys' => 'encrypted:array',
        // Slack + Teams webhook URLs carry an auth token slug in their path.
        'slack_webhook_url' => 'encrypted',
        'teams_webhook_url' => 'encrypted',
    ];

    protected $hidden = [
        'cta_context_secret',
        'byok_keys',
        'slack_webhook_url',
        'teams_webhook_url',
    ];

    public function hasLifetimeAccess(): bool
    {
        return $this->lifetime_plan_id !== null;
    }

    public function lifetimePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'lifetime_plan_id');
    }

    /**
     * The plan whose quotas + features should apply to this workspace.
     * Lifetime plan wins when set — its limits still apply, but the
     * subscription gate is bypassed for plan-feature lookups.
     */
    public function effectivePlan(): ?Plan
    {
        if ($this->hasLifetimeAccess()) {
            return $this->lifetime_plan_id
                ? Plan::query()->find($this->lifetime_plan_id)
                : null;
        }

        return $this->plan;
    }

    /**
     * Branding-removal flag — checks the effective plan first so an
     * LTD purchase with `features.remove_branding=true` unlocks the
     * white-label footer even if the workspace's nominal `plan_id`
     * still points at a free / cheaper subscription tier. The widget
     * `branding.show` field reads through this accessor so callers
     * don't need to remember the lifetime priority.
     */
    public function removesBranding(): bool
    {
        $plan = $this->effectivePlan();

        return $plan !== null && $plan->removesBranding();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot('role', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function workspaceUsers(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function planSubscription(): HasOne
    {
        return $this->hasOne(PlanSubscription::class);
    }

    /**
     * Statuses on the plan-subscription ledger that grant full paid
     * access. `trialing` is deliberately excluded — a trial is not paid
     * access, it's the thing that expires.
     */
    private const PAID_ACCESS_STATUSES = ['active', 'past_due'];

    /**
     * Whether this workspace has access that outlives a trial — a
     * lifetime deal or a paid (active / past-due) subscription. There is
     * exactly one plan-subscription row per workspace (unique
     * `workspace_id`); an upgrade flips that row's status in place rather
     * than adding another.
     */
    public function hasActivePaidAccess(): bool
    {
        if ($this->hasLifetimeAccess()) {
            return true;
        }

        return in_array(
            (string) $this->planSubscription?->status,
            self::PAID_ACCESS_STATUSES,
            true,
        );
    }

    /**
     * The workspace's trial end, or null when it isn't on a trial. A row
     * only counts as a trial while its status is still `trialing` — once
     * the customer upgrades the status flips and this returns null.
     */
    public function trialEndsAt(): ?CarbonInterface
    {
        $sub = $this->planSubscription;

        if ($sub === null || $sub->status !== 'trialing') {
            return null;
        }

        return $sub->current_period_end;
    }

    /**
     * Mid-trial: on a trial that hasn't run out yet.
     */
    public function onTrial(): bool
    {
        if ($this->hasLifetimeAccess()) {
            return false;
        }

        $endsAt = $this->trialEndsAt();

        return $endsAt !== null && $endsAt->isFuture();
    }

    /**
     * Trial is over and nothing paid has taken its place — the workspace
     * should be walled to the upgrade page.
     */
    public function trialExpired(): bool
    {
        if ($this->hasLifetimeAccess()) {
            return false;
        }

        $endsAt = $this->trialEndsAt();

        return $endsAt !== null && $endsAt->isPast();
    }

    /**
     * Whole days remaining in the trial (rounded up so "11 hours left"
     * reads as 1, not 0). Null when there's no trial; 0 once expired.
     */
    public function trialDaysLeft(): ?int
    {
        $endsAt = $this->trialEndsAt();

        if ($endsAt === null) {
            return null;
        }

        if ($endsAt->isPast()) {
            return 0;
        }

        return (int) max(1, ceil(now()->diffInHours($endsAt) / 24));
    }
}
