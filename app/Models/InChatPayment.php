<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InChatPayment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'workspace_id',
        'agent_id',
        'conversation_id',
        'stripe_session_id',
        'stripe_payment_intent',
        'amount_cents',
        'currency',
        'title',
        'description',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public const STATUS_CREATED = 'created';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
