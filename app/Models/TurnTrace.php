<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Behind-the-scenes record of a single widget turn: route decision,
 * retrieval summary, tool-loop hops, blocks, outcome/error. Written by
 * PersistTurnTraceJob AFTER the SSE stream closes (never on the hot
 * path) and read by the super-admin conversation debugger. Pruned by
 * `turn-traces:prune` after TURN_TRACE_RETENTION_DAYS.
 */
class TurnTrace extends Model
{
    use BelongsToAgent;
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_id', 'conversation_id', 'message_id', 'kind', 'payload', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
