<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-LLM-call usage breadcrumb. One row per upstream provider call
 * the chat hot-path made (chat completion, embedding, reranker, tool
 * fan-out). Used by /admin/usage to chart per-workspace burn and lay
 * the groundwork for token-based quotas in v1.4.0.
 *
 * Writes are dispatched via PersistUsageJob AFTER the SSE 'done' event
 * so the hot path doesn't pay an extra DB write.
 */
class UsageLog extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'agent_id', 'conversation_id', 'message_id',
        'provider', 'model', 'purpose',
        'tokens_in', 'tokens_out', 'cost_usd_micro', 'latency_ms',
    ];

    protected $casts = [
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_usd_micro' => 'integer',
        'latency_ms' => 'integer',
        'created_at' => 'datetime',
    ];
}
