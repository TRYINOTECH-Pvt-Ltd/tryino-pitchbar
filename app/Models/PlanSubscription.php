<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanSubscription extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    /**
     * Recognised gateway discriminators — mirrors PaymentGatewayRegistry.
     */
    public const GATEWAY_STRIPE = 'stripe';

    public const GATEWAY_PAYPAL = 'paypal';

    public const GATEWAY_RAZORPAY = 'razorpay';

    protected $fillable = [
        'workspace_id', 'plan_id',
        'gateway', 'gateway_subscription_id',
        'stripe_subscription_id', // legacy — preserved for old rows
        'status', 'current_period_end', 'cancel_at_period_end',
    ];

    protected $casts = [
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
