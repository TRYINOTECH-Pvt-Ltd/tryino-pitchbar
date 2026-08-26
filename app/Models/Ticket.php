<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C3: durable support ticket. Conversations are ephemeral (24h
 * resume); tickets persist forever. Created via the `open_ticket` tool
 * the LLM calls in help_center vertical agents, OR via a CTA of kind
 * `open_ticket` that operators put on agents directly.
 */
class Ticket extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasUuidV7;

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'workspace_id', 'agent_id', 'conversation_id',
        'subject', 'body', 'status', 'priority',
        'assigned_to_user_id', 'metadata', 'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function markResolved(?User $by = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'assigned_to_user_id' => $by?->id ?? $this->assigned_to_user_id,
        ])->save();
    }

    public function close(): void
    {
        $this->forceFill(['status' => self::STATUS_CLOSED])->save();
    }
}
